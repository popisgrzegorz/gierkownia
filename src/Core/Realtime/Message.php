<?php

declare(strict_types=1);

namespace App\Core\Realtime;

/**
 * Event wraz z adresatem - to zwracaja moduly gier z handleAction().
 *
 * Sam Event nie wystarczy, bo o tym, kto ma go zobaczyc, decyduje mechanika
 * gry: wynik buzza idzie do wszystkich, ale slowo dla aktora w kalamburach
 * tylko do jednego telefonu. Gdyby modul publikowal bezposrednio, kazdy z nich
 * musialby sam pamietac o fladze "private" - a jedno przeoczenie zdradza haslo
 * calemu pokojowi.
 */
final readonly class Message
{
    private function __construct(
        public MessageTarget $target,
        public Event $event,
        public ?string $teamId,
    ) {
    }

    public static function broadcast(Event $event): self
    {
        return new self(MessageTarget::Room, $event, null);
    }

    public static function toHost(Event $event): self
    {
        return new self(MessageTarget::Host, $event, null);
    }

    public static function toTeam(string $teamId, Event $event): self
    {
        if ('' === $teamId) {
            throw new \InvalidArgumentException('Event kierowany do druzyny musi wskazywac jej id.');
        }

        return new self(MessageTarget::Team, $event, $teamId);
    }
}
