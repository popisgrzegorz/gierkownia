<?php

declare(strict_types=1);

namespace App\Game\Melodia;

use App\Entity\Track;
use App\Repository\DeckRepository;
use App\Repository\TrackRepository;

/**
 * Wybor utworow z talii dla jednego pokoju.
 *
 * Losowanie jest po stronie serwera i pamieta, co juz bylo - inaczej ten sam
 * kawalek wracalby po dwoch rundach, co przy dwunastu utworach zdarza sie
 * czesciej, niz podpowiada intuicja.
 */
final readonly class MelodiaPlaylist
{
    public function __construct(
        private DeckRepository $decks,
        private TrackRepository $tracks,
    ) {
    }

    /**
     * Talie dostepne dla melodii - do listy wyboru na ekranie hosta.
     *
     * @return list<array{slug: string, name: string, tracks: int}>
     */
    public function availableDecks(): array
    {
        return array_map(
            static fn ($deck): array => [
                'slug' => $deck->getSlug(),
                'name' => $deck->getName(),
                'tracks' => $deck->getTracks()->count(),
            ],
            $this->decks->findForGame(MelodiaGame::type()),
        );
    }

    public function deckExists(string $slug): bool
    {
        return null !== $this->decks->findOneBySlug($slug);
    }

    public function deckName(string $slug): ?string
    {
        return $this->decks->findOneBySlug($slug)?->getName();
    }

    public function countTracks(string $deckSlug): int
    {
        return $this->decks->findOneBySlug($deckSlug)?->getTracks()->count() ?? 0;
    }

    /**
     * Losuje utwor, ktorego jeszcze nie bylo w tej rozgrywce.
     *
     * @param list<int> $playedIds
     */
    public function drawNext(string $deckSlug, array $playedIds): ?Track
    {
        $deck = $this->decks->findOneBySlug($deckSlug);

        if (null === $deck) {
            return null;
        }

        $remaining = [];
        foreach ($deck->getTracks() as $track) {
            if (!\in_array($track->getId(), $playedIds, true)) {
                $remaining[] = $track;
            }
        }

        if ([] === $remaining) {
            return null;
        }

        return $remaining[random_int(0, \count($remaining) - 1)];
    }

    public function find(int $id): ?Track
    {
        return $this->tracks->find($id);
    }
}
