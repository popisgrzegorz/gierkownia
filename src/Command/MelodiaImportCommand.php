<?php

declare(strict_types=1);

namespace App\Command;

use App\Content\TrackListImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Wsypuje do melodii liste nagran z pliku tekstowego (tytul, wykonawca, adres).
 *
 * Panel wgrywa nagrania po jednym przez przegladarke - przy stu pozycjach
 * to droga donikad. Tutaj plik z odnosnikami wjezdza jednym poleceniem.
 */
#[AsCommand(
    name: 'app:melodia:import',
    description: 'Importuje nagrania z pliku tekstowego jako linki zewnętrzne.',
)]
final class MelodiaImportCommand extends Command
{
    public function __construct(
        private readonly TrackListImporter $importer,
    ) {
        parent::__construct();
    }

    /** Opcje konsoli przychodza jako mixed - tu zamieniamy je na to, czym sa. */
    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    protected function configure(): void
    {
        $this
            ->addArgument('plik', InputArgument::REQUIRED, 'Plik z listą: tytuł [TAB] wykonawca [TAB] adres')
            ->addOption('kategoria', 'k', InputOption::VALUE_REQUIRED, 'Nazwa kategorii melodii', 'Ballady rockowe')
            ->addOption('start', 's', InputOption::VALUE_REQUIRED, 'Od której sekundy grać', '0')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Najwyżej tyle nowych utworów')
            ->addOption('podglad', null, InputOption::VALUE_NONE, 'Pokaż, co by weszło, i nie zapisuj');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = $this->text($input->getArgument('plik'));
        $limit = $input->getOption('limit');
        $dryRun = true === $input->getOption('podglad');

        try {
            $category = $this->importer->categoryFor($this->text($input->getOption('kategoria')));

            $result = $this->importer->import(
                $file,
                $category,
                max(0, (int) $this->text($input->getOption('start'))),
                null === $limit ? null : max(1, (int) $this->text($limit)),
                $dryRun,
            );
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['kategoria' => sprintf('%s (%s)', $category->getName(), $category->getSlug())],
            ['dodano' => (string) $result['added']],
            ['pominięto (już były)' => (string) $result['skipped']],
            ['odrzucono (zły wiersz)' => (string) $result['invalid']],
        );

        if ([] !== $result['samples']) {
            $io->listing(array_map(
                static fn (array $row): string => sprintf('%s — %s', $row[1], $row[0]),
                $result['samples'],
            ));
        }

        if ($dryRun) {
            $io->note('Podgląd - nic nie zapisano.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Gotowe. Kategoria ma teraz %d nagrań.', $category->getTracks()->count()));

        return Command::SUCCESS;
    }
}
