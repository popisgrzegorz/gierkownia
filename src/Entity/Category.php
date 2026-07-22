<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Kategoria tresci - to, co host wybiera na starcie gry.
 *
 * Jeden poziom zamiast dwoch (talia + kategoria): "Piosenki na lato" jest
 * jednoczesnie zestawem do zagrania i etykieta, ktora widza gracze. Przy dwoch
 * poziomach osoba dodajaca tresci musialaby za kazdym razem rozstrzygac, co jest
 * talia, a co tagiem - a w praktyce zawsze chodzi o to samo.
 *
 * W kole fortuny nazwa kategorii sluzy dodatkowo za podpowiedz na planszy.
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'category')]
#[ORM\UniqueConstraint(name: 'uniq_category_slug', columns: ['slug'])]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $slug;

    #[ORM\Column(length: 128)]
    private string $name;

    /** Gra, dla ktorej kategoria jest przeznaczona: melodia, kolo, kalambury. */
    #[ORM\Column(length: 32)]
    private string $gameType;

    /** @var Collection<int, Track> */
    #[ORM\OneToMany(targetEntity: Track::class, mappedBy: 'category', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $tracks;

    /** @var Collection<int, PuzzleWord> */
    #[ORM\OneToMany(targetEntity: PuzzleWord::class, mappedBy: 'category', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $puzzles;

    /** @var Collection<int, CharadeCard> */
    #[ORM\OneToMany(targetEntity: CharadeCard::class, mappedBy: 'category', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $charades;

    public function __construct(string $slug, string $name, string $gameType)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->gameType = $gameType;
        $this->tracks = new ArrayCollection();
        $this->puzzles = new ArrayCollection();
        $this->charades = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
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

    /** @return Collection<int, CharadeCard> */
    public function getCharades(): Collection
    {
        return $this->charades;
    }

    public function addCharade(CharadeCard $card): void
    {
        if (!$this->charades->contains($card)) {
            $this->charades->add($card);
        }
    }

    public function removeCharade(CharadeCard $card): void
    {
        $this->charades->removeElement($card);
    }

    /** Liczba pozycji niezaleznie od gry - do listy kategorii w panelu i u hosta. */
    public function countItems(): int
    {
        return $this->tracks->count() + $this->puzzles->count() + $this->charades->count();
    }

    /**
     * Nazwa kategorii jako jej reprezentacja tekstowa.
     *
     * Potrzebne panelowi tresci: listy wyboru pokazuja tak powiazana kategorie
     * przy hasle czy nagraniu. Bez tego formularz dodawania konczy sie bledem
     * "could not be converted to string".
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
