<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\PhraseDeck;
use App\Entity\Category;
use App\Entity\Phrase;
use App\Tests\DoctrineTestCase;

/**
 * Wspolny slownik hasel. Sens calej zmiany jest tutaj: jedno haslo wpisane raz
 * ma dzialac w kazdej grze, ktorej kategoria zostala udostepniona.
 */
final class PhraseDeckTest extends DoctrineTestCase
{
    private PhraseDeck $deck;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deck = self::getContainer()->get(PhraseDeck::class);
    }

    /** Gdyby to padlo, "Kot w butach" znow trzeba by wpisywac dwa razy. */
    public function testTheSameCategoryServesTwoGames(): void
    {
        $this->seed('wspolna', ['kolo', 'kalambury'], ['Kot w butach']);

        self::assertTrue($this->deck->hasCategory('kolo', 'wspolna'));
        self::assertTrue($this->deck->hasCategory('kalambury', 'wspolna'));

        self::assertSame('Kot w butach', $this->deck->drawNext('kolo', 'wspolna', [])?->getPhrase());
        self::assertSame('Kot w butach', $this->deck->drawNext('kalambury', 'wspolna', [])?->getPhrase());
    }

    /**
     * Przyslowia sa nie do pokazania na migi, wiec kategoria zamknieta dla
     * kalambur nie moze im sie pokazac ani na liscie, ani przy losowaniu -
     * inaczej host wybralby zestaw, ktory zabija runde.
     */
    public function testGameSeesOnlyItsOwnCategories(): void
    {
        $this->seed('tylko-kolo', ['kolo'], ['Bez pracy nie ma kołaczy']);

        self::assertFalse($this->deck->hasCategory('kalambury', 'tylko-kolo'));
        self::assertNull($this->deck->drawNext('kalambury', 'tylko-kolo', []));
        self::assertNotContains('tylko-kolo', array_column($this->deck->categoriesFor('kalambury'), 'slug'));
        self::assertContains('tylko-kolo', array_column($this->deck->categoriesFor('kolo'), 'slug'));
    }

    /** Pusta kategoria na liscie u hosta to obietnica bez pokrycia. */
    public function testEmptyCategoryIsNotOffered(): void
    {
        $this->seed('pusta', ['kolo'], []);

        self::assertNotContains('pusta', array_column($this->deck->categoriesFor('kolo'), 'slug'));
    }

    /** Zagrane hasla nie wracaja, a wyczerpana kategoria konczy sie jawnie. */
    public function testPlayedPhrasesDoNotComeBack(): void
    {
        $this->seed('mala', ['kolo'], ['Pierwsze', 'Drugie']);

        $first = $this->deck->drawNext('kolo', 'mala', []);
        self::assertNotNull($first);

        $second = $this->deck->drawNext('kolo', 'mala', [(int) $first->getId()]);
        self::assertNotNull($second);
        self::assertNotSame($first->getId(), $second->getId());

        self::assertNull($this->deck->drawNext('kolo', 'mala', [(int) $first->getId(), (int) $second->getId()]));
    }

    /**
     * @param list<string> $games
     * @param list<string> $items
     */
    private function seed(string $slug, array $games, array $items): void
    {
        $category = new Category($slug, ucfirst($slug), Category::KIND_HASLA, $games);
        $this->entityManager->persist($category);

        foreach ($items as $position => $text) {
            $phrase = new Phrase($category, $text);
            $phrase->setPosition($position);
            $this->entityManager->persist($phrase);
        }

        $this->entityManager->flush();
    }
}
