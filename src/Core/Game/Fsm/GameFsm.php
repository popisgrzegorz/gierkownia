<?php

declare(strict_types=1);

namespace App\Core\Game\Fsm;

use App\Core\Game\GameDefinition;
use App\Core\Game\IllegalActionException;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition as WorkflowTransition;

/**
 * Wykonawca przejsc miedzy stanami gry.
 *
 * Uzywamy StateMachine, a nie Workflow: gra jest zawsze w dokladnie jednym
 * stanie, a StateMachine dodatkowo pozwala, by to samo przejscie wychodzilo
 * z kilku stanow (np. "finish" z guessed i z timeout w kalamburach).
 *
 * Klasa nie jest readonly, bo cache'uje zbudowane maszyny - skladanie definicji
 * przy kazdym buzzie byloby marnotrawstwem na sciezce, ktora ma byc najszybsza
 * w calej aplikacji.
 */
final class GameFsm
{
    /** @var array<string, StateMachine> */
    private array $machines = [];

    public function __construct(
        private readonly RedisMarkingStore $markingStore,
    ) {
    }

    /** Biezacy stan pokoju; przy pierwszym wywolaniu ustawia stan poczatkowy. */
    public function state(GameDefinition $definition, string $roomCode): string
    {
        $places = array_keys(
            $this->machine($definition)->getMarking(new GameSubject($roomCode))->getPlaces(),
        );

        return (string) $places[0];
    }

    public function can(GameDefinition $definition, string $roomCode, string $transition): bool
    {
        return $this->machine($definition)->can(new GameSubject($roomCode), $transition);
    }

    /**
     * Wykonuje przejscie i zwraca nowy stan.
     *
     * Odmowa nie jest bledem serwera: telefon mogl wyslac akcje na podstawie
     * ekranu sprzed sekundy (klasyk - buzz do rundy, ktora wlasnie sie skonczyla).
     */
    public function apply(GameDefinition $definition, string $roomCode, string $transition): string
    {
        if (!$definition->hasTransition($transition)) {
            throw new IllegalActionException(sprintf(
                'Gra "%s" nie zna przejscia "%s".',
                $definition->type,
                $transition,
            ));
        }

        $subject = new GameSubject($roomCode);
        $machine = $this->machine($definition);

        if (!$machine->can($subject, $transition)) {
            throw new IllegalActionException(sprintf(
                'Przejscie "%s" jest niedozwolone w stanie "%s".',
                $transition,
                $this->state($definition, $roomCode),
            ));
        }

        $machine->apply($subject, $transition);

        return $this->state($definition, $roomCode);
    }

    /** @return list<string> nazwy przejsc mozliwych tu i teraz */
    public function available(GameDefinition $definition, string $roomCode): array
    {
        // array_unique, bo przejscie z kilku stanow zrodlowych wystepuje
        // w komponencie kilka razy pod ta sama nazwa (patrz machine()).
        return array_values(array_unique(array_map(
            static fn (WorkflowTransition $t): string => $t->getName(),
            $this->machine($definition)->getEnabledTransitions(new GameSubject($roomCode)),
        )));
    }

    private function machine(GameDefinition $definition): StateMachine
    {
        if (isset($this->machines[$definition->type])) {
            return $this->machines[$definition->type];
        }

        $transitions = [];

        foreach ($definition->transitions as $transition) {
            // Jedno przejscie komponentu na kazdy stan zrodlowy. Podanie kilku
            // naraz znaczy w symfony/workflow "wymaga wszystkich tych miejsc
            // jednoczesnie" (semantyka sieci Petriego), a gra jest zawsze
            // w jednym stanie - taki warunek nie spelnilby sie nigdy.
            // Komponent dopuszcza wiele przejsc o tej samej nazwie i sprawdza
            // je po kolei, wiec efektem jest oczekiwane "z ktoregokolwiek z".
            foreach ($transition->from as $from) {
                $transitions[] = new WorkflowTransition($transition->name, $from, $transition->to);
            }
        }

        return $this->machines[$definition->type] = new StateMachine(
            new Definition($definition->states, $transitions, [$definition->initialState]),
            $this->markingStore,
            name: $definition->type,
        );
    }
}
