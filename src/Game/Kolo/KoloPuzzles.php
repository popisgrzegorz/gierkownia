<?php

declare(strict_types=1);

namespace App\Game\Kolo;

use App\Entity\PuzzleWord;
use App\Repository\CategoryRepository;
use App\Repository\PuzzleWordRepository;

/**
 * Dostep do hasel dla kola. Losowanie po stronie serwera i pamiec o tym,
 * co juz bylo - tak samo jak przy melodii.
 */
final readonly class KoloPuzzles
{
    public function __construct(
        private CategoryRepository $categories,
        private PuzzleWordRepository $puzzles,
    ) {
    }

    /** @return list<array{slug: string, name: string, items: int}> */
    public function availableCategories(): array
    {
        return array_map(
            static fn ($category): array => [
                'slug' => $category->getSlug(),
                'name' => $category->getName(),
                'items' => $category->getPuzzles()->count(),
            ],
            $this->categories->findForGame(KoloFortunyGame::type()),
        );
    }

    public function categoryExists(string $slug): bool
    {
        return null !== $this->categories->findOneBySlug($slug);
    }

    public function categoryName(string $slug): ?string
    {
        return $this->categories->findOneBySlug($slug)?->getName();
    }

    public function countPuzzles(string $slug): int
    {
        return $this->categories->findOneBySlug($slug)?->getPuzzles()->count() ?? 0;
    }

    /** @param list<int> $playedIds */
    public function drawNext(string $categorySlug, array $playedIds): ?PuzzleWord
    {
        $category = $this->categories->findOneBySlug($categorySlug);

        if (null === $category) {
            return null;
        }

        $remaining = [];
        foreach ($category->getPuzzles() as $puzzle) {
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
