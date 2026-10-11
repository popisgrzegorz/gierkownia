<?php

declare(strict_types=1);

namespace App\Command;

use App\Content\Deezer\DeezerImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Buduje kategorie melodii z listy "wykonawca - tytul": wyszukuje utwory na
 * Deezerze i zapisuje ich 30-sekundowe fragmenty jako pliki lokalne.
 *
 * Roznica wobec app:melodia:import: tamten przyjmuje gotowe adresy i niczego
 * nie pobiera, ten sam znajduje nagrania i trzyma kopie u siebie.
 */
#[AsCommand(
    name: 'app:melodia:wyszukaj',
    description: 'Wyszukuje utwory z listy na Deezerze i pobiera 30-sekundowe fragmenty.',
)]
final class MelodiaWyszukajCommand extends Command
{
    public function __construct(
        private readonly DeezerImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('plik', InputArgument::REQUIRED, 'Plik z listą: wykonawca - tytuł (jedna pozycja w linii)')
            ->addOption('kategoria', 'k', InputOption::VALUE_REQUIRED, 'Nazwa kategorii melodii', 'Z Deezera')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Najwyżej tyle nowych utworów')
            ->addOption('pauza', null, InputOption::VALUE_REQUIRED, 'Przerwa między zapytaniami w ms (limit Deezera)', '200')
            ->addOption('podglad', null, InputOption::VALUE_NONE, 'Tylko wyszukaj, nic nie pobieraj ani nie zapisuj');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = $this->text($input->getArgument('plik'));

        if (!is_file($file) || !is_readable($file)) {
            $io->error(sprintf('Nie mogę odczytać pliku "%s".', $file));

            return Command::FAILURE;
        }

        $lines = file($file, \FILE_IGNORE_NEW_LINES);

        if (false === $lines) {
            $io->error('Nieczytelny plik.');

            return Command::FAILURE;
        }

        $limit = $input->getOption('limit');
        $dryRun = true === $input->getOption('podglad');

        $labels = [
            DeezerImporter::ADDED => $dryRun ? '<info>JEST</info>   ' : '<info>DODANO</info>',
            DeezerImporter::EXISTS => '<comment>BYŁ</comment>   ',
            DeezerImporter::NOT_FOUND => '<error>BRAK</error>  ',
            DeezerImporter::NO_PREVIEW => '<comment>BEZ FRAGMENTU</comment>',
            DeezerImporter::INVALID => '<error>ZŁA LINIA</error>',
            DeezerImporter::ERROR => '<error>BŁĄD</error>  ',
        ];

        try {
            $category = $this->importer->categoryFor($this->text($input->getOption('kategoria')));

            $counts = $this->importer->import(
                $lines,
                $category,
                null === $limit ? null : max(1, (int) $this->text($limit)),
                $dryRun,
                max(0, (int) $this->text($input->getOption('pauza'))),
                static function (string $status, string $description) use ($io, $labels): void {
                    $io->writeln(sprintf('%s %s', $labels[$status], $description));
                },
            );
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->newLine();
        $io->definitionList(
            ['kategoria' => sprintf('%s (%s)', $category->getName(), $category->getSlug())],
            [$dryRun ? 'znaleziono' : 'dodano' => (string) $counts[DeezerImporter::ADDED]],
            ['już były' => (string) $counts[DeezerImporter::EXISTS]],
            ['nie znaleziono w Deezerze' => (string) $counts[DeezerImporter::NOT_FOUND]],
            ['bez fragmentu' => (string) $counts[DeezerImporter::NO_PREVIEW]],
            ['złe linie' => (string) $counts[DeezerImporter::INVALID]],
            ['błędy' => (string) $counts[DeezerImporter::ERROR]],
        );

        if ($dryRun) {
            $io->note('Podgląd — nic nie pobrano ani nie zapisano.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Gotowe. Kategoria ma teraz %d nagrań.', $category->getTracks()->count()));

        return Command::SUCCESS;
    }

    /** Opcje konsoli przychodzą jako mixed - tu zamieniamy je na to, czym są. */
    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
