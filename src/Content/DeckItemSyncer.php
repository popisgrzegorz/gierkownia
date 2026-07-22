<?php

declare(strict_types=1);

namespace App\Content;

use App\Entity\Deck;

/**
 * Import pozycji jednego typu gry (utwory, hasla, karty kalambur).
 *
 * Rdzen importu zna wylacznie talie i kategorie; co siedzi w srodku, wie
 * juz tylko implementacja. Dolozenie gry to nowa klasa z tym interfejsem -
 * zero zmian w DeckSynchronizer, tak samo jak przy modulach gier (spec 7).
 */
interface DeckItemSyncer
{
    /** Typ gry z pola "game" w YAML-u, np. "melodia". */
    public static function gameType(): string;

    /** Nazwa listy pozycji w YAML-u, np. "tracks". */
    public function itemsKey(): string;

    /**
     * Wgrywa pozycje do talii i usuwa te, ktorych w pliku juz nie ma.
     *
     * @param list<array<mixed>> $items
     */
    public function sync(Deck $deck, array $items, string $origin, DeckSyncResult $result, CategoryResolver $categories): void;
}
