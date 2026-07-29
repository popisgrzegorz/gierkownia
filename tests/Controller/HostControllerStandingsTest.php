<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Realtime\Topics;
use App\Core\Room\RoomManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ciasteczko Mercure jest jedno na przegladarke (nazwa i sciezka na sztywno
 * w protokole) - gdyby tablica wynikow (/tablo) zawsze zapisywala WEZSZY
 * zestaw topicow niz ekran hosta, otwarcie jej w drugiej karcie nadpisywalo
 * by ciasteczko pierwszej i po najblizszym reconnekcie TV przestawaloby
 * dostawac prywatny topic hosta bez zadnego komunikatu (P2-4 z audytu).
 */
final class HostControllerStandingsTest extends WebTestCase
{
    public function testAnonymousVisitorGetsOnlyThePublicRoomTopic(): void
    {
        $client = static::createClient();
        $room = static::getContainer()->get(RoomManager::class)->create();

        $client->request('GET', "/host/{$room->code}/tablo");

        $topics = $this->subscribedTopics($client);
        self::assertContains(Topics::room($room->code), $topics);
        self::assertNotContains(Topics::host($room->code), $topics);
    }

    public function testTheRoomsOwnHostAlsoGetsTheHostTopicSoTheCookieDoesNotNarrow(): void
    {
        $client = static::createClient();
        $room = static::getContainer()->get(RoomManager::class)->create();

        // Ten sam klient najpierw otwiera ekran glowny - to on zapamietuje
        // token hosta w sesji przegladarki (RoomAccess::rememberHost).
        $client->request('GET', "/host/{$room->code}?t={$room->hostToken}");

        $client->request('GET', "/host/{$room->code}/tablo");

        $topics = $this->subscribedTopics($client);
        self::assertContains(Topics::room($room->code), $topics);
        self::assertContains(Topics::host($room->code), $topics, 'Bez tego druga karta nadpisze ciasteczko wezszym tokenem.');
    }

    /** @return list<string> */
    private function subscribedTopics(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        $cookie = $client->getCookieJar()->get('mercureAuthorization');
        self::assertNotNull($cookie);

        $payload = explode('.', (string) $cookie->getValue())[1] ?? '';
        /** @var array{mercure?: array{subscribe?: list<string>}} $claims */
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true, flags: \JSON_THROW_ON_ERROR);

        return $claims['mercure']['subscribe'] ?? [];
    }
}
