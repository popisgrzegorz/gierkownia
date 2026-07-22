# Platforma gier towarzyskich — brief techniczny

> Dokument specyfikacji do użycia w Claude Code. Traktuj go jako źródło prawdy dla
> architektury i decyzji projektowych. Buduj w kamieniach milowych (sekcja 13),
> pytaj przed dużymi odstępstwami od przyjętych decyzji.

## 1. Cel i kontekst

Webowa aplikacja do rodzinnych/towarzyskich gier prowadzonych „na żywo" w jednym
pomieszczeniu (jeden duży ekran + telefony graczy). Pierwsze gry:

1. **Jaka to melodia** — rozpoznawanie utworów, buzzer „kto pierwszy".
2. **Koło fortuny** — koło + odgadywanie hasła, gra turowa.
3. **Kalambury** — jedna osoba pokazuje, reszta zgaduje, z timerem.
4. **…kolejne** (Familiada, 1 z 10, Państwa-Miasta, itp.) — dokładane jako moduły.

Kluczowa zasada architektury: **jeden rdzeń platformy + wymienne moduły gier**
(wzorzec plugin). Gry różnią się wyłącznie mechaniką rundy; całą hydraulikę
(pokój, drużyny, real-time, tablo, timer, lockout) współdzielą.

Aplikacja działa w **sieci lokalnej (LAN), bez internetu** — na infrastrukturze
Proxmox. Niskie opóźnienia, pełna autonomia na imprezie.

## 2. Zakres MVP i non-goals

**MVP:**
- Rdzeń: pokój z kodem + QR, dołączanie drużyn telefonem, tablo wyników.
- Real-time przez Mercure (push) + REST (akcje graczy).
- Silnik gry z kontraktem modułu i maszyną stanów per gra.
- Moduł „Jaka to melodia" w pełni (buzzer + lockout + ocena + punkty).
- Panel hosta (ekran TV) + sterownik drużyny (PWA na telefonie).

**Kolejne iteracje:** moduł koła fortuny, moduł kalambur, integracja przycisków
fizycznych (ESP32/Flic), edytor talii treści.

**Non-goals (na teraz):**
- Gra przez internet / matchmaking między domami.
- Konta użytkowników, logowanie społecznościowe.
- Aplikacja natywna (telefon używa PWA w przeglądarce).

## 3. Architektura

```
  Urządzenia                 Backend — Symfony (Proxmox, LAN)          Moduły gier
  ┌───────────────┐          ┌───────────────────────────────┐        ┌──────────────┐
  │ Ekran hosta   │ ──REST──▶│ API + silnik gry (Workflow FSM)│◀──────▶│ Jaka to      │
  │ (TV)          │◀─Mercure─│                               │        │ melodia      │
  ├───────────────┤          ├───────────────────────────────┤        ├──────────────┤
  │ Telefony      │ ──REST──▶│ Redis — stan pokoju + lockout  │        │ Koło fortuny │
  │ drużyn (PWA)  │◀─Mercure─│                               │        ├──────────────┤
  ├───────────────┤          ├───────────────────────────────┤        │ Kalambury    │
  │ Przyciski     │ ──REST──▶│ Mercure — push SSE → klienci   │        ├──────────────┤
  │ fizyczne (opc)│          ├───────────────────────────────┤        │ …kolejne     │
  └───────────────┘          │ PostgreSQL — treści            │        └──────────────┘
                             └───────────────────────────────┘
```

- **Klient → serwer:** zwykły REST POST (buzz, dołączenie, wybór litery, zgłoszenie).
- **Serwer → klient:** Mercure (SSE) — fazy gry, wynik buzza, tablo, timer.
- Arbitraż zawsze po stronie serwera; kierunek klient→serwer nie musi być push,
  więc pełny WebSocket jest zbędny.

## 4. Stack techniczny

| Warstwa            | Wybór                                                        |
|--------------------|--------------------------------------------------------------|
| Backend            | Symfony 7.x (PHP 8.3+)                                        |
| Stan „live" pokoju | Redis (phpredis)                                             |
| Treści trwałe      | PostgreSQL + Doctrine ORM                                     |
| Push real-time     | Mercure (hub jako kontener), symfony/mercure-bundle          |
| Maszyna stanów     | symfony/workflow (definicja) + marking w Redisie             |
| Frontend           | Symfony UX: Stimulus + Turbo + Mercure Turbo Streams          |
| Animacje gier      | Canvas / CSS w kontrolerach Stimulus (np. koło)              |
| Sterownik telefonu | PWA (manifest + service worker), full-screen                 |
| Tożsamość          | JWT (LexikJWTAuthenticationBundle): token drużyny + hosta    |
| Deploy             | Proxmox / LAN, Symfony za Caddy lub Nginx, hub Mercure w kontenerze |

