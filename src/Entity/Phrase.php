<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PhraseRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Haslo ze slownika - jedna pozycja, z ktorej korzysta kazda gra operujaca
 * na slowach: kolo fortuny, kalambury, a w przyszlosci panstwa-miasta.
 *
 * Wczesniej te same "Kot w butach" trzeba bylo wpisac dwa razy: raz jako
 * PuzzleWord dla kola, raz jako CharadeCard dla kalambur. Rozne byly tylko
 * pola poboczne (podpowiedz kontra trudnosc), a to za malo, zeby trzymac
 * dwie tabele i zmuszac cztowieka do wpisywania tresci dwa razy.
 *
 * O tym, ktora gra moze wziac dane haslo, decyduje kategoria
 * (Category::usableIn) - przyslowia pasuja do kola, ale nie da sie ich pokazac
 * na migi.
 */
#[ORM\Entity(repositoryClass: PhraseRepository::class)]
#[ORM\Table(name: 'phrase')]
#[ORM\UniqueConstraint(name: 'uniq_phrase_category_text', columns: ['category_id', 'phrase'])]
class Phrase
{
    /** Poziomy trudnosci - host moze dobrac hasla do wieku grajacych. */
    public const DIFFICULTIES = ['latwy', 'sredni', 'trudny'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'phrases')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\Column(length: 255)]
    private string $phrase;

    /**
     * Doprecyzowanie pojedynczego hasla ("cos bardzo latwego").
     * W kole fortuny leci na plansze pod haslem; kalambury go nie pokazuja,
     * bo aktor i tak zna slowo, a widownia ma zgadywac z ruchow.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hint = null;

    #[ORM\Column(length: 16, options: ['default' => 'sredni'])]
    private string $difficulty = 'sredni';

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(Category $category, string $phrase)
    {
        $this->category = $category;
        $this->phrase = $phrase;

        $category->addPhrase($this);
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

        $this->category->removePhrase($this);
        $this->category = $category;
        $category->addPhrase($this);
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

    /**
     * Haslo w postaci, w jakiej idzie na plansze z literami.
     *
     * Kolo porownuje litery znak po znaku, wiec plansza musi dostac jeden
     * ustalony rejestr - inaczej "Bulka" i "BULKA" byly by dwoma roznymi
     * haslami przy zgadywaniu.
     */
    public function forBoard(): string
    {
        return mb_strtoupper($this->phrase);
    }
}
