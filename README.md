# Nasze Gierki

Platforma gier towarzyskich na jeden telewizor i kilka telefonów, działająca
w LAN bez internetu. Specyfikacja: [`spec-gry-towarzyskie.md`](spec-gry-towarzyskie.md).

> **Stawiasz projekt na nowej maszynie albo wracasz do niego po przerwie?**
> Zacznij od [`CLAUDE.md`](CLAUDE.md) — jest tam kolejność kroków instalacji,
> podjęte decyzje wraz z uzasadnieniem, lista pułapek i to, co zostało do zrobienia.

Stan: **kamienie 1–3 i 5** — rdzeń pokoju, silnik gry, „Jaka to melodia" i „Koło fortuny"
(spec §13.1–3, §13.5). Obie gry są grywalne od początku do końca.

## Uruchomienie

```bash
./bin/dev
```

Skrypt podnosi kontenery i wystawia aplikację na `0.0.0.0`, żeby telefony
w sieci ją widziały. Adres i porty siedzą w `.env` — nadpisuj w `.env.local`.

| Usługa            | Port    | Uwaga                                            |
|-------------------|---------|--------------------------------------------------|
| Aplikacja         | `8210`  | ekran hosta i sterowniki telefonów                |
| Mercure (SSE)     | `3010`  | push serwer → klient                              |
| Redis             | `56379` | stan „live" pokoju                                |
| PostgreSQL        | `55432` | treści: kategorie, nagrania, hasła, karty         |

Porty są nietypowe celowo: na tej maszynie stoją już inne projekty zajmujące
`6379`, `5432`, `3000` i `8080`. Własne kontenery zamiast współdzielenia tamtych,
żeby reset pokoju nie skasował cudzych kluczy.

```bash
php bin/phpunit                     # testy (wymagają Redisa i bazy testowej)
vendor/bin/phpstan analyse          # analiza statyczna, poziom max
```

## Co już działa

- Host tworzy pokój → na TV pojawia się kod i QR.
- Telefony dołączają jako drużyny, ekran hosta dokłada je na tablo **bez
  odświeżania** (Mercure SSE).
- Punkty przez `INCRBY` + broadcast `score`.
- Reset gry czyści stan i zeruje punkty, zostawiając drużyny w pokoju.
- Cały stan wygasa sam po `ROOM_TTL` (domyślnie 6 h).
- Silnik gry: moduły wpinają się przez tag `app.game`, FSM z markingiem w Redisie,
  rozgłaszanie zmian faz, prywatne kanały do hosta i pojedynczej drużyny.
- **„Jaka to melodia"**: kategorie z Postgresa, losowanie utworu po stronie serwera,
  buzzer z lockoutem (`SET NX`), automatyczna pauza muzyki po naciśnięciu,
  ocena hosta, dogrywka po złej odpowiedzi, odsłonięcie tytułu po rundzie.
- **Panel treści** (`/admin`): kategorie, wgrywanie nagrań, hasła i karty kalambur.
- **„Koło fortuny"**: gra turowa (`TurnManager`), koło losowane po stronie serwera,
  plansza hasła z polskim alfabetem, bank punktów per hasło, bankrut i stop,
  klawiatura liter na telefonie drużyny, która ma turę.

## Panel treści

Wszystkie treści dodaje się w panelu pod **`/admin`**: kategorie, wgrywanie
nagrań do melodii, hasła do koła i karty kalambur. Nie ma już importu z plików —
baza jest jedynym źródłem prawdy, więc nic nie kasuje po cichu tego, co ktoś dodał.

```bash
# .env.local — bez hasła panel nie wpuszcza nikogo
ADMIN_PASSWORD=twoje-haslo

php bin/console app:demo:seed   # przykładowe kategorie do wszystkich trzech gier
```

**Kategoria to jeden poziom**: zestaw, który host wybiera na starcie gry
(„Piosenki na lato”), a w kole fortuny jednocześnie podpowiedź dla graczy
(„Przyroda”). Slug kategorii siedzi w ścieżkach wgranych plików i w stanie
trwającej rozgrywki, więc zmiana nazwy go **nie** rusza.

