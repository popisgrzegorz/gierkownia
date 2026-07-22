<?php

declare(strict_types=1);

namespace App\Core\Game\Fsm;

use App\Core\Game\GameState;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

/**
 * Marking FSM trzymany w Redisie (spec 7).
 *
 * Domyslny MethodMarkingStore zapisywalby stan we wlasciwosci obiektu, czyli
 * w pamieci procesu PHP-FPM - a ten po odpowiedzi znika. Kolejne zadanie
 * (buzz z innego telefonu) trafiloby do innego procesu i zobaczylo grę
 * od nowa, w stanie poczatkowym.
 *
 * Maszyna stanow gry ma zawsze dokladnie jedno miejsce aktywne, wiec zapis
 * i odczyt sprowadzaja sie do jednego stringa.
 */
final readonly class RedisMarkingStore implements MarkingStoreInterface
{
    public function __construct(
        private GameState $state,
    ) {
    }

    public function getMarking(object $subject): Marking
    {
        $state = $this->state->state($this->roomCode($subject));

        // Pusty Marking jest sygnalem dla komponentu, ze rozgrywka jeszcze sie
        // nie zaczela - ustawi wtedy stan poczatkowy z definicji i zapisze go
        // przez setMarking(), wiec nie musimy tego robic sami.
        return null === $state ? new Marking() : new Marking([$state => 1]);
    }

    /** @param array<string, mixed> $context */
    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $places = array_keys($marking->getPlaces());

        if (1 !== \count($places)) {
            throw new \LogicException(sprintf(
                'Gra moze byc w dokladnie jednym stanie, a marking ma ich %d (%s).',
                \count($places),
                implode(', ', $places),
            ));
        }

        $this->state->setState($this->roomCode($subject), (string) $places[0]);
    }

    private function roomCode(object $subject): string
    {
        if (!$subject instanceof GameSubject) {
            throw new \InvalidArgumentException(sprintf(
                'Ten MarkingStore obsluguje wylacznie %s, dostal %s.',
                GameSubject::class,
                $subject::class,
            ));
        }

        return $subject->roomCode;
    }
}
