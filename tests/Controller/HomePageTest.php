<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Game\GameRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Strona glowna wymienia gry z GameRegistry, nie z reki - inaczej lista
 * kłamie o stanie projektu (P2-21 audytu): gotowe gry oznaczone jako
 * "wkrótce", nowsze gry (Dutch, "Jaka to melodia - 1 z 4") nieobecne w ogóle.
 */
final class HomePageTest extends WebTestCase
{
    public function testHomePageListsEveryRegisteredGameByName(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $registry = static::getContainer()->get(GameRegistry::class);
        $listed = $crawler->filter('.home-games li')->each(static fn ($node) => trim($node->text()));

        foreach ($registry->all() as $game) {
            self::assertContains($game->name(), $listed, sprintf('Gra "%s" powinna byc na liscie.', $game->name()));
        }
    }

    /** Zadna gra na liscie nie jest oznaczona jako "wkrótce" - rejestr zawiera tylko dzialajace moduly. */
    public function testHomePageDoesNotMarkAnyRegisteredGameAsComingSoon(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertCount(0, $crawler->filter('.home-games .soon'));
    }
}
