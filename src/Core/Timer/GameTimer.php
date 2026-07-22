<?php

declare(strict_types=1);

namespace App\Core\Timer;

use App\Core\Redis\RedisReader;
use App\Core\Room\RoomKeys;
use Predis\ClientInterface;

/**
 * Odliczanie autorytatywne po stronie serwera (spec 8).
 *
 * Serwer publikuje **termin**, a nie tykanie: klient odlicza sam do podanej
 * chwili. Wysylanie eventu co sekunde obciazyloby hub bez zadnego zysku -
 * przegladarka i tak umie odmierzac czas.
 *
 * Deadline jedzie razem z `serverNow`. Bez tej drugiej wartosci telefon
 * z przestawionym zegarem policzylby zupelnie inny czas do konca, a przy
 * kalamburach ten sam licznik oglada naraz telewizor i wszystkie telefony -
 * rozjazd bylby widoczny golym okiem. Klient liczy offset miedzy swoim
 * zegarem a serwerowym i dopiero od niego odmierza.
 */
final readonly class GameTimer
{
    public function __construct(
        private ClientInterface $redis,
        private RedisReader $read,
        private int $roomTtl,
    ) {
    }

    /**
     * Startuje odliczanie i zwraca dane do rozeslania klientom.
     *
     * @return array{deadline: int, serverNow: int, seconds: int} znaczniki w ms
     */
    public function start(string $code, int $seconds): array
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('Odliczanie musi trwac co najmniej sekunde.');
        }

        $now = $this->now();
        $deadline = $now + $seconds * 1000;

        $this->redis->setex(RoomKeys::gameDeadline($code), $this->roomTtl, (string) $deadline);

        return ['deadline' => $deadline, 'serverNow' => $now, 'seconds' => $seconds];
    }

    /** Termin w ms albo null, gdy nic nie jest odliczane. */
    public function deadline(string $code): ?int
    {
        $value = $this->read->string(RoomKeys::gameDeadline($code));

        return null === $value ? null : (int) $value;
    }

    /** Ile ms zostalo. Zero, gdy czas minal albo nic nie odliczamy. */
    public function remaining(string $code): int
    {
        $deadline = $this->deadline($code);

        return null === $deadline ? 0 : max(0, $deadline - $this->now());
    }

    /**
     * Czy czas minal.
     *
     * Rozstrzyga serwer, a nie klient: telefon moglby zglosic "zdazylem"
     * na podstawie wlasnego zegara, a punkty to nie miejsce na zaufanie.
     */
    public function expired(string $code): bool
    {
        return null !== $this->deadline($code) && 0 === $this->remaining($code);
    }

    public function running(string $code): bool
    {
        return $this->remaining($code) > 0;
    }

    public function clear(string $code): void
    {
        $this->redis->del([RoomKeys::gameDeadline($code)]);
    }

    /** Czas serwera w ms - jedno zrodlo prawdy dla calej klasy. */
    private function now(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