Pole `start` przy nagraniu to sekunda, od której leci utwór — w praktyce
najważniejsze pole w całej treści: refren rozpoznaje się w dwie sekundy,
wstęp bywa nie do zgadnięcia.

## Jak dodać grę

Jedna klasa implementująca `App\Core\Game\GameInterface` (albo dziedzicząca po
`AbstractGame`) wystarczy — kontener otaguje ją sam, rejestr ją podchwyci,
a rdzeń nie wymaga **żadnej** zmiany. Dowodem jest
[`tests/Fixtures/PingPongGame.php`](tests/Fixtures/PingPongGame.php): leży
w katalogu testów, rdzeń nic o niej nie wie, a `GameEngine` uruchamia ją
jak każdą inną grę.

Moduł zwraca z `handleAction()` listę `Message` — i to on decyduje o adresacie:

```php
Message::broadcast($event)              // cały pokój: tablo, fazy, wynik buzza
Message::toHost($event)                 // tylko TV: np. podgląd rozwiązania
Message::toTeam($teamId, $event)        // tylko jeden telefon: słowo dla aktora
```

Klucze kontekstu zaczynające się od `_` są prywatne dla modułu i nigdy nie
wychodzą w rozgłoszeniu zmiany fazy.

## Decyzje odbiegające od specyfikacji

Trzy rzeczy zrobiliśmy inaczej niż mówi spec — wszystkie do cofnięcia w jednym
miejscu, gdyby okazały się złe.

**Predis zamiast phpredis** (spec §4). W systemie nie ma rozszerzenia `redis`,
a Predis to czysty PHP — zero rozszerzeń do budowania przy przenosinach na
Proxmox. Atomowość `SET NX` i `INCRBY` jest po stronie serwera Redis, nie
klienta, więc arbitraż buzzera z §9 działa identycznie. Podmiana = jedna
definicja w `config/services.yaml`.

**Nieprzezroczyste tokeny zamiast JWT** (spec §4, Lexik). Stan pokoju i tak
żyje w Redisie z TTL, więc weryfikacja tokenu przy Redisie daje unieważnienie
przy resecie za darmo — JWT trzeba by osobno odwoływać. Cała logika siedzi
w `src/Core/Room/RoomAccess.php`; wpięcie Lexika to podmiana tego jednego serwisu.
Uwaga: **tokeny Mercure to osobna sprawa** i tam JWT jest — podpisany token
subskrypcji ogranicza telefon do jego własnych topików (`src/Core/Realtime/Subscription.php`).

