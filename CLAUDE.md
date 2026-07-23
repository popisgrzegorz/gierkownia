# Nasze Gierki — kontekst dla Claude Code

Platforma gier towarzyskich: jeden telewizor (ekran hosta) + telefony gości,
działająca w sieci lokalnej bez internetu.

**Źródło prawdy dla architektury:** [`spec-gry-towarzyskie.md`](spec-gry-towarzyskie.md).
Buduj w kamieniach z §13, pytaj przed dużymi odstępstwami od przyjętych decyzji.
Ten plik opisuje, co już powstało, dlaczego tak, i gdzie czyhają pułapki —
żeby nie odkrywać ich po raz drugi.

Rozmowa jest po polsku; komentarze w kodzie też.

---

## Stan prac

| Kamień (spec §13) | Stan |
|---|---|
| 1. Rdzeń pokoju | **gotowy** |
| 2. Silnik gry | **gotowy** |
| 3. „Jaka to melodia" | **gotowy**, grywalny |
| 4. PWA | **nie zaczęty** — patrz decyzje niżej |
| 5. „Koło fortuny" | **gotowy**, grywalny |
| 6. Kalambury | **gotowy**, grywalny |
| 7. Przyciski fizyczne | **nie zaczęty** |
| 8. Panel treści | **gotowy** (zamiast `app:deck:sync` z YAML) |

Ponadto: panel administracyjny `/admin`, którego spec nie przewidywała.

211 testów, PHPStan na poziomie `max` bez błędów. Symfony 7.4, PHP 8.3.

---

## Pierwsze uruchomienie po sklonowaniu

Kolejność ma znaczenie. Kroki 1–3 **wymagają internetu** — aplikacja działa
offline dopiero po nich, bo wtedy wszystkie zależności leżą już na dysku.

Najpierw rozszerzenia PHP — bez nich nie ruszy ani baza, ani testy, a komunikaty
o tym są mylące (`could not find driver`, PHPUnit odmawiający startu):

```bash
apt install php8.3-pgsql php8.3-mbstring
```

`gd` nie jest potrzebny — QR generujemy jako SVG (patrz pułapki).

```bash
composer install

# Stimulus i Turbo są poza repo (.gitignore: /assets/vendor/).
# Bez tego kroku ekrany wczytają się bez JS-a: tablo nie będzie się
# aktualizować, buzzer nie zadziała, panel gry zostanie pusty.
php bin/console importmap:install

docker compose up -d                                   # redis, postgres, mercure

# .env.local — nie trafia do repo
php -r 'echo "APP_SECRET=", bin2hex(random_bytes(16)), PHP_EOL;' >> .env.local
echo 'ADMIN_PASSWORD=wybierz-haslo' >> .env.local

php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:demo:seed                          # demo melodii + cały słownik haseł
# sam słownik (kilkaset haseł do koła i kalambur), bez ruszania melodii:
php bin/console app:slownik:seed

# baza testowa
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction --env=test

./bin/dev                                              # serwer na 0.0.0.0
```

**Zanim uruchomisz:** ustaw w `.env` `PUBLIC_URL` (adres, pod którym grę widzą
telefony) oraz `APP_BIND`/`MERCURE_BIND` (adresy nasłuchu). W repo siedzą
wartości poprzedniej maszyny.

```bash
php bin/phpunit                                        # wymaga Redisa i bazy testowej
php -d memory_limit=1G vendor/bin/phpstan analyse      # poziom max
```

Gdyby `composer install` sypał się na receptach Flexa („could not be downloaded",
HTTP 404 z `raw.githubusercontent.com`) — na starej maszynie przyczyną był
nieważny token GitHuba w globalnej konfiguracji Composera. Obejście:
`COMPOSER_HOME=<pusty katalog> COMPOSER_AUTH='{}' composer install`.

### Konfiguracja, którą trzeba ustawić na nowej maszynie

W `.env.local` (nie trafia do repo):

```
APP_SECRET=<losowy hex>
ADMIN_PASSWORD=<hasło do panelu; bez niego panel nie wpuszcza nikogo>
```

W `.env` (trafia do repo, bo `docker compose` czyta **tylko** ten plik):

- `PUBLIC_URL` — adres, pod którym grę widzą telefony i telewizor. Za reverse
  proxy jest to adres publiczny (`https://…`), **nie** adres nasłuchu.
