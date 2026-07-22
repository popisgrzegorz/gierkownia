<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Deck;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Deck> */
class DeckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Deck::class);
    }

    public function findOneBySlug(string $slug): ?Deck
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Talie dostepne dla danej gry - do listy wyboru na ekranie hosta.
     *
     * @return list<Deck>
     */
    public function findForGame(string $gameType): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.gameType = :type')
            ->setParameter('type', $gameType)
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