Front: domyślnie Symfony UX (bez pełnego SPA). React tylko gdyby moduł wymagał
ciężkiej interaktywności — decyzja per moduł.

## 5. Model danych

### 5.1 Stan „live" — Redis (ulotny, TTL)

Klucze dla pokoju o kodzie `{room}` i gry `{game}`:

```
room:{room}:meta            HASH   { code, hostToken, gameType, phase, createdAt }
room:{room}:teams           HASH   teamId -> JSON { name, color, deviceToken }
room:{room}:score:{teamId}  STRING licznik punktów (INCRBY)
room:{room}:game:round      STRING licznik rund (INCR przy arm)
room:{room}:game:armed      STRING numer uzbrojonej rundy (istnieje = można buzzować)
room:{room}:game:winner:{r} STRING zwycięzca rundy r  (SET NX = pierwszy wygrywa)
room:{room}:game:state      STRING marking FSM aktualnej gry
room:{room}:game:ctx        JSON   kontekst modułu (np. aktualne hasło, tura)
```

Wszystkie klucze pokoju z TTL (np. 6h), sprzątają się same po imprezie.

### 5.2 Treści trwałe — PostgreSQL / Doctrine

Wspólna abstrakcja `Deck` (talia) + `Category`. Typy pozycji:

- `Track` — utwór do melodii: `title`, `artist`, `file/url`, `deck`.
- `PuzzleWord` — hasło do koła: `phrase`, `hint`, `category`, `deck`.
- `CharadeCard` — karta kalambur: `word`, `category`, `difficulty`, `deck`.
- `QuizQuestion` — (przyszłość): `question`, `answers[]`, `correct`.
- (opcjonalnie) `Score` / `GameLog` — historia wyników.

Import talii: **idempotentne komendy sync z plików YAML** (`app:deck:sync`),
klucz naturalny na pozycję, upsert. Rodzina dorzuca własne talie bez ruszania kodu.

## 6. Real-time (Mercure)

**Topici per pokój** (pod-topici izolują tajne informacje):

```
room/{room}            broadcast: fazy gry, tablo, wynik buzza, timer
room/{room}/host       tylko ekran hosta (np. podgląd hasła)
room/{room}/team/{id}  tylko konkretna drużyna (np. słowo dla aktora w kalamburach)
```

**Format eventu** (JSON): `{ "type": "...", ...payload }`. Typy współdzielone:

- `armed`   `{ round }`                          — runda uzbrojona, buzzery odblokowane
- `buzz`    `{ round, teamId, teamName, ts }`     — ktoś nacisnął pierwszy
- `score`   `{ teamId, total }`                   — zmiana punktów
- `timer`   `{ deadline }`                        — start odliczania (timestamp ms)
- `phase`   `{ state, ctx? }`                     — zmiana stanu FSM
- `reset`   `{}`                                  — reset gry/pokoju

Moduły mogą dodawać własne typy (np. `wheel_result`, `letter_revealed`).

**Bezpieczeństwo (LAN, lekko):** subskrypcja per-topic. Dla domu można pozwolić
na anonimową subskrypcję topiku pokoju; publikacja tylko z backendu (JWT huba).
Tajne pod-topici (`/host`, `/team/{id}`) chronione tokenem, żeby zgadujący nie
podejrzeli hasła.

## 7. Kontrakt modułu gry

Każda gra implementuje wspólny interfejs; silnik ładuje moduł po `gameType`
i deleguje. Rdzeń nic nie wie o konkretnej grze.

```php
interface GameInterface
{
    public static function type(): string;                 // np. "melodia"

    // Definicja FSM: stany, przejścia, stan początkowy.
    public function definition(): GameDefinition;

    // Obsługa akcji gracza/hosta w danym pokoju. Zwraca eventy do publikacji.
    public function handleAction(Room $room, string $action, array $payload, Actor $actor): array;

    // Dane do renderu widoku hosta (ekran TV) i sterownika gracza.
    public function hostView(Room $room): array;
    public function playerView(Room $room, Team $team): array;
}
```

Dodanie nowej gry = nowa klasa `GameInterface` + definicja FSM + widoki (Twig/Stimulus)
+ talia treści. **Zero zmian w rdzeniu.**

