<?php

declare(strict_types=1);

namespace App\Tests\Core\Realtime;

use App\Core\Realtime\Event;
use App\Core\Realtime\Message;
use App\Core\Realtime\Publisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Podzial na update jawny i prywatny jest jedynym, co dzieli haslo od zgadujacych.
 * Gdyby ktos kiedys "uproscil" toHost()/toTeam() do zwyklego broadcastu, kalambury
 * i kolo fortuny przestalyby dzialac jako gry - a nikt by tego nie zauwazyl,
 * bo ekran wygladalby identycznie.
 */
final class PublisherTest extends TestCase
{
    /** @var list<Update> */
    private array $published = [];

    private Publisher $publisher;

    protected function setUp(): void
    {
        $this->published = [];

        $this->publisher = new Publisher(new MockHub(
            url: 'http://localhost/.well-known/mercure',
            jwtProvider: new StaticTokenProvider('test'),
            publisher: function (Update $update): string {
                $this->published[] = $update;

                return 'test-id';
            },
        ));
    }

    public function testBroadcastGoesToTheWholeRoomAndIsPublic(): void
    {
        $this->publisher->broadcast('ABCD', new Event(Event::ARMED, ['round' => 3]));

        self::assertSame(['room/ABCD'], $this->published[0]->getTopics());
        self::assertFalse($this->published[0]->isPrivate());
        self::assertSame('{"type":"armed","round":3}', $this->published[0]->getData());
    }

    public function testHostOnlyUpdateIsPrivate(): void
    {
        $this->publisher->toHost('ABCD', new Event('solution', ['phrase' => 'ZIMNY LEJEK']));

        self::assertSame(['room/ABCD/host'], $this->published[0]->getTopics());
        self::assertTrue($this->published[0]->isPrivate(), 'Haslo poszloby do wszystkich telefonow w pokoju.');
    }

    public function testTeamOnlyUpdateIsPrivate(): void
    {
        $this->publisher->toTeam('ABCD', 'team-7', new Event('word', ['word' => 'ZYRAFA']));

        self::assertSame(['room/ABCD/team/team-7'], $this->published[0]->getTopics());
        self::assertTrue($this->published[0]->isPrivate(), 'Slowo aktora zobaczyliby zgadujacy.');
    }

    /**
     * Kolejnosc ma znaczenie: modul najpierw oglasza wynik calemu pokojowi,
     * a dopiero potem wysyla sekret jednej druzynie.
     */
    public function testMessagesFromAGameModuleAreSentInOrderAndToTheRightTargets(): void
    {
        $this->publisher->sendAll('ABCD', [
            Message::broadcast(new Event(Event::BUZZ, ['round' => 1])),
            Message::toTeam('team-1', new Event('word', ['word' => 'ZYRAFA'])),
        ]);

        self::assertCount(2, $this->published);

        self::assertStringContainsString('"type":"buzz"', $this->published[0]->getData());
        self::assertSame(['room/ABCD'], $this->published[0]->getTopics());
        self::assertFalse($this->published[0]->isPrivate());

        self::assertSame(['room/ABCD/team/team-1'], $this->published[1]->getTopics());
        self::assertTrue($this->published[1]->isPrivate());
    }

    public function testMessageToATeamNeedsATeamId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Message::toTeam('', new Event('word'));
    }

    public function testPolishCharactersSurviveSerialization(): void
    {
        $this->publisher->broadcast('ABCD', new Event(Event::TEAM_JOINED, ['name' => 'Zaściankowi']));

        self::assertStringContainsString('Zaściankowi', $this->published[0]->getData());
    }
}
