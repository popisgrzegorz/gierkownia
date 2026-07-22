<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Podsumowanie jednego przebiegu importu talii - to, co komenda pokazuje
 * na ekranie i czego test uzywa jako dowodu idempotentnosci.
 */
final class DeckSyncResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $removed = 0;

    /** @var list<string> */
    public array $missingFiles = [];

    public function __construct(
        public readonly string $deckSlug,
        public readonly bool $deckCreated,
    ) {
    }

    public function touched(): int
    {
        return $this->created + $this->updated + $this->removed;
    }

    /** Drugi import bez zmian w YAML-u nie ma prawa niczego ruszyc. */
    public function isNoop(): bool
    {
        return !$this->deckCreated && 0 === $this->touched();
    }
}
