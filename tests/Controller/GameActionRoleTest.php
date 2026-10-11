<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Game\GameEngine;
use App\Core\Room\RoomManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ta sama przegladarka ma ciasteczko hosta i druzyny (host otworzyl w drugiej
 * karcie swoj kontroler). Gdy host wygrywal zawsze, kazdy przycisk druzyny
 * - litera w kole, buzzer - zwracal "dostepna tylko dla druzyny" i grajacy
 * nie wiedzial, czemu nic sie nie dzieje.
 */
final class GameActionRoleTest extends WebTestCase
{
    public function testBrowserWithBothCookiesActsAsTeamWhenRequestComesFromThePlayerPage(): void
    {
        [$client, $code] = $this->browserThatIsHostAndTeam();

        $detail = $this->buzz($client, $code, "http://localhost/play/{$code}/controller");

        self::assertStringNotContainsString('tylko dla druzyny', $detail, 'Zadanie z /play/ ma byc potraktowane jak akcja druzyny.');
    }

    public function testBrowserWithBothCookiesStillActsAsHostOnTheHostScreen(): void
    {
        [$client, $code] = $this->browserThatIsHostAndTeam();

        $detail = $this->buzz($client, $code, "http://localhost/host/{$code}");

        self::assertStringContainsString('tylko dla druzyny', $detail, 'Akcja z ekranu hosta nadal jest akcja hosta.');
    }

    /** @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: string} */
    private function browserThatIsHostAndTeam(): array
    {
        $client = static::createClient();
        $rooms = static::getContainer()->get(RoomManager::class);
        $room = $rooms->create();
        static::getContainer()->get(GameEngine::class)->start($room, 'melodia');

        // Host zapamietywany w sesji przegladarki, druzyna w ciasteczku.
        $client->request('GET', "/host/{$room->code}?t={$room->hostToken}");
        $team = $rooms->join($room->code, 'Kanapowcy');
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie("gierki_team_{$room->code}", $team->deviceToken));

        return [$client, $room->code];
    }

    private function buzz(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $code, string $referer): string
    {
        $client->request(
            'POST',
            "/room/{$code}/game/action",
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_REFERER' => $referer, 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(['action' => 'buzz', 'payload' => []], \JSON_THROW_ON_ERROR),
        );

        return (string) $client->getResponse()->getContent();
    }
}
