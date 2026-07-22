<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\ContentError;
use App\Admin\ContentManager;
use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Tests\DoctrineTestCase;

/**
 * Zarzadzanie trescia z panelu. Baza jest jedynym zrodlem prawdy, wiec bledy
 * tutaj nie maja gdzie sie odtworzyc z pliku - licza sie podwojnie.
 */
final class ContentManagerTest extends DoctrineTestCase
{
    private ContentManager $content;
    private CategoryRepository $categories;

    protected function setUp(): void
    {
        parent::setUp();

        $this->content = self::getContainer()->get(ContentManager::class);
        $this->categories = self::getContainer()->get(CategoryRepository::class);
    }

    public function testCreatingACategoryBuildsASlugFromTheName(): void
    {
        $category = $this->content->createCategory('Piosenki na lato', 'melodia');

        self::assertSame('piosenki-na-lato', $category->getSlug());
        self::assertSame('Piosenki na lato', $category->getName());
        self::assertSame('melodia', $category->getGameType());
    }

    public function testPolishCharactersSurviveInTheSlug(): void
    {
        $category = $this->content->createCategory('Zażółć gęślą jaźń', 'kolo');

        self::assertSame('zazolc-gesla-jazn', $category->getSlug());
    }

    /** Dwie kategorie o tej samej nazwie to normalna sytuacja, kolizja slugow nie. */
    public function testSecondCategoryWithTheSameNameGetsItsOwnSlug(): void
    {
        $first = $this->content->createCategory('Na lato', 'melodia');
        $second = $this->content->createCategory('Na lato', 'kolo');

        self::assertNotSame($first->getSlug(), $second->getSlug());
        self::assertSame('na-lato-2', $second->getSlug());
    }

    public function testEmptyNameIsRefused(): void
    {
        $this->expectException(ContentError::class);
        $this->expectExceptionMessage('Podaj nazwę kategorii.');

        $this->content->createCategory('   ', 'melodia');
    }

    public function testUnknownGameIsRefused(): void
    {
        $this->expectException(ContentError::class);

        $this->content->createCategory('Cokolwiek', 'szachy');
    }

    /**
     * Slug siedzi w sciezkach plikow i w stanie trwajacej rozgrywki. Zmiana
     * nazwy w trakcie imprezy nie moze przestawic ani jednego, ani drugiego.
     */
    public function testRenamingKeepsTheSlug(): void
    {
        $category = $this->content->createCategory('Na lato', 'melodia');

        $this->content->renameCategory($category, 'Letnie przeboje');

        self::assertSame('Letnie przeboje', $category->getName());
        self::assertSame('na-lato', $category->getSlug());
    }

    public function testPuzzlesAreStoredInUppercase(): void
    {
        $category = $this->content->createCategory('Powiedzenia', 'kolo');

        $this->content->addItem($category, ['phrase' => 'Kasa kwiatowa', 'hint' => 'związek frazeologiczny']);

        $puzzle = $category->getPuzzles()->first();
        self::assertNotFalse($puzzle);
        self::assertSame('KASA KWIATOWA', $puzzle->getPhrase());
        self::assertSame('związek frazeologiczny', $puzzle->getHint());
    }

    public function testTheSamePuzzleCannotBeAddedTwice(): void
    {
        $category = $this->content->createCategory('Powiedzenia', 'kolo');
        $this->content->addItem($category, ['phrase' => 'Kasa kwiatowa']);

        $this->expectException(ContentError::class);
        $this->expectExceptionMessage('już jest w tej kategorii');

        $this->content->addItem($category, ['phrase' => 'kasa KWIATOWA']);
    }

    public function testPuzzleWithoutLettersIsRefused(): void
    {
        $category = $this->content->createCategory('Powiedzenia', 'kolo');

        $this->expectException(ContentError::class);
        $this->expectExceptionMessage('choć jedną literę');

        $this->content->addItem($category, ['phrase' => '123 !!!']);
    }

    public function testCharadeCardKeepsItsDifficulty(): void
    {
        $category = $this->content->createCategory('Na rozgrzewkę', 'kalambury');

        $this->content->addItem($category, ['word' => 'parasol', 'difficulty' => 'latwy']);

        $card = $category->getCharades()->first();
        self::assertNotFalse($card);
        self::assertSame('parasol', $card->getWord());
        self::assertSame('latwy', $card->getDifficulty());
    }

    /** Nieznany poziom trudnosci ma wpasc na bezpieczna wartosc, a nie do bazy. */
    public function testUnknownDifficultyFallsBackToMedium(): void
    {
        $category = $this->content->createCategory('Na rozgrzewkę', 'kalambury');

        $this->content->addItem($category, ['word' => 'parasol', 'difficulty' => 'niemozliwy']);

        $card = $category->getCharades()->first();
        self::assertNotFalse($card);
        self::assertSame('sredni', $card->getDifficulty());
    }

    public function testTrackWithoutAFileIsRefused(): void
    {
        $category = $this->content->createCategory('Na lato', 'melodia');

        $this->expectException(ContentError::class);
        $this->expectExceptionMessage('Wybierz plik');

        $this->content->addItem($category, ['title' => 'Cokolwiek']);
    }

    public function testEditingAnItemChangesOnlyWhatWasSent(): void
    {
        $category = $this->content->createCategory('Powiedzenia', 'kolo');
        $this->content->addItem($category, ['phrase' => 'Kasa kwiatowa', 'hint' => 'stara podpowiedź']);
        $puzzle = $category->getPuzzles()->first();
        self::assertNotFalse($puzzle);

        $this->content->updateItem($puzzle, ['phrase' => 'Bułka z masłem', 'hint' => 'coś łatwego']);

        self::assertSame('BUŁKA Z MASŁEM', $puzzle->getPhrase());
        self::assertSame('coś łatwego', $puzzle->getHint());
    }

    public function testDeletingACategoryTakesItsItemsWithIt(): void
    {
        $category = $this->content->createCategory('Powiedzenia', 'kolo');
        $this->content->addItem($category, ['phrase' => 'Kasa kwiatowa']);
        $slug = $category->getSlug();

        $this->content->deleteCategory($category);

        self::assertNull($this->categories->findOneBySlug($slug));
    }

    public function testCategoriesAreGroupedByGame(): void
    {
        $this->content->createCategory('Na lato', 'melodia');
        $this->content->createCategory('Powiedzenia', 'kolo');

        $grouped = $this->content->byGame();

        self::assertArrayHasKey('melodia', $grouped);
        self::assertArrayHasKey('kolo', $grouped);
        // Kalambury nie maja jeszcze modulu gry, ale tresci zbiera sie wczesniej.
        self::assertArrayHasKey('kalambury', $grouped);
        self::assertCount(1, array_filter(
            $grouped['melodia'],
            static fn (Category $c): bool => 'Na lato' === $c->getName(),
        ));
    }
}
