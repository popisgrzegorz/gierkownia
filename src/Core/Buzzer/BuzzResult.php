<?php

declare(strict_types=1);

namespace App\Core\Buzzer;

/**
 * Wynik proby buzza. Odmowa nie jest bledem - w grze "kto pierwszy" wiekszosc
 * nacisniec z definicji przegrywa i telefon musi umiec to pokazac.
 */
final readonly class BuzzResult
{
    /** Runda juz nieaktualna: nie jest uzbrojona albo trwa nastepna. */
    public const REASON_STALE = 'stale';

    /** Ktos byl szybszy. */
    public const REASON_LOCKED = 'locked';

    private function __construct(
        public bool $accepted,
        public int $round,
        public ?string $reason,
        public ?string $winnerTeamId,
    ) {
    }

    public static function won(int $round, string $teamId): self
    {
        return new self(true, $round, null, $teamId);
    }

    public static function stale(int $round): self
    {
        return new self(false, $round, self::REASON_STALE, null);
    }

    public static function locked(int $round, ?string $winnerTeamId): self
    {
        return new self(false, $round, self::REASON_LOCKED, $winnerTeamId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'round' => $this->round,
            'reason' => $this->reason,
            'winner' => $this->winnerTeamId,
        ];
    }
}
