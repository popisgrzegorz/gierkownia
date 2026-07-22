<?php

declare(strict_types=1);

namespace App\Core\Score;

use App\Core\Realtime\Event;
use App\Core\Realtime\Publisher;
use App\Core\Redis\RedisReader;
use App\Core\Room\RoomKeys;
use App\Core\Room\RoomManager;
use Predis\ClientInterface;

/**
 * Punkty druzyn (spec 8). Zmiana zawsze przez INCRBY, nigdy przez odczyt-i-zapis:
 * host moze klikac szybciej niz leci odpowiedz, a dwa rownolegle zadania
 * pod PHP-FPM zgubily by jeden z punktow.
 */
final readonly class Scoreboard
{
    public function __construct(
        private ClientInterface $redis,
        private RedisReader $read,
        private RoomManager $rooms,
        private Publisher $publisher,
        private int $roomTtl,
    ) {
    }

    /** Dodaje delta punktow (moze byc ujemna) i rozsyla nowy stan. */
    public function add(string $code, string $teamId, int $delta): int
    {
        $key = RoomKeys::score($code, $teamId);
        $total = (int) $this->redis->incrby($key, $delta);
        $this->redis->expire($key, $this->roomTtl);

        $this->publisher->broadcast($code, new Event(Event::SCORE, [
            'teamId' => $teamId,
            'total' => $total,
            'delta' => $delta,
        ]));

        return $total;
    }

    public function get(string $code, string $teamId): int
    {
        return $this->read->int(RoomKeys::score($code, $teamId));
    }

    /**
     * Tablo: teamId => punkty, w kolejnosci dolaczenia druzyn.
     *
     * @return array<string, int>
     */
    public function all(string $code): array
    {
        $teams = $this->rooms->teams($code);

        if ([] === $teams) {
            return [];
        }

        $keys = array_map(static fn ($team): string => RoomKeys::score($code, $team->id), $teams);
        $values = $this->read->ints($keys);

        $scores = [];
        foreach ($teams as $i => $team) {
            $scores[$team->id] = $values[$i];
        }

        return $scores;
    }
}
