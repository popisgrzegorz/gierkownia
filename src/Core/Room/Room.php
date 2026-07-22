<?php

declare(strict_types=1);

namespace App\Core\Room;

/**
 * Pokoj - jedna sesja przy telewizorze. Odpowiada hashowi room:{code}:meta.
 *
 * Nie trzyma druzyn ani punktow: te siedza w osobnych kluczach Redisa i zmieniaja
 * sie niezaleznie, wiec czytamy je przez RoomManager dopiero gdy sa potrzebne.
 */
final readonly class Room
{
    public function __construct(
        public string $code,
        public string $hostToken,
        public ?string $gameType,
        public string $phase,
        public int $createdAt,
    ) {
    }

    /** @param array<string, string> $meta */
    public static function fromRedisHash(array $meta): self
    {
        return new self(
            code: $meta['code'],
            hostToken: $meta['hostToken'],
            gameType: ('' === ($meta['gameType'] ?? '')) ? null : $meta['gameType'],
            phase: $meta['phase'] ?? 'lobby',
            createdAt: (int) ($meta['createdAt'] ?? 0),
        );
    }

    /** @return array<string, string> */
    public function toRedisHash(): array
    {
        return [
            'code' => $this->code,
            'hostToken' => $this->hostToken,
            'gameType' => $this->gameType ?? '',
            'phase' => $this->phase,
            'createdAt' => (string) $this->createdAt,
        ];
    }
}
