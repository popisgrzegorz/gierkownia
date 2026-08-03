<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrackRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Utwor do "Jaka to melodia".
 *
 * Zrodlo dzwieku jest jedno z dwoch:
 *
 *  - `path` - sciezka wzgledna wewnatrz katalogu mediow, nigdy bezwzgledna.
 *    Dzieki temu przeniesienie kolekcji na inny dysk nie wymaga ruszania bazy,
 *    a pliku spoza katalogu mediow nie da sie podstawic.
 *  - `sourceUrl` - adres nagrania na cudzym serwerze. Nic nie ladujemy na dysk;
 *    przegladarka hosta pobiera je sama, a my trzymamy sam odnosnik.
 *
 * Przy odnosniku `path` zostaje pusty - dlatego kolumna jest nullowalna.
 * Unikalny indeks (category_id, path) tego nie blokuje: w Postgresie dwa NULL-e
 * nie sa sobie rowne, wiec kategoria moze miec dowolnie wiele nagran z sieci.
 */
#[ORM\Entity(repositoryClass: TrackRepository::class)]
#[ORM\Table(name: 'track')]
#[ORM\UniqueConstraint(name: 'uniq_track_category_path', columns: ['category_id', 'path'])]
#[ORM\UniqueConstraint(name: 'uniq_track_category_source', columns: ['category_id', 'source_url'])]
class Track
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'tracks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $path = null;

    /** Adres nagrania poza serwerem gry; wyklucza sie ze sciezka na dysku. */
    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255)]
    private string $artist;

    /**
     * Moment w sekundach, od ktorego zaczac odtwarzanie.
     *
     * Refren rozpoznaje sie duzo szybciej niz wstep, a przy melodii chodzi o to,
     * zeby zagadka byla zagadka przez kilka sekund, a nie przez pol minuty ciszy.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $startAt = 0;

    /** Kolejnosc w kategorii - utrzymuje liste w kolejnosci ustawionej w panelu. */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(Category $category, ?string $path, string $title, string $artist)
    {
        $this->category = $category;
        $this->title = $title;
        $this->artist = $artist;

        $this->setPath($path);

        $category->addTrack($this);
    }

    /** Utwor grany prosto z cudzego serwera - bez kopiowania czegokolwiek. */
    public static function fromUrl(Category $category, string $url, string $title, string $artist): self
    {
        $track = new self($category, null, $title, $artist);
        $track->setSourceUrl($url);

        return $track;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    /**
     * Zmiana kategorii z panelu (formularz zawsze wysyla ja jako pole
     * mapowane, nawet gdy zostaje bez zmian) - bez tego Symfony Form nie ma
     * jak zapisac wyboru z powrotem ("Could not determine access type").
     */
    public function setCategory(Category $category): void
    {
        if ($category === $this->category) {
            return;
        }

        $this->category->removeTrack($this);
        $this->category = $category;
        $category->addTrack($this);
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(?string $path): void
    {
        // Pusty string zamiast NULL-a wywrocilby unikalny indeks przy drugim
        // nagraniu z sieci w tej samej kategorii.
        $this->path = null === $path || '' === trim($path) ? null : $path;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): void
    {
        $this->sourceUrl = null === $sourceUrl || '' === trim($sourceUrl) ? null : trim($sourceUrl);
    }

    /** Czy dzwiek lezy poza nami - decyduje o tym, jak go podac przegladarce. */
    public function isExternal(): bool
    {
        return null !== $this->sourceUrl;
    }

    /** Utwor bez zadnego zrodla dzwieku nie da sie zagrac - panel ma to zlapac. */
    public function hasAudio(): bool
    {
        return null !== $this->path || null !== $this->sourceUrl;
    }

    /**
     * Zrodlo dzwieku jest jedno z dwoch, nigdy zero, nigdy oba naraz - inaczej
     * nie wiadomo, co faktycznie zagrac (albo nie ma czego).
     */
    #[Assert\Callback]
    public function validateAudioSource(ExecutionContextInterface $context): void
    {
        if (null !== $this->path && null !== $this->sourceUrl) {
            $context->buildViolation('Wybierz plik z nagraniem albo podaj link.')
                ->atPath('sourceUrl')
                ->addViolation();

            return;
        }

        if (null === $this->path && null === $this->sourceUrl) {
            $context->buildViolation('Wybierz plik z nagraniem albo podaj link.')
                ->atPath('path')
                ->addViolation();
        }
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getArtist(): string
    {
        return $this->artist;
    }

    public function setArtist(string $artist): void
    {
        $this->artist = $artist;
    }

    public function getStartAt(): int
    {
        return $this->startAt;
    }

    public function setStartAt(int $startAt): void
    {
        $this->startAt = max(0, $startAt);
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    /**
     * Podpis nagrania: WYKONAWCA — Tytuł.
     *
     * Wersaliki dokłada etykieta, a nie baza - tak samo jak plansza koła robi to
     * z hasłami (Phrase::forBoard). W panelu wykonawca ma wyglądać tak, jak go
     * wpisano; na liście czterech odpowiedzi ma rzucać się w oczy z kanapy.
     */
    public function getLabel(): string
    {
        return sprintf('%s — %s', mb_strtoupper($this->artist), $this->title);
    }
}
