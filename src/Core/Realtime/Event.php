<?php

declare(strict_types=1);

namespace App\Core\Realtime;

/**
 * Event serwer -> klient. Na drucie: { "type": "...", ...payload } (spec 6).
 *
 * Typy wspoldzielone przez wszystkie gry sa stalymi ponizej; moduly dorzucaja
 * wlasne (np. wheel_result) podajac dowolny string do konstruktora.
 */
final readonly class Event implements \JsonSerializable
{
    public const TEAM_JOINED = 'team_joined';
    public const ARMED = 'armed';
    public const BUZZ = 'buzz';
    public const SCORE = 'score';
    public const TIMER = 'timer';
    public const PHASE = 'phase';
    public const RESET = 'reset';

    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $type,
        public array $payload = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type] + $this->payload;
    }

    public function toJson(): string
    {
        return json_encode($this, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }
}
