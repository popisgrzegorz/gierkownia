<?php

declare(strict_types=1);

namespace App\Tests\Core\Game;

use App\Core\Game\Fsm\GameFsm;
use App\Core\Game\Fsm\RedisMarkingStore;
use App\Core\Game\GameEngine;
use App\Core\Game\GameRegistry;
use App\Core\Game\GameState;
use App\Core\Redis\RedisReader;
use App\Core\Room\RoomCodeGenerator;
use App\Core\Room\RoomLock;
use App\Core\Room\RoomManager;
use App\Core\Score\Scoreboard;
use App\Core\Timer\GameTimer;
use App\Game\PanstwaMiasta\PanstwaMiastaGame;
use App\Tests\RedisTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;

/**
 * Prawdziwy wyscig o zapis kontekstu gry - w osobnych procesach, jak
 * BuzzerRaceTest. Sekwencyjne wywolania w jednym procesie nie dowodza niczego:
 * scenariusz z audytu to Panstwa-Miasta, gdzie WSZYSCY piszą naraz przez cala
 * runde, a kazda literka to GameState::mergeCtx (GET+SETEX na wspolnym _answers).
 *
 * Bez RoomLock w GameEngine::handle() dwa rownolegle zadania czytaja ten sam
 * stan sprzed zmiany, a drugi zapis kasuje pierwszy - jedna z odpowiedzi
 * znika bez sladu. Ten test odpala N niezaleznych procesow piszacych
 * jednoczesnie do TEJ SAMEJ rubryki i sprawdza, czy wszystkie przetrwaly.
 */
final class GameEngineRaceTest extends RedisTestCase
{
    private const TTL = 60;
    private const WRITERS = 6;

