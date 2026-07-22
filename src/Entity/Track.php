<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrackRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Utwor do "Jaka to melodia".
 *
 * path jest sciezka wzgledna wewnatrz katalogu mediow - nigdy bezwzgledna.
 * Dzieki temu przeniesienie kolekcji na inny dysk nie wymaga ruszania bazy,
 * a plik spoza katalogu mediow nie da sie podstawic.
 */
#[ORM\Entity(repositoryClass: TrackRepository::class)]
#[ORM\Table(name: 'track')]
#[ORM\UniqueConstraint(name: 'uniq_track_category_path', columns: ['category_id', 'path'])]
class Track
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'tracks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\Column(length: 255)]
    private string $path;

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

    public function __construct(Category $category, string $path, string $title, string $artist)
    {
        $this->category = $category;
        $this->path = $path;
        $this->title = $title;
        $this->artist = $artist;

        $category->addTrack($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
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

    /** Etykieta dla hosta po odsłonięciu odpowiedzi. */
    public function getLabel(): string
    {
        return sprintf('%s — %s', $this->artist, $this->title);
    }
}
