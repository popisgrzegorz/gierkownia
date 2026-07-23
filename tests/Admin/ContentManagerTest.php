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
        $category = $this->content->createCategory('Piosenki na lato', Category::KIND_MELODIA);

        self::assertSame('piosenki-na-lato', $category->getSlug());
        self::assertSame('Piosenki na lato', $category->getName());
        self::assertSame(Category::KIND_MELODIA, $category->getKind());
    }

    public function testPolishCharactersSurviveInTheSlug(): void
    {
        $category = $this->content->createCategory('Zażółć gęślą jaźń', Category::KIND_HASLA, ['kolo']);

        self::assertSame('zazolc-gesla-jazn', $category->getSlug());
    }

    /** Dwie kategorie o tej samej nazwie to normalna sytuacja, kolizja slugow nie. */
    public function testSecondCategoryWithTheSameNameGetsItsOwnSlug(): void
    {
        $first = $this->content->createCategory('Na lato', Category::KIND_MELODIA);
        $second = $this->content->createCategory('Na lato', Category::KIND_HASLA, ['kolo']);

        self::assertNotSame($first->getSlug(), $second->getSlug());
        self::assertSame('na-lato-2', $second->getSlug());
    }

    public function testEmptyNameIsRefused(): void
    {
        $this->expectException(ContentError::class);
        $this->expectExceptionMessage('Podaj nazwę kategorii.');

        $this->content->createCategory('   ', Category::KIND_MELODIA);
    }

    public function testUnknownKindIsRefused(): void
    {
        $this->expectException(ContentError::class);

        $this->content->createCategory('Cokolwiek', 'szachy');
    }

    /**
     * Sedno wspolnego slownika: jedna kategoria obsluguje kilka gier.
     * Gdyby to przestalo dzialac, hasla znow trzeba by wpisywac dwa razy.
     */
    public function testOneCategoryCanServeSeveralGames(): void
    {
        $category = $this->content->createCategory('Bajki', Category::KIND_HASLA, ['kolo', 'kalambury']);

        self::assertTrue($category->usableIn('kolo'));
        self::assertTrue($category->usableIn('kalambury'));
        self::assertContains($category->getSlug(), array_map(
            static fn (Category $c): string => $c->getSlug(),
            $this->categories->findForGame('kolo'),
        ));
        self::assertContains($category->getSlug(), array_map(
            static fn (Category $c): string => $c->getSlug(),
            $this->categories->findForGame('kalambury'),
        ));
    }

    /** Gra, ktora nie pasuje do rodzaju tresci, nie ma prawa sie przykleic. */
    public function testRecordingCategoryIgnoresPhraseGames(): void
    {
        $category = $this->content->createCategory('Na lato', Category::KIND_MELODIA, ['kolo', 'kalambury']);

        self::assertSame(['melodia'], $category->getGames());
        self::assertFalse($category->usableIn('kolo'));
    }

    /** Odebranie gry to decyzja o widocznosci, nie o kasowaniu tresci. */
    public function testTakingAGameAwayKeepsThePhrases(): void
    {
        $category = $this->content->createCategory('Bajki', Category::KIND_HASLA, ['kolo', 'kalambury']);
        $this->content->addItem($category, ['phrase' => 'Kot w butach']);

        $this->content->setGames($category, ['kolo']);

        self::assertFalse($category->usableIn('kalambury'));
        self::assertCount(1, $category->getPhrases());
    }

    public function testCategoryWithoutAnyGameIsRefused(): void
    {
        $category = $this->content->createCategory('Bajki', Category::KIND_HASLA, ['kolo']);

        $this->expectException(ContentError::class);
        $this->expectExceptionMessage('przynajmniej jedną grę');

        $this->content->setGames($category, []);
    }

    /**
     * Slug siedzi w sciezkach plikow i w stanie trwajacej rozgrywki. Zmiana
     * nazwy w trakcie imprezy nie moze przestawic ani jednego, ani drugiego.
     */
    public function testRenamingKeepsTheSlug(): void
    {
        $category = $this->content->createCategory('Na lato', Category::KIND_MELODIA);

        $this->content->renameCategory($category, 'Letnie przeboje');

        self::assertSame('Letnie przeboje', $category->getName());
        self::assertSame('na-lato', $category->getSlug());
    }

    /**
     * Haslo zostaje tak, jak je wpisano - aktor kalambur dostaje je na telefon
     * i "KOT W BUTACH" krzyczalby bez powodu. Wersaliki dokleja plansza kola.
     */
    public function testPhraseIsStoredAsTyped(): void
    {
        $category = $this->content->createCategory('Powiedzenia', Category::KIND_HASLA, ['kolo']);

        $this->content->addItem($category, ['phrase' => 'Kasa kwiatowa', 'hint' => 'związek frazeologiczny']);

        $phrase = $category->getPhrases()->first();
        self::assertNotFalse($phrase);
        self::assertSame('Kasa kwiatowa', $phrase->getPhrase());
        self::assertSame('KASA KWIATOWA', $phrase->forBoard());
        self::assertSame('związek frazeologiczny', $phrase->getHint());
    }

    public function testTheSamePhraseCannotBeAddedTwice(): void
    {
        $category = $this->content->createCategory('Powiedzenia', Category::KIND_HASLA, ['kolo']);
        $this->content->addItem($category, ['phrase' => 'Kasa kwiatowa']);

        $this->expectException(ContentError::class);
        $this->expectExceptionMessage('już jest w tej kategorii');

        $this->content->addItem($category, ['phrase' => 'kasa KWIATOWA']);
    }

    public function testPhraseWithoutLettersIsRefused(): void
    {
        $category = $this->content->createCategory('Powiedzenia', Category::KIND_HASLA, ['kolo']);

        $this->expectException(ContentError::class);
        $this->expectExceptionMessage('choć jedną literę');

        $this->content->addItem($category, ['phrase' => '123 !!!']);
    }

    public function testPhraseKeepsItsDifficulty(): void
    {
        $category = $this->content->createCategory('Na rozgrzewkę', Category::KIND_HASLA, ['kalambury']);

        $this->content->addItem($category, ['phrase' => 'parasol', 'difficulty' => 'latwy']);

        $phrase = $category->getPhrases()->first();
        self::assertNotFalse($phrase);
        self::assertSame('parasol', $phrase->getPhrase());
        self::assertSame('latwy', $phrase->getDifficulty());
    }

    /** Nieznany poziom trudnosci ma wpasc na bezpieczna wartosc, a nie do bazy. */
    public function testUnknownDifficultyFallsBackToMedium(): void
    {
        $category = $this->content->createCategory('Na rozgrzewkę', Category::KIND_HASLA, ['kalambury']);

        $this->content->addItem($category, ['phrase' => 'parasol', 'difficulty' => 'niemozliwy']);

        $phrase = $category->getPhrases()->first();
        self::assertNotFalse($phrase);
        self::assertSame('sredni', $phrase->getDifficulty());
    }

    public function testTrackWithoutAFileIsRefused(): void
    {
        $category = $this->content->createCategory('Na lato', Category::KIND_MELODIA);

        $this->expectException(ContentError::class);
        $this->expectExceptionMessage('Wybierz plik');

        $this->content->addItem($category, ['title' => 'Cokolwiek']);
    }

    public function testEditingAnItemChangesOnlyWhatWasSent(): void
    {
        $category = $this->content->createCategory('Powiedzenia', Category::KIND_HASLA, ['kolo']);
        $this->content->addItem($category, ['phrase' => 'Kasa kwiatowa', 'hint' => 'stara podpowiedź']);
        $phrase = $category->getPhrases()->first();
        self::assertNotFalse($phrase);

        $this->content->updateItem($phrase, ['phrase' => 'Bułka z masłem', 'hint' => 'coś łatwego']);

        self::assertSame('Bułka z masłem', $phrase->getPhrase());
        self::assertSame('coś łatwego', $phrase->getHint());
    }

    public function testDeletingACategoryTakesItsItemsWithIt(): void
    {
        $category = $this->content->createCategory('Powiedzenia', Category::KIND_HASLA, ['kolo']);
        $this->content->addItem($category, ['phrase' => 'Kasa kwiatowa']);
        $slug = $category->getSlug();

        $this->content->deleteCategory($category);

        self::assertNull($this->categories->findOneBySlug($slug));
    }

    public function testCategoriesAreGroupedByKind(): void
    {
        $this->content->createCategory('Na lato', Category::KIND_MELODIA);
        $this->content->createCategory('Powiedzenia', Category::KIND_HASLA, ['kolo']);

        $grouped = $this->content->byKind();

        self::assertArrayHasKey(Category::KIND_MELODIA, $grouped);
        self::assertArrayHasKey(Category::KIND_HASLA, $grouped);
        self::assertCount(1, array_filter(
            $grouped[Category::KIND_MELODIA],
            static fn (Category $c): bool => 'Na lato' === $c->getName(),
        ));
    }
}
