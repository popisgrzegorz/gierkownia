<?php

declare(strict_types=1);

namespace App\Core\Buzzer;

use App\Core\Realtime\Event;
use App\Core\Realtime\Publisher;
use App\Core\Redis\RedisReader;
use App\Core\Room\RoomKeys;
use App\Core\Room\Team;
use Predis\ClientInterface;

/**
 * Lockout "kto pierwszy" (spec 9). Sedno gier z buzzerem.
 *
 * O zwyciezcy rozstrzyga atomowe SET NX w Redisie, a nie kolejnosc instrukcji
 * w PHP. To nie jest ozdobnik: backend chodzi pod PHP-FPM w wielu procesach,
 * wiec dwa nacisniecia w odstepie milisekund trafiaja do dwoch **roznych**
 * procesow. Zaden warunek w pamieci procesu nie moze ich rozsadzic - jedynym
 * miejscem, ktore widzi oba, jest Redis, a SET NX daje tam gwarancje, ze
 * dokladnie jedno zadanie dostanie odpowiedz "wygrales".
 *
 * Uczciwosc: wygrywa ten, ktorego zadanie **dotarlo** do serwera pierwsze.
 * W LAN round-trip to 20-60 ms i w praktyce jest to sprawiedliwe.
 *
 * Publikujemy stad bezposrednio, a nie przez zwrocone wiadomosci modulu:
 * na tej sciezce liczy sie kazda milisekunda, bo dopoki lockout nie dotrze,
 * pozostale telefony wygladaja na wciaz aktywne.
 */
final readonly class BuzzerArbiter
{
    public function __construct(
        private ClientInterface $redis,
        private RedisReader $read,
        private Publisher $publisher,
        private int $roomTtl,
    ) {
    }

    /**
     * Uzbraja nowa runde i odblokowuje buzzery. Zwraca jej numer.
     *
     * Numer rundy rosnie przez INCR i nigdy sie nie powtarza w obrebie pokoju.
     * Dzieki temu buzz spozniony o jedna runde da sie rozpoznac i odrzucic
     * zamiast zaliczyc go do biezacej.
     */
    public function arm(string $code): int
    {
        $roundKey = RoomKeys::gameRound($code);
        $round = (int) $this->redis->incr($roundKey);
        $this->redis->expire($roundKey, $this->roomTtl);

        // Czyscimy zwyciezce tej rundy na wypadek, gdyby licznik zostal
        // wczesniej cofniety (reset gry) i numer sie powtorzyl.
        $this->redis->del([RoomKeys::gameWinner($code, $round)]);
        $this->redis->setex(RoomKeys::gameArmed($code), $this->roomTtl, (string) $round);

        $this->publisher->broadcast($code, new Event(Event::ARMED, ['round' => $round]));

        return $round;
    }

    /**
     * Zamyka runde bez zwyciezcy - host przerywa, nikt nie zgadl.
     * Buzzery gasna, ale numer rundy zostaje, wiec spoznione zadania odpadaja.
     */
    public function disarm(string $code): void
    {
        $this->redis->del([RoomKeys::gameArmed($code)]);
    }

    /**
     * Proba buzza. Dokladnie jedna w rundzie moze zwrocic accepted = true.
     */
    public function buzz(string $code, int $round, Team $team): BuzzResult
    {
        // Wczesny odsiew: tania odpowiedz dla zadan z nieaktualnej rundy.
        // To NIE jest bramka rozstrzygajaca - miedzy tym sprawdzeniem a SET NX
        // ponizej moze wejsc inne zadanie i tez je przejsc. Rozsadza dopiero
        // SET NX, wiec wyscig w tym miejscu jest nieszkodliwy.
        if ($this->armedRound($code) !== $round) {
            // Zwyciezca tej rundy juz jest, tylko uzbrojenie zdazylo zgasnac -
            // przegrany ma prawo wiedziec, kto byl szybszy, i pokazac to od razu
            // zamiast czekac na broadcast. Jeden GET wiecej, ale wylacznie
            // na sciezce przegranej, nigdy na tej, ktora rozstrzyga runde.
            $winner = $this->winner($code, $round);

            return null === $winner ? BuzzResult::stale($round) : BuzzResult::locked($round, $winner);
        }

        $won = $this->redis->set(
            RoomKeys::gameWinner($code, $round),
            $team->id,
            'EX',
            $this->roomTtl,
            'NX',
        );

        if (null === $won) {
            return BuzzResult::locked($round, $this->winner($code, $round));
        }

        // Dopiero teraz gasimy uzbrojenie. Kolejnosc ma znaczenie: gdyby DEL
        // szedl przed SET NX, przegrany moglby zdazyc uzbroic sie ponownie.
        $this->disarm($code);

        $this->publisher->broadcast($code, new Event(Event::BUZZ, [
            'round' => $round,
            'teamId' => $team->id,
            'teamName' => $team->name,
            'color' => $team->color,
            'ts' => (int) (microtime(true) * 1000),
        ]));

        return BuzzResult::won($round, $team->id);
    }

    /** Numer ostatnio uzbrojonej rundy (rosnie takze po jej zamknieciu). */
    public function currentRound(string $code): int
    {
        return $this->read->int(RoomKeys::gameRound($code));
    }

    /** Numer rundy przyjmujacej buzze albo null, gdy buzzery sa zgaszone. */
    public function armedRound(string $code): ?int
    {
        $armed = $this->read->string(RoomKeys::gameArmed($code));

        return null === $armed ? null : (int) $armed;
    }

    public function isArmed(string $code): bool
    {
        return null !== $this->armedRound($code);
    }

    public function winner(string $code, int $round): ?string
    {
        return $this->read->string(RoomKeys::gameWinner($code, $round));
    }
}
