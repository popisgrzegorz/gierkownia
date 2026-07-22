<?php

declare(strict_types=1);

namespace App\Core\Realtime;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Jedyne wyjscie serwer -> klient (spec 15: brak pollingu).
 *
 * Moduly gier nigdy nie dotykaja HubInterface bezposrednio - dostaja ten serwis,
 * dzieki czemu wybor "publiczny czy prywatny update" zapada w jednym miejscu.
 */
final readonly class Publisher
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    /** Do wszystkich w pokoju: fazy, tablo, wynik buzza, timer. */
    public function broadcast(string $code, Event $event): void
    {
        $this->hub->publish(new Update(
            topics: Topics::room($code),
            data: $event->toJson(),
        ));
    }

    /**
     * Tylko ekran hosta. private: true sprawia, ze hub wysle to wylacznie
     * subskrybentom z JWT obejmujacym ten topic - anonim po LAN nie dostanie nic.
     */
    public function toHost(string $code, Event $event): void
    {
        $this->hub->publish(new Update(
            topics: Topics::host($code),
            data: $event->toJson(),
            private: true,
        ));
    }

    /** Tylko jedna druzyna (np. slowo dla aktora w kalamburach). */
    public function toTeam(string $code, string $teamId, Event $event): void
    {
        $this->hub->publish(new Update(
            topics: Topics::team($code, $teamId),
            data: $event->toJson(),
            private: true,
        ));
    }

    /** Wysyla event tam, gdzie wskazal modul gry. */
    public function send(string $code, Message $message): void
    {
        match ($message->target) {
            MessageTarget::Room => $this->broadcast($code, $message->event),
            MessageTarget::Host => $this->toHost($code, $message->event),
            MessageTarget::Team => $this->toTeam($code, $message->teamId ?? '', $message->event),
        };
    }

    /**
     * Moduly gier zwracaja liste wiadomosci z handleAction() - tu je rozsylamy.
     *
     * Kolejnosc ma znaczenie: modul moze najpierw ogloszic zmiane fazy,
     * a dopiero potem wyslac sekret do jednej druzyny.
     *
     * @param iterable<Message> $messages
     */
    public function sendAll(string $code, iterable $messages): void
    {
        foreach ($messages as $message) {
            $this->send($code, $message);
        }
    }
}
