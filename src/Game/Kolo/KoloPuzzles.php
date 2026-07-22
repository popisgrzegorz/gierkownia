<?php

declare(strict_types=1);

namespace App\Game\Kolo;

use App\Entity\PuzzleWord;
use App\Repository\DeckRepository;
use App\Repository\PuzzleWordRepository;

/**
 * Dostep do talii hasel dla kola. Losowanie po stronie serwera i pamiec o tym,
 * co juz bylo - tak samo jak przy melodii.
 */
final readonly class KoloPuzzles
{
    public function __construct(
        private DeckRepository $decks,
        private PuzzleWordRepository $puzzles,
    ) {
    }

    /** @return list<array{slug: string, name: string, items: int}> */
    public function availableDecks(): array
    {
        return array_map(
            static fn ($deck): array => [
                'slug' => $deck->getSlug(),
                'name' => $deck->getName(),
                'items' => $deck->getPuzzles()->count(),
            ],
            $this->decks->findForGame(KoloFortunyGame::type()),
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

    public function countPuzzles(string $slug): int
    {
        return $this->decks->findOneBySlug($slug)?->getPuzzles()->count() ?? 0;
    }

    /** @param list<int> $playedIds */
    public function drawNext(string $deckSlug, array $playedIds): ?PuzzleWord
    {
        $deck = $this->decks->findOneBySlug($deckSlug);

        if (null === $deck) {
            return null;
        }

        $remaining = [];
        foreach ($deck->getPuzzles() as $puzzle) {
            if (!\in_array($puzzle->getId(), $playedIds, true)) {
                $remaining[] = $puzzle;
            }
        }

        if ([] === $remaining) {
            return null;
        }

        return $remaining[random_int(0, \count($remaining) - 1)];
    }

    public function find(int $id): ?PuzzleWord
    {
        return $this->puzzles->find($id);
    }
}
