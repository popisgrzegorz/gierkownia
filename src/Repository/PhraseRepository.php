<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Phrase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Phrase> */
class PhraseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Phrase::class);
    }

    /** @return list<Phrase> */
    public function findForCategory(Category $category): array
    {
        return $this->findBy(['category' => $category], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function countForCategory(Category $category): int
    {
        return $this->count(['category' => $category]);
    }

    /** Czy haslo juz jest w tej kategorii - bez wzgledu na wielkosc liter. */
    public function existsIn(Category $category, string $phrase): bool
    {
        $found = $this->createQueryBuilder('p')
            ->select('1')
            ->andWhere('p.category = :category')
            ->andWhere('LOWER(p.phrase) = LOWER(:phrase)')
            ->setParameter('category', $category)
            ->setParameter('phrase', $phrase)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return [] !== $found;
    }
}
