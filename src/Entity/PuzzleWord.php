<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PuzzleWordRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Haslo do kola fortuny.
 *
 * Nazwa kategorii jest widoczna dla graczy jako podpowiedz ("Przyroda"),
 * a hint doprecyzowuje pojedyncze haslo ("cos bardzo latwego").
 */
#[ORM\Entity(repositoryClass: PuzzleWordRepository::class)]
#[ORM\Table(name: 'puzzle_word')]
#[ORM\UniqueConstraint(name: 'uniq_puzzle_category_phrase', columns: ['category_id', 'phrase'])]
class PuzzleWord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'puzzles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\Column(length: 255)]
    private string $phrase;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hint = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(Category $category, string $phrase)
    {
        $this->category = $category;
        $this->phrase = $phrase;

        $category->addPuzzle($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function getPhrase(): string
    {
        return $this->phrase;
    }

    public function setPhrase(string $phrase): void
    {
        $this->phrase = $phrase;
    }

    public function getHint(): ?string
    {
        return $this->hint;
    }

    public function setHint(?string $hint): void
    {
        $this->hint = $hint;
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
