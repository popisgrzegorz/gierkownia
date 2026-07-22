<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrackRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Utwor do "Jaka to melodia" (spec 5.2).
 *
 * path jest sciezka wzgledna wewnatrz katalogu mediow - nigdy bezwzgledna.
 * Dzieki temu przeniesienie kolekcji na inny dysk czy na Proxmoxa nie wymaga
 * ruszania bazy, a plik spoza katalogu mediow nie da sie podstawic.
 *
 * Para (talia, sciezka) jest kluczem naturalnym importu: ponowny sync tej samej
 * talii aktualizuje utwor, zamiast tworzyc duplikat (spec 5.2).
 */
#[ORM\Entity(repositoryClass: TrackRepository::class)]
#[ORM\Table(name: 'track')]
#[ORM\UniqueConstraint(name: 'uniq_track_deck_path', columns: ['deck_id', 'path'])]
class Track
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Deck::class, inversedBy: 'tracks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Deck $deck;

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255)]
    private string $artist;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    /**
     * Moment w sekundach, od ktorego zaczac odtwarzanie.
     *
     * Refren rozpoznaje sie duzo szybciej niz wstep, a przy melodii chodzi
     * o to, zeby zagadka byla zagadka przez kilka sekund, a nie przez pol minuty
     * ciszy przed pierwsza nuta.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $startAt = 0;

    /** Kolejnosc w talii - utrzymuje playliste w kolejnosci z pliku YAML. */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(Deck $deck, string $path, string $title, string $artist)
    {
        $this->deck = $deck;
        $this->path = $path;
        $this->title = $title;
        $this->artist = $artist;

        $deck->addTrack($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeck(): Deck
    {
        return $this->deck;
    }

    public function getPath(): string
    {
        return $this->path;
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

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): void
    {
        $this->category = $category;
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

    /** Etykieta dla hosta po odsłonięciu odpowiedzi. */
    public function getLabel(): string
    {
        return sprintf('%s — %s', $this->artist, $this->title);
    }
}
