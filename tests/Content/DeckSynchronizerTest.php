<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\DeckSyncException;
use App\Content\DeckSynchronizer;
use App\Repository\DeckRepository;
use App\Tests\DoctrineTestCase;

/**
 * Import talii. Talie pisze rodzina w edytorze tekstu, wiec licza sie dwie
 * rzeczy: ponowny import nie moze niczego zepsuc, a blad w pliku musi mowic,
 * gdzie dokladnie siedzi.
 */
final class DeckSynchronizerTest extends DoctrineTestCase
{
    private DeckSynchronizer $sync;
    private DeckRepository $decks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sync = self::getContainer()->get(DeckSynchronizer::class);
        $this->decks = self::getContainer()->get(DeckRepository::class);
    }

    public function testImportsADeckWithItsTracks(): void
    {
        $result = $this->sync->sync($this->deckData(), 'test.yaml');

        self::assertTrue($result->deckCreated);
        self::assertSame(2, $result->created);

        $deck = $this->decks->findOneBySlug('testowa');
        self::assertNotNull($deck);
        self::assertSame('Talia testowa', $deck->getName());
        self::assertSame('melodia', $deck->getGameType());
        self::assertCount(2, $deck->getTracks());
    }

    /** Sedno spec 5.2: komende mozna puszczac ile razy sie chce. */
    public function testSecondImportOfTheSameFileChangesNothing(): void
    {
        $this->sync->sync($this->deckData(), 'test.yaml');

        $again = $this->sync->sync($this->deckData(), 'test.yaml');

        self::assertTrue($again->isNoop(), 'Powtorny import nie ma prawa niczego ruszyc.');
        self::assertCount(2, $this->decks->findOneBySlug('testowa')?->getTracks() ?? []);
    }

    public function testEditingATrackUpdatesItInsteadOfAddingACopy(): void
    {
        $this->sync->sync($this->deckData(), 'test.yaml');

        $data = $this->deckData();
        $data['tracks'][0]['title'] = 'Nowy tytul';
        $result = $this->sync->sync($data, 'test.yaml');

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->updated);

        $deck = $this->decks->findOneBySlug('testowa');
        $tracks = array_values($deck?->getTracks()->toArray() ?? []);
        self::assertCount(2, $tracks);
        self::assertSame('Nowy tytul', $tracks[0]->getTitle());
    }

    /**
     * YAML jest zrodlem prawdy. Bez tego talia po kilku poprawkach zawieralaby
     * wszystkie posrednie wersje naraz i nikt nie wiedzialby, co poleci.
     */
    public function testTrackRemovedFromTheFileDisappearsFromTheDeck(): void
    {
        $this->sync->sync($this->deckData(), 'test.yaml');

        $data = $this->deckData();
        array_pop($data['tracks']);
        $result = $this->sync->sync($data, 'test.yaml');

        self::assertSame(1, $result->removed);
        self::assertCount(1, $this->decks->findOneBySlug('testowa')?->getTracks() ?? []);
    }

    public function testTrackOrderFollowsTheFile(): void
    {
        $data = $this->deckData();
        $this->sync->sync($data, 'test.yaml');

        $data['tracks'] = array_reverse($data['tracks']);
        $this->sync->sync($data, 'test.yaml');

        // Czyscimy jednostke pracy: kolejnosc ma wynikac z zapisanych pozycji,
        // a nie z tego, w jakiej kolejnosci obiekty trafily do pamieci. Ekran
        // hosta i tak wczytuje talie od zera, w osobnym zadaniu.
        $this->entityManager->clear();

        $titles = array_map(
            static fn ($track): string => $track->getTitle(),
            $this->decks->findOneBySlug('testowa')?->getTracks()->toArray() ?? [],
        );

        self::assertSame(['Drugi', 'Pierwszy'], array_values($titles));
    }

    /**
     * Dwa utwory w jednej kategorii nie moga stworzyc jej dwa razy - nowa
     * kategoria czeka na flush, wiec zapytanie do bazy jeszcze jej nie widzi.
     */
    public function testTwoTracksShareOneCategory(): void
    {
        $data = $this->deckData();
        $data['tracks'][0]['category'] = 'wspolna';
        $data['tracks'][1]['category'] = 'wspolna';

        $this->sync->sync($data, 'test.yaml');

        $tracks = $this->decks->findOneBySlug('testowa')?->getTracks()->toArray() ?? [];

        self::assertCount(2, $tracks);
        $first = $tracks[0]->getCategory();
        $second = $tracks[1]->getCategory();
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(
            $first->getId(),
            $second->getId(),
            'Powstaly dwie kategorie o tym samym slugu.',
        );
    }

    public function testMissingAudioFileIsReportedButDoesNotStopTheImport(): void
    {
        $data = $this->deckData();
        $data['tracks'][0]['file'] = 'demo/nie-ma-takiego-pliku.wav';

        $result = $this->sync->sync($data, 'test.yaml');

        self::assertSame(['demo/nie-ma-takiego-pliku.wav'], $result->missingFiles);
        self::assertSame(2, $result->created, 'Reszta talii ma sie zaimportowac mimo braku pliku.');
    }

    public function testDryRunLeavesTheDatabaseAlone(): void
    {
        $this->sync->sync($this->deckData(), 'test.yaml', dryRun: true);

        self::assertNull($this->decks->findOneBySlug('testowa'));
    }

    public function testErrorPointsAtTheOffendingTrack(): void
    {
        $data = $this->deckData();
        unset($data['tracks'][1]['artist']);

        $this->expectException(DeckSyncException::class);
        $this->expectExceptionMessage('test.yaml, pozycja #2: brakuje pola "artist"');

        $this->sync->sync($data, 'test.yaml');
    }

    public function testErrorPointsAtAMissingDeckField(): void
    {
        $data = $this->deckData();
        unset($data['name']);

        $this->expectException(DeckSyncException::class);
        $this->expectExceptionMessage('test.yaml: brakuje pola "name"');

        $this->sync->sync($data, 'test.yaml');
    }

    public function testStartAtMustBeANumberOfSeconds(): void
    {
        $data = $this->deckData();
        $data['tracks'][0]['startAt'] = 'refren';

        $this->expectException(DeckSyncException::class);
        $this->expectExceptionMessage('pole "startAt" musi byc liczba sekund');

        $this->sync->sync($data, 'test.yaml');
    }

    /**
     * @return array{deck: string, name: string, game: string, tracks: list<array<string, mixed>>}
     */
    private function deckData(): array
    {
        return [
            'deck' => 'testowa',
            'name' => 'Talia testowa',
            'game' => 'melodia',
            'tracks' => [
                ['file' => 'demo/wlazl-kotek.wav', 'title' => 'Pierwszy', 'artist' => 'Nikt'],
                ['file' => 'demo/panie-janie.wav', 'title' => 'Drugi', 'artist' => 'Nikt'],
            ],
        ];
    }
}
