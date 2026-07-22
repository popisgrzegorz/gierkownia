<?php

declare(strict_types=1);

namespace App\Core\Turn;

use App\Core\Realtime\Event;
use App\Core\Realtime\Publisher;
use App\Core\Redis\RedisReader;
use App\Core\Room\RoomKeys;
use App\Core\Room\Team;
use Predis\ClientInterface;

/**
 * Kolejnosc druzyn w grach turowych (spec 8): kolo fortuny, kalambury.
 *
 * Kolejnosc jest ustalana raz, przy starcie rozgrywki, i zapisana w Redisie -
 * nie wynika z aktualnej listy druzyn. Gdyby wynikala, dolaczenie spoznionego
 * gracza w polowie gry przestawialoby ture w losowe miejsce.
 *
 * Druzyna, ktora odpadla z pokoju, zostaje w kolejce jako "duch": pomijanie jej
 * byloby ciche, a przy stole i tak ktos zaraz zapyta, czyja kolej. Host ma od
 * tego jawne przekazanie tury.
 */
final readonly class TurnManager
{
    public function __construct(
        private ClientInterface $redis,
        private RedisReader $read,
        private Publisher $publisher,
        private int $roomTtl,
    ) {
    }

    /**
     * Ustala kolejnosc i oddaje ture pierwszej druzynie.
     *
     * @param list<Team> $teams
     */
    public function start(string $code, array $teams): ?string
    {
        $ids = array_map(static fn (Team $team): string => $team->id, $teams);

        $this->redis->del([RoomKeys::turnOrder($code)]);

        if ([] === $ids) {
            return null;
        }

        $this->redis->rpush(RoomKeys::turnOrder($code), $ids);
        $this->redis->expire(RoomKeys::turnOrder($code), $this->roomTtl);
        $this->setIndex($code, 0);

        return $ids[0];
    }

    public function current(string $code): ?string
    {
        $order = $this->order($code);

        if ([] === $order) {
            return null;
        }

        return $order[$this->index($code) % \count($order)];
    }

    public function isCurrent(string $code, string $teamId): bool
    {
        return $this->current($code) === $teamId;
    }

    /** Przekazuje ture nastepnej druzynie i oglasza to calemu pokojowi. */
    public function next(string $code): ?string
    {
        $order = $this->order($code);

        if ([] === $order) {
            return null;
        }

        // INCR, a nie odczyt-i-zapis: host moze klikac "dalej" szybciej niz
        // leci odpowiedz, a tura ma przeskoczyc dokladnie tyle razy, ile klikniec.
        $index = (int) $this->redis->incr(RoomKeys::turnIndex($code));
        $this->redis->expire(RoomKeys::turnIndex($code), $this->roomTtl);

        $teamId = $order[$index % \count($order)];
        $this->announce($code, $teamId);

        return $teamId;
    }

    /** Oddaje ture wskazanej druzynie - host rozstrzyga spory przy stole. */
    public function giveTo(string $code, string $teamId): bool
    {
        $position = array_search($teamId, $this->order($code), true);

        if (false === $position) {
            return false;
        }

        $this->setIndex($code, $position);
        $this->announce($code, $teamId);

        return true;
    }

    /** @return list<string> */
    public function order(string $code): array
    {
        $order = $this->redis->lrange(RoomKeys::turnOrder($code), 0, -1);

        return array_values(array_filter($order, \is_string(...)));
    }

    public function announce(string $code, string $teamId): void
    {
        $this->publisher->broadcast($code, new Event('turn', ['teamId' => $teamId]));
    }

    private function index(string $code): int
    {
        return $this->read->int(RoomKeys::turnIndex($code));
    }

    private function setIndex(string $code, int $index): void
    {
        $this->redis->setex(RoomKeys::turnIndex($code), $this->roomTtl, (string) $index);
    }
}