Uwaga do FSM: `symfony/workflow` służy do definicji i walidacji przejść;
marking przechowuj w Redisie (`room:{room}:game:state`) przez własny
`MarkingStoreInterface`. Alternatywa dla prostszych gier — lekki FSM na enumach.
Stany poniżej obowiązują niezależnie od wyboru implementacji.

## 8. Wspólne prymitywy (usługi rdzenia)

Zbuduj raz, moduły tylko konsumują:

- **BuzzerArbiter** — lockout „kto pierwszy" (patrz sekcja 9).
- **TurnManager** — kolejność drużyn, „czyja tura" (koło, kalambury).
- **GameTimer** — odliczanie autorytatywne po stronie serwera: publikuje `deadline`
  (timestamp), klient odlicza lokalnie do tej wartości; **nie** synchronizuje co sekundę.
- **Scoreboard** — `INCRBY` w Redisie + event `score`.
- **RoomManager** — tworzenie/dołączanie/reset pokoju, generacja kodu i QR.
- **MediaController hosta** — HTML5 audio/video wyłącznie na ekranie hosta.

## 9. Buzzer / lockout — algorytm krytyczny

To sedno gier „kto pierwszy". Dwa telefony mogą strzelić w odstępie milisekund —
o zwycięzcy decyduje **atomowy `SET NX` w Redisie**, nie kolejność w kodzie PHP
(backend jest wieloprocesowy pod PHP-FPM, stan w pamięci procesu byłby niespójny).

```
arm(room):
    round = INCR room:{room}:game:round
    DEL   room:{room}:game:winner:{round}
    SETEX room:{room}:game:armed TTL round
    publish 'armed' { round }

buzz(room, round, teamId, teamName):
    if GET room:{room}:game:armed != round: return { accepted:false, reason:'stale' }
    won = SET room:{room}:game:winner:{round} teamId NX EX TTL   # bramka
    if not won: return { accepted:false, reason:'locked', winner: GET ...winner:{round} }
    DEL room:{room}:game:armed                                    # zamknij rundę
    publish 'buzz' { round, teamId, teamName, ts }
    return { accepted:true }
```

Uczciwość: to „pierwszy, którego żądanie dotarło do serwera" — standard dla
buzzerów sieciowych. W LAN round-trip ~20–60 ms, w praktyce sprawiedliwe.

