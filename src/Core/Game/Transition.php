<?php

declare(strict_types=1);

namespace App\Core\Game;

/**
 * Jedno przejscie w maszynie stanow gry.
 *
 * Wlasny typ zamiast Symfony\Component\Workflow\Transition, zeby moduly gier
 * opisywaly swoja mechanike bez importowania komponentu - wymiana silnika FSM
 * (spec 7 dopuszcza lekki wariant na enumach) nie ruszy wtedy zadnej gry.
 */
final readonly class Transition
{
    /** @var list<string> */
    public array $from;

    /** @param list<string>|string $from */
    public function __construct(
        public string $name,
        array|string $from,
        public string $to,
    ) {
        $this->from = \is_string($from) ? [$from] : $from;

        if ('' === $name) {
            throw new \InvalidArgumentException('Przejscie musi miec nazwe.');
        }

        if ([] === $this->from) {
            throw new \InvalidArgumentException(sprintf('Przejscie "%s" nie ma stanu poczatkowego.', $name));
        }
    }
}
