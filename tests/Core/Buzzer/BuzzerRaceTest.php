<?php

declare(strict_types=1);

namespace App\Tests\Core\Buzzer;

use App\Core\Buzzer\BuzzerArbiter;
use App\Core\Buzzer\BuzzResult;
use App\Core\Realtime\Publisher;
use App\Core\Redis\RedisReader;
use App\Tests\RedisTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;

/**
 * Prawdziwy wyscig o buzzer - w osobnych procesach.
 *
 * Wywolania sekwencyjne w jednym procesie niczego tu nie dowodza: sprawdzaja
 * kolejnosc instrukcji, a chroniony jest przypadek, w ktorym dwa zadania
 * naprawde biegna **jednoczesnie** w dwoch procesach PHP-FPM. Dlatego ten test
 * odpala kilka niezaleznych procesow, ustawia im wspolny moment startu
 * i sprawdza, czy Redis rozsadzil ich tak, jak obiecuje spec 9.
 *
 * Test jest wolniejszy od reszty (uruchamia procesy PHP), ale dotyczy jedynego
 * miejsca w aplikacji, gdzie blad wyscigu oznacza klotnie przy stole.
 */
final class BuzzerRaceTest extends RedisTestCase
{
    private const ROOM = 'RACE';
    private const TTL = 60;
    private const CONTENDERS = 8;

    private BuzzerArbiter $arbiter;
    private string $workerPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open jest wylaczone - nie da sie odpalic rownoleglych procesow.');
        }

        $this->arbiter = new BuzzerArbiter(
            $this->redis,
            new RedisReader($this->redis),
            new Publisher(new MockHub(
                url: 'http://localhost/.well-known/mercure',
                jwtProvider: new StaticTokenProvider('test'),
                publisher: static fn (): string => 'test-id',
            )),
            self::TTL,
        );

        $this->workerPath = tempnam(sys_get_temp_dir(), 'buzz-worker-').'.php';
        file_put_contents($this->workerPath, self::workerSource());
    }

    protected function tearDown(): void
    {
        if (isset($this->workerPath) && is_file($this->workerPath)) {
            unlink($this->workerPath);
        }
    }

    public function testExactlyOneOfManySimultaneousBuzzesWins(): void
    {
        $round = $this->arbiter->arm(self::ROOM);

        $results = $this->race($round);

        self::assertCount(self::CONTENDERS, $results, 'Nie wszystkie procesy odpowiedzialy.');

        $winners = array_values(array_filter($results, static fn (array $r): bool => true === $r['accepted']));

        self::assertCount(1, $winners, sprintf(
            'Wygrac moze dokladnie jeden. Wyniki: %s',
            json_encode($results, \JSON_THROW_ON_ERROR),
        ));
    }

    public function testEveryLoserIsToldWhoWasFaster(): void
    {
        $round = $this->arbiter->arm(self::ROOM);

        $results = $this->race($round);
        $winners = array_values(array_filter($results, static fn (array $r): bool => true === $r['accepted']));
        $losers = array_filter($results, static fn (array $r): bool => true !== $r['accepted']);

        self::assertCount(1, $winners);
        self::assertCount(self::CONTENDERS - 1, $losers);

        foreach ($losers as $loser) {
            self::assertSame(BuzzResult::REASON_LOCKED, $loser['reason']);
            self::assertSame($winners[0]['teamId'], $loser['winner']);
        }
    }

    public function testRedisAgreesWithTheProcessThatWasToldItWon(): void
    {
        $round = $this->arbiter->arm(self::ROOM);

        $results = $this->race($round);
        $winners = array_values(array_filter($results, static fn (array $r): bool => true === $r['accepted']));

        self::assertCount(1, $winners);
        self::assertSame($winners[0]['teamId'], $this->arbiter->winner(self::ROOM, $round));
        self::assertFalse($this->arbiter->isArmed(self::ROOM), 'Zwycieski buzz musi zamknac runde.');
    }

    /**
     * Odpala procesy i zbiera ich odpowiedzi.
     *
     * @return list<array{teamId: string, accepted: bool, reason: string|null, winner: string|null}>
     */
    private function race(int $round): array
    {
        $url = $_ENV['REDIS_URL'] ?? $_SERVER['REDIS_URL'] ?? '';
        self::assertIsString($url);

        // Procesy PHP wstaja zauwazalnie dlugo, wiec nie startujemy "od razu":
        // kazdy czeka na ten sam moment na zegarze. Bez tego pierwszy proces
        // zdazylby wygrac, zanim ostatni w ogole wczyta autoloader - i test
        // przechodzilby nawet przy zepsutym lockoucie.
        $startAt = microtime(true) + 1.5;

        $processes = [];
        foreach (range(1, self::CONTENDERS) as $i) {
            $command = [
                \PHP_BINARY,
                $this->workerPath,
                \dirname(__DIR__, 3).'/vendor/autoload.php',
                $url,
                self::ROOM,
                (string) $round,
                'team-'.$i,
                sprintf('%.6F', $startAt),
            ];

            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process, 'Nie udalo sie uruchomic procesu zawodnika.');

            $processes[] = ['handle' => $process, 'pipes' => $pipes];
        }

        $results = [];
        foreach ($processes as $process) {
            $stdout = stream_get_contents($process['pipes'][1]);
            $stderr = stream_get_contents($process['pipes'][2]);

            fclose($process['pipes'][1]);
            fclose($process['pipes'][2]);
            proc_close($process['handle']);

            self::assertIsString($stdout);
            self::assertIsString($stderr);
            self::assertSame('', trim($stderr), 'Proces zawodnika zglosil blad: '.$stderr);

            $decoded = json_decode(trim($stdout), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, 'Proces zawodnika nie zwrocil wyniku: '.$stdout);
            self::assertIsString($decoded['teamId'] ?? null);

            $results[] = [
                'teamId' => $decoded['teamId'],
                'accepted' => true === ($decoded['accepted'] ?? null),
                'reason' => \is_string($decoded['reason'] ?? null) ? $decoded['reason'] : null,
                'winner' => \is_string($decoded['winner'] ?? null) ? $decoded['winner'] : null,
            ];
        }

        return $results;
    }

    /**
     * Zrodlo procesu-zawodnika. Kazdy nawiazuje polaczenie z Redisem **przed**
     * umowionym momentem startu, zeby w wyscigu mierzyl sie sam buzz,
     * a nie czas nawiazywania polaczenia.
     */
    private static function workerSource(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            [, $autoload, $url, $code, $round, $teamId, $startAt] = $argv;

            require $autoload;

            $redis = new Predis\Client($url);
            $redis->ping();

            $arbiter = new App\Core\Buzzer\BuzzerArbiter(
                $redis,
                new App\Core\Redis\RedisReader($redis),
                new App\Core\Realtime\Publisher(new Symfony\Component\Mercure\MockHub(
                    'http://localhost/.well-known/mercure',
                    new Symfony\Component\Mercure\Jwt\StaticTokenProvider('test'),
                    static fn (): string => 'test-id',
                )),
                60,
            );

            $team = new App\Core\Room\Team($teamId, $teamId, '#e5484d', 'token', 1, time());

            $start = (float) $startAt;
            while (microtime(true) < $start) {
                // czekamy na wspolny moment startu
            }

            $result = $arbiter->buzz($code, (int) $round, $team);

            echo json_encode([
                'teamId' => $teamId,
                'accepted' => $result->accepted,
                'reason' => $result->reason,
                'winner' => $result->winnerTeamId,
            ], JSON_THROW_ON_ERROR);
            PHP;
    }
}
