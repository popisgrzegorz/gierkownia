<?php

declare(strict_types=1);

namespace App\Content;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Zamienia slug kategorii z YAML-a na encje, tworzac ja w razie potrzeby.
 *
 * Trzyma mape kategorii utworzonych w biezacym przebiegu, jeszcze przed flushem.
 * Bez niej druga pozycja z ta sama kategoria nie znalazlaby jej w bazie (nowy
 * obiekt czeka dopiero na zapis) i utworzylaby duplikat, ktory przy flushu
 * rozbija sie o unikalny indeks na slugu.
 */
final class CategoryResolver
{
    /** @var array<string, Category> */
    private array $pending = [];

    /** @var array<string, string> */
    private array $names = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * Zaczyna nowy przebieg importu z mapa nazw z naglowka pliku.
     *
     * @param array<string, string> $names slug => nazwa do pokazania
     */
    public function reset(array $names): void
    {
        $this->pending = [];
        $this->names = $names;
    }

    /** @param array<mixed> $item */
    public function resolve(array $item, string $where): ?Category
    {
        $slug = Fields::optionalString($item, 'category', $where);

        if (null === $slug) {
            return null;
        }

        $category = $this->pending[$slug] ?? $this->categories->findOneBySlug($slug);

        if (null === $category) {
            $category = new Category($slug, $this->names[$slug] ?? ucfirst(str_replace('-', ' ', $slug)));
            $this->entityManager->persist($category);
        }

        return $this->pending[$slug] = $category;
    }
}
