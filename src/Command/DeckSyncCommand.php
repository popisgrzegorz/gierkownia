<?php

declare(strict_types=1);

namespace App\Command;

use App\Content\DeckSyncException;
use App\Content\DeckSynchronizer;
use App\Content\MediaLibrary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

/**
 * Import talii tresci z plikow YAML (spec 13.8).
 *
 * Komenda jest idempotentna: mozna ja pusczac ile razy sie chce, takze
 * w trakcie imprezy po dopisaniu utworu.
 */
#[AsCommand(
    name: 'app:deck:sync',
    description: 'Wczytuje talie tresci z plikow YAML do bazy (idempotentnie).',
)]
final class DeckSyncCommand extends Command
{
    public function __construct(
        private readonly DeckSynchronizer $synchronizer,
        private readonly MediaLibrary $media,
        private readonly string $decksDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Pojedynczy plik talii zamiast calego katalogu')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sprawdza pliki, nie zapisuje niczego do bazy');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');
        $file = $input->getOption('file');

        $files = \is_string($file) ? [$file] : $this->findDeckFiles();

        if ([] === $files) {
            $io->warning(sprintf('Nie znalazlem zadnego pliku talii w %s.', $this->decksDir));

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note('Tryb probny - baza nie zostanie zmieniona.');
        }

        $rows = [];
        $missing = [];

        foreach ($files as $path) {
            try {
                $result = $this->synchronizer->syncFile($path, $dryRun);
            } catch (DeckSyncException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            $rows[] = [
                $result->deckSlug,
                $result->deckCreated ? 'nowa' : 'istniala',
                $result->created,
                $result->updated,
                $result->removed,
                \count($result->missingFiles),
            ];

            foreach ($result->missingFiles as $missingFile) {
                $missing[] = $missingFile;
            }
        }

        $io->table(['talia', 'stan', 'dodane', 'zmienione', 'usuniete', 'brak pliku'], $rows);

        if ([] !== $missing) {
            // Talia z brakujacym plikiem zaimportuje sie bez slowa, a zawiedzie
            // dopiero przy odtwarzaniu - czyli na imprezie. Lepiej krzyknac teraz.
            $io->error(sprintf('Brakuje %d plikow w katalogu %s:', \count($missing), $this->media->directory()));
            $io->listing(array_slice($missing, 0, 20));

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Pliki talii sa poprawne.' : 'Talie zsynchronizowane.');

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function findDeckFiles(): array
    {
        if (!is_dir($this->decksDir)) {
            return [];
        }

        $finder = (new Finder())->files()->in($this->decksDir)->name(['*.yaml', '*.yml'])->sortByName();

        return array_values(array_map(
            static fn ($file): string => $file->getPathname(),
            iterator_to_array($finder),
        ));
    }
}