**Własny adres huba zamiast `MERCURE_PUBLIC_URL`.** Wbudowane
`Symfony\Component\Mercure\Authorization` bierze host huba ze sztywnej
konfiguracji i wywala się, gdy host żądania jest inny („different second-level
domain"). Ten sam serwer jest widziany raz jako `192.168.x.x` z telewizora, raz
jako `localhost` przy pisaniu kodu — więc adres huba wyprowadzamy z bieżącego
żądania. Warunek: aplikacja i hub muszą być osiągalne pod **tą samą** nazwą/IP.

## Do rozstrzygnięcia

- **PWA wymaga HTTPS** (spec §13.4). Service worker nie zarejestruje się po
  `http://192.168.x.x`. Potrzebny Caddy z lokalnym CA albo cert instalowany na
  telefonach — decyzja przed kamieniem 4.
- **Timer i rozjazd zegarów** (spec §8): sam `deadline` w evencie skłamie na
  telefonie z przestawionym zegarem. Proponowane `{deadline, serverNow}`, klient
  liczy offset.
- **Autoryzacja przycisków fizycznych** (spec §10): ESP32 strzela w ten sam
  endpoint, ale nie ma tokenu drużyny. Potrzebny sekret per urządzenie.

## Przejścia FSM dodane ponad spec §14

Melodia ma poza spec dwa przejścia, bez których gra utyka przy stole:

- `cancel(armed → idle)` — nikt nie zabuzzował, host chce zmienić utwór. Bez tego
  pokój zostaje uzbrojony na zawsze, bo `arm` prowadzi wyłącznie z `idle`.
- `rearm(judging → armed)` — drużyna odpowiedziała źle, więc ten sam utwór wraca
  do gry dla pozostałych. To najczęstszy przebieg rundy w praktyce.

Tytuł odsłaniamy dopiero przy `finish` i `cancel` — nigdy przy ocenie, bo po złej
odpowiedzi utwór wraca do gry i pokazanie tytułu popsułoby zagadkę wszystkim.

W kole `next_team` znaczy „czyjaś tura, czeka na zakręcenie" — wchodzi się w niego
zarówno po przejęciu tury, jak i po trafionej literze, gdy tura zostaje przy tej
samej drużynie. Różnica siedzi w `TurnManagerze`, nie w stanie; plansza wygląda
tak samo. Doszło też `hand_over(next_team → next_team)` — host musi móc oddać
turę, gdy gra stoi właśnie na jej granicy.

## Pułapka w symfony/workflow

W Symfony 7.4 `StateMachine` nie nadpisuje już logiki blokad przejść, więc
przejście zadeklarowane z kilku stanów źródłowych oznacza „wymaga **wszystkich**
tych stanów naraz" (semantyka sieci Petriego) — a gra jest zawsze w jednym
stanie, więc taki warunek nie spełni się nigdy. `GameFsm` rozbija je na jedno
przejście komponentu per stan źródłowy; komponent dopuszcza wiele przejść o tej
samej nazwie, więc efekt to oczekiwane „z któregokolwiek z". Dotyczy to
`finish(judging|armed → idle)` w melodii i `reset` w kalamburach.

## Zanim wyjdzie poza domową sieć

`MERCURE_JWT_SECRET` w `.env` to **domyślna wartość z recepty** i jest w repozytorium,
bo `docker compose` czyta wyłącznie `.env` — hub i aplikacja muszą widzieć ten sam
sekret. W LAN bez internetu to nie ma znaczenia; przy wystawieniu czegokolwiek na
zewnątrz trzeba go podmienić w `.env` i przeładować kontener. `APP_SECRET` jest
natomiast w `.env.local`, który do repozytorium nie trafia.

## Uwagi środowiskowe

`symfony server:start` na tej maszynie chodzi przez PHP-FPM i **gubi kod statusu
przekierowań** — 302 wraca jako 200 z samym nagłówkiem `Location`. Dlatego
`bin/dev` używa wbudowanego serwera PHP z własnym routerem (`bin/dev-router.php`).
Docelowo (Proxmox/LAN) stoi przed tym Caddy albo Nginx i problem znika.

## Struktura

```
src/Core/Room/       pokój, drużyny, kody, QR, dostęp
src/Core/Realtime/   topici, eventy, wiadomości, publisher, subskrypcja Mercure
src/Core/Game/       kontrakt modułu, rejestr, silnik, stan; Fsm/ — maszyna stanów
src/Core/Buzzer/     arbiter buzzera (SET NX)
src/Core/Score/      tablo
src/Core/Redis/      typowany odczyt z Redisa
src/Core/Http/       wymuszenie formatu JSON na trasach API
src/Admin/           panel: dostęp, wgrywanie nagrań, zarządzanie treścią
src/Content/         katalog mediów
src/Entity/          Category / Track / PuzzleWord / CharadeCard
src/Core/Turn/       kolejność drużyn w grach turowych
src/Game/Melodia/    moduł „Jaka to melodia" + losowanie utworów
src/Game/Kolo/       moduł „Koło fortuny": koło, plansza hasła, losowanie
src/Command/         app:demo:seed
src/Controller/      Room / Host / Player / Game / Media / Admin
templates/game/      widoki modułów: game/{typ}/host, player, card
templates/admin/     panel treści
assets/controllers/  Stimulus: tablo hosta, panel melodii, buzzer telefonu
assets/live/         nakładka na EventSource
```

Katalog `src/Core/Timer/` dochodzi przy kalamburach (spec §11).
