<?php

declare(strict_types=1);

namespace App\Core\Game;

/**
 * Maszyna stanow jednej gry (spec 14): stany, przejscia i stan poczatkowy.
 *
 * Definicja jest deklaratywna i niezalezna od pokoju - opisuje mechanike gry,
 * a nie jej biezaca rozgrywke. Konkretny stan konkretnego pokoju siedzi
 * w Redisie i czyta go GameState.
 */
final readonly class GameDefinition
{
    /** @var list<string> */
    public array $states;

    /** @var list<Transition> */
    public array $transitions;

    /**
     * @param list<string>     $states
     * @param list<Transition> $transitions
     */
    public function __construct(
        public string $type,
        public string $initialState,
        array $states,
        array $transitions,
    ) {
        $this->states = $states;
        $this->transitions = $transitions;

        if (!\in_array($initialState, $states, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Gra "%s": stan poczatkowy "%s" nie wystepuje na liscie stanow.',
                $type,
                $initialState,
            ));
        }

        // Literowka w nazwie stanu inaczej wyszlaby dopiero w trakcie gry,
        // przy probie przejscia - czyli w najgorszym momencie, na imprezie.
        foreach ($transitions as $transition) {
            foreach ([...$transition->from, $transition->to] as $state) {
                if (!\in_array($state, $states, true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Gra "%s": przejscie "%s" uzywa nieznanego stanu "%s".',
                        $type,
                        $transition->name,
                        $state,
                    ));
                }
            }
        }
    }

    public function hasTransition(string $name): bool
    {
        foreach ($this->transitions as $transition) {
            if ($transition->name === $name) {
                return true;
            }
        }

        return false;
    }
}