Klient (sterownik): po naciśnięciu **od razu** blokuje przycisk lokalnie
(optymistyczny lockout, anty-dubel) i wysyła POST; ostateczny wynik przychodzi
eventem `buzz` (wygrał → „MASZ GŁOS", inni → lockout).

## 10. Role urządzeń i przyciski fizyczne

- **Ekran hosta (TV):** pokazuje grę, gra dźwięk (jeden głośnik w sali), kręci kołem,
  pokazuje tablo. Dla melodii playlista z plików lokalnych przez File API (bez uploadu).
- **Telefon drużyny (PWA):** buzzer + kontekstowe wejścia (klawiatura liter dla koła,
  zgłoszenie w kalamburach). Instalowany na full-screen.
- **Przycisk fizyczny (opcjonalny, tactile upgrade dla melodii):** wpina się w ten
  sam endpoint `/api/buzz` co telefon — backend nie rozróżnia źródła. Dwie drogi:
  - **ESP32 (preferowane, DIY):** każdy guzik ma wgrany `teamId`, po naciśnięciu
    strzela POST/WebSocket prosto do backendu po LAN. Identyfikacja drużyny w sprzęcie.
  - **Flic:** gotowiec, każdy Flic → webhook z `teamId` w URL, mostek na hoście.

Podstawą buzzowania są telefony (działają dla wszystkich gier i identyfikują drużynę);
przyciski fizyczne to dodatek tam, gdzie liczy się „walnięcie w guzik".

## 11. Proponowana struktura katalogów

```
src/
  Core/
    Room/           RoomManager, Room, Team, kody + QR
    Realtime/       Publisher (Mercure), definicje topiców/eventów
    Buzzer/         BuzzerArbiter
    Turn/           TurnManager
    Timer/          GameTimer
    Score/          Scoreboard
    Game/           GameInterface, GameDefinition, GameRegistry, FSM/MarkingStore
  Game/
    Melodia/        MelodiaGame + widoki
    Kolo/           KoloFortunyGame + widoki
    Kalambury/      KalamburyGame + widoki
  Controller/
    RoomController      (create/join/reset)
    GameController      (akcje: arm/buzz/score/action)
    HostController      (ekran hosta)
    PlayerController     (sterownik drużyny)
templates/
  host/ player/ game/{melodia,kolo,kalambury}/
assets/
  controllers/    Stimulus (buzzer, wheel, timer, board)
config/
  packages/mercure.yaml, workflow.yaml
  decks/*.yaml    talie treści do sync
```

## 12. API (szkic endpointów)

```
POST /room                      utwórz pokój → { code, qr, hostToken }
POST /room/{code}/join          dołącz drużynę → { teamId, deviceToken }
POST /room/{code}/reset         reset gry/pokoju (host)
GET  /host/{code}               ekran hosta (TV)
GET  /play/{code}?team=..       sterownik drużyny (telefon)

POST /room/{code}/game/arm      uzbrój rundę (host)
POST /room/{code}/game/buzz     buzz { round, teamId, teamName }
POST /room/{code}/game/score    punkty { teamId, delta }
POST /room/{code}/game/action   akcja modułowa { action, payload }  (koło, kalambury)
```

Autoryzacja: JWT hosta dla akcji hosta, token drużyny dla akcji gracza.

## 13. Kamienie milowe (kolejność budowy)

1. **Rdzeń pokoju** — RoomManager, kod + QR, dołączanie drużyn, tablo, Mercure publisher,
   szkielet host/player. Kryterium: telefony dołączają, widać drużyny na ekranie hosta.
2. **Silnik gry** — GameInterface, GameRegistry, FSM + MarkingStore w Redisie,
   ładowanie modułu po `gameType`.
3. **Moduł „Jaka to melodia"** — BuzzerArbiter (SET NX), FSM melodii, panel hosta
   z playlistą lokalną + pauzą po buzzu, sterownik z lockoutem, punktacja.
   Kryterium: pełna rozgrywka melodii na kilku telefonach.
4. **PWA** — manifest + service worker, sterownik na full-screen.
5. **Moduł „Koło fortuny"** — TurnManager, animacja koła (Canvas/Stimulus), plansza haseł.
6. **Moduł „Kalambury"** — GameTimer, tajne słowo przez pod-topic, cykl draw/act/score.
7. **Przyciski fizyczne** — endpoint wspólny z telefonami, wariant ESP32 i/lub Flic.
8. **Edytor/sync talii** — komendy `app:deck:sync` + proste zarządzanie treścią.

## 14. Definicje FSM per gra

### Melodia (buzzer)
```
stany:      idle → armed → buzzed → judging → idle
przejścia:  arm(idle→armed) buzz(armed→buzzed) judge(buzzed→judging) finish(judging→idle)
```
Prymitywy: BuzzerArbiter, Scoreboard, media na hoście.

### Koło fortuny (turowa)
```
stany:      idle → spinning → landed → pick_letter → reveal → next_team → (pętla) / solved
```
Prymitywy: TurnManager, Scoreboard. Wynik kręcenia liczony po stronie serwera
(losowość autorytatywna), animacja hosta tylko wizualizuje wylosowaną wartość.
Plansza hasła: litery ukryte, odsłaniane przez event `letter_revealed`.

### Kalambury (timer)
```
stany:      idle → drawn → acting(timer) → guessed / skipped / timeout → scored → idle
```
Prymitywy: GameTimer, TurnManager, Scoreboard. Słowo trafia **tylko** do aktora
(pod-topic `room/{room}/team/{id}`), nigdy do zgadujących.

## 15. Konwencje i wymagania jakościowe

- PHP 8.3+, `declare(strict_types=1)`, typy wszędzie; PSR-12; PHPStan max.
- Stan gry wyłącznie w Redisie (nie w sesji PHP, nie w pamięci procesu).
- Treści wyłącznie w Postgres; brak twardo zakodowanych list w kodzie.
- Moduły gier izolowane — brak zależności moduł→moduł; komunikacja przez rdzeń.
- Eventy Mercure jako jedyny kanał serwer→klient; brak pollingu.
- Testy: jednostkowe dla BuzzerArbiter (wyścig/lockout), TurnManager, GameTimer;
  test integracyjny pełnej rundy melodii.
- Wszystko działa offline w LAN — brak zależności od zewnętrznych CDN/API w runtime.

## 16. Kryteria akceptacji MVP

- Host tworzy pokój, na TV widnieje kod + QR.
- 2–4 telefony dołączają jako drużyny, pojawiają się na tablo.
- Host uruchamia rundę melodii; gra leci na głośniku hosta.
- Pierwsze naciśnięcie dowolnej drużyny **zatrzymuje melodię**, na TV widać która
  drużyna, pozostałe telefony dostają lockout.
- Host przyznaje/odejmuje punkt; tablo aktualizuje się u wszystkich w czasie rzeczywistym.
- Kolejna runda działa w pętli; reset czyści stan.
```