- `APP_BIND` + `APP_PORT` — gdzie nasłuchuje PHP i dokąd celuje proxy.
  W czystym LAN `APP_BIND=0.0.0.0`; za proxy — adres widoczny tylko dla proxy.
- `TRUSTED_PROXIES` — adres kontenera proxy. Puste = aplikacja uzna połączenie
  za nieszyfrowane (patrz pułapki).
- `MERCURE_BIND`, `MERCURE_PUBLIC_PORT` — gdzie stoi hub i pod jakim portem
  widzi go przeglądarka. `0` = ten sam adres co gra, przepuszczony przez to samo
  proxy pod `/.well-known/mercure`.
- `MERCURE_PORT`, `REDIS_PORT`, `POSTGRES_PORT` — porty dobrane tak, by nie
  kolidowały z innymi projektami na maszynie.
- `MERCURE_JWT_SECRET` — domyślna wartość z recepty. W LAN bez znaczenia;
  przy wystawieniu na świat podmień i przeładuj kontener huba.

**Nie kopiuj całego `.env` do `.env.local`.** Przy przenosinach oba pliki
rozjechały się tak, że aplikacja generowała adresy z `.env.local`, a kontenery
i `bin/dev` startowały z wartościami z `.env`. W `.env.local` trzymaj wyłącznie
sekrety.

### Wdrożenie za reverse proxy

Aktualna maszyna: `nginx-proxy-manager` terminuje TLS dla `gierkownia.j33.pl`
i przekazuje ruch na `10.0.7.1:8210` (brama sieci dockerowej proxy). Proxy musi
mieć **dwie** rzeczy:

1. `location /` → `http://10.0.7.1:8210` — sama gra.
2. `location /.well-known/mercure` → `http://10.0.7.1:3010` — hub, **koniecznie**
   z `proxy_buffering off` i długim `proxy_read_timeout`. SSE to jedno wiszące
   połączenie; przy domyślnym buforowaniu nginx trzyma eventy u siebie i tablo
   aktualizuje się skokami albo wcale.

Hub musi być widoczny pod **tą samą nazwą** co aplikacja — `Subscription`
wyprowadza jego adres z bieżącego żądania (patrz decyzje).

Redis, Postgres i hub są związane z adresami nieosiągalnymi z internetu
(`compose.yaml`). Maszyna ma publiczny adres i nie ma firewalla, a Redis nie ma
hasła — na `0.0.0.0` zostałby przejęty w kilka godzin.

---

## Architektura w skrócie

```
src/Core/Room/       pokój, drużyny, kody, QR, dostęp (tokeny hosta i drużyn)
src/Core/Realtime/   topici Mercure, eventy, wiadomości, subskrypcja
src/Core/Game/       kontrakt modułu, rejestr, silnik, stan; Fsm/ — maszyna stanów
src/Core/Buzzer/     arbiter buzzera (SET NX)
src/Core/Turn/       kolejność drużyn
src/Core/Score/      tablo
src/Core/Redis/      typowany odczyt z Redisa
src/Core/Http/       wymuszenie JSON na trasach API
src/Admin/           panel: dostęp, wgrywanie nagrań, zarządzanie treścią
src/Content/         katalog mediów + wspólny słownik haseł (PhraseDeck, Slownik)
src/Entity/          Category / Track / Phrase
src/Game/Melodia/    moduł „Jaka to melodia"
src/Game/Kolo/       moduł „Koło fortuny"
src/Game/Kalambury/  moduł „Kalambury"
```

**Stan „live" (pokój, drużyny, punkty, stan gry) żyje wyłącznie w Redisie** z TTL
i sprząta się sam. Postgres trzyma tylko treści. Nigdy nie trzymaj stanu gry
w sesji PHP ani w pamięci procesu — backend jest wieloprocesowy.

### Dodanie nowej gry

Jedna klasa implementująca `App\Core\Game\GameInterface` (albo dziedzicząca po
`AbstractGame`). Kontener otaguje ją sam, rejestr podchwyci — **zero zmian
w rdzeniu**. Dowodem jest `tests/Fixtures/PingPongGame.php`: leży w katalogu
testów, rdzeń nic o niej nie wie, a `GameEngine` uruchamia ją jak każdą inną grę.

