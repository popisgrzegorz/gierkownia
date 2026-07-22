<?php

declare(strict_types=1);

namespace App\Repository;

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

    /** Ile razy dany plik jest uzywany - kasujac utwor nie wolno usunac pliku wspoldzielonego. */
    public function countByPath(string $path): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.path = :path')
            ->setParameter('path', $path)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
