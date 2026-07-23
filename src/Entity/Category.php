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
 * Kategoria ma dwie osie, bo to dwa rozne pytania:
 *
 *  - `kind` - CO w niej lezy: nagrania (melodia) albo hasla (slownik).
 *    Tego nie da sie mieszac: gra o odgadywanie tytulu potrzebuje pliku audio,
 *    a nie napisu.
 *  - `games` - KTORE gry moga z niej skorzystac. Jedno haslo "Kot w butach"
 *    nadaje sie i do kola, i do kalambur, ale "Kto rano wstaje, temu Pan Bog
 *    daje" tylko do kola - na migi tego nikt nie pokaze.
 *
 * W kole fortuny nazwa kategorii sluzy dodatkowo za podpowiedz na planszy.
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'category')]
#[ORM\UniqueConstraint(name: 'uniq_category_slug', columns: ['slug'])]
class Category
{
    /** Kategoria z nagraniami - obsluguje ja wylacznie "Jaka to melodia". */
    public const KIND_MELODIA = 'melodia';

    /** Kategoria slownikowa - hasla do kola, kalambur i kolejnych gier na slowa. */
    public const KIND_HASLA = 'hasla';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $slug;

    #[ORM\Column(length: 128)]
    private string $name;

    /** Rodzaj tresci w srodku: melodia (nagrania) albo hasla (slownik). */
    #[ORM\Column(length: 32)]
    private string $kind;

    /**
     * Typy gier, ktore moga siegnac po te kategorie.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $games = [];

    /** @var Collection<int, Track> */
    #[ORM\OneToMany(targetEntity: Track::class, mappedBy: 'category', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $tracks;

    /** @var Collection<int, Phrase> */
    #[ORM\OneToMany(targetEntity: Phrase::class, mappedBy: 'category', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $phrases;

    /** @param list<string> $games */
    public function __construct(string $slug, string $name, string $kind, array $games = [])
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->kind = $kind;
        $this->tracks = new ArrayCollection();
        $this->phrases = new ArrayCollection();

        $this->setGames([] === $games && self::KIND_MELODIA === $kind ? [self::KIND_MELODIA] : $games);
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

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): void
    {
        $this->kind = $kind;
    }

    /** @return list<string> */
    public function getGames(): array
    {
        return $this->games;
    }

    /**
     * @param list<string> $games
     */
    public function setGames(array $games): void
    {
        // Duplikaty biora sie z pola wielokrotnego wyboru w panelu, a lista gier
        // trafia wprost do JSON-a - wolimy ja miec czysta juz w encji.
        $this->games = array_values(array_unique(array_filter($games, static fn (string $g): bool => '' !== $g)));
    }

    public function usableIn(string $gameType): bool
    {
        return \in_array($gameType, $this->games, true);
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

    /** @return Collection<int, Phrase> */
    public function getPhrases(): Collection
    {
        return $this->phrases;
    }

    public function addPhrase(Phrase $phrase): void
    {
        if (!$this->phrases->contains($phrase)) {
            $this->phrases->add($phrase);
        }
    }

    public function removePhrase(Phrase $phrase): void
    {
        $this->phrases->removeElement($phrase);
    }

    /** Liczba pozycji niezaleznie od rodzaju - do listy kategorii w panelu i u hosta. */
    public function countItems(): int
    {
        return $this->tracks->count() + $this->phrases->count();
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
