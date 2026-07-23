<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nagranie moze lezec poza serwerem gry.
 *
 * Do tej pory utwor mial wylacznie sciezke do pliku w katalogu mediow. Teraz
 * alternatywa jest `source_url` - adres nagrania na cudzym serwerze, ktory
 * przegladarka hosta pobiera sama. Wtedy `path` zostaje pusty, dlatego kolumna
 * przestaje byc wymagana.
 *
 * Unikalny indeks (category_id, path) nie przeszkadza wielu nagraniom z sieci:
 * w Postgresie dwa NULL-e nie sa sobie rowne. Drugi indeks pilnuje tego samego
 * po stronie adresow - dzieki niemu powtorzony import nie zdubluje kategorii.
 */
final class Version20260723082359 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Utwor moze miec adres zewnetrzny zamiast pliku na dysku (track.source_url).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE track ADD source_url VARCHAR(1024) DEFAULT NULL');
        $this->addSql('ALTER TABLE track ALTER path DROP NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_track_category_source ON track (category_id, source_url)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_track_category_source');

        // Powrot gubi utwory bez pliku - stary model nie umial ich zapisac,
        // a pusta sciezka wywrocilaby unikalny indeks przy drugim takim utworze.
        $this->addSql('DELETE FROM track WHERE path IS NULL');

        $this->addSql('ALTER TABLE track DROP source_url');
        $this->addSql('ALTER TABLE track ALTER path SET NOT NULL');
    }
}
