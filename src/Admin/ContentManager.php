<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Category;
use App\Entity\CharadeCard;
use App\Entity\PuzzleWord;
use App\Entity\Track;
use App\Repository\CategoryRepository;
use App\Repository\TrackRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Zarzadzanie trescia z panelu: kategorie i ich pozycje.
 *
 * Baza jest jedynym zrodlem prawdy - nie ma juz importu z plikow, wiec nic
 * nie kasuje po cichu tego, co zostalo dodane recznie.
 */
final readonly class ContentManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CategoryRepository $categories,
        private TrackRepository $tracks,
        private AudioUploader $uploader,
    ) {
    }

    /**
     * Kategorie pogrupowane po grze - w takiej postaci pokazuje je panel.
     *
     * @return array<string, list<Category>>
     */
    public function byGame(): array
    {
        $grouped = array_fill_keys(ContentTypes::all(), []);

        foreach ($this->categories->findAllOrdered() as $category) {
            $grouped[$category->getGameType()][] = $category;
        }

        return $grouped;
    }

    public function createCategory(string $name, string $gameType): Category
    {
        $name = trim($name);

        if ('' === $name) {
            throw new ContentError('Podaj nazwę kategorii.');
        }

        if (!ContentTypes::exists($gameType)) {
            throw new ContentError('Nie znam takiej gry.');
        }

        $category = new Category($this->uniqueSlug($name), $name, $gameType);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    public function renameCategory(Category $category, string $name): void
    {
        $name = trim($name);

        if ('' === $name) {
            throw new ContentError('Podaj nazwę kategorii.');
        }

        // Slug zostaje bez zmian: siedzi w sciezkach plikow nagran i w stanie
        // trwajacej rozgrywki. Zmiana nazwy nie moze psuc ani jednego, ani drugiego.
        $category->setName($name);
        $this->entityManager->flush();
    }

    /** Kasuje kategorie razem z pozycjami; pliki nagran znikaja z dysku. */
    public function deleteCategory(Category $category): void
    {
        foreach ($category->getTracks() as $track) {
            $this->deleteAudioIfUnused($track);
        }

        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }

    /**
     * Dodaje pozycje odpowiednia dla gry, do ktorej nalezy kategoria.
     *
     * @param array<string, mixed> $data
     */
    public function addItem(Category $category, array $data, ?UploadedFile $file = null): void
    {
        match ($category->getGameType()) {
            'melodia' => $this->addTrack($category, $data, $file),
            'kolo' => $this->addPuzzle($category, $data),
            'kalambury' => $this->addCharade($category, $data),
            default => throw new ContentError('Nie znam takiej gry.'),
        };

        $this->entityManager->flush();
    }

    /** @param array<string, mixed> $data */
    public function updateItem(Track|PuzzleWord|CharadeCard $item, array $data): void
    {
        match (true) {
            $item instanceof Track => $this->fillTrack($item, $data),
            $item instanceof PuzzleWord => $this->fillPuzzle($item, $data),
            default => $this->fillCharade($item, $data),
        };

        $this->entityManager->flush();
    }

    public function deleteItem(Track|PuzzleWord|CharadeCard $item): void
    {
        if ($item instanceof Track) {
            $this->deleteAudioIfUnused($item);
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();
    }

    /** @param array<string, mixed> $data */
    private function addTrack(Category $category, array $data, ?UploadedFile $file): void
    {
        if (null === $file) {
            throw new ContentError('Wybierz plik z nagraniem.');
        }

        $path = $this->uploader->store($file, $category);

        $track = new Track(
            $category,
            $path,
            $this->text($data, 'title') ?? pathinfo($path, \PATHINFO_FILENAME),
            $this->text($data, 'artist') ?? 'Nieznany',
        );
        $track->setStartAt($this->int($data, 'startAt'));
        $track->setPosition($category->getTracks()->count());

        $this->entityManager->persist($track);
    }

    /** @param array<string, mixed> $data */
    private function fillTrack(Track $track, array $data): void
    {
        $track->setTitle($this->text($data, 'title') ?? $track->getTitle());
        $track->setArtist($this->text($data, 'artist') ?? $track->getArtist());
        $track->setStartAt($this->int($data, 'startAt'));
    }

    /** @param array<string, mixed> $data */
    private function addPuzzle(Category $category, array $data): void
    {
        $phrase = $this->text($data, 'phrase') ?? throw new ContentError('Podaj hasło.');
        $phrase = mb_strtoupper($phrase);

        if (!preg_match('/\p{L}/u', $phrase)) {
            throw new ContentError('Hasło musi zawierać choć jedną literę.');
        }

        foreach ($category->getPuzzles() as $existing) {
            if ($existing->getPhrase() === $phrase) {
                throw new ContentError('To hasło już jest w tej kategorii.');
            }
        }

        $puzzle = new PuzzleWord($category, $phrase);
        $puzzle->setHint($this->text($data, 'hint'));
        $puzzle->setPosition($category->getPuzzles()->count());

        $this->entityManager->persist($puzzle);
    }

    /** @param array<string, mixed> $data */
    private function fillPuzzle(PuzzleWord $puzzle, array $data): void
    {
        $phrase = $this->text($data, 'phrase');

        if (null !== $phrase) {
            $puzzle->setPhrase(mb_strtoupper($phrase));
        }

        $puzzle->setHint($this->text($data, 'hint'));
    }

    /** @param array<string, mixed> $data */
    private function addCharade(Category $category, array $data): void
    {
        $word = $this->text($data, 'word') ?? throw new ContentError('Podaj hasło do pokazania.');

        foreach ($category->getCharades() as $existing) {
            if (0 === strcasecmp($existing->getWord(), $word)) {
                throw new ContentError('Ta karta już jest w tej kategorii.');
            }
        }

        $card = new CharadeCard($category, $word);
        $card->setDifficulty($this->text($data, 'difficulty') ?? 'sredni');
        $card->setPosition($category->getCharades()->count());

        $this->entityManager->persist($card);
    }

    /** @param array<string, mixed> $data */
    private function fillCharade(CharadeCard $card, array $data): void
    {
        $card->setWord($this->text($data, 'word') ?? $card->getWord());
        $card->setDifficulty($this->text($data, 'difficulty') ?? $card->getDifficulty());
    }

    /**
     * Kasuje plik nagrania, o ile nie korzysta z niego zaden inny utwor.
     * Ten sam plik moze siedziec w kilku kategoriach - usuniecie go przy
     * pierwszym kasowaniu zabiloby pozostale.
     */
    private function deleteAudioIfUnused(Track $track): void
    {
        if (1 >= $this->tracks->countByPath($track->getPath())) {
            $this->uploader->delete($track->getPath());
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = (new AsciiSlugger())->slug($name)->lower()->toString();
        $base = '' === $base ? 'kategoria' : mb_substr($base, 0, 56);

        $slug = $base;
        $suffix = 1;

        while (!$this->categories->isSlugFree($slug)) {
            $slug = sprintf('%s-%d', $base, ++$suffix);
        }

        return $slug;
    }

    /** @param array<string, mixed> $data */
    private function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /** @param array<string, mixed> $data */
    private function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return \is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
