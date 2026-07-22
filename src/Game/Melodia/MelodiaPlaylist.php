<?php

declare(strict_types=1);

namespace App\Game\Melodia;

use App\Entity\Track;
use App\Repository\CategoryRepository;
use App\Repository\TrackRepository;

/**
 * Wybor utworow z kategorii dla jednego pokoju.
 *
 * Losowanie jest po stronie serwera i pamieta, co juz bylo - inaczej ten sam
 * kawalek wracalby po dwoch rundach, co przy kilkunastu utworach zdarza sie
 * czesciej, niz podpowiada intuicja.
 */
final readonly class MelodiaPlaylist
{
    public function __construct(
        private CategoryRepository $categories,
        private TrackRepository $tracks,
    ) {
    }

    /**
     * Kategorie dostepne dla melodii - do listy wyboru na ekranie hosta.
     *
     * @return list<array{slug: string, name: string, items: int}>
     */
    public function availableCategories(): array
    {
        return array_map(
            static fn ($category): array => [
                'slug' => $category->getSlug(),
                'name' => $category->getName(),
                'items' => $category->getTracks()->count(),
            ],
            $this->categories->findForGame(MelodiaGame::type()),
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

    public function countTracks(string $slug): int
    {
        return $this->categories->findOneBySlug($slug)?->getTracks()->count() ?? 0;
    }

    /**
     * Losuje utwor, ktorego jeszcze nie bylo w tej rozgrywce.
     *
     * @param list<int> $playedIds
     */
    public function drawNext(string $categorySlug, array $playedIds): ?Track
    {
        $category = $this->categories->findOneBySlug($categorySlug);

        if (null === $category) {
            return null;
        }

        $remaining = [];
        foreach ($category->getTracks() as $track) {
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
