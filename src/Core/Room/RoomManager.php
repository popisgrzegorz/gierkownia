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
    /**
     * Kolory druzyn - rozroznialne z drugiego konca pokoju, w kolejnosci przydzialu.
     * Niebieski i fioletowy sa rozjasnione wzgledem "podrecznikowych" wartosci -
     * oryginalne #3e63dd/#8e4ec6 jako kolor TEKSTU na ciemnym tle dawaly
     * kontrast 3,47:1 i 3,49:1, ponizej progu AA 4,5:1 (P2-23 audytu).
     */
    private const COLORS = ['#e5484d', '#6381e4', '#30a46c', '#f5a524', '#a16cd0', '#0ea5e9'];

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
     *
     * $knownDevice: to urzadzenie ma juz wazny token JAKIEJS druzyny w tym
     * pokoju (sprawdza wywolujacy, po ciasteczku - RoomAccess). Bez tego
     * "powrot pod ta sama nazwa" byłby przejeciem cudzej druzyny przez zwykle
     * wpisanie jej nazwy w formularzu dolaczania.
     */
    public function join(string $code, string $name, bool $knownDevice = false): Team
    {
        $room = $this->getOrFail($code);
        $name = trim($name);

        if ('' === $name) {
            throw new \InvalidArgumentException('Nazwa druzyny nie moze byc pusta.');
        }

        foreach ($this->teams($room->code) as $existing) {
            if (0 === strcasecmp($existing->name, $name)) {
                // Odtworzona druzyna, po ktora jeszcze nikt zywy sie nie zglosil -
                // to zadanie jest pierwsze, wiec fizycznie nie moze byc przejeciem.
                $wasUnclaimed = 1 === (int) $this->redis->srem(RoomKeys::unclaimedTeams($room->code), [$existing->id]);

                if (!$knownDevice && !$wasUnclaimed) {
                    throw new TeamNameTakenException($name);
                }

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
     * odda mu te druzyne z jej dorobkiem - dlatego kazda odtworzona druzyna
     * laduje tez do zbioru "nieodebranych" (patrz join()), inaczej nikt bez
     * ciasteczka nowego pokoju nie mogby juz nigdy jej przejac. Licznik seq
     * ustawiamy na najwyzszy odtworzony, zeby ktos dosiadajacy sie jutro dostal
     * kolejny numer i kolor, a nie zdublowal istniejacy.
     *
     * @param list<array{id: string, name: string, color: string, seq: int}> $teams
     */
    public function restoreTeams(string $code, array $teams): void
    {
        $teamsKey = RoomKeys::teams($code);
        $unclaimedKey = RoomKeys::unclaimedTeams($code);
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
            $this->redis->sadd($unclaimedKey, [$team->id]);
            $this->redis->setex(RoomKeys::score($code, $team->id), $this->roomTtl, '0');
            $maxSeq = max($maxSeq, $data['seq']);
        }

        $this->redis->expire($teamsKey, $this->roomTtl);
        $this->redis->expire($unclaimedKey, $this->roomTtl);

        $seqKey = RoomKeys::teamSeq($code);
        $this->redis->set($seqKey, (string) $maxSeq);
        $this->redis->expire($seqKey, $this->roomTtl);
    }

    /**
     * Odnotowuje, ze pokoj powstal ze wznowienia zapisu - host dostaje na
     * ekranie wyrazny komunikat, ze to NOWY kod (TournamentArchive::restore).
     */
    public function markResumed(string $code, string $savedName): void
    {
        $this->redis->setex(RoomKeys::resumedFrom($code), $this->roomTtl, $savedName);
    }

    /** Nazwa zapisu, z ktorego powstal ten pokoj - null, gdy pokoj zalozono normalnie. */
    public function resumedFromName(string $code): ?string
    {
        return $this->read->string(RoomKeys::resumedFrom($code));
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

    /**
     * Usuwa druzyne z pokoju - host wyrzuca kogos, kto dolaczyl przez pomylke
     * albo opuscil impreze. Telefon traci dostep natychmiast: `teamByDeviceToken()`
     * czyta z tego samego hasha, wiec kolejne zadanie z jego ciasteczkiem
     * przestaje sie uwierzytelniac, tak jak gdyby nigdy nie dolaczyl.
     *
     * Celowo NIE dotyka stanu biezacej gry (kolejka tur, kontekst modulu) -
     * ten sam "duch w kolejce", ktory juz dzis powstaje, gdy telefonowi
     * padnie bateria (patrz TurnManager) - host ma na to jawne narzedzia
     * w kazdej grze (np. "Nastepna druzyna"), zamiast rekonstruowac tury
     * na sile tutaj, w miejscu, ktore nie zna zadnej konkretnej gry.
     */
    public function removeTeam(string $code, string $teamId): void
    {
        $this->redis->hdel(RoomKeys::teams($code), [$teamId]);
        $this->redis->del([RoomKeys::score($code, $teamId)]);
        $this->redis->srem(RoomKeys::unclaimedTeams($code), [$teamId]);
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
        $this->touchMeta($code);
        $this->redis->hset(RoomKeys::meta($code), 'phase', $phase);
    }

    public function setGameType(string $code, string $gameType): void
    {
        $this->touchMeta($code);
        $this->redis->hset(RoomKeys::meta($code), 'gameType', $gameType);
    }

    /**
     * Wraca do wyboru gry. Osobna metoda zamiast nullable setGameType():
     * "ustaw grę" i "wróć do lobby" to różne intencje i żadna nie powinna
     * dziać się przypadkiem przez przekazanie nulla.
     */
    public function clearGameType(string $code): void
    {
        $this->touchMeta($code);
        $this->redis->hdel(RoomKeys::meta($code), ['gameType']);
    }

    /**
     * Odswieza TTL meta pokoju i pilnuje, zeby nie pisac w klucz, ktorego juz
     * nie ma.
     *
     * Dwa zwiazane problemy naraz: (1) setPhase/setGameType byly wolane przy
     * KAZDEJ akcji gry, ale nigdy nie przedluzaly TTL meta - liczyl sie
     * wylacznie czas od ostatniego pelnego zaladowania ekranu hosta (touch()),
     * wiec dlugo trwajaca gra bez odswiezenia strony mogla wygasnac w polowie,
     * mimo ze cala gra trwala nieprzerwanie. (2) hset/hdel na kluczu, ktory
     * PRZED chwila wygasl, tworzy go OD NOWA z jednym polem, bez TTL i bez
     * pozostalych pol (code, hostToken) - Room::fromRedisHash dostaje potem
     * null zamiast string i wywraca sie trwale, do recznego usuniecia kluczem
     * przez administratora. Sprawdzenie istnienia zamienia to na czyste,
     * oczekiwane "pokoj wygasl" zamiast cichego, permanentnego bledu.
     */
    private function touchMeta(string $code): void
    {
        if (1 !== (int) $this->redis->exists(RoomKeys::meta($code))) {
            throw new RoomNotFoundException($code);
        }

        $this->redis->expire(RoomKeys::meta($code), $this->roomTtl);
    }

    /**
     * Kasuje WSZYSTKIE klucze gry (state, ctx, round, armed, winner:N,
     * turn:order, turn:index, deadline) - nie tylko state+ctx, ktore czysci
     * GameState::clear(). Bez tego np. termin z poprzedniej gry (Kalambury,
     * Panstwa-Miasta, Dutch - kazda ma wlasny zegar pod tym samym kluczem)
     * zostawal w Redisie i "wyciekal" do nastepnej gry w tym samym pokoju:
     * publicView() nowej gry oddawal deadline, ktorego nikt nie uruchomil.
     */
    public function clearGameKeys(string $code): void
    {
        $keys = $this->read->keys(RoomKeys::gamePattern($code));

        if ([] !== $keys) {
            $this->redis->del($keys);
        }
    }

    /**
     * Reset rozgrywki: kasuje stan gry i zeruje punkty, ale zostawia pokoj
     * i druzyny - nikt nie musi ponownie skanowac QR miedzy grami.
     */
    public function resetGame(string $code): void
    {
        $this->clearGameKeys($code);

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
