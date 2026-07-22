<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Baza dla testow dotykajacych Postgresa.
 *
 * Kazdy test biegnie w transakcji, ktora na koniec jest wycofywana - dzieki
 * temu testy nie zostawiaja po sobie danych i nie zaleza od kolejnosci
 * uruchomienia. Baza testowa jest osobna (sufiks _test z konfiguracji Doctrine),
 * wiec talie z prawdziwej bazy sa nietykalne.
 */
abstract class DoctrineTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        try {
            // Najtansze mozliwe zapytanie: DBAL nawiazuje polaczenie leniwie,
            // wiec bez niego dowiedzielibysmy sie o braku bazy dopiero w srodku
            // testu, jako blad zamiast czytelnego pominiecia.
            $this->entityManager->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Baza testowa niedostepna: '.$e->getMessage());
        }

        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }
}
