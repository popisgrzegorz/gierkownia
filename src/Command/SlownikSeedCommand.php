<?php

declare(strict_types=1);

namespace App\Command;

use App\Content\DictionarySeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Wsypuje slownik hasel: kategorie wspolne dla kola fortuny i kalambur.
 *
 * Bezpieczna do powtorzenia - dokłada tylko to, czego jeszcze nie ma,
 * i niczego nie kasuje (patrz DictionarySeeder).
 */
#[AsCommand(
    name: 'app:slownik:seed',
    description: 'Wsypuje słownik haseł do koła fortuny i kalambur.',
)]
final class SlownikSeedCommand extends Command
{
    public function __construct(
        private readonly DictionarySeeder $seeder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rows = array_map(
            static fn (array $row): array => [
                $row['name'],
                $row['games'],
                $row['added'],
                $row['skipped'],
            ],
            $this->seeder->seed(),
        );

        $io->table(['kategoria', 'gry', 'dodano', 'było'], $rows);
        $io->success('Słownik gotowy. Resztą treści zarządzasz w panelu (/panel).');

        return Command::SUCCESS;
    }
}
