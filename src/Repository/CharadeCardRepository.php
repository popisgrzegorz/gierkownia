<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CharadeCard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CharadeCard> */
class CharadeCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharadeCard::class);
    }
}
