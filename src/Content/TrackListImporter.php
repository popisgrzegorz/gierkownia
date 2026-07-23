<?php

declare(strict_types=1);

namespace App\Content;

use App\Entity\Category;
use App\Entity\Track;
use App\Repository\CategoryRepository;
use App\Repository\TrackRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Import listy nagran z pliku tekstowego: tytul, wykonawca, adres nagrania.
 *
 * Pola rozdziela tabulator - taki plik wypluwa `tools/midi24-parser.php`,
 * a i arkusz kalkulacyjny zapisuje w tej postaci bez zadnych sztuczek.
 *
 * Nic nie laduje na dysk: w bazie ladu je sam odnosnik, a nagranie pobiera
 * przegladarka hosta w chwili odtwarzania. Dlatego import setek pozycji trwa
 * chwile i nie zajmuje miejsca - ale tez dlatego utwor przestanie grac, gdy
 * zrodlo zniknie albo zablokuje odtwarzanie z obcej strony.
 *
 * Powtorzenie importu jest bezpieczne: adres, ktory juz jest w kategorii,
 * zostaje pominiety.
 */
final readonly class TrackListImporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CategoryRepository $categories,
        private TrackRepository $tracks,
    ) {
    }

    /**
     * @return array{added: int, skipped: int, invalid: int, samples: list<array{string, string}>}
     */
    public function import(
        string $file,
        Category $category,
        int $startAt = 0,
        ?int $limit = null,
        bool $dryRun = false,
    ): array {
        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException(sprintf('Nie moge odczytac pliku "%s".', $file));
        }

        if (Category::KIND_MELODIA !== $category->getKind()) {
            throw new \RuntimeException('Nagrania wchodza tylko do kategorii z nagraniami.');
        }

        $lines = file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        if (false === $lines) {
            throw new \RuntimeException(sprintf('Pusty albo nieczytelny plik "%s".', $file));
        }

        $added = 0;
        $skipped = 0;
        $invalid = 0;
        $samples = [];
        $position = $category->getTracks()->count();
        $seen = [];

        foreach ($lines as $line) {
            if (null !== $limit && $added >= $limit) {
                break;
            }

            $row = $this->row($line);

            if (null === $row) {
                ++$invalid;

                continue;
            }

            [$title, $artist, $url] = $row;

            // Duplikat moze siedziec w bazie albo w samym pliku - drugi warunek
            // liczy sie przy podgladzie, gdzie do bazy nic nie trafia.
            if (isset($seen[$url]) || $this->tracks->hasUrlIn($category, $url)) {
                ++$skipped;

                continue;
            }

            $seen[$url] = true;
            ++$added;

            if (\count($samples) < 5) {
                $samples[] = [$title, $artist];
            }

            if ($dryRun) {
                continue;
            }

            $track = Track::fromUrl($category, $url, $title, $artist);
            $track->setStartAt($startAt);
            $track->setPosition($position++);

            $this->entityManager->persist($track);
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return ['added' => $added, 'skipped' => $skipped, 'invalid' => $invalid, 'samples' => $samples];
    }

    public function categoryFor(string $name): Category
    {
        $slug = (new AsciiSlugger())->slug($name)->lower()->toString();
        $slug = '' === $slug ? 'nagrania' : mb_substr($slug, 0, 56);

        $existing = $this->categories->findOneBySlug($slug);

        if (null !== $existing) {
            if (Category::KIND_MELODIA !== $existing->getKind()) {
                throw new \RuntimeException(sprintf('Kategoria "%s" trzyma hasla, nie nagrania.', $slug));
            }

            return $existing;
        }

        $category = new Category($slug, $name, Category::KIND_MELODIA);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    /**
     * Jedna linia pliku. Null, gdy nie da sie z niej zrobic utworu.
     *
     * @return array{string, string, string}|null
     */
    private function row(string $line): ?array
    {
        // Komentarz i pusta linia to normalna zawartosc recznie poprawianego
        // pliku - nie sa bledem, ale i utworem tez nie.
        $line = trim($line);

        if ('' === $line || str_starts_with($line, '#')) {
            return null;
        }

        $fields = array_map('trim', explode("\t", $line));

        $title = $fields[0];
        $artist = $fields[1] ?? '';
        $url = $fields[2] ?? '';

        if ('' === $title || '' === $url) {
            return null;
        }

        if (!preg_match('#^https?://#i', $url) || false === filter_var($url, \FILTER_VALIDATE_URL)) {
            return null;
        }

        return [$title, '' === $artist ? 'Nieznany' : $artist, $url];
    }
}
