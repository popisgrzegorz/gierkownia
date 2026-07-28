<?php

declare(strict_types=1);

namespace App\Core\Room;

use App\Core\Redis\RedisReader;
use Predis\ClientInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cykl zycia pokoju: utworzenie, dolaczanie druzyn, reset, wygasanie.
 *
 * Caly stan siedzi w Redisie (spec 15) - backend pod PHP-FPM jest wieloprocesowy,
 * wiec cokolwiek trzymane w pamieci procesu bylo by niespojne miedzy zadaniami.
 */
final readonly class RoomManager
{
    /** Kolory druzyn - rozroznialne z drugiego konca pokoju, w kolejnosci przydzialu. */
    private const COLORS = ['#e5484d', '#3e63dd', '#30a46c', '#f5a524', '#8e4ec6', '#0ea5e9'];

    private const MAX_CODE_ATTEMPTS = 20;

    public function __construct(
        private ClientInterface $redis,
        private RedisReader $read,
        private RoomCodeGenerator $codeGenerator,
        private int $roomTtl,
    ) {
    }

    /**
     * Tworzy pokoj z unikalnym kodem.
     *
     * Unikalnosc bierze sie z HSETNX, nie z "sprawdz-potem-zapisz": dwoch hostow
     * moze klikac w tej samej chwili i wylosowac ten sam kod.
     */
    public function create(?string $gameType = null): Room
    {
        for ($attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; ++$attempt) {
            $code = $this->codeGenerator->generate();
            $metaKey = RoomKeys::meta($code);

            if (1 !== (int) $this->redis->hsetnx($metaKey, 'code', $code)) {
                continue; // kod juz zajety, losujemy dalej
            }

            $room = new Room(
                code: $code,
                hostToken: $this->generateToken(),
                gameType: $gameType,
                phase: 'lobby',
                createdAt: time(),
            );

            $this->redis->hmset($metaKey, $room->toRedisHash());
            $this->redis->expire($metaKey, $this->roomTtl);

            return $room;
        }

        throw new \RuntimeException('Nie udalo sie wylosowac wolnego kodu pokoju.');
    }

    public function get(string $code): ?Room
    {
        $meta = $this->read->hash(RoomKeys::meta($code));

        return [] === $meta ? null : Room::fromRedisHash($meta);
    }

    public function getOrFail(string $code): Room
    {
        return $this->get($code) ?? throw new RoomNotFoundException($code);
    }

    public function exists(string $code): bool
    {
        return 1 === (int) $this->redis->exists(RoomKeys::meta($code));
    }

    /**
     * Dolacza druzyne. Nazwa jest kluczem naturalnym w obrebie pokoju: telefon,
     * ktoremu padla bateria, wraca pod ta sama nazwa i odzyskuje swoje punkty
     * zamiast zakladac duplikat obok.
     */
    public function join(string $code, string $name): Team
    {
        $room = $this->getOrFail($code);
        $name = trim($name);

        if ('' === $name) {
            throw new \InvalidArgumentException('Nazwa druzyny nie moze byc pusta.');
        }

        foreach ($this->teams($room->code) as $existing) {
            if (0 === strcasecmp($existing->name, $name)) {
                return $existing;
            }
        }

        $teamsKey = RoomKeys::teams($room->code);

        // INCR, a nie HLEN-i-potem-HSET: dwa telefony klikajace "Wchodzę"
        // w tej samej chwili dostalyby ten sam numer, a wiec i ten sam kolor.
        $seqKey = RoomKeys::teamSeq($room->code);
        $seq = (int) $this->redis->incr($seqKey);
        $this->redis->expire($seqKey, $this->roomTtl);

        $team = new Team(
            id: Uuid::v4()->toBase58(),
            name: $name,
            color: self::COLORS[($seq - 1) % \count(self::COLORS)],
            deviceToken: $this->generateToken(),
            seq: $seq,
            joinedAt: time(),
        );

        $this->redis->hset($teamsKey, $team->id, $team->toJson());
        $this->redis->expire($teamsKey, $this->roomTtl);
        $this->redis->setex(RoomKeys::score($room->code, $team->id), $this->roomTtl, '0');

        return $team;
    }

    /**
     * Odtwarza druzyny z zapisanego turnieju do nowego pokoju.
     *
     * Zachowujemy id, nazwe, kolor i numer - dzieki temu tabela turnieju wciaz
     * wskazuje te same druzyny. deviceToken losujemy na nowo: telefon z wczoraj
     * i tak stracil ciasteczko, a wracajacy gracz wpisze te sama nazwe i join()
     * odda mu te druzyne z jej dorobkiem. Licznik seq ustawiamy na najwyzszy
     * odtworzony, zeby ktos dosiadajacy sie jutro dostal kolejny numer i kolor,
     * a nie zdublowal istniejacy.
     *
     * @param list<array{id: string, name: string, color: string, seq: int}> $teams
     */
    public function restoreTeams(string $code, array $teams): void
    {
        $teamsKey = RoomKeys::teams($code);
        $maxSeq = 0;

        foreach ($teams as $data) {
            $team = new Team(
                id: $data['id'],
                name: $data['name'],
                color: $data['color'],
                deviceToken: $this->generateToken(),
                seq: $data['seq'],
                joinedAt: time(),
            );

            $this->redis->hset($teamsKey, $team->id, $team->toJson());
            $this->redis->setex(RoomKeys::score($code, $team->id), $this->roomTtl, '0');
            $maxSeq = max($maxSeq, $data['seq']);
        }

        $this->redis->expire($teamsKey, $this->roomTtl);

        $seqKey = RoomKeys::teamSeq($code);
        $this->redis->set($seqKey, (string) $maxSeq);
        $this->redis->expire($seqKey, $this->roomTtl);
    }

    /** @return list<Team> */
    public function teams(string $code): array
    {
        $teams = array_map(
            Team::fromJson(...),
            array_values($this->read->hash(RoomKeys::teams($code))),
        );

        usort($teams, static fn (Team $a, Team $b): int => $a->seq <=> $b->seq);

        return $teams;
    }

    public function team(string $code, string $teamId): ?Team
    {
        $json = $this->redis->hget(RoomKeys::teams($code), $teamId);

        return \is_string($json) ? Team::fromJson($json) : null;
    }

    /** Uwierzytelnienie telefonu: token urzadzenia -> druzyna. */
    public function teamByDeviceToken(string $code, string $token): ?Team
    {
        if ('' === $token) {
            return null;
        }

        foreach ($this->teams($code) as $team) {
            if (hash_equals($team->deviceToken, $token)) {
                return $team;
            }
        }

        return null;
    }

    public function isHostToken(string $code, string $token): bool
    {
        $room = $this->get($code);

        return null !== $room && '' !== $token && hash_equals($room->hostToken, $token);
    }

    public function setPhase(string $code, string $phase): void
    {
        $this->redis->hset(RoomKeys::meta($code), 'phase', $phase);
    }

    public function setGameType(string $code, string $gameType): void
    {
        $this->redis->hset(RoomKeys::meta($code), 'gameType', $gameType);
    }

    /**
     * Wraca do wyboru gry. Osobna metoda zamiast nullable setGameType():
     * "ustaw grę" i "wróć do lobby" to różne intencje i żadna nie powinna
     * dziać się przypadkiem przez przekazanie nulla.
     */
    public function clearGameType(string $code): void
    {
        $this->redis->hdel(RoomKeys::meta($code), ['gameType']);
    }

    /**
     * Reset rozgrywki: kasuje stan gry i zeruje punkty, ale zostawia pokoj
     * i druzyny - nikt nie musi ponownie skanowac QR miedzy grami.
     */
    public function resetGame(string $code): void
    {
        $keys = $this->read->keys(RoomKeys::gamePattern($code));

        if ([] !== $keys) {
            $this->redis->del($keys);
        }

        foreach ($this->teams($code) as $team) {
            $this->redis->setex(RoomKeys::score($code, $team->id), $this->roomTtl, '0');
        }

        $this->setPhase($code, 'lobby');
    }

    /** Kasuje pokoj w calosci. Bez tego i tak zniknie po TTL. */
    public function destroy(string $code): void
    {
        $keys = $this->read->keys(RoomKeys::allPattern($code));

        if ([] !== $keys) {
            $this->redis->del($keys);
        }
    }

    /**
     * Odswieza TTL calego pokoju. Wolane przy aktywnosci, zeby dluga impreza
     * nie wygasla graczom w trakcie gry.
     */
    public function touch(string $code): void
    {
        foreach ($this->read->keys(RoomKeys::allPattern($code)) as $key) {
            $this->redis->expire($key, $this->roomTtl);
        }
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
