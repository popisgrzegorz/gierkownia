<?php

declare(strict_types=1);

namespace App\Core\Room;

use Predis\ClientInterface;

/**
 * Zamek per pokoj: serializuje akcje, ktore czytaja i modyfikuja stan gry
 * w kilku krokach (odczyt -> zmiana w PHP -> zapis).
 *
 * Backend jest wieloprocesowy (PHP-FPM), a stan gry (GameState::mergeCtx,
 * marking FSM, tabela turnieju) to wlasnie taki wzorzec bez ochrony - dwa
 * rownolegle zadania dla tego samego pokoju czytaja ten sam stan sprzed zmiany,
 * a drugi zapis kasuje pierwszy. "Redis szereguje polecenia" nie broni przed
 * tym niczego: szereguje POJEDYNCZE polecenia, a nie pare GET+SETEX rozlozona
 * na dwa procesy. Ten zamek jest tym, czego brakowalo - caly blok krytyczny
 * (typowo jedno wywolanie handleAction) biegnie pod wylacznoscia dla danego
 * kodu pokoju, wiec drugie zadanie czeka, zamiast pisac na oslep na starym stanie.
 *
 * SET NX daje wylacznosc (dokladnie ten sam prymityw co w BuzzerArbiter), a
 * zwolnienie przez Lua sprawdza token wlasciciela - gdyby TTL zamka wygasl
 * w trakcie wyjatkowo dlugiej akcji i ktos inny zdazyl go przejac, nie chcemy
 * zdjac JEGO zamka.
 */
final readonly class RoomLock
{
    /** Ile najdluzej trzyma sie zamek, zanim TTL zwolni go sam - siatka
     *  bezpieczenstwa na wypadek procesu, ktory padnie w trakcie akcji. */
    private const TTL_MS = 5000;

    /** Jak dlugo czekamy na cudzy zamek, zanim uznamy to za zator. Pojedyncza
     *  akcja trwa milisekundy, wiec 2 s to duzy zapas, nie normalny tryb pracy. */
    private const WAIT_TIMEOUT_MS = 2000;

    private const RETRY_DELAY_US = 15_000;

    private const RELEASE_SCRIPT = <<<'LUA'
        if redis.call('GET', KEYS[1]) == ARGV[1] then
            return redis.call('DEL', KEYS[1])
        end
        return 0
        LUA;

    public function __construct(
        private ClientInterface $redis,
    ) {
    }

    /**
     * Wykonuje $callback majac wylacznosc na dany pokoj.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function synchronized(string $code, callable $callback): mixed
    {
        $key = self::key($code);
        $token = bin2hex(random_bytes(8));
        $deadline = microtime(true) + (self::WAIT_TIMEOUT_MS / 1000);

        while (true) {
            if ($this->redis->set($key, $token, 'PX', self::TTL_MS, 'NX')) {
                break;
            }

            if (microtime(true) >= $deadline) {
                throw new RoomBusyException($code);
            }

            usleep(self::RETRY_DELAY_US);
        }

        try {
            return $callback();
        } finally {
            $this->redis->eval(self::RELEASE_SCRIPT, 1, $key, $token);
        }
    }

    private static function key(string $code): string
    {
        return sprintf('room:%s:lock', $code);
    }
}