Widoki modułu idą po konwencji: `templates/game/{typ}/host.html.twig`,
`player.html.twig`, `card.html.twig` (kafelek na ekranie wyboru gry —
moduł decyduje o swoim kolorze i ilustracji).

Moduł zwraca z `handleAction()` listę `Message` i **sam wskazuje adresata**:

```php
Message::broadcast($event)         // cały pokój
Message::toHost($event)            // tylko TV — np. podgląd rozwiązania
Message::toTeam($teamId, $event)   // tylko jeden telefon
```

Klucze kontekstu z prefiksem `_` są prywatne dla modułu i **nigdy** nie wychodzą
w rozgłoszeniu zmiany fazy ani w odpowiedzi HTTP na akcję (to samo żądanie
wykonuje telefon gracza). Jest na to test.

---

## Decyzje odbiegające od specyfikacji

Każda do cofnięcia w jednym miejscu.

**Predis zamiast phpredis** (§4). Brak rozszerzenia w systemie; Predis to czysty
PHP. Atomowość `SET NX` i `INCRBY` jest po stronie serwera Redis, więc arbitraż
buzzera z §9 działa identycznie. Podmiana = jedna definicja w `services.yaml`.

**Nieprzezroczyste tokeny zamiast JWT dla hosta i drużyn** (§4, Lexik). Stan
pokoju i tak żyje w Redisie z TTL, więc weryfikacja przy Redisie daje
unieważnienie przy resecie za darmo. Logika w `src/Core/Room/RoomAccess.php`.
**Tokeny Mercure to osobna sprawa i tam JWT jest** — podpisany token subskrypcji
ogranicza telefon do jego własnych topików (`src/Core/Realtime/Subscription.php`).

