<?php

declare(strict_types=1);

namespace App\Content;

use App\Entity\Deck;
use App\Entity\PuzzleWord;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import hasel do kola fortuny. Klucz naturalny: samo haslo w obrebie talii.
 */
final readonly class PuzzleSyncer implements DeckItemSyncer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public static function gameType(): string
    {
        return 'kolo';
    }

    public function itemsKey(): string
    {
        return 'puzzles';
    }

    public function sync(Deck $deck, array $items, string $origin, DeckSyncResult $result, CategoryResolver $categories): void
    {
        $seen = [];

        foreach ($items as $index => $item) {
            $where = sprintf('%s, pozycja #%d', $origin, $index + 1);

            // Wielkie litery od razu przy imporcie: plansza i tak pokazuje
            // wersalikami, a porownanie liter przy zgadywaniu ma byc trywialne.
            $phrase = mb_strtoupper(Fields::string($item, 'phrase', $where));

            if (!preg_match('/\p{L}/u', $phrase)) {
                throw new DeckSyncException(sprintf('%s: haslo "%s" nie zawiera zadnej litery.', $where, $phrase));
            }

            $seen[] = $phrase;

            $puzzle = null;
            foreach ($deck->getPuzzles() as $existing) {
                if ($existing->getPhrase() === $phrase) {
                    $puzzle = $existing;
                    break;
                }
            }

            $hint = Fields::optionalString($item, 'hint', $where);
            $category = $categories->resolve($item, $where);

            if (null === $puzzle) {
                $puzzle = new PuzzleWord($deck, $phrase);
                $puzzle->setHint($hint);
                $puzzle->setCategory($category);
                $puzzle->setPosition($index);
                $this->entityManager->persist($puzzle);
                ++$result->created;

                continue;
            }

            $changed = $puzzle->getHint() !== $hint
                || $puzzle->getPosition() !== $index
                || $puzzle->getCategory()?->getSlug() !== $category?->getSlug();

            if ($changed) {
                $puzzle->setHint($hint);
                $puzzle->setPosition($index);
                $puzzle->setCategory($category);
                ++$result->updated;
            }
        }

        foreach ($deck->getPuzzles() as $puzzle) {
            if (!\in_array($puzzle->getPhrase(), $seen, true)) {
                $deck->removePuzzle($puzzle);
                $this->entityManager->remove($puzzle);
                ++$result->removed;
            }
        }
    }
}
