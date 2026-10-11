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

    /** Ta druzyna juz probowala na tym samym utworze/pytaniu i odpowiedziala zle. */
    public const REASON_EXCLUDED = 'excluded';

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

    /**
     * Wykluczenie nie jest rozstrzygane tu ani w BuzzerArbiter (to bramka
     * "kto pierwszy", nie "kto juz probowal") - modul gry (np. Melodia) sam
     * pilnuje listy wykluczonych druzyn i buduje ten wynik, zanim w ogole
     * zapyta arbitra o SET NX.
     */
    public static function excluded(int $round): self
    {
        return new self(false, $round, self::REASON_EXCLUDED, null);
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