**Adres huba z bieżącego żądania zamiast `MERCURE_PUBLIC_URL`.** Wbudowane
`Symfony\Component\Mercure\Authorization` wywala się, gdy host żądania różni się
od skonfigurowanego („different second-level domain"), a ten sam serwer bywa
widziany raz jako IP LAN, raz jako localhost. Warunek: aplikacja i hub muszą być
osiągalne pod **tą samą** nazwą/IP.

**Kategoria jako jedyny poziom treści** (§5.2 mówiła o talii + kategorii).
„Piosenki na lato" jest jednocześnie zestawem wybieranym na starcie gry
i podpowiedzią dla graczy w kole. Dwa poziomy zmuszały osobę dodającą treści
do rozstrzygania za każdym razem, co jest czym.

**Jeden słownik haseł zamiast tabeli na grę.** Koło miało `PuzzleWord`,
kalambury `CharadeCard` — te same hasła, różne tylko pole poboczne, więc „Kot
w butach" trzeba było wpisywać dwa razy. Teraz jest `Phrase`, a kategoria ma
dwie osie: `kind` (co w środku: nagrania albo hasła) i `games` (kto może po nią
sięgnąć). Dzięki temu „Bajki" zasilają koło i kalambury, a „Przysłowia" tylko
koło — bo na migi ich nikt nie pokaże. Dopisanie kolejnej gry na słowa to jedna
pozycja w `ContentTypes::PHRASE_GAMES`, bez ruszania encji.
Hasła leżą w bazie **tak, jak je wpisano** — wersaliki dokleja plansza koła
(`Phrase::forBoard()`), bo aktor kalambur dostaje słowo na telefon i nie ma
powodu na niego krzyczeć.

**Tabler (Bootstrap 5) z CDN-u zamiast pełnej autonomii offline** (§15). Własny
CSS wymagał ręcznego dopieszczania każdego breakpointu, a widok telefonu i tak
wychodził słaby. Skoro gra stoi pod publiczną domeną z certyfikatem, internet
jest warunkiem wejścia — więc zależność z sieci przestała być realnym ryzykiem.
**Konsekwencja:** impreza bez internetu = strona bez styli. Powrót do trybu LAN
oznacza ściągnięcie `tabler.min.css` do `assets/styles/vendor/` i podmianę
jednego `<link>` w `base.html.twig`. Wersja jest pinowana (1.4.0), nie `@latest`.

**Panel zamiast `app:deck:sync` z YAML** (§13.8). Import z pliku kasował pozycje,
których w pliku nie było — przy panelu oznaczałoby to ciche kasowanie ręcznej
pracy. Baza jest jedynym źródłem prawdy. Importer YAML jest w historii gita.

**Dodatkowe przejścia FSM ponad §14.** Melodia: `cancel(armed→idle)` (nikt nie
zabuzzował) i `rearm(judging→armed)` (zła odpowiedź, utwór wraca dla reszty —
najczęstszy przebieg rundy). Koło: `hand_over(next_team→next_team)`.
W kole `next_team` znaczy „czyjaś tura, czeka na zakręcenie" i wchodzi się w niego
też po **trafionej** literze — różnica siedzi w `TurnManagerze`, nie w stanie.

---

## Pułapki, na które już wdepnąłem

**`symfony/workflow` 7.4:** `StateMachine` nie nadpisuje już logiki blokad, więc
przejście z kilku stanów źródłowych znaczy „wymaga **wszystkich** naraz"
(semantyka sieci Petriego) — a gra jest zawsze w jednym stanie, więc taki warunek
nie spełni się nigdy. `GameFsm` rozbija je na jedno przejście komponentu per stan
źródłowy.

**Za proxy bez `trusted_proxies` gra zamiera bez śladu błędu.** TLS kończy się
na proxy, do PHP wchodzi zwykły HTTP — więc Symfony uznaje połączenie za
nieszyfrowane i buduje adresy z `http://`. Strona otwarta po HTTPS dostaje wtedy
adres huba po HTTP, przeglądarka blokuje SSE jako mixed content i tablo,
buzzer oraz fazy gry stoją. Na ekranie nie ma żadnego komunikatu — `EventSource`
po prostu nigdy się nie otwiera. Ustawienie siedzi w `config/packages/framework.yaml`,
a pilnuje go test w `SubscriptionTest`.

Objaw pokrewny: ciasteczko sesji dostaje flagę `secure` dopiero wtedy, gdy
Symfony widzi HTTPS. Dlatego `curl` po zwykłym HTTP do portu nasłuchu dostanie
403 na ekranie hosta — to nie błąd, tylko nieodesłane ciasteczko. Testuj przez
prawdziwą domenę albo sprawdzaj nagłówki `Set-Cookie`.

**`[hidden]` nie działa** na elementach z `display:flex/grid` — nasze reguły
wygrywają z domyślnym stylem przeglądarki. W `app.css` jest globalne
`[hidden] { display: none !important; }`. Bez tego ukrywane z JS-a fragmenty
zostają na ekranie.

**`Room` to niemutowalny snapshot.** `GameEngine` czyta `gameType` z Redisa,
a nie z przekazanego obiektu — egzemplarz wczytany przed `start()` twierdziłby,
że gry nie ma.

**QR generuj jako SVG.** `PngWriter` z endroid wymaga rozszerzenia GD, którego
na serwerze może nie być („Unable to generate image"). `SvgWriter` nie wymaga
niczego i jest ostrzejszy na dużym ekranie.

**Recepty Flexa dopisują duplikaty do `compose.yaml`** (Doctrine dopisał drugi
serwis `database` z własnymi hasłami). Po każdym `composer require` z receptą
zajrzyj do `compose.yaml` i `.env`.

**Błędy na trasach API muszą być JSON-em.** Symfony wybiera format po nagłówku
`Accept`; klient bez niego dostaje HTML. `ApiFormatSubscriber` wymusza JSON na
`/api/*` i `/room/{kod}/game/*` — przycisk ESP32 (kamień 7) strzeli gołym POST-em.

**Kolejność drużyn i kolory** biorą się z licznika `INCR` per pokój, nie z `HLEN`
ani z czasu z dokładnością do sekundy. Wszyscy skanują QR jednocześnie.

**Zrzuty ekranu w headless Chrome kłamią o `vw`** — przy oknie 430 px viewport
wychodzi ~500. Nie goń tego jako błędu layoutu; sprawdzone na izolowanym
przypadku. Strona z otwartym SSE nigdy się nie „ustali", więc do zrzutu trzeba
zatrzymać kontener Mercure (`docker compose stop mercure`).

**Serwer deweloperski:** na starej maszynie `symfony server:start` chodził przez
PHP-FPM i **gubił kody statusu przekierowań** (302 → 200 z samym `Location`).
Dlatego `bin/dev` używa wbudowanego serwera PHP z routerem `bin/dev-router.php`.
Router **musi** robić `return require public/index.php` — samo `require` zwraca
`int` i Symfony Runtime wywala się na „callable object expected", co objawia się
brakiem CSS i JS. Na nowej maszynie sprawdź, czy `symfony server:start` działa
poprawnie; jeśli tak, `bin/dev` można uprościć.

---

## Konwencje

- PHP 8.3, `declare(strict_types=1)`, typy wszędzie, PSR-12, PHPStan `max`.
  Nie tłum błędów analizy `@phpstan-ignore` ani baseline'em — napraw przyczynę.
- Komentarze wyjaśniają **dlaczego**, nie co robi kod. Bez komentarzy oczywistych.
- Testy: prawdziwy Redis i prawdziwy Postgres, bez atrap. To właśnie atomowość
  Redisa jest tym, co testujemy — atrapa potwierdzałaby tylko, że umiemy pisać atrapy.
  `tests/RedisConnection.php` (cecha) i `tests/DoctrineTestCase.php` (transakcja
  wycofywana po teście).
- Nazwy testów opisują zachowanie, nie metodę. Komentarz w teście mówi, co się
  psuje przy stole, gdy asercja padnie.
- Nie commituj bez prośby użytkownika.

---

## Do zrobienia

### Decyzje czekające na użytkownika

- ~~**HTTPS a PWA (kamień 4).**~~ **Rozstrzygnięte przez przenosiny:** gra stoi
  za `nginx-proxy-manager` z certyfikatem Let's Encrypt, więc service worker ma
  wymagane HTTPS i kamień 4 nie jest już niczym blokowany. Zostaje pytanie
  odwrotne: czy gra ma nadal działać **bez internetu** w LAN — bo wtedy pod
  adresem IP HTTPS-a nie ma i PWA się nie zarejestruje. Tryb LAN jest wciąż
  wspierany konfiguracją (`APP_BIND=0.0.0.0`, `MERCURE_PUBLIC_PORT=3010`).
- ~~**Timer a rozjazd zegarów (§8).**~~ **Rozstrzygnięte przy kalamburach:**
  event niesie parę `{deadline, serverNow}`, telefon liczy z niej offset zegara,
  a o wygaśnięciu i tak decyduje serwer (`GameTimer::expired()`).
- **Autoryzacja przycisków fizycznych (§10).** ESP32 strzela w ten sam endpoint,
  ale nie ma tokenu drużyny — potrzebny sekret per urządzenie.

### Następne kroki

1. **PWA (kamień 4)** — po decyzji o trybie LAN.
2. **Przyciski fizyczne (kamień 7)**.
3. **Państwa-Miasta** — kafelek „wkrótce" na ekranie hosta zamieni się
   w grywalny sam, gdy moduł się wpnie (lista filtruje się po zarejestrowanych typach).
   Treści ma gotowe: wystarczy dopisać typ do `ContentTypes::PHRASE_GAMES`
   i zaznaczyć kategorie w panelu.

### Znane braki i długi

- **Widok telefonu nie był oglądany na prawdziwym urządzeniu** — tylko w headless
  Chrome, który kłamie o `vw`. Przy pierwszym teście z gośćmi to pierwsza rzecz
  do sprawdzenia.
- **Limit wgrywania**: `post_max_size` bywa niższe niż `upload_max_filesize`
  i to ono decyduje. Panel pokazuje czytelny komunikat, ale limit trzeba podnieść
  w `php.ini` pod dłuższe nagrania.
- **Punkty melodii (1 pkt) i koła (setki) trafiają na to samo tablo.** Odkąd
  „Zmień grę" zostawia punkty, widać to na każdym wieczorze z dwiema grami:
  setki z koła przykrywają wszystko inne. Turniej z wielu gier wymagałby
  normalizacji.
- **Słownik nie zna trudności per gra.** `difficulty` opisuje, jak trudno hasło
  *pokazać* (kalambury); koło używa go tylko do sortowania. Hasło łatwe do
  pokazania bywa nudne na planszy i odwrotnie — jeśli zacznie przeszkadzać,
  trzeba drugiego pola albo tagów.
- **Brak testów funkcjonalnych HTTP** (WebTestCase). Ścieżki przez kontrolery
  sprawdzałem ręcznie curl-em; logika ma testy jednostkowe i integracyjne.
- **Kategorie nie mają zmiany kolejności pozycji w panelu** — `position` istnieje
  w encjach i jest respektowane, ale UI go nie wystawia.
