<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** @extends ServiceEntityRepository<Category> */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function findOneBySlug(string $slug): ?Category
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Kategorie, po ktore moze siegnac dana gra - do listy wyboru u hosta.
     *
     * Filtr idzie w PHP, a nie w SQL, bo lista gier siedzi w kolumnie JSON,
     * a pytanie o zawieranie w JSON-ie wyglada inaczej w kazdym silniku bazy.
     * Kategorii sa dziesiatki, nie miliony - a kod zostaje przenosny.
     *
     * @return list<Category>
     */
    public function findForGame(string $gameType): array
    {
        return array_values(array_filter(
            $this->findAllOrdered(),
            static fn (Category $category): bool => $category->usableIn($gameType),
        ));
    }

    /** Kategoria po slugu, ale tylko jesli ta gra ma do niej prawo. */
    public function findOneForGame(string $gameType, string $slug): ?Category
    {
        $category = $this->findOneBySlug($slug);

        return null !== $category && $category->usableIn($gameType) ? $category : null;
    }

    /** @return list<Category> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.kind', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Category> */
    public function findByKind(string $kind): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.kind = :kind')
            ->setParameter('kind', $kind)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Czy slug jest wolny (pomijajac wskazana kategorie przy edycji). */
    public function isSlugFree(string $slug, ?int $exceptId = null): bool
    {
        $existing = $this->findOneBySlug($slug);

        return null === $existing || $existing->getId() === $exceptId;
    }

    /**
     * Slug z nazwy, unikalny w calej tabeli - jedno miejsce zamiast osobnej
     * kopii w kazdym miejscu, ktore tworzy kategorie.
     */
    public function uniqueSlugFor(string $name): string
    {
        $base = (new AsciiSlugger('pl'))->slug($name)->lower()->toString();
        $base = '' === $base ? 'kategoria' : mb_substr($base, 0, 56);

        $slug = $base;
        $suffix = 1;

        while (!$this->isSlugFree($slug)) {
            $slug = sprintf('%s-%d', $base, ++$suffix);
        }

        return $slug;
    }
}
