<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Category;
use App\Entity\Phrase;
use App\Entity\Track;
use App\Repository\CategoryRepository;
use App\Repository\PhraseRepository;
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
        private PhraseRepository $phrases,
        private AudioUploader $uploader,
    ) {
    }

    /**
     * Kategorie pogrupowane po rodzaju tresci - w takiej postaci pokazuje je panel.
     *
     * @return array<string, list<Category>>
     */
    public function byKind(): array
    {
        $grouped = array_fill_keys(ContentTypes::kinds(), []);

        foreach ($this->categories->findAllOrdered() as $category) {
            $grouped[$category->getKind()][] = $category;
        }

        return $grouped;
    }

    /**
     * @param list<string> $games gry, ktore moga korzystac z kategorii
     */
    public function createCategory(string $name, string $kind, array $games = []): Category
    {
        $name = trim($name);

        if ('' === $name) {
            throw new ContentError('Podaj nazwę kategorii.');
        }

        if (!ContentTypes::exists($kind)) {
            throw new ContentError('Nie znam takiego rodzaju treści.');
        }

        $category = new Category($this->uniqueSlug($name), $name, $kind, $this->cleanGames($kind, $games));
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

    /**
     * Ustawia, ktore gry moga siegac po te kategorie.
     *
     * Odebranie gry nie kasuje ani jednego hasla - kategoria po prostu znika
     * z jej listy wyboru. Dzieki temu "Przyslowia" da sie w kazdej chwili
     * wypuscic do kalambur i tak samo latwo cofnac.
     *
     * @param list<string> $games
     */
    public function setGames(Category $category, array $games): void
    {
        $clean = $this->cleanGames($category->getKind(), $games);

        if ([] === $clean) {
            throw new ContentError('Wybierz przynajmniej jedną grę - inaczej nikt tej kategorii nie zobaczy.');
        }

        $category->setGames($clean);
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
     * Dodaje pozycje odpowiednia dla rodzaju kategorii.
     *
     * @param array<string, mixed> $data
     */
    public function addItem(Category $category, array $data, ?UploadedFile $file = null): void
    {
        match ($category->getKind()) {
            Category::KIND_MELODIA => $this->addTrack($category, $data, $file),
            Category::KIND_HASLA => $this->addPhrase($category, $data),
            default => throw new ContentError('Nie znam takiego rodzaju treści.'),
        };

        $this->entityManager->flush();
    }

    /** @param array<string, mixed> $data */
    public function updateItem(Track|Phrase $item, array $data): void
    {
        match (true) {
            $item instanceof Track => $this->fillTrack($item, $data),
            default => $this->fillPhrase($item, $data),
        };

        $this->entityManager->flush();
    }

    public function deleteItem(Track|Phrase $item): void
    {
        if ($item instanceof Track) {
            $this->deleteAudioIfUnused($item);
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();
    }

    /**
     * Dodaje nagranie: albo wgrany plik, albo odnosnik do cudzego serwera.
     *
     * @param array<string, mixed> $data
     */
    private function addTrack(Category $category, array $data, ?UploadedFile $file): void
    {
        $link = $this->text($data, 'sourceUrl');

        if (null === $file && null === $link) {
            throw new ContentError('Wybierz plik z nagraniem albo podaj link.');
        }

        if (null !== $file) {
            $path = $this->uploader->store($file, $category);

            $track = new Track(
                $category,
                $path,
                $this->text($data, 'title') ?? pathinfo($path, \PATHINFO_FILENAME),
                $this->text($data, 'artist') ?? 'Nieznany',
            );
        } else {
            $track = Track::fromUrl(
                $category,
                $this->url($link),
                $this->text($data, 'title') ?? 'Nagranie',
                $this->text($data, 'artist') ?? 'Nieznany',
            );
        }

        $track->setStartAt($this->int($data, 'startAt'));
        $track->setPosition($category->getTracks()->count());

        $this->entityManager->persist($track);
    }

    /**
     * Odnosnik do nagrania. Wpisany bez schematu ("www.cos.pl/x.mp3") trafilby
     * do atrybutu src jako sciezka wzgledna naszej strony i nic by nie zagral.
     */
    private function url(string $value): string
    {
        $value = trim($value);

        if (!preg_match('#^https?://#i', $value) || false === filter_var($value, \FILTER_VALIDATE_URL)) {
            throw new ContentError('Link do nagrania musi zaczynać się od http:// albo https://');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function fillTrack(Track $track, array $data): void
    {
        $track->setTitle($this->text($data, 'title') ?? $track->getTitle());
        $track->setArtist($this->text($data, 'artist') ?? $track->getArtist());
        $track->setStartAt($this->int($data, 'startAt'));

        // Odnosnik podmieniamy tylko utworom, ktore juz nim stoja - wpisanie go
        // przy nagraniu z dysku dalo by utwor z dwoma zrodlami dzwieku.
        $link = $this->text($data, 'sourceUrl');

        if (null !== $link && $track->isExternal()) {
            $track->setSourceUrl($this->url($link));
        }
    }

    /** @param array<string, mixed> $data */
    private function addPhrase(Category $category, array $data): void
    {
        $text = $this->text($data, 'phrase') ?? throw new ContentError('Podaj hasło.');

        if (!preg_match('/\p{L}/u', $text)) {
            throw new ContentError('Hasło musi zawierać choć jedną literę.');
        }

        // Porownanie bez wzgledu na rejestr: haslo trafia i do kola (wersaliki
        // na planszy), i do kalambur (naturalny zapis dla aktora) - "Kot"
        // i "KOT" to jedna i ta sama pozycja slownika.
        if ($this->phrases->existsIn($category, $text)) {
            throw new ContentError('To hasło już jest w tej kategorii.');
        }

        $phrase = new Phrase($category, $text);
        $phrase->setHint($this->text($data, 'hint'));
        $phrase->setDifficulty($this->text($data, 'difficulty') ?? 'sredni');
        $phrase->setPosition($category->getPhrases()->count());

        $this->entityManager->persist($phrase);
    }

    /** @param array<string, mixed> $data */
    private function fillPhrase(Phrase $phrase, array $data): void
    {
        $phrase->setPhrase($this->text($data, 'phrase') ?? $phrase->getPhrase());
        $phrase->setHint($this->text($data, 'hint'));
        $phrase->setDifficulty($this->text($data, 'difficulty') ?? $phrase->getDifficulty());
    }

    /**
     * Odsiewa gry, ktore nie pasuja do rodzaju tresci.
     *
     * Kategoria z nagraniami nie ma czego szukac w kalamburach - a gdyby tam
     * trafila, host wybralby zestaw, z ktorego nie da sie wylosowac hasla.
     *
     * @param list<string> $games
     *
     * @return list<string>
     */
    private function cleanGames(string $kind, array $games): array
    {
        $allowed = ContentTypes::gamesForKind($kind);
        $clean = array_values(array_intersect($games, $allowed));

        // Kategoria z nagraniami ma dokladnie jednego odbiorce - nie ma o czym
        // decydowac, wiec panel o to nie pyta.
        return Category::KIND_MELODIA === $kind ? ['melodia'] : $clean;
    }

    /**
     * Kasuje plik nagrania, o ile nie korzysta z niego zaden inny utwor.
     * Ten sam plik moze siedziec w kilku kategoriach - usuniecie go przy
     * pierwszym kasowaniu zabiloby pozostale.
     */
    private function deleteAudioIfUnused(Track $track): void
    {
        $path = $track->getPath();

        // Nagranie z sieci nie ma naszego pliku - nie ma czego kasowac.
        if (null === $path) {
            return;
        }

        if (1 >= $this->tracks->countByPath($path)) {
            $this->uploader->delete($path);
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
