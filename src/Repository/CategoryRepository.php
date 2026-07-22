<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
     * Kategorie danej gry - do listy wyboru u hosta i w panelu.
     *
     * @return list<Category>
     */
    public function findForGame(string $gameType): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.gameType = :type')
            ->setParameter('type', $gameType)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Category> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.gameType', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Czy slug jest wolny (pomijajac wskazana kategorie przy edycji). */
    public function isSlugFree(string $slug, ?int $exceptId = null): bool
    {
        $existing = $this->findOneBySlug($slug);

        return null === $existing || $existing->getId() === $exceptId;
    }
}
