<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Deck;
use App\Entity\Track;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Track> */
class TrackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Track::class);
    }

    public function findOneInDeckByPath(Deck $deck, string $path): ?Track
    {
        return $this->findOneBy(['deck' => $deck, 'path' => $path]);
    }
}
