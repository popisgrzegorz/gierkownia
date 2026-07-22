<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DeckRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Talia tresci - zestaw pozycji dla jednej gry (spec 5.2).
 *
 * slug jest kluczem naturalnym: pliki YAML odwoluja sie po nim, wiec ponowny
 * import tej samej talii aktualizuje istniejaca zamiast tworzyc kolejna kopie.
 */
#[ORM\Entity(repositoryClass: DeckRepository::class)]
#[ORM\Table(name: 'deck')]
#[ORM\UniqueConstraint(name: 'uniq_deck_slug', columns: ['slug'])]
class Deck
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $slug;

    #[ORM\Column(length: 128)]
    private string $name;

    /** Typ gry, dla ktorej talia jest przeznaczona - np. "melodia". */
    #[ORM\Column(length: 32)]
    private string $gameType;

    /** @var Collection<int, Track> */
    #[ORM\OneToMany(targetEntity: Track::class, mappedBy: 'deck', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $tracks;

    /** @var Collection<int, PuzzleWord> */
    #[ORM\OneToMany(targetEntity: PuzzleWord::class, mappedBy: 'deck', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $puzzles;

    public function __construct(string $slug, string $name, string $gameType)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->gameType = $gameType;
        $this->tracks = new ArrayCollection();
        $this->puzzles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getGameType(): string
    {
        return $this->gameType;
    }

    public function setGameType(string $gameType): void
    {
        $this->gameType = $gameType;
    }

    /** @return Collection<int, Track> */
    public function getTracks(): Collection
    {
        return $this->tracks;
    }

    public function addTrack(Track $track): void
    {
        if (!$this->tracks->contains($track)) {
            $this->tracks->add($track);
        }
    }

    public function removeTrack(Track $track): void
    {
        $this->tracks->removeElement($track);
    }

    /** @return Collection<int, PuzzleWord> */
    public function getPuzzles(): Collection
    {
        return $this->puzzles;
    }

    public function addPuzzle(PuzzleWord $puzzle): void
    {
        if (!$this->puzzles->contains($puzzle)) {
            $this->puzzles->add($puzzle);
        }
    }

    public function removePuzzle(PuzzleWord $puzzle): void
    {
        $this->puzzles->removeElement($puzzle);
    }

    /** Liczba pozycji niezaleznie od typu gry - do listy talii na ekranie hosta. */
    public function countItems(): int
    {
        return $this->tracks->count() + $this->puzzles->count();
    }
}
