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
| 3. „Jaka to melodia" | **gotowy**, grywalny (dwa warianty: buzzer i 1 z 4) |
| 4. PWA | **instalowalna** (manifest, ikony, service worker bez cache'owania) — offline w LAN to osobna, otwarta decyzja, patrz niżej |
| 5. „Koło fortuny" | **gotowy**, grywalny |
| 6. Kalambury | **gotowy**, grywalny |
| 7. Przyciski fizyczne | **nie zaczęty** |
| Państwa i miasta | **gotowa**, grywalna (poza planem §13) |
| Dutch (Cabo) | **backend gotowy i przetestowany**, frontend niesprawdzony na żywo (poza planem §13) |
| 8. Panel treści | **gotowy** (zamiast `app:deck:sync` z YAML) |

Ponadto: panel administracyjny `/panel` (EasyAdmin), którego spec nie przewidywała —
`/admin` zostaje jako wspólne logowanie/wylogowanie.

381 testów, PHPStan na poziomie `max` bez błędów. Symfony 7.4, PHP 8.3.

**Pełny audyt logiki, bezpieczeństwa i wyglądu** ([`AUDYT-2026-07.md`](AUDYT-2026-07.md))
i wdrożenie wszystkich znalezisk z Bloków 0–3 (bezpieczeństwo produkcyjne, wyścigi
i błędy psujące rozgrywkę, stabilność/CSRF/rate limiting, wygląd i dostępność) —
patrz sekcje „Decyzje odbiegające" i „Pułapki" niżej za szczegółami tego, co się
zmieniło. Blok 4 (dług) też zrobiony: lint JS, podstawy PWA, ta aktualizacja
dokumentacji.

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
echo 'HOST_PASSWORD=wybierz-inne-haslo' >> .env.local

php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:demo:seed                          # demo melodii + cały słownik haseł
# sam słownik (kilkaset haseł do koła i kalambur), bez ruszania melodii:
php bin/console app:slownik:seed

# lista nagrań z pliku (tytuł [TAB] wykonawca [TAB] adres) jako linki zewnętrzne:
php bin/console app:melodia:import lista.txt --kategoria="Ballady rockowe" --podglad

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

npm install                                            # tylko lint JS - AssetMapper go nie potrzebuje
npm run lint                                           # eslint assets/ (assets/vendor/ pominięty - to Stimulus/Turbo/GSAP)
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
HOST_PASSWORD=<hasło do założenia pokoju; puste = zakładanie pokoju otwarte dla każdego>
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
src/Admin/           dostęp do panelu (AdminAccess), typy treści (ContentTypes),
                     brama /panel (AdminPanelGuard)
src/Controller/Admin/ panel treści na EasyAdminie (/panel): Category/Track/Phrase
                     CrudController + Dashboard
src/Content/         katalog mediów, czyszczenie plików po skasowaniu nagrania
                     (MediaLibrary, TrackAudioCleaner), wspólny słownik haseł
                     i talia nagrań (PhraseDeck, Slownik, TrackDeck)
src/Entity/          Category / Track / Phrase (Track: plik na dysku ALBO link)
src/Game/Melodia/    moduł „Jaka to melodia" (buzzer)
src/Game/MelodiaQuiz/ moduł „Jaka to melodia — 1 z 4"
src/Game/Kolo/       moduł „Koło fortuny"
src/Game/Kalambury/  moduł „Kalambury"
src/Game/PanstwaMiasta/ moduł „Państwa i miasta"
src/Game/Dutch/      moduł „Dutch" (gra karciana; Karty, Punktacja)
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

**`HostAccess` — osobne hasło na ZAŁOŻENIE pokoju** (`HOST_PASSWORD`, wzorowane
na `AdminAccess`). Gra stoi pod publiczną domeną, więc bez tego każdy, kto
trafi na stronę główną, mógł kliknąć „Nowa gra" i zostać hostem. Bramka
sprawdza się **wyłącznie** przy `POST /room` — token hosta istniejącego już
pokoju (`RoomAccess`) to zupełnie inna sprawa i tej bramki nie dotyczy, więc
powrót na już założony ekran hosta nigdy o nie pyta. Puste `HOST_PASSWORD`
(domyślny stan w `.env`) wyłącza bramkę całkowicie - zakładanie pokoju zostaje
otwarte jak przed tą zmianą. Hasło pyta się raz na przeglądarkę: pierwsza
„Nowa gra" zapamiętuje sesję (`session->migrate(true)`, jak w adminie), kolejne
pokoje z tego samego urządzenia zakładają się już bez pytania. Osobne od
`ADMIN_PASSWORD` świadomie - hostowanie imprezy i zarządzanie treścią to różne
uprawnienia, jeden sekret nie powinien otwierać obu.

**Adres huba z bieżącego żądania zamiast `MERCURE_PUBLIC_URL`.** Wbudowane
`Symfony\Component\Mercure\Authorization` wywala się, gdy host żądania różni się
od skonfigurowanego („different second-level domain"), a ten sam serwer bywa
widziany raz jako IP LAN, raz jako localhost. Warunek: aplikacja i hub muszą być
osiągalne pod **tą samą** nazwą/IP.

**Kategoria jako jedyny poziom treści** (§5.2 mówiła o talii + kategorii).
„Piosenki na lato" jest jednocześnie zestawem wybieranym na starcie gry
i podpowiedzią dla graczy w kole. Dwa poziomy zmuszały osobę dodającą treści
do rozstrzygania za każdym razem, co jest czym.

**Nagranie może leżeć poza serwerem.** `Track` ma albo `path` (plik w katalogu
mediów), albo `sourceUrl` (adres u kogoś innego) — nigdy oba. Przy linku
`MediaController` odsyła przekierowaniem, więc przeglądarka hosta pobiera dźwięk
prosto ze źródła, a my nie trzymamy ani bajta. `path` musi wtedy być **NULL-em**,
nie pustym stringiem: unikalny indeks `(category_id, path)` przepuszcza wiele
NULL-i, ale nie dwa puste stringi. **Konsekwencja:** utwór umiera, gdy źródło
przeniesie plik albo zablokuje odtwarzanie z obcej strony — pliku nie mamy.

**Wariant gry = osobny moduł, nie przełącznik w istniejącym.** „Jaka to melodia"
ma dwa warianty i są to dwie klasy. W buzzerowym liczy się refleks jednej drużyny
(arbiter blokuje resztę), w teście 1 z 4 wszyscy odpowiadają naraz i rozstrzyga
zegar. Wciśnięte w jeden automat stanów dałyby maszynę, w której połowa przejść
obowiązuje tylko w połowie przypadków. Treść dzielą przez `TrackDeck` — te same
kategorie grają w obu, nikt nie wprowadza nagrań drugi raz.

**Wybór kategorii to lista, nie jedna pozycja.** Obie melodie losują ze
**wspólnej puli** wszystkich zaznaczonych kategorii, a nie „najpierw kategoria,
potem utwór" — przy 920 nagraniach dance i 156 balladach ten drugi sposób dawałby
ballady sześć razy częściej, niż wynika z ich udziału w puli. Moduły przyjmują
nadal pojedyncze `category` obok `categories`: tak strzeli przycisk fizyczny
(kamień 7) i tak wyglądają starsze zapisy w Redisie.

**Państwa i miasta sprawdza wykaz, reszta rubryk człowiek** (`Wykaz`). Automat
odrzuca to, co da się rozstrzygnąć bez słownika języka: złą pierwszą literę,
jedną literę zamiast słowa i odpowiedź spoza wykazu państw albo miast. Przy
roślinie czy zwierzęciu nie ocenia niczego. Odrzucenie z wykazu jest **miękkie** —
komórka dostaje osobny status i żółty pytajnik zamiast przekreślenia, bo lista
nigdy nie będzie pełna; jeden klik hosta ją zalicza. Wykaz siedzi w kodzie obok
`Kolumny::PRESETS`: to materiał pomocniczy do rubryk, a nie treść do zgadywania,
więc nie idzie przez panel.

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

**Dutch: jeden telefon = jeden gracz, a nie drużyna.** Cała gra to indywidualna
pamięć własnych czterech kart, więc „drużyna" dzieląca rękę nie ma sensu.
Rotację graczy trzymamy we własnej kolejce w kontekście (`order`/`turn`),
nie w `TurnManagerze`: on rozgłasza `turn` przy każdym `INCR`, a tu potrzebne
były runda końcowa z licznikiem i wołanie „Dutch" kończące turę natychmiast.

**Dutch liczy własny ranking, nie dotyka wspólnego tabla.** `Core/Score` sumuje
„więcej = lepiej" przez cały wieczór; Dutch to „najniższa suma wygrywa", liczone
per runda. Zmieszanie obu psułoby jedno i drugie, więc `totals` Dutcha siedzą
w jego kontekście, a `Scoreboard` zostaje nietknięty (moduł nie wstrzykuje go
w ogóle). Konsekwencja: wynik Dutcha nie przechodzi na turniej wielu gier.

**Dutch: uczciwa pamięć zamiast ściągi.** `playerView` oddaje podejrzane karty
**tylko w fazie `peek`**; gdy runda rusza, znikają — telefon nie pamięta ich za
gracza. Na telefonie podgląd działa na przytrzymanie (jak podniesienie
prawdziwej karty). Sedno gry to pamięć; apka, która trzyma znane karty na
ekranie, zamieniłaby ją w samą arytmetykę.

**Dutch: moce kart wg zasad domowych użytkownika** (`Karty::power()`):
**dama — podgląd WŁASNEJ karty**, **walet — wskazujesz DWÓCH graczy, a serwer
LOSOWO zamienia im po jednej karcie** (w ciemno). Reszta (7–10, as, król) jest
bez mocy. To NIE jest standardowe Cabo (tam podglądają 7–10, a J/Q zamieniają
WYBRANE karty, nie losowe) ani wariant z artykułu 8gates — to konkretny wybór
przy stole, więc nie zmieniaj go „na standard" bez pytania. Moc gracz odpala,
zrzucając kartę zamiast podmiany: `usePower` ustawia `_power` (`peek_own`/`swap`),
a `power_peek` (własna karta) / `power_swap` (`{a,b}` = dwaj gracze) domykają
akcję. Podgląd trafia wyłącznie do gracza (`Message::toTeam`, jest test na brak
wycieku); zamiana rozgłasza tylko, czyje pola się przełożyły — nie wartości.
Tura po użyciu mocy się kończy.

**Dutch: dorzucanie pasującej karty („snap") w refleksie.** KAŻDY, w każdej
chwili gry (nie tylko w swojej turze), może dorzucić na stos kartę o tej samej
**figurze** co wierzch stosu (kolor bez znaczenia) — akcja `snap`. Trafienie
ujmuje kartę z ręki (mniej kart = mniej punktów). Pudło jest podwójnie karane:
kartę, którą chciał wyrzucić, **widzą wszyscy przez 3 sekundy** (kara pamięciowa —
`snap_failed` niesie jej wartość, host pokazuje ją na środku) ORAZ dochodzi karna
karta z talii. Serwer rozstrzyga wg wierzchu stosu w chwili żądania — Redis
szereguje, więc dwa dorzucenia się nie pobiją. **Konsekwencja: ręka nie jest
stała.** Puste pole po dorzuceniu trzymamy jako wartownik `EMPTY = -1` (a nie
skracamy listy), żeby pamięć o pozycjach nie rozjechała się przy każdym ubytku;
karna dokłada nowe pole na końcu. `handShapes()` oddaje ekranom same kształty
(gdzie karta, gdzie luka), nigdy wartości. Punktacja i odsłonięcie pomijają `-1`.

**Dutch w wariancie startowym.** Bez jokerów i króla z mocą. Kara za błędny
„Dutch" to stałe +5 (`Punktacja::score`), a trafny call wymaga **samodzielnie**
najniższej sumy (remis liczy się przeciw wołającemu). Punktacja prosta: as=1,
2–10 wprost, figury=10 (wariant czerwony-król-0 pominięty).

**Animacje nie decydują o niczym.** Siła zamachu kołem (długość przytrzymania
przycisku na telefonie) zmienia wyłącznie liczbę obrotów i czas dohamowania na
telewizorze — sektor wybiera `Wheel::spin()` na serwerze i to on siedzi w kącie
docelowym, a zamach dokłada tylko pełne obroty. Tak samo bęben z literą
w państwach-miastach: przewijane litery są dekoracją, a wynik zapada dopiero
w chwili, gdy host naciśnie STOP i poleci akcja `draw`. Gdyby było odwrotnie,
o wyniku decydowałby telefon albo refleks — a litera leżałaby w odpowiedzi HTTP,
zanim ktokolwiek zobaczy ją na ekranie.
Wszystkie animacje wyłącza `prefers-reduced-motion`.

**Dutch animuje ruch kart na GSAP.** Rozdanie (kaskada z talii), dobieranie
(rewers leci do gracza), lot na stos (dorzucenie/podmiana/wyrzucenie), obroty
przy podglądzie i odsłonięciu — wszystko na `gsap`, dodanym przez
`importmap:require gsap` (wersja w `importmap.php`, plik wektorowany lokalnie do
`assets/vendor/` jak Stimulus/Turbo — `importmap:install` pobiera go na świeżym
klonie). To nadal sama dekoracja: stan gry i tak zapada na serwerze. Karty leczą
w osobnej warstwie nad panelem (`.dutch-fly`, `position: fixed`), więc
odświeżenie stołu ich nie zabiera; pozycje mierzymy z DOM-u sprzed odświeżenia.

**Koło ratunkowe w kalamburach odsłania jedną literę.** Pierwszą literę
pierwszego wyrazu **dłuższego niż jedna litera** — jednoliterowy wyraz to spójnik
albo przyimek, więc odsłonięcie go oddaje cały wyraz i nie mówi nic o haśle.
Raz na kartę: druga podpowiedź zamieniłaby kalambury w zgadywankę z planszy.
Punktacji nie zmienia, bo o podpowiedź i tak prosi ten, kto już utknął.

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
Kalambury: `guessed` przyjmuje jako źródło `acting` **i** `timeout` — host może
kliknąć „Zgadnięto" tuż po tym, jak zegar TV już wysłał `timeout`; bez tego
punkt nie szedł do nikogo, mimo że ktoś naprawdę zgadł.

**`RoomLock` — zamek Redisowy (`SET NX`) wokół `GameEngine::start/handle`**
i akcji kończącej grę (`GameController::stop`). Backend jest wieloprocesowy pod
PHP-FPM, więc dwa równoległe żądania czytające ten sam stan gry (np. dwa
dorzucenia w Dutchu, dwa kliknięcia „Zmień grę") potrafiły się zgubić nawzajem —
`GameEngineRaceTest` odpala to naprawdę, sześcioma procesami na raz. `stop()`
w `GameEngine` **nie ma własnego zamka** — zagnieżdżenie dwóch blokad z tego
samego żądania (kontroler już trzyma zamek na czas zaliczenia turnieju + stop)
skończyłoby się zakleszczeniem.

**CSRF tylko na klasycznych formularzach, nie na akcjach gry.** `SameSite=Lax`
nie chroni przed innym serwisem pod tym samym site (`*.j33.pl`, różne
subdomeny) — ale to dotyczy wyłącznie formularzy powodujących pełną nawigację
POST-em (`<form method="post">`). Fetch z obcej (choć tej samej site) strony
i tak nie dołączy ciasteczka bez zgody CORS, więc akcje gry (`/room/{code}/game/*`,
wysyłane fetchem) zostają bez tokenu — dodanie go objęłoby ~15 kontrolerów JS
bez realnego zysku. Token generuje `csrf_token('room')` (pokój/gra/turniej) albo
`csrf_token('admin')` (panel + wznowienie turnieju, bo chroni je to samo hasło) —
sprawdzany przez `isCsrfTokenValid()` w każdej z 14 chronionych akcji.

**Rate limiting per IP** (`symfony/rate-limiter`, `config/packages/rate_limiter.yaml`):
`admin_login` (10/15 min — hasło słownikowe), `room_join` (30/min — prawdziwa
impreza dołącza kilkanaście telefonów na minutę, limit łapie skrypt zasypujący
pokój tysiącami drużyn), `room_state` (60/min — kod pokoju to 4 litery, ok. 234 tys.
kombinacji, bez limitu enumeracja zajmuje ~40 min). Magazyn liczników to
domyślny `cache.app` (plik na dysku, wspólny dla workerów PHP-FPM) — to nie stan
gry, więc nie musi siedzieć w Redisie. **W testach każdy przypadek dotykający
tych tras używa losowego `REMOTE_ADDR`** — magazyn przeżywa między osobnymi
uruchomieniami `phpunit`, więc dzielony adres psuje wynik w zależności od tego,
ile razy wcześniej odpalono testy.

**Przejęcie drużyny przez wpisanie jej nazwy wymaga dowodu.** `RoomManager::join()`
oddawał cudzą drużynę każdemu, kto trafił jej nazwę (widoczną na ekranie hosta) —
sam telefon bez żadnego tokenu tego pokoju. Teraz odzyskanie nazwy wymaga albo
ważnego ciasteczka z tego pokoju (`knownDevice`), albo bycia **pierwszym**
zgłoszeniem po drużynie odtworzonej z wznowionego turnieju (`RoomKeys::unclaimedTeams` —
zbiór Redisowy, `SREM` przy pierwszym trafieniu). To drugie jest konieczne:
wznowienie zakłada **nowy** pokój z nowym kodem, więc żadne ciasteczko z wczoraj
nie może pasować, a mimo to gracz musi móc wrócić pod swoją nazwą.

**Mercure: `/host/{code}/tablo` poszerza topiki, gdy pyta o nie sam host.**
Ciasteczko `mercureAuthorization` jest jedno na przeglądarkę (nazwa i ścieżka na
sztywno w protokole Mercure) — gdyby host otworzył tablicę wyników w drugiej
karcie z węższym zestawem topików niż ekran główny, nadpisałby ciasteczko i po
najbliższym reconnekcie TV przestałby dostawać prywatny topik hosta (podgląd
hasła) bez żadnego komunikatu. Anonimowy widz (skądinąd cały sens `/tablo` —
punkty są jawne, nie trzeba tokenu) dostaje wciąż tylko wąski, publiczny topik.

**Jeden panel treści (`/panel`, EasyAdmin), nie dwa.** Poprzednia sesja zostawiła
`/panel` i stary `/admin` współistniejące — `/admin` obsługiwał jedyną rzecz,
której EasyAdmin jeszcze nie umiał: wgrywanie plików audio. Domknięte: nowe pliki
lądują pod `var/media/{RRRR}/{MM}/{slug}-{contenthash}.{rozszerzenie}` (wg daty
wgrania, decyzja użytkownika — nie wg kategorii, bo `FileField::setUploadedFileNamePattern()`
przyjmuje closure z encją, ale ta jest przechwytywana PRZED wypełnieniem
formularza, więc `category` nie musi być jeszcze ustawiona; wzorzec trzyma się
wyłącznie placeholderów zależnych od samego pliku). `Track::validateAudioSource`
i `Category::validateGames` (oba `#[Assert\Callback]`) przenoszą na encje reguły,
które wcześniej pilnował wyłącznie stary `ContentManager` — "plik ALBO link,
nigdy oba, nigdy żadne" i "kategoria potrzebuje choć jednej gry"; regułę "melodia
= zawsze dokładnie `['melodia']`" **normalizuje cicho kontroler**
(`CategoryCrudController::normalizeGames()`), bo to nie błąd użytkownika, więc
nie ma prawa wyjść jako czerwony komunikat walidacji. `TrackAudioCleaner`
(`src/Content/`) zastąpił `AudioUploader::delete()` i
`ContentManager::deleteAudioIfUnused()` — jedno miejsce, wołane zarówno przy
kasowaniu nagrania, jak i całej kategorii z nagraniami w środku. `/admin` zostaje
wyłącznie jako `login()`/`logout()` — wspólna sesja i wspólne hasło dla obu
adresów, `AdminPanelGuard` odsyła tam kogokolwiek niezalogowanego pod `/panel`.

**Przy okazji znaleziony i naprawiony bug sprzed tej sesji:** ani `Track`, ani
`Phrase` nie miały `setCategory()` — formularz EasyAdmina do zmiany kategorii
(pole `AssociationField`) wywalał się na "Could not determine access type for
property category" przy każdym submicie, bo Symfony Form zawsze wywołuje setter
dla pola mapowanego, nawet gdy wybór się nie zmienił. Dotyczyło to też **już
istniejącego** `PhraseCrudController` (formularz nowego hasła), nie tylko nowego
`TrackCrudController` — nikt wcześniej nie przeklikał NEW hasła na żywo. Oba
settery aktualizują teraz też odwrotną kolekcję (`Category::addTrack`/`addPhrase`
i `removeTrack`/`removePhrase`), żeby stara kategoria nie trzymała sieroty.

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
- JS: `npm run lint` (ESLint, `eslint.config.js`) po każdej zmianie w `assets/`.
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
  wymagane HTTPS i kamień 4 nie jest już niczym blokowany. Podstawy PWA są
  gotowe (`public/manifest.json`, `public/sw.js`, ikony 192/512 + apple-touch) —
  aplikacja instaluje się na ekran, ale worker **niczego nie cache'uje**
  (`fetch` leci prosto do sieci). Zostaje pytanie odwrotne: czy gra ma nadal
  działać **bez internetu** w LAN — cache'owanie Tablera (z CDN) albo assetów
  gry przesądziłoby to bez rozmowy, więc worker świadomie tego nie robi.
  Pod adresem IP bez TLS worker i tak by się nie zarejestrował. Tryb LAN jest
  wciąż wspierany konfiguracją (`APP_BIND=0.0.0.0`, `MERCURE_PUBLIC_PORT=3010`).
- ~~**Timer a rozjazd zegarów (§8).**~~ **Rozstrzygnięte przy kalamburach:**
  event niesie parę `{deadline, serverNow}`, telefon liczy z niej offset zegara,
  a o wygaśnięciu i tak decyduje serwer (`GameTimer::expired()`).
- **Autoryzacja przycisków fizycznych (§10).** ESP32 strzela w ten sam endpoint,
  ale nie ma tokenu drużyny — potrzebny sekret per urządzenie.

### Następne kroki

1. **Cache offline w PWA** — dopiero po decyzji o trybie LAN (patrz wyżej);
   instalowalność sama w sobie już działa.
2. **Przyciski fizyczne (kamień 7)**.
3. ~~**Państwa-Miasta**~~ — **gotowe**. Kafelek „wkrótce" zniknął sam, bo lista
   filtruje się po zarejestrowanych typach — moduł nie dotknął rdzenia ani
   jednym wierszem. Rubryki (`Kolumny::PRESETS`) siedzą w kodzie, nie w słowniku:
   to nagłówki tabeli, a nie treść do zgadywania.

### Znane braki i długi

- **Frontend Dutcha nie był puszczony na żywo w przeglądarce.** Backend przechodzi
  28 testów przez prawdziwy silnik i Redis (tajność rąk, tury, punktacja, kara,
  odsłonięcie, narastanie rankingu), a kontrolery/szablony trzymają się wzorca
  pozostałych gier i przechodzą lint + rejestrację AssetMappera — ale pętli tur
  na dwóch telefonach + TV jeszcze nikt nie przeklikał. To pierwsza rzecz do
  sprawdzenia. Poza wariantem świadomie: jokery i moc króla (czerwony-0/czarny-13).
- **Widok telefonu nie był oglądany na prawdziwym urządzeniu** — tylko w headless
  Chrome, który kłamie o `vw`. Przy pierwszym teście z gośćmi to pierwsza rzecz
  do sprawdzenia.
- **Limit wgrywania**: `post_max_size` bywa niższe niż `upload_max_filesize`
  i to ono decyduje. Panel pokazuje czytelny komunikat, ale limit trzeba podnieść
  w `php.ini` pod dłuższe nagrania.
- **Punkty melodii (1 pkt), państw-miast (dziesiątki) i koła (setki) trafiają
  na to samo tablo.** Odkąd
  „Zmień grę" zostawia punkty, widać to na każdym wieczorze z dwiema grami:
  setki z koła przykrywają wszystko inne. Turniej z wielu gier wymagałby
  normalizacji.
- **Rundę państw-miast kończy zegar ekranu hosta**, który po odliczeniu melduje
  `finish` — serwer to weryfikuje, ale przy zamkniętym TV runda stoi w `ending`
  do ręcznego zakończenia. Tak samo działają kalambury.
- **Słownik nie zna trudności per gra.** `difficulty` opisuje, jak trudno hasło
  *pokazać* (kalambury); koło używa go tylko do sortowania. Hasło łatwe do
  pokazania bywa nudne na planszy i odwrotnie — jeśli zacznie przeszkadzać,
  trzeba drugiego pola albo tagów.
- **Kategorie nie mają zmiany kolejności pozycji w panelu** — `position` istnieje
  w encjach i jest respektowane, ale UI go nie wystawia.
- **Ikony PWA są tymczasowe** — wygenerowana gwiazda z logotypu, nie prawdziwy
  branding. Wystarcza do instalowalności, ale warto podmienić na docelowy design.