    private string $workerPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open jest wylaczone - nie da sie odpalic rownoleglych procesow.');
        }

        $this->workerPath = tempnam(sys_get_temp_dir(), 'answer-worker-').'.php';
        file_put_contents($this->workerPath, self::workerSource());
    }

    protected function tearDown(): void
    {
        if (isset($this->workerPath) && is_file($this->workerPath)) {
            unlink($this->workerPath);
        }
    }

    public function testAllSimultaneousAnswersSurviveInTheSameColumn(): void
    {
        $reader = new RedisReader($this->redis);
        $gameState = new GameState($this->redis, $reader, self::TTL);
        $fsm = new GameFsm(new RedisMarkingStore($gameState));
        $rooms = new RoomManager($this->redis, $reader, new RoomCodeGenerator(), self::TTL);
        $timer = new GameTimer($this->redis, $reader, self::TTL);

        $publisher = new \App\Core\Realtime\Publisher(new MockHub(
            url: 'http://localhost/.well-known/mercure',
            jwtProvider: new StaticTokenProvider('test'),
            publisher: static fn (): string => 'test-id',
        ));

        $scoreboard = new Scoreboard($this->redis, $reader, $rooms, $publisher, self::TTL);
        $game = new PanstwaMiastaGame($fsm, $gameState, $rooms, $scoreboard, $timer);
        $engine = new GameEngine(new GameRegistry([$game]), $gameState, $fsm, $rooms, $publisher, new RoomLock($this->redis));

        $room = $rooms->create();
        $teams = [];
        foreach (range(1, self::WRITERS) as $i) {
            $teams[] = $rooms->join($room->code, 'Druzyna'.$i);
        }

        $engine->start($room, PanstwaMiastaGame::type());
        $engine->handle($room, 'configure', ['preset' => 'krotkie', 'seconds' => 30], \App\Core\Game\Actor::host());
        $engine->handle($room, 'draw', [], \App\Core\Game\Actor::host());

        $this->race($room->code, $teams, 'Państwo');

        // Odczyt niezaleznie od zamka (tak jak zrobilby to nastepny watek po
        // wyscigu): jesli lock dziala, kazda z WRITERS odpowiedzi jest na
        // miejscu, mimo ze wszystkie proby trafily w ten sam moment.
        $ctx = $gameState->ctx($room->code);
        $answers = $ctx['_answers'] ?? null;
        self::assertIsArray($answers);

        foreach ($teams as $team) {
            $forTeam = $answers[$team->id] ?? null;
            self::assertIsArray($forTeam, sprintf('Odpowiedz druzyny "%s" zginela w wyscigu o zapis.', $team->name));
            self::assertSame(
                'odpowiedz-'.$team->name,
                $forTeam['Państwo'] ?? null,
                sprintf('Odpowiedz druzyny "%s" zginela w wyscigu o zapis.', $team->name),
            );
        }
    }

    /** @param list<\App\Core\Room\Team> $teams */
    private function race(string $code, array $teams, string $column): void
    {
        $url = $_ENV['REDIS_URL'] ?? $_SERVER['REDIS_URL'] ?? '';
        self::assertIsString($url);

        $startAt = microtime(true) + 1.5;

        $processes = [];
        foreach ($teams as $team) {
            $command = [
                \PHP_BINARY,
                $this->workerPath,
                \dirname(__DIR__, 3).'/vendor/autoload.php',
                $url,
                $code,
                $team->id,
                $column,
                'odpowiedz-'.$team->name,
                sprintf('%.6F', $startAt),
            ];

            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process, 'Nie udalo sie uruchomic procesu pisza,cego odpowiedz.');

            $processes[] = ['handle' => $process, 'pipes' => $pipes];
        }

        foreach ($processes as $process) {
            $stdout = stream_get_contents($process['pipes'][1]);
            $stderr = stream_get_contents($process['pipes'][2]);

            fclose($process['pipes'][1]);
            fclose($process['pipes'][2]);
            proc_close($process['handle']);

            self::assertSame('', trim((string) $stderr), 'Proces piszacy zglosil blad: '.$stderr);
            self::assertSame('OK', trim((string) $stdout), 'Proces piszacy nie potwierdzil zapisu: '.$stdout);
        }
    }

    /**
     * Zrodlo procesu-pisarza. Kazdy nawiazuje polaczenie z Redisem PRZED
     * umowionym momentem startu, zeby w wyscigu mierzyl sie sam zapis
     * odpowiedzi, a nie czas nawiazywania polaczenia.
     */
    private static function workerSource(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            [, $autoload, $url, $code, $teamId, $column, $text, $startAt] = $argv;

            require $autoload;

            $redis = new Predis\Client($url);
            $redis->ping();

            $reader = new App\Core\Redis\RedisReader($redis);
            $gameState = new App\Core\Game\GameState($redis, $reader, 60);
            $fsm = new App\Core\Game\Fsm\GameFsm(new App\Core\Game\Fsm\RedisMarkingStore($gameState));
            $rooms = new App\Core\Room\RoomManager($redis, $reader, new App\Core\Room\RoomCodeGenerator(), 60);
            $timer = new App\Core\Timer\GameTimer($redis, $reader, 60);

            $publisher = new App\Core\Realtime\Publisher(new Symfony\Component\Mercure\MockHub(
                'http://localhost/.well-known/mercure',
                new Symfony\Component\Mercure\Jwt\StaticTokenProvider('test'),
                static fn (): string => 'test-id',
            ));

            $scoreboard = new App\Core\Score\Scoreboard($redis, $reader, $rooms, $publisher, 60);
            $game = new App\Game\PanstwaMiasta\PanstwaMiastaGame($fsm, $gameState, $rooms, $scoreboard, $timer);
            $engine = new App\Core\Game\GameEngine(
                new App\Core\Game\GameRegistry([$game]),
                $gameState,
                $fsm,
                $rooms,
                $publisher,
                new App\Core\Room\RoomLock($redis),
            );

            $room = $rooms->getOrFail($code);
            $team = $rooms->team($code, $teamId);

            $start = (float) $startAt;
            while (microtime(true) < $start) {
                // czekamy na wspolny moment startu
            }

            $engine->handle($room, 'answer', ['column' => $column, 'text' => $text], App\Core\Game\Actor::team($team));

            echo 'OK';
            PHP;
    }
}
