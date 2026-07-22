<?php

declare(strict_types=1);

namespace App\Core\Game\Fsm;

/**
 * Podmiot maszyny stanow, ktorego wymaga symfony/workflow.
 *
 * Komponent oczekuje obiektu i przekazuje go do MarkingStore. U nas cala
 * tozsamosc rozgrywki sprowadza sie do kodu pokoju - stan i tak lezy
 * w Redisie, wiec ten obiekt jest tylko kopertą na ten kod.
 */
final readonly class GameSubject
{
    public function __construct(
        public string $roomCode,
    ) {
    }
}
