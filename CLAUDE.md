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
| Ostatnia litera | **backend gotowy i przetestowany**, frontend niesprawdzony na żywo (poza planem §13) |
| Kalambury rysowane | **backend gotowy i przetestowany**, frontend niesprawdzony na żywo (poza planem §13) |
| 8. Panel treści | **gotowy** (zamiast `app:deck:sync` z YAML) |

Ponadto: panel administracyjny `/panel` (EasyAdmin), którego spec nie przewidywała —
`/admin` zostaje jako wspólne logowanie/wylogowanie.

415 testów, PHPStan na poziomie `max` bez błędów. Symfony 7.4, PHP 8.3.

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

Do gry „Ostatnia litera" (sprawdzanie słów) potrzebny jest hunspell z polskim
słownikiem — bez niego gra dalej działa, ale KAŻDE słowo trafia do hosta jako
„niepewne" (patrz `HunspellWordJudge`):

```bash
apt install hunspell hunspell-pl
```

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
src/Game/OstatniaLitera/ moduł „Ostatnia litera" (WordJudge, HunspellWordJudge)
src/Game/KalamburyRysowane/ moduł „Kalambury rysowane" (rysowanie na tablecie,
                     jako drugim ekranie hosta - patrz decyzje niżej)
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

**Melodia: zła odpowiedź wyklucza TĘ drużynę z bieżącego utworu, nie tylko
kończy jej turę.** Poprzednia wersja świadomie NIE wykluczała - komentarz
w kodzie mówił wprost "przy stole rozstrzyga to host, a nie regulamin".
Użytkownik zmienił zdanie po pierwszych testach: kto się pomyli, czeka na
kolejny utwór, zamiast móc próbować w kółko na tym samym, aż się uda.
`wrongTeams` (lista id drużyn) żyje w kontekście gry, dopisywana w `judge()`
przy `correct: false`, czyszczona wyłącznie w `nextTrack()` (nowy utwór to
czysta karta) - `rearm()` (ten sam utwór wraca dla POZOSTAŁYCH) świadomie jej
nie rusza. `buzz()` odrzuca wykluczoną drużynę wcześniej niż `BuzzerArbiter`
w ogóle się o nią dowie (`BuzzResult::excluded()` - nowy, trzeci powód
odmowy obok `stale`/`locked`, rozstrzygany przez MODUŁ gry, nie przez
arbitra, bo "kto już próbował" to co innego niż "kto pierwszy"). Wykluczona
drużyna dostaje powiadomienie **od razu po ponownym uzbrojeniu** (`arm()`/
`rearm()` zwracają `excludedMessages()`), nie dopiero po naciśnięciu
przycisku - inaczej jej telefon mylnie pokazywałby aktywny "NACIŚNIJ!" mimo
że każda próba i tak skończy się odmową. Host widzi `wrongTeams` w
`hostView()` (podpowiedź "Już próbowali: X, Y" w `armed`), gracze - przez
`excluded` w `playerView()` (stan początkowy renderowany w Twigu, na wypadek
odświeżenia w trakcie wykluczenia).

**Kalambury rysowane: tablet w trakcie rysowania pokazuje TYLKO płótno.**
Zgłoszenie użytkownika po realnym teście: zegar, punkty, przełącznik roli
i podpowiedzi tekstowe na tablecie odciągały uwagę rysującego od samego
rysowania i zabierały miejsce płótnu. `rysowanki_host_controller.js` liczy
`tabletDrawing = 'tablet' === this.role && 'drawing' === view.state` w
`#render()` i na tej podstawie chowa `.rys-role-switch`, `.game-foot` oraz
podpowiedź (`.hint`) - WYŁĄCZNIE na tablecie i WYŁĄCZNIE w trakcie rysowania;
inne stany (`dealt`, `idle`) i projektor nie tracą nic. **Jedyny wyjątek**
(świadomie zostawiony na życzenie użytkownika: "no może tylko pokaż/ukryj
hasło") to `.rys-word-toggle` - hasło jest domyślnie SCHOWANE (rysujący sam
je przed chwilą wybrał, nie musi na nie patrzeć cały czas), przycisk "👁 Pokaż
hasło" pokazuje je na żądanie. Zegar **wizualnie** znika (`clockTargets`/
`pointsBadgeTargets` dostają `hidden = tabletDrawing`), ale sam interwał
(`tick()`) leci dalej - to on zgłasza serwerowi `timeout`, gdy czas minie,
a tablet bywa jedynym otwartym ekranem, gdyby projektor akurat nie żył;
ukrycie DOM-u nie może ukryć też tej logiki.

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

