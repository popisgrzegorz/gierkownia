<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PuzzleWordRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Haslo do koła fortuny (spec 5.2).
 *
 * Para (talia, haslo) jest kluczem naturalnym importu - to samo haslo w jednej
 * talii nie ma sensu, a w dwoch roznych jak najbardziej.
 */
#[ORM\Entity(repositoryClass: PuzzleWordRepository::class)]
#[ORM\Table(name: 'puzzle_word')]
#[ORM\UniqueConstraint(name: 'uniq_puzzle_deck_phrase', columns: ['deck_id', 'phrase'])]
class PuzzleWord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Deck::class, inversedBy: 'puzzles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Deck $deck;

    #[ORM\Column(length: 255)]
    private string $phrase;

    /** Podpowiedz pokazywana graczom od poczatku - np. "zwiazek frazeologiczny". */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hint = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(Deck $deck, string $phrase)
    {
        $this->deck = $deck;
        $this->phrase = $phrase;

        $deck->addPuzzle($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeck(): Deck
    {
        return $this->deck;
    }

    public function getPhrase(): string
    {
        return $this->phrase;
    }

    public function getHint(): ?string
    {
        return $this->hint;
    }

    public function setHint(?string $hint): void
    {
        $this->hint = $hint;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): void
    {
        $this->category = $category;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
