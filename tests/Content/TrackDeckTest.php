<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\TrackDeck;
use App\Entity\Category;
use App\Entity\Track;
use App\Tests\DoctrineTestCase;

/**
 * Wspolna talia nagran dla obu wariantow melodii.
 *
 * Sedno tych testow to mieszanie kategorii: host laczy "Hity" z "Dance", zeby
 * wieczor nie brzmial jednostajnie, i nagrania maja lecec ze wspolnej puli.
 */
final class TrackDeckTest extends DoctrineTestCase
{
    private TrackDeck $deck;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deck = self::getContainer()->get(TrackDeck::class);
    }

    public function testDrawingReachesEveryChosenCategory(): void
    {
        $this->seed('pierwsza', ['Raz']);
        $this->seed('druga', ['Dwa']);

        $seen = [];

        // Sto losowan wystarczy: gdyby talia siegala tylko po pierwsza
        // kategorie, "Dwa" nie wypadloby ani razu.
        for ($i = 0; $i < 100; ++$i) {
            $seen[$this->deck->drawNext(['pierwsza', 'druga'], [])?->getTitle()] = true;
        }

        self::assertArrayHasKey('Raz', $seen);
        self::assertArrayHasKey('Dwa', $seen);
    }

    /**
     * Losujemy ze wspolnej puli, a nie najpierw kategorie, potem utwor - inaczej
     * kategoria z trzema nagraniami wypadalaby tak samo czesto, jak ta z setka.
     */
    public function testABigCategoryWinsMoreOftenThanATinyOne(): void
    {
        $this->seed('duza', array_map(static fn (int $i): string => 'Duzy '.$i, range(1, 30)));
        $this->seed('mala', ['Maly']);

        $small = 0;

        for ($i = 0; $i < 200; ++$i) {
            if ('Maly' === $this->deck->drawNext(['duza', 'mala'], [])?->getTitle()) {
                ++$small;
            }
        }

        // Udzial w puli to 1/31, czyli okolo 6 trafien na 200 losowan.
        self::assertLessThan(40, $small, 'Mała kategoria wypada zbyt często - losujemy chyba najpierw kategorię.');
    }

    /** Zagrane nagrania nie wracaja, niezaleznie od tego, z ktorej kategorii sa. */
    public function testPlayedTracksDoNotComeBack(): void
    {
        $this->seed('pierwsza', ['Raz']);
        $this->seed('druga', ['Dwa']);

        $first = $this->deck->drawNext(['pierwsza', 'druga'], []);
        self::assertNotNull($first);

        $second = $this->deck->drawNext(['pierwsza', 'druga'], [(int) $first->getId()]);
        self::assertNotNull($second);
        self::assertNotSame($first->getTitle(), $second->getTitle());

        self::assertNull($this->deck->drawNext(
            ['pierwsza', 'druga'],
            [(int) $first->getId(), (int) $second->getId()],
        ));
    }

    /** Blednne odpowiedzi do testu 1 z 4 tez biora sie z calego wyboru. */
    public function testWrongAnswersComeFromEveryChosenCategory(): void
    {
        $this->seed('pierwsza', ['Raz']);
        $this->seed('druga', ['Dwa']);

        $labels = $this->deck->otherLabels(['pierwsza', 'druga'], -1);

        self::assertSame(['WYKONAWCA — Raz', 'WYKONAWCA — Dwa'], $labels);
    }

    /** Nieistniejaca kategoria nie moze wejsc do wyboru - i nie moze go wywrocic. */
    public function testUnknownCategoriesAreDroppedAndDuplicatesCollapse(): void
    {
        $this->seed('pierwsza', ['Raz']);

        self::assertSame(['pierwsza'], $this->deck->known(['pierwsza', 'nie-ma-takiej', 'pierwsza']));
        self::assertSame([], $this->deck->known(['nie-ma-takiej']));
    }

    public function testLabelAndCountCoverTheWholeChoice(): void
    {
        $this->seed('pierwsza', ['Raz']);
        $this->seed('druga', ['Dwa', 'Trzy']);

        self::assertSame(3, $this->deck->countTracks(['pierwsza', 'druga']));
        self::assertSame('Kategoria pierwsza + Kategoria druga', $this->deck->name(['pierwsza', 'druga']));
    }

    /** @param list<string> $titles */
    private function seed(string $slug, array $titles): void
    {
        $category = new Category($slug, 'Kategoria '.$slug, Category::KIND_MELODIA);
        $this->entityManager->persist($category);

        foreach ($titles as $i => $title) {
            $track = new Track($category, sprintf('%s/%d.wav', $slug, $i), $title, 'Wykonawca');
            $track->setPosition($i);
            $this->entityManager->persist($track);
        }

        $this->entityManager->flush();
    }
}
