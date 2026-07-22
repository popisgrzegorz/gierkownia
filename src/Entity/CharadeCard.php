<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CharadeCardRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Karta do kalambur (spec 5.2). Modul gry powstanie w kamieniu 6, ale tresci
 * da sie zbierac wczesniej - i tak zajmuje to najwiecej czasu.
 */
#[ORM\Entity(repositoryClass: CharadeCardRepository::class)]
#[ORM\Table(name: 'charade_card')]
#[ORM\UniqueConstraint(name: 'uniq_charade_category_word', columns: ['category_id', 'word'])]
class CharadeCard
{
    /** Poziomy trudnosci - host moze dobrac karty do wieku grajacych. */
    public const DIFFICULTIES = ['latwy', 'sredni', 'trudny'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'charades')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\Column(length: 255)]
    private string $word;

    #[ORM\Column(length: 16, options: ['default' => 'sredni'])]
    private string $difficulty = 'sredni';

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(Category $category, string $word)
    {
        $this->category = $category;
        $this->word = $word;

        $category->addCharade($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function getWord(): string
    {
        return $this->word;
    }

    public function setWord(string $word): void
    {
        $this->word = $word;
    }

    public function getDifficulty(): string
    {
        return $this->difficulty;
    }

    public function setDifficulty(string $difficulty): void
    {
        $this->difficulty = \in_array($difficulty, self::DIFFICULTIES, true) ? $difficulty : 'sredni';
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
