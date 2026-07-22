<?php

declare(strict_types=1);

namespace App\Game\Kalambury;

use App\Entity\CharadeCard;
use App\Repository\CategoryRepository;
use App\Repository\CharadeCardRepository;

/**
 * Talia kart kalambur: kategorie do wyboru i losowanie kolejnej karty.
 *
 * Losujemy po stronie serwera i pamietamy zagrane id w kontekscie gry -
 * inaczej ta sama karta wracalaby w kolko, bo przy kilkunastu hasłach
 * powtorka trafia sie szybciej, niz podpowiada intuicja.
 */
final readonly class CharadeDeck
{
    public function __construct(
        private CategoryRepository $categories,
        private CharadeCardRepository $cards,
    ) {
    }

    /** @return list<array{slug: string, name: string, count: int}> */
    public function availableCategories(): array
    {
        $out = [];

        foreach ($this->categories->findBy(['gameType' => 'kalambury'], ['name' => 'ASC']) as $category) {
            $count = \count($this->cards->findBy(['category' => $category]));

            if ($count > 0) {
                $out[] = ['slug' => $category->getSlug(), 'name' => $category->getName(), 'count' => $count];
            }
        }

        return $out;
    }

    public function categoryName(string $slug): ?string
    {
        return $this->categories->findOneBy(['slug' => $slug, 'gameType' => 'kalambury'])?->getName();
    }

    public function countCards(string $slug): int
    {
        $category = $this->categories->findOneBy(['slug' => $slug, 'gameType' => 'kalambury']);

        return null === $category ? 0 : \count($this->cards->findBy(['category' => $category]));
    }

    /** @param list<int> $playedIds */
    public function drawNext(string $slug, array $playedIds): ?CharadeCard
    {
        $category = $this->categories->findOneBy(['slug' => $slug, 'gameType' => 'kalambury']);

        if (null === $category) {
            return null;
        }

        $remaining = array_values(array_filter(
            $this->cards->findBy(['category' => $category], ['position' => 'ASC']),
            static fn (CharadeCard $card): bool => !\in_array($card->getId(), $playedIds, true),
        ));

        // Talia wyczerpana - runda konczy sie sama, zamiast zapetlac te same karty.
        return [] === $remaining ? null : $remaining[random_int(0, \count($remaining) - 1)];
    }

    public function find(int $id): ?CharadeCard
    {
        return $this->cards->find($id);
    }
}