**Host może usunąć drużynę z pokoju (`POST /room/{code}/team/remove`).**
`RoomManager::removeTeam()` czyści drużynę z Redisa (hasz drużyn, wynik,
`unclaimedTeams`) i **świadomie nie dotyka stanu żadnej trwającej gry** — to nie
przeoczenie, tylko wykorzystanie istniejącej odporności na „drużyny-duchy",
udokumentowanej już przy `TurnManager` (drużyna, która zniknie z pokoju
w trakcie gry, zostaje w kolejce tur jako duch, aż host jawnie przekaże turę
dalej). Pisanie osobnej ścieżki czyszczącej kontekst każdego modułu z osobna
byłoby dokładnie tą robotą, którą `TurnManager` już rozwiązał raz, ogólnie.
Endpoint jest fetch-owy jak reszta akcji gry, więc bez CSRF (patrz „CSRF tylko
na klasycznych formularzach" wyżej), rozgłasza `team_removed` do całego
pokoju — telefon usuniętej drużyny łapie to po swoim `teamId`, zrywa SSE
i pokazuje pełnoekranową nakładkę blokującą dalszą grę (drużyny i tak nie ma
już w Redisie, każde kolejne zgłoszenie i tak dostałoby odmowę). Nakładka
działa wyłącznie na żywo, przez event — telefon, który akurat przeładował
stronę PO usunięciu, nie zobaczy „zostałeś usunięty", tylko zwykły formularz
dołączania (serwer nie odróżnia „nigdy nie dołączył" od „usunięty i strona
odświeżona" — drużyny po prostu już nie ma). Świadomy kompromis: telefon
otwarty i nietknięty w trakcie usunięcia dostaje jasny komunikat, telefon
odświeżony już nie - ale i tak nie ma czym dalej grać.

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

**Ostatnia litera: wyścig do progu punktów z eliminacją jako siatką
bezpieczeństwa, hunspell zamiast udawanego słownika.** Druga wersja zasad
(pierwsza miała płaskie ±1 „kredytu” bez progu zwycięstwa - w historii gita).
Host ustala przy starcie punkty startowe i próg zwycięstwa (musi być wyższy
niż start, walidowane w `start()`) - pierwsza drużyna, która osiągnie próg,
wygrywa natychmiast, niezależnie od tego, ile innych drużyn jeszcze gra.
Trafienie jest warte TYLE PUNKTÓW, ILE SEKUND ZOSTAŁO na zegarze w chwili
odpowiedzi (10 s na słowo, odpowiedź po 7 s = 3 punkty; `ceil()`, żeby zgadzało
się z tym, co drużyna WIDZIAŁA na wyświetlaczu) - im szybsza odpowiedź, tym
więcej warta. Spóźnienie kosztuje stałą karę (`TIMEOUT_PENALTY`). Zero punktów
lub mniej = odpadnięcie - to ta sama siatka bezpieczeństwa co poprzednie
kredyty, tylko waluta jest teraz zmiennej wartości, nie płaskim ±1 (użytkownik
świadomie wybrał zachowanie eliminacji OBOK wyścigu do progu, nie zamiast
niego). Słowo sprawdza `HunspellWordJudge` (`apt install hunspell hunspell-pl`,
patrz „Pierwsze uruchomienie") - rozpoznane wchodzi od razu, nierozpoznane
zatrzymuje rundę w stanie `judging` i czeka na werdykt hosta (ten sam duch co
`Wykaz` w Państwach-miastach: serwer nie udaje, że zna cały język polski,
więc ostatnie słowo należy do człowieka przy stole). Brak hunspella nie wywala
gry - `WordJudge` jest interfejsem właśnie po to, żeby awaria/brak pakietu
systemowego degradowała do "każde słowo idzie do sędziego", nie do błędu 500.
Kolejność drużyn (`order`/`turn` w kontekście, z pomijaniem wyeliminowanych
przy `nextAlive()`) jest własna, nie przez `TurnManager` - ten robi gołą
modulo-`INCR` po stałej liście i nie umie pomijać nikogo (ten sam powód, dla
którego Dutch też ma własną kolejkę).

Punkty za trafienie liczą się w CHWILI PODANIA SŁOWA, nie w chwili werdyktu
hosta - inaczej zwlekający host oszukiwałby drużynę z należnych punktów.
Dla ścieżki sędziowskiej (`submit` → `hold` → `rule`) zegar stoi przez całą
naradę, więc wartość trzeba zapamiętać w chwili zgłoszenia (`pendingPoints`
w kontekście) i użyć jej później - świeże przeliczenie po stronie `rule()`
dałoby zero, bo `GameTimer` jest już wyczyszczony. Wspólne tablo (`Scoreboard`)
JEST punktacją wprost, bez osobnego mirrorowania jak przy poprzednich
kredytach (tam `credits` w kontekście i tablo były dwoma zapisami tej samej
liczby, bo tablo umiało tylko +1/-1) - `total` z odpowiedzi `Scoreboard::add()`
rozstrzyga od razu i próg zwycięstwa, i eliminację, jedno źródło prawdy
zamiast dwóch zsynchronizowanych. `start()` zasiewa każdą drużynę punktami
startowymi, żeby drużyna, która jeszcze nic nie zrobiła, nie wyglądała na
"0 punktów". **Konsekwencja:** kilku wyeliminowanych w jednej rundzie remisuje
na tablie (wszyscy przy zerze) - `Tournament::placements()` obsługuje remisy
tak samo jak wszędzie indziej. Druga konsekwencja: `restart()` w tym samym
pokoju **resetuje** tablo do punktów startowych zamiast akumulować wynik
z poprzedniej rundy (inaczej niż Kalambury/Państwa-miasta) - bo tu wynik MA
być zawsze zgodny z aktualną punktacją, a ta też resetuje się przy każdym
`start()`.

**Ostatnia litera: wymagana litera wpisana w pole, nie tylko wyświetlona obok.**
Na telefonie klawiatura systemowa zakrywa dolną część ekranu, więc panel
z wymaganą literą (nad polem tekstowym) znikał z widoku dokładnie wtedy, gdy
był potrzebny - zgłoszenie użytkownika. Zamiast przebudowywać layout pod
nieznaną wysokość klawiatury, `ostatnia_litera_player_controller.js` wpisuje
literę (albo dwuznak: `ni/ci/si/zi` dla `ń/ć/ś/ź` - w realnych polskich
słowach na starcie to on występuje, nie goła miękka spółgłoska) prosto do
pustego pola przy PRZEJŚCIU na "moja tura" (`wasTypable` false→true), z
kursorem za wpisanym tekstem. Gracz dopisuje resztę słowa tam, gdzie i tak
patrzy - walidacja pozostaje wyłącznie po stronie serwera, to czysta
podpowiedź. Warunek przejścia (nie każdy render) jest celowy: inaczej
kasowałoby to, co gracz właśnie pisze, przy każdym niepowiązanym evencie.
Litera nad polem przeniesiona dodatkowo obok zegara (`.ol-clock-row`) - drugie
wzmocnienie tej samej naprawy, na życzenie użytkownika, który dalej jej nie
widział mimo podpowiedzi w polu (dwie niezależne wskazówki są tańsze niż
zgadywanie, która zadziała na konkretnym telefonie).

**Ostatnia litera: słowa wersalikami, cały łańcuch widoczny na TV.** Pole
gracza wymusza wielkie litery na bieżąco (`normalizeInput()`, zdarzenie
`input`, kursor zachowany przez `setSelectionRange`) - walidacja i tak jest
case-insensitive po stronie serwera, to czysta czytelność z dystansu (ten sam
powód co wersaliki `secret-word` w kalamburach). Ekran hosta pokazuje `usedWords`
w całości jako `.ol-word-chain` zamiast samego ostatniego słowa - duplikaty
były już od początku odrzucane przez `checkWord()` (patrz wyżej), to wyłącznie
dokłada im widoczność, żeby cały stół widział historię, nie tylko serwer.

**Ostatnia litera: powtórka słowa ma własny sygnał na TV, reszta odrzuceń nie.**
`checkWord()` i tak odrzuca duplikat (patrz wyżej) - zwykły błąd HTTP na
telefonie gracza wystarczyłby, ale powtórka to moment, który cały stół chce
zobaczyć i usłyszeć naraz, nie tylko ta jedna drużyna. `submit()` porównuje
zwrócony komunikat z `self::DUPLICATE_MESSAGE` (nie parsuje wszystkich
przyczyn odrzucenia) i przed rzuceniem wyjątku rozgłasza `word_duplicate`
przez `Publisher::toHost()` wstrzyknięty wprost do modułu - dokładnie ten sam
wzorzec co `Scoreboard`, które publikuje samo, niezależnie od listy
`Message` zwracanej z `handleAction()`. Musi być `toHost`, bo wyjątek i tak
przerywa `GameEngine::handle()` przed wysłaniem zwróconych `Message` - gdyby
moduł nie publikował sam, host nigdy by się nie dowiedział. Host reaguje
dźwiękiem `wrong` (ten sam brzęczyk porażki co Koło) i komunikatem w
`#flash()`. Za krótkie słowo czy zła litera startowa zostają tylko błędem na
telefonie - to pomyłki jednej drużyny, nie wydarzenie dla całego stołu.

**Ostatnia litera: własne, większe cyfry zegara na TV** (`.clock-huge`,
osobna klasa modyfikująca `.clock-value`, nie zmiana wspólnej reguły) - ekran
tej gry poza zegarem i literami nie pokazuje nic innego (żadnej planszy ani
hasła), więc stać go na więcej niż Kalambury czy Koło, którym reszta
interfejsu też potrzebuje miejsca.

**Kalambury rysowane: tablet do rysowania to DRUGI ekran hosta, nie nowa rola
urządzenia.** Gra powstała z myślą o dużym spotkaniu rodzinnym: telewizor/rzutnik
pokazuje grę całej sali, a jedna, fizycznie przekazywana osobie rysującej
tabletka jest płótnem. Rozważona była alternatywa "tablet loguje się jak
telefon drużyny" (istniejący `RoomAccess::team()`), ale odpadła: telefon
drużyny jest **na stałe** związany z jedną drużyną (ciasteczko + token
subskrypcji Mercure wypieczony w JWT przy wejściu), a tablet wędruje między
drużynami co rundę - przełączanie, KTÓREJ drużyny prywatny topik subskrybuje,
w trakcie gry nie jest tu nigdzie wspierane. Zamiast tego tablet loguje się
**tym samym tokenem hosta** co telewizor, przez istniejący już mechanizm
`HostController::screen()` (`?t={hostToken}`, wcześniej służący wyłącznie do
przenoszenia JEDNEGO ekranu hosta z laptopa na telewizor) - `JoinLink::hostTransferUrl()`
dokłada do niego tylko generowanie QR. Oba ekrany widzą więc identyczny
`hostView()` (hasło i oferty trudności włącznie - `KalamburyRysowaneGame::hostView()`
nie robi między nimi różnicy, tak jak zwykłe Kalambury już dziś oddają hasło
do `hostView()` i tylko szablon je chowa), a którą rolę pełni KONKRETNE
urządzenie, decyduje wyłącznie `localStorage` tego urządzenia
(`rysowanki_host_controller.js`, klucz `rys-role-{kod}`) - CSS (`[data-role]`)
chowa resztę. **Konsekwencja:** link/QR do przejęcia roli hosta daje pełną
kontrolę nad pokojem (start/stop/reset/wynik), więc szablon pokazuje go
wyłącznie w bramce wyboru roli, z jawnym ostrzeżeniem - nie wolno bez pytania
przenieść tego QR na ekran widoczny dla całej sali ani dodać go do żadnego
ogólnego (nie-tylko-tej-gry) miejsca w UI.

**Kalambury rysowane: kto skanuje QR transferu, dostaje ekran rysowania od
razu, bez bramki wyboru roli.** Zgłoszenie użytkownika: bramka "Ten ekran
będzie... Projektorem / Tabletem" po zeskanowaniu kodu opisanego wprost jako
"Na TABLECIE zeskanuj ten kod" była zbędnym, mylącym powtórzeniem wyboru,
który skanujący już podjął samym aktem skanowania. `JoinLink::hostTransferUrl()`
przyjmuje teraz opcjonalny `$role` doklejany jako zwykły parametr `role` w
URL-u - celowo NIE wie, co ta wartość znaczy (żaden `if` po stronie rdzenia),
to czysty passthrough. `HostController::screen()` generuje `hostTransferQr`
z `role: 'tablet'` wprost (jedyny dzisiejszy konsument tego QR-a - gdyby
kiedyś powstała druga gra z tym samym mechanizmem transferu, ale INNYM
znaczeniem roli, ta stała wartość wymaga przejrzenia, nie jest generycznym
rozwiązaniem na zapas). Przekierowanie zdejmujące token z adresu (ten sam
mechanizm co zwykłe "laptop → telewizor") przepuszcza `role` dalej bez zmian -
rdzeń nie interpretuje wartości, tylko ją niesie. Dopiero `rysowanki_host_controller.js`
odczytuje `?role=` z adresu **po** przekierowaniu i, jeśli to `tablet` albo
`projector`, stosuje ją od razu (`#setRole`) zamiast czekać na kliknięcie w
bramce - i czyści adres (`history.replaceState`), żeby ewentualne odświeżenie
polegało już wyłącznie na `localStorage`, tak jak przy ręcznym wyborze roli.

**Kalambury rysowane: rysunek pomija `GameEngine` - nowy prymityw rdzenia
`POST /room/{code}/game/live`.** Kreska potrafi iść kilkanaście razy na
sekundę; przepychanie KAŻDEGO punktu przez `RoomLock` (blokada Redisowa całego
pokoju) i pełny cykl FSM+ctx `GameEngine::handle()` byłoby marnotrawstwem bez
żadnej korzyści - rysunek nie jest stanem, o który rozstrzyga serwer (jak
zegar czy wynik), tylko czystą dekoracją, jak animacje GSAP w Dutchu. Trasa
żąda wyłącznie tokenu hosta (ten sam, którym loguje się tablet - patrz wyżej)
i od razu woła `Publisher::broadcast()` z przepuszczonym `type`+`x`+`y`
(`GameController::live()`). Celowo w **rdzeniu**, nie w module gry - ten sam
duch co `/score` ("Punktacja jest prymitywem rdzenia, nie mechaniką gry"):
kolejna gra chcąca lekki, dekoracyjny strumień (inne rysowanie, animacja na
żywo) dostaje go za darmo. Renderowanie kresek na obu ekranach (tablet i
telewizor) idzie WYŁĄCZNIE z odebranych zdarzeń SSE, nigdy z lokalnego
zdarzenia wskaźnika wprost - jedna ścieżka rysowania dla obu ról, bez
podwójnego rysowania tej samej kreski na urządzeniu, które ją wysłało.

**Kalambury rysowane: rysujący wybiera trudność, punkty topnieją z czasem.**
`deal()` oferuje trzy nieużyte hasła naraz - po jednym z `Phrase::DIFFICULTIES`
(`latwy`/`sredni`/`trudny`) - i to on decyduje, ile punktów jest do zdobycia
(`BASE_POINTS`: 3/5/8). Wszystkie trzy trafiają do `_played` od razu przy
ofercie, nie dopiero przy wyborze - odrzucona oferta (`skip`) nie wraca
w kolejnej turze, tak samo jak `skip` w zwykłych Kalamburach. Pierwsze 10
sekund (`GRACE_SECONDS`) to pełna pula; potem `pointsFor()` liczy topnienie
liniowo względem pozostałego czasu, nigdy poniżej 1 punktu - trafienie
w ostatniej sekundzie ma się wciąż opłacać. Telewizor i tablet pokazują TĘ
SAMĄ liczbę na bieżąco (kopia `pointsFor()` w JS, skomentowana jako lustro
metody PHP - dwie kopie muszą się zgadzać, bo inaczej malejący licznik na
ekranie kłamałby o faktycznym wyniku), ale liczy się WYŁĄCZNIE wynik policzony
na serwerze w chwili `guessed()`, z `GameTimer::remaining()` sprzed
wyczyszczenia zegara - dokładnie ten sam duch co reszta gier w tej appce
("wynik zapada dopiero w chwili, gdy host naciśnie"). **Etykieta trudności
bywa tylko stawką punktową, nie prawdą o haśle.** Istniejący słownik ma
**jedną, uniform trudność na całą kategorię** (patrz "Słownik nie zna
trudności per gra" niżej), więc dopasowanie "poziom = dokładnie ta trudność"
trafiało niemal zawsze tylko w JEDEN slot z trzech - rysujący widział jedną
ofertę zamiast trzech (zgłoszenie użytkownika po pierwszym realnym teście:
"Losujesz 3 hasła - widać tylko 1!"). `PhraseDeck::drawChoices()` robi więc
dwa przebiegi: najpierw dokładne trafienia poziomu (przyda się, gdy ktoś
kiedyś zróżnicuje trudność w obrębie kategorii), a drugi przebieg dobiera
z reszty puli KAŻDY jeszcze pusty slot, żeby rysujący zawsze miał trzy realne
hasła do wyboru - nawet jeśli wszystkie trzy formalnie dzielą tę samą
"prawdziwą" trudność. Pusty slot w wyniku znaczy odtąd wyłącznie "hasła
w kategorii się skończyły", nigdy "ten poziom nie istnieje".

**Kalambury rysowane: „Rysuj na całym ekranie" łączy DWA niezależne chromy,
nie jeden.** Życzenie użytkownika po pierwszych testach: płótno miało zająć
NAPRAWDĘ cały ekran, nie tylko tyle, ile już dawał wcześniejszy container-query
fit. Przycisk (`.rys-focus-toggle`, zawsze widoczny w rogu - poza siatką
`.game-panel` przez `position:absolute`, tak jak `.rys-role-gate`) chowa
WŁASNY chrom gry (`.rys-rail`/`.rys-hud`/`.game-foot` - klasa `rys-focus` na
`.rys-panel`), ale to tylko połowa ekranu hosta: pasek u góry i panel wyników
po lewej (`.tv-bar`/`.tv-teams`) żyją POZA `.rys-panel`, w kontrolerze
`room-board` (`host/screen.html.twig`). Zamiast wołać `requestFullscreen`
drugi raz z osobna (dwa wywołania nakładałyby się i psuły stan `tv-focus` po
stronie `room-board`), `rysowanki_host_controller.js` klika WŁASNY przycisk
`room-board#toggleFocus` z paska - dokładnie ten gest, jaki zrobiłby host
ręcznie. **Pułapka, na którą to wpadło**: `.tv.tv-focus` ścieśnia `.tv` z
powrotem do JEDNEGO wiersza (`grid-template-rows: minmax(0,1fr)`), a osobna,
starsza reguła (`.tv:has(.rys-panel[data-role="tablet"]) .tv-body { grid-row: 2 }`,
patrz Pułapki niżej) zakłada DWURZĘDOWĄ siatkę - w trybie `tv-focus` wiersza
2 po prostu nie ma, więc `.tv-body` trafiał do niejawnie dołożonego wiersza
na dole, a canvas spadał pod widoczny ekran. Naprawka: `.tv.tv-focus:has(.rys-panel[data-role="tablet"]) .tv-body { grid-row: 1 }`
z wyższą specyficznością (dokłada `.tv-focus` do selektora), wygrywa
niezależnie od kolejności w źródle. Wniosek ogólny: każde jawne przypięcie
do konkretnego numeru wiersza/kolumny (`grid-row`/`grid-column`) trzeba
sprawdzić pod KAŻDYM trybem, który zmienia `grid-template-rows`/`-columns`
tego samego przodka - liczba wierszy nie jest stała tylko dlatego, że
wygląda na stałą w jednym trybie.

**Kalambury rysowane: płótno 16:9, nie kwadrat - bo tablet ZAWSZE stoi
w poziomie.** Kwadrat (1:1, patrz Pułapki: "Największy kwadrat...") był
kompromisem MIĘDZY dwiema różnymi orientacjami (tablet w pionie, projektor
w poziomie) - żadne stałe proporcje nie faworyzowałyby wtedy żadnego z dwóch
ekranów. Użytkownik zdecydował, że w tej grze tablet stoi w poziomie zawsze,
więc kompromis stał się zbędny: oba ekrany są już tego samego, szerokiego
typu, i 16:9 wykorzystuje realne miejsce lepiej niż kwadrat na obu. Sama
technika (container query na `.rys-canvas-wrap`, `width: min(...)` na
`.rys-canvas-frame`) zostaje identyczna - zmienia się tylko wzór: przy
kwadracie oba wymiary kontenera są równoważne (`min(100cqw, 100cqh)`), przy
16:9 trzeba przeskalować jeden z nich współczynnikiem proporcji
(`min(100cqw, calc(100cqh * 16 / 9))`), bo inaczej porównanie „która strona
kontenera jest mniejsza” dalej dawałoby kwadrat. **Konsekwencja: założenie
„tablet zawsze w poziomie” nie jest wymuszone w kodzie** - telefon/tablet
obrócony do pionu dostanie mocno oklejkowany (letterboxed), wąski pasek 16:9
zamiast wykorzystania szerokości ekranu; to świadomie zaakceptowane ryzyko,
nie błąd.

**Ekran TV — layout v3: nazwa gry w pasku, nie w szynie gry.** Pasek w trakcie
gry ma trzy strefy: logo + pigułka z nazwą i ikoną gry (kolor z `--g-*`, ikona
z `game/{typ}/icon.html.twig`, ta sama co na ścianie), dołączanie (adres + kod;
klik otwiera duży QR) i ciche przyciski hosta (Reset i pełny ekran jako same
ikony). `.game-rail > .game-name` jest przez to schowany, ale **zostaje
w przepływie z zerową wysokością** - szyny gier mają siatkę wierszy liczoną
z tytułem na pierwszym miejscu (`auto / 1fr / auto`), a `display:none`
przesunęłoby ilustrację do wiersza `auto` i koło skurczyłoby się do zera.
Tablo drużyn: jeden rząd `miejsce | nazwa | wynik`, szersza kolumna
(`clamp(14rem, 19vw, 25rem)`), nazwa łamie się tylko na spacjach, ✕ wisi w rogu
i pokazuje się pod kursorem. Wszystkie dawne, nakładające się definicje
`.tv-teams .team` zastąpiła jedna. Ściana wyboru gry (`lobby_v2`): QR i kod
przeniesione z rogu do lewej kolumny jako duży blok nad listą graczy, przyciski
hosta - z dołu tej kolumny do paska u góry.

**Motyw „Gierkownia” wg makiety `var/gierkownia.png`** (życzenie użytkownika:
„nowy layout, nie kosmetyka”). Cała skóra siedzi w osobnym arkuszu
`assets/styles/gierkownia.css`, ładowanym PO `app.css` - app.css trzyma układ
(siatki pilnujące, żeby gra mieściła się w TV), gierkownia.css wygląd: tło
„salon wieczorem” z samych gradientów, fonty Baloo 2 / Nunito / Caveat (**nie** Lilita One z makiety - nie ma polskich liter z ogonkami; nowy font zawsze sprawdź na „ąćęłńóśźż”)
(Google Fonts, z sieci jak Tabler - bez internetu spadają do systemowych),
przyciski z grubym spodem, fioletowe panele. Logo to tekst + inline SVG
(`templates/_brand/logo.html.twig`, wariant `small`). Każda gra ma kolorową
ilustrację `templates/game/{typ}/art.html.twig` (zapas: `game/_art.html.twig`) -
**nowa gra powinna dostać własny `art.html.twig`**; stare `icon.html.twig`
zostały dla ekranu `?v=1`. Paleta `--g-*` w app.css przestawiona na kolory
z makiety, a sztywno wpisane stare kolory gier (kaseta quizu, rewersy Dutcha,
kropki państw-miast, ludzik kalambur) podpięte pod te zmienne. Ściana wyboru
gry (`lobby_v2`) ma układ makiety: logo + „Losuj grę” (nowe:
`game-starter#startRandom` losuje spośród kafelków na ekranie, więc nie zna
gier z nazwy) + „Wznów turniej”, kafelki 4×2, po prawej kod+QR, instrukcja
i turniej, na dole pas graczy z awatarami. Strona startowa i telefon gracza
dostały tę samą skórę.

Ekrany gier to „scena studia”: każdy `.game-panel` ma `--c` = kolor swojej
gry (mapa na początku sekcji SCENA STUDIA w gierkownia.css), z którego idą
reflektory i konfetti (pseudo-elementy `::before/::after` z `z-index: -1` -
**nie** elementy w DOM, bo siatka rail/main/foot nie znosi dodatkowych
dzieci), pasek tablicy, plakietki i zegar jako okrągła tarcza. Plansza hasła
(koło, kalambury) to tablica z lampkami; rozmiar kafelka liczony też z `vw`,
bo wyraz się nie łamie, a najdłuższe hasła słownika mają 11-12 liter.
`.game-main` ma `min-width: 0` - bez tego długie hasło rozpychało kolumnę
poza panel. Kalambury rysowane dostały `data-state` na panelu (jedyna zmiana
w JS), żeby CSS mógł zrobić scenę oczekiwania na wybór hasła. **Pułapka:**
ogólna skórka `.tv .btn` zjada kolory przycisków, które gra koloruje sama
(oferty trudności w rysowankach) - takie przyciski potrzebują własnej reguły
o wyższej specyficzności w gierkownia.css.

---

## Pułapki, na które już wdepnąłem

**Ściana wyboru gry (`lobby_v2.html.twig`) liczyła kolumny ręcznie w Twigu -
parzysta/nieparzysta liczba gier.** Działało tylko dopóki gier było mało: przy
7 zarejestrowanych (nieparzysta) wychodził jeden rząd siedmiu wąskich,
pionowych kafelków zamiast mozaiki - zgłoszenie użytkownika ("słabo to
wygląda"). Każda kolejna gra psuje ten rachunek na nowo, więc zamiast liczyć
`n % 2` w Twigu, `.l2-wall` dostał `grid-template-columns: repeat(auto-fit,
minmax(...))` - przeglądarka sama dobiera liczbę kolumn z szerokości ekranu,
niezależnie od tego, ile gier akurat jest zarejestrowanych. Rozmiary kafelka
(`.l2-panel`/`.l2-emoji`/`.l2-name`) tunowane pod scenariusz z kilkoma rzędami
(bezpieczniej za małe niż wystające poza kafelek) - auto-fit nie mówi z góry,
ile rzędów wyjdzie, więc nie ma już osobnego wariantu CSS na "dwa rzędy".

**`display: revert` nie przywraca reguły autora, tylko domyślny wygląd
znacznika w przeglądarce.** Przy chowaniu/pokazywaniu elementów wg roli
w kalamburach rysowanych (`.rys-only-tablet`) pierwsza wersja chowała przez
`display: none`, a "przywracała" przez `display: revert` - brzmi jak "cofnij
do tego, co było", ale naprawdę cofa do stylu BEZ arkuszy autora: dla zwykłego
`<div>` to zawsze `block`, nigdy `grid`/`flex`, niezależnie od tego, co
deklaruje własna klasa elementu (`.rys-choices { display: grid }`). Efekt:
trzy oferty trudności wracały jako pojedyncza kolumna, mimo że reguła "w
poziomie" była poprawna i w ogóle się nie odpalała. Naprawka: nie "pokazuj z
powrotem" niczego - warunkuj samo CHOWANIE przez `:not([data-role=...])`, żeby
w drugiej roli reguła po prostu nie dotyczyła elementu i jego własny
`display` obowiązywał bez przeszkód.

**Ukrycie sąsiada w CSS Grid z `grid-template-rows: auto minmax(0, 1fr)`
przesuwa resztę zawartości do złego wiersza.** Ukrycie `.tv-bar`
(`display: none`) na tablecie w kalamburach rysowanych miało oddać jego
miejsce reszcie ekranu - zamiast tego `.tv-body` (jedyny widoczny grid item
`.tv`) trafiał przez auto-placement do PIERWSZEGO, `auto`-wysokiego wiersza
(tego po `.tv-bar`), zamiast do drugiego, `minmax(0, 1fr)` - auto-placement
nie wie, że `.tv-body` "należy" do wiersza 2, po prostu wypełnia po kolei to,
co zostało. Cała gra kurczyła się do naturalnej wysokości treści, a pod
spodem zostawała pusta, czarna szpara wielkości nieużywanego drugiego
wiersza. Naprawka: jawne `grid-row: 2` na `.tv-body`, wymuszone tylko wtedy,
gdy `.tv-bar` faktycznie znika (`:has()`).

**Elementy w CSS Grid/Flex mają domyślnie `min-width: auto` - odmawiają
zawijania tekstu poniżej szerokości najdłuższego "słowa" w treści**, nawet
z `overflow-wrap`/`word-break` na dziecku. W kalamburach rysowanych długie,
dwuwyrazowe hasło ("Czerwony Kapturek") w wąskiej kolumnie oferty wypływało
poza kartę zamiast zawinąć się na spacji - `overflow-wrap: break-word` łamie
słowo W ŚRODKU, gdy naprawdę nie ma innego wyjścia, ale nie wymusza
zawinięcia dwuwyrazowej frazy, dopóki element-kontener ma nieograniczone
`min-width`. Naprawka: `min-width: 0` na elemencie z tekstem (nie tylko na
jego rodzicu-siatce).

**"Największy kwadrat, jaki zmieści się w dostępnym miejscu" NIE wychodzi
z samego `aspect-ratio` na pustym `<div>` w gridzie - potrzeba container
query.** Płótno w kalamburach rysowanych ma mieć te same proporcje (1:1) na
projektorze i tablecie, ale różny rozmiar (życzenie użytkownika). Dwie
pierwsze próby zawiodły: `place-items: center` + `aspect-ratio` na pustym
divie bez własnej treści nie ma z czego policzyć rozmiaru i spada do
zastępczych 300×150 (kwadrat drobny, mimo wolnego miejsca dookoła);
`place-items: stretch` z kolei IGNORUJE `aspect-ratio`, gdy oba wymiary są
"rozciągnij" - dostajesz prostokąt na całą komórkę, nie kwadrat. Zadziałało
dopiero `container-type: size` na rodzicu + `width: min(100cqw, 100cqh)` na
dziecku - to jawne, policzalne "bok kwadratu = mniejszy z dwóch wymiarów
kontenera", niezależne od tego, jak silnik CSS interpretuje `aspect-ratio`
przy braku innej podstawy do wyliczenia rozmiaru.

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

**Symfony w produkcji chowa `getMessage()` KAŻDEGO wyjątku za tekstem statusu
HTTP — niezależnie od typu wyjątku.** Znalezione przy „Ostatnia litera", ale
dotyczyło **każdej** gry: `IllegalActionException('Nie twoja kolej.')` wracał
do telefonu jako gołe `{"detail": "Bad Request"}`. Przyczyna:
`Symfony\Component\Serializer\Normalizer\ProblemNormalizer::normalize()` ma
wprost `'detail' => $debug ? $object->getMessage() : $object->getStatusText()`
— w `debug=false` **zawsze** bierze tekst statusu, bez wyjątku dla
`HttpExceptionInterface`, mimo że te są w tej aplikacji pisane właśnie z myślą
o pokazaniu ich treści graczowi. Testy HTTP w tym repo nigdy tego nie złapały,
bo `WebTestCase` startuje z `debug=true` — błąd wyszedł dopiero na prawdziwym
serwerze produkcyjnym. Naprawione `ApiExceptionSubscriber`
(`src/Core/Http/`): nasłuchuje `kernel.exception` z priorytetem wyższym niż
domyślny `ErrorListener` frameworka, i dla tras API (`ApiFormatSubscriber::isApiPath()`)
buduje odpowiedź JSON samemu, kładąc `getMessage()` do `detail` dla każdego
`HttpExceptionInterface` — nieoczekiwane błędy (nie-HTTP-wyjątki) zostają
ogólne, tak jak powinny. Pilnuje tego `ApiExceptionSubscriberTest`, budujący
`ExceptionEvent` ręcznie, żeby sprawdzić zachowanie niezależnie od trybu debug
całego jądra.

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

**Ustawienie `canvas.width`/`canvas.height` czyści całe płótno**, nawet gdy
wpisujesz tę samą wartość co poprzednio - odkryte przy rysunku w kalamburach
rysowanych (`rysowanki_host_controller.js`, `#resizeCanvas()`). Przeliczanie
rozdzielczości przy KAŻDYM renderze (odświeżenie leci po prawie każdym evencie
SSE) wymazywałoby rysunek w środku rundy. Rozdzielczość liczy się od nowa
wyłącznie przy przejściu płótna z ukrycia do widoczności i przy `resize` okna.

**`.game-panel` ma sztywne `grid-template-areas: "rail main" "foot foot"`
(app.css) - `.game-rail`/`.game-main`/`.game-foot` MUSZĄ być jego
BEZPOŚREDNIMI dziećmi.** Pierwsza wersja kalambur rysowanych owinęła je
w dodatkowy `<div class="rys-screen">` (żeby jednym `hidden` chować całą grę
przed wyborem roli ekranu) - i to złamało layout kompletnie: `.rys-screen`
nie miał żadnego `grid-area`, więc trafiał do siatki na oślep, a rail/main
w środku nie uczestniczyły w siatce w ogóle, tylko renderowały się zwykłym
blokiem w tym, co zostało. Efekt na żywo: „źle graficznie, nie ma rysowania"
(canvas dziedziczył zerowy/losowy rozmiar po zapadniętym opakowaniu) -
zgłoszenie użytkownika po pierwszym realnym teście. Naprawione wyniesieniem
nakładki wyboru roli (`.rys-role-gate`) z siatki przez `position: absolute;
inset: 0;` zamiast przez zagnieżdżenie - rail/main/foot wróciły jako
bezpośrednie dzieci `.game-panel`, nakładka po prostu przykrywa całość, dopóki
`hidden` nie zniknie. Wniosek ogólny: żaden nowy `<div>` nie może wejść
między `.game-panel` a jego trzy potomne strefy (`rail`/`main`/`foot`) w ŻADNEJ
grze - to samo dotyczyłoby `.kolo-panel`, `.panstwa-panel` itd.

**`.tv-teams .team` miał TRZY nakładające się definicje `grid-template-*` w
`app.css`, w różnych miejscach pliku - wygrywała ostatnia w kolejności źródła
(ta sama specyficzność selektora za każdym razem). Od layoutu v3 jest jedna,
ale wniosek poniżej dotyczy całego arkusza.** Dopisanie przycisku
usuwania drużyny do kafelka trafiło najpierw w NIEaktywną, wcześniejszą wersję
reguły - zmiana nic nie robiła na żywo, dopóki nie znalazłem tej, którą
przeglądarka faktycznie stosuje (dwie pierwsze to żywa historia kolejnych
przeróbek tego kafelka, nikt ich nie posprzątał). Wniosek: przy zmianie
layoutu elementu, który "już ma" `display: grid`, najpierw `grep -n` całego
pliku po tym selektorze - edycja pierwszej definicji, na którą się trafi,
może być całkowicie bez efektu. Dodanie 4. elementu jako kolejnej kolumny
`auto` też się zemściło osobno: zmieściło się bez nakładania na inne elementy,
ale ścisnęło kolumnę z nazwą na tyle, że krótkie nazwy drużyn (`Gamma`) łamały
się w środku wyrazu. Naprawka: nowy przycisk wszedł w OSOBNY wiersz pod nazwą
(`grid-template-areas: 'rank name score' 'rank remove score'`), nie w nową
kolumnę obok niej - rank i wynik i tak już rozciągały się przez oba wiersze
w historycznej wersji tej reguły, więc to nie nowy wzorzec, tylko przywrócenie
starego z innym przeznaczeniem drugiego wiersza.

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
- **Frontend Ostatniej litery też nie był puszczony na żywo.** Backend przechodzi
  29 testów przez prawdziwy silnik i Redis (kredyty, eliminacja z pomijaniem,
  pauza na sędziego, punktacja), szablony/kontrolery trzymają się wzorca
  pozostałych gier i przechodzą lint — ale pełnej rundy (kilka telefonów + TV,
  w tym słowo trafiające do sędziego) jeszcze nikt nie przeklikał. `WordJudge`
  testowany atrapą (`FixedWordJudge`) — prawdziwego `hunspell`+`hunspell-pl`
  na polskich słowach z klawiatury telefonu jeszcze nie sprawdzono na żywo.
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
- **Frontend kalambur rysowanych nie był puszczony na żywo.** Backend przechodzi
  22 testy przez prawdziwy silnik, Redis i Postgres (trzy oferty trudności,
  degradacja przy wyczerpanej kategorii, topnienie punktów, wyścig
  guessed-po-timeout), a pełny łańcuch HTTP (start gry, transfer tokenu hosta
  na drugi ekran, kanał `/game/live`) zweryfikowany przez `WebTestCase` — ale
  nikt jeszcze nie przeklikał prawdziwej rundy na dwóch fizycznych ekranach
  (projektor + tablet dotykowy), więc płynność rysowania po realnym Wi-Fi
  i wygoda przenoszenia tokenu hosta przez QR to pierwsze rzeczy do sprawdzenia.
- **Słownik nie zna trudności per gra ani per hasło w obrębie kategorii —
  cała kategoria ma jedną, wspólną `difficulty`.** Opisuje, jak trudno hasło
  *pokazać* (kalambury) albo *narysować* (kalambury rysowane); koło używa go
  tylko do sortowania. `PhraseDeck::drawChoices()` (kalambury rysowane) to
  obchodzi dwuprzebiegowym dobieraniem - patrz decyzja „Etykieta trudności
  bywa tylko stawką punktową" wyżej - więc rysujący zawsze widzi trzy realne
  hasła; nadal jednak żadne z trzech nie odzwierciedla naprawdę zróżnicowanej
  trudności, dopóki ktoś nie zróżnicuje jej W OBRĘBIE kategorii w panelu.
- **Kategorie nie mają zmiany kolejności pozycji w panelu** — `position` istnieje
  w encjach i jest respektowane, ale UI go nie wystawia.
- **Ikony PWA są tymczasowe** — wygenerowana gwiazda z logotypu, nie prawdziwy
  branding. Wystarcza do instalowalności, ale warto podmienić na docelowy design.
