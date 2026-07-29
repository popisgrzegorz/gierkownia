<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Room\RoomManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Dolaczanie do pokoju przez formularz na telefonie.
 *
 * RoomManager::join() cicho oddaje istniejaca druzyne temu, kto wpisze jej
 * nazwe - wygodne dla telefonu, ktoremu padla bateria. Bez dodatkowego
 * warunku to samo wygodne zachowanie pozwalaloby kazdemu przejac cudza
 * druzyne, znajac tylko jej nazwe (widoczna na ekranie hosta). Ten test
 * pilnuje, zeby przejecie wymagalo choc jednego waznego tokenu druzyny
 * z tego pokoju w ciasteczkach klienta - dokladnie ta dziura z audytu.
 */
final class RoomControllerJoinTest extends WebTestCase
{
    public function testAStrangerWithNoTeamCookieCannotClaimATakenName(): void
    {
        $client = static::createClient();
        $room = static::getContainer()->get(RoomManager::class)->create();

        $client->request('POST', "/room/{$room->code}/join", ['name' => 'Kanapowcy', '_csrf_token' => $this->csrfToken($client)]);
        self::assertResponseRedirects("/play/{$room->code}/controller");

        // Ten sam klient, ale bez ciasteczek druzyny - jakby ktos inny na
        // wlasnym telefonie odczytal nazwe z ekranu hosta (jeden kernel
        // na test, wiec to samo polaczenie, nie drugi createClient()).
        $client->getCookieJar()->clear();
        $client->request('POST', "/room/{$room->code}/join", ['name' => 'kanapowcy', '_csrf_token' => $this->csrfToken($client)]);

        self::assertResponseRedirects("/play/{$room->code}");
        self::assertCount(1, static::getContainer()->get(RoomManager::class)->teams($room->code));
    }

    public function testADeviceThatAlreadyHoldsAnyTeamInTheRoomCanReclaimTheName(): void
    {
        $client = static::createClient();
        $room = static::getContainer()->get(RoomManager::class)->create();

        $client->request('POST', "/room/{$room->code}/join", ['name' => 'Kanapowcy', '_csrf_token' => $this->csrfToken($client)]);
        $firstToken = $client->getCookieJar()->get('gierki_team_'.$room->code)?->getValue();

        // Ten sam klient (te same ciasteczka) probuje dolaczyc pod inna,
        // juz zajeta nazwa - ma dowod, ze byl juz w tym pokoju, wiec przechodzi.
        static::getContainer()->get(RoomManager::class)->join($room->code, 'Dywanowi');
        $client->request('POST', "/room/{$room->code}/join", ['name' => 'Dywanowi', '_csrf_token' => $this->csrfToken($client)]);

        self::assertResponseRedirects("/play/{$room->code}/controller");
        $secondToken = $client->getCookieJar()->get('gierki_team_'.$room->code)?->getValue();
        self::assertNotSame($firstToken, $secondToken, 'Po przejeciu "Dywanowi" ciasteczko musi wskazywac na ta druzyne.');
    }

    /**
     * Token CSRF trzeba wyciagnac z realnie wyrenderowanej strony - manager
     * nie dziala poza zadaniem. Strona glowna zawsze renderuje formularz
     * z tym samym tokenem "room" - w przeciwienstwie do /play/{code}, ktora
     * dla telefonu z juz waznym ciasteczkiem druzyny przekierowuje dalej.
     */
    private function csrfToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/');

        return (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
    }
}
