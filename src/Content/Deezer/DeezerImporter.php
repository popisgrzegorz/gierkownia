<?php

declare(strict_types=1);

namespace App\Content\Deezer;

use App\Content\MediaLibrary;
use App\Content\TrackListImporter;
use App\Entity\Category;
use App\Entity\Track;
use App\Repository\TrackRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Buduje kategorie melodii z listy "wykonawca - tytul": kazdy utwor szuka na
 * Deezerze, a jego 30-sekundowy fragment laduje w katalogu mediow jako zwykly
 * plik. Dzieki kopii gra nie zalezy od internetu ani od wygasajacych adresow.
 *
 * Powtorzenie importu jest bezpieczne: utwor, ktory juz jest w kategorii
 * (ten sam identyfikator Deezera), zostaje pominiety.
 */
final readonly class DeezerImporter
{
    public const ADDED = 'added';
    public const EXISTS = 'exists';
    public const NOT_FOUND = 'not_found';
    public const NO_PREVIEW = 'no_preview';
    public const INVALID = 'invalid';
    public const ERROR = 'error';

    public function __construct(
        private DeezerClient $deezer,
        private MediaLibrary $media,
        private TrackListImporter $categories,
        private TrackRepository $tracks,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function categoryFor(string $name): Category
    {
        return $this->categories->categoryFor($name);
    }

    /**
     * @param list<string>              $lines linie pliku
     * @param callable(string, string): void|null $progress wywolywane po kazdej linii: (status, opis)
     * @param int                       $pauseMs przerwa miedzy zapytaniami - Deezer limituje ruch
     *
     * @return array<string, int> liczba linii per status
     */
    public function import(
        array $lines,
        Category $category,
        ?int $limit = null,
        bool $dryRun = false,
        int $pauseMs = 200,
        ?callable $progress = null,
    ): array {
        if (Category::KIND_MELODIA !== $category->getKind()) {
            throw new \RuntimeException('Nagrania wchodza tylko do kategorii z nagraniami.');
        }

        $counts = array_fill_keys(
            [self::ADDED, self::EXISTS, self::NOT_FOUND, self::NO_PREVIEW, self::INVALID, self::ERROR],
            0,
        );
        $position = $category->getTracks()->count();
        $seen = [];

        foreach ($lines as $line) {
            if (null !== $limit && $counts[self::ADDED] >= $limit) {
                break;
            }

            $parsed = self::parseLine($line);

            if (null === $parsed) {
                // Pusta linia i komentarz to zwykla zawartosc recznie pisanego pliku.
                if ('' !== trim($line) && !str_starts_with(trim($line), '#')) {
                    $this->report($counts, $progress, self::INVALID, $line);
                }

                continue;
            }

            [$artist, $title] = $parsed;
            $label = sprintf('%s - %s', $artist, $title);

            try {
                $hit = $this->deezer->find($artist, $title);

                if (null === $hit) {
                    $this->report($counts, $progress, self::NOT_FOUND, $label);
                } elseif ('' === $hit['preview']) {
                    $this->report($counts, $progress, self::NO_PREVIEW, $label);
                } else {
                    $path = sprintf('deezer/%d.mp3', $hit['id']);

                    if (isset($seen[$path]) || $this->tracks->hasPathIn($category, $path)) {
                        $this->report($counts, $progress, self::EXISTS, $label);
                    } else {
                        $seen[$path] = true;

                        if (!$dryRun) {
                            $this->store($path, $this->deezer->download($hit['preview']));

                            $track = new Track($category, $path, $title, $artist);
                            $track->setPosition($position++);
                            $this->entityManager->persist($track);
                            // Flush po kazdym utworze: przy 200 pozycjach przerwane
                            // polaczenie nie moze kosztowac calej dotychczasowej pracy.
                            $this->entityManager->flush();
                        }

                        $this->report($counts, $progress, self::ADDED, sprintf('%s (Deezer: %s - %s)', $label, $hit['artist'], $hit['title']));
                    }
                }
            } catch (\RuntimeException $e) {
                $this->report($counts, $progress, self::ERROR, $label.': '.$e->getMessage());
            }

            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        return $counts;
    }

    /**
     * Jedna linia pliku: "wykonawca - tytul" (myslnik, polpauza albo tabulator).
     * Dzielimy na PIERWSZYM separatorze, bo w tytule moze byc kolejny myslnik.
     *
     * @return array{string, string}|null
     */
    public static function parseLine(string $line): ?array
    {
        $line = trim($line);

        if ('' === $line || str_starts_with($line, '#')) {
            return null;
        }

        $parts = preg_split('/\t|\s+[-–—]\s+/u', $line, 2);

        if (false === $parts || 2 !== \count($parts)) {
            return null;
        }

        $artist = trim($parts[0]);
        $title = trim($parts[1]);

        return '' === $artist || '' === $title ? null : [$artist, $title];
    }

    private function store(string $relativePath, string $bytes): void
    {
        $target = $this->media->absolutePath($relativePath);
        $directory = \dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Nie moge utworzyc katalogu "%s".', $directory));
        }

        // Zapis przez plik tymczasowy: przerwany zapis nie zostawi uciętego mp3,
        // ktore potem wygladaloby w bazie jak pelnoprawne nagranie.
        $temporary = $target.'.part';

        if (false === file_put_contents($temporary, $bytes) || !rename($temporary, $target)) {
            throw new \RuntimeException(sprintf('Nie moge zapisac pliku "%s".', $relativePath));
        }
    }

    /**
     * @param array<string, int>                  $counts
     * @param callable(string, string): void|null $progress
     */
    private function report(array &$counts, ?callable $progress, string $status, string $description): void
    {
        ++$counts[$status];

        if (null !== $progress) {
            $progress($status, $description);
        }
    }
}
