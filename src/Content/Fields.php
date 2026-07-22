<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Odczyt pol z YAML-a talii z komunikatami wskazujacymi miejsce bledu.
 *
 * Talie pisze rodzina w edytorze tekstu, wiec "blad skladni" bez podania pliku
 * i numeru pozycji byloby bezuzyteczne. Kazdy komunikat ma odpowiadac na
 * pytanie "co poprawic i gdzie".
 */
final class Fields
{
    /** @param array<mixed> $data */
    public static function string(array $data, string $key, string $where): string
    {
        $value = $data[$key] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            throw new DeckSyncException(sprintf('%s: brakuje pola "%s" albo jest puste.', $where, $key));
        }

        return trim($value);
    }

    /** @param array<mixed> $data */
    public static function optionalString(array $data, string $key, string $where): ?string
    {
        $value = $data[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new DeckSyncException(sprintf('%s: pole "%s" musi byc tekstem.', $where, $key));
        }

        return '' === trim($value) ? null : trim($value);
    }

    /** @param array<mixed> $data */
    public static function optionalInt(array $data, string $key, string $where, string $unit = 'liczba'): int
    {
        $value = $data[$key] ?? 0;

        if (!\is_int($value)) {
            throw new DeckSyncException(sprintf('%s: pole "%s" musi byc %s.', $where, $key, $unit));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    public static function itemList(array $data, string $key, string $where): array
    {
        $value = $data[$key] ?? null;

        if (!\is_array($value)) {
            throw new DeckSyncException(sprintf('%s: brakuje listy "%s".', $where, $key));
        }

        $items = [];
        foreach (array_values($value) as $index => $item) {
            if (!\is_array($item)) {
                throw new DeckSyncException(sprintf('%s: pozycja #%d nie jest obiektem.', $where, $index + 1));
            }

            $items[] = $item;
        }

        return $items;
    }
}
