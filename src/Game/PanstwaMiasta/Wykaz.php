<?php

declare(strict_types=1);

namespace App\Game\PanstwaMiasta;

/**
 * Wykaz odpowiedzi, ktore da sie sprawdzic maszynowo: panstwa i miasta.
 *
 * Reszta rubryk (roslina, zwierze, imie, rzecz, zawod, potrawa) zostaje przy
 * czlowieku - serwer nie zna slownika jezyka polskiego i nie bedzie udawal,
 * ze zna. Panstwa to jednak zbior zamkniety i skonczony, a miasta - na tyle
 * przewidywalny, ze wiekszosc odpowiedzi da sie rozstrzygnac bez sporu.
 *
 * **Odrzucenie z wykazu jest miekkie**: komorka dostaje zero punktow i osobny
 * status, a host uznaje ja jednym klikniecm. Ta lista nigdy nie bedzie pelna
 * (male miejscowosci, warianty nazw), wiec ostatnie slowo ma czlowiek przy
 * telewizorze - tak samo jak przy literowce.
 *
 * Wykaz siedzi w kodzie razem z `Kolumny`: to material pomocniczy do rubryk,
 * a nie tresc do zgadywania, ktora idzie przez panel.
 */
final class Wykaz
{
    /**
     * Panstwa swiata po polsku, razem z nazwami potocznymi, ktorych ludzie
     * uzywaja przy stole ("USA", "Anglia", "Czechy").
     */
    private const PANSTWA = [
        'Afganistan', 'Albania', 'Algieria', 'Andora', 'Angola', 'Antigua i Barbuda',
        'Arabia Saudyjska', 'Argentyna', 'Armenia', 'Australia', 'Austria', 'Azerbejdżan',
        'Bahamy', 'Bahrajn', 'Bangladesz', 'Barbados', 'Belgia', 'Belize', 'Benin', 'Bhutan',
        'Białoruś', 'Boliwia', 'Bośnia i Hercegowina', 'Botswana', 'Brazylia', 'Brunei',
        'Bułgaria', 'Burkina Faso', 'Burundi',
        'Chile', 'Chiny', 'Chorwacja', 'Cypr', 'Czad', 'Czarnogóra', 'Czechy', 'Republika Czeska',
        'Dania', 'Demokratyczna Republika Konga', 'Dominika', 'Dominikana', 'Dżibuti',
        'Egipt', 'Ekwador', 'Erytrea', 'Estonia', 'Eswatini', 'Etiopia',
        'Fidżi', 'Filipiny', 'Finlandia', 'Francja',
        'Gabon', 'Gambia', 'Ghana', 'Grecja', 'Grenada', 'Gruzja', 'Gujana', 'Gwatemala',
        'Gwinea', 'Gwinea Bissau', 'Gwinea Równikowa',
        'Haiti', 'Hiszpania', 'Holandia', 'Niderlandy', 'Honduras',
        'Indie', 'Indonezja', 'Irak', 'Iran', 'Irlandia', 'Islandia', 'Izrael',
        'Jamajka', 'Japonia', 'Jemen', 'Jordania',
        'Kambodża', 'Kamerun', 'Kanada', 'Katar', 'Kazachstan', 'Kenia', 'Kirgistan',
        'Kiribati', 'Kolumbia', 'Komory', 'Kongo', 'Korea Południowa', 'Korea Północna',
        'Kostaryka', 'Kuba', 'Kuwejt',
        'Laos', 'Lesotho', 'Liban', 'Liberia', 'Libia', 'Liechtenstein', 'Litwa',
        'Luksemburg', 'Łotwa',
        'Macedonia Północna', 'Madagaskar', 'Malawi', 'Malediwy', 'Malezja', 'Mali', 'Malta',
        'Maroko', 'Mauretania', 'Mauritius', 'Meksyk', 'Mikronezja', 'Mjanma', 'Birma',
        'Mołdawia', 'Monako', 'Mongolia', 'Mozambik',
        'Namibia', 'Nauru', 'Nepal', 'Niemcy', 'Niger', 'Nigeria', 'Nikaragua', 'Norwegia',
        'Nowa Zelandia',
        'Oman',
        'Pakistan', 'Palau', 'Palestyna', 'Panama', 'Papua-Nowa Gwinea', 'Paragwaj', 'Peru',
        'Polska', 'Portugalia',
        'Republika Południowej Afryki', 'RPA', 'Republika Środkowoafrykańska',
        'Republika Zielonego Przylądka', 'Rosja', 'Rumunia', 'Rwanda',
        'Saint Kitts i Nevis', 'Saint Lucia', 'Saint Vincent i Grenadyny', 'Salwador', 'Samoa',
        'San Marino', 'Senegal', 'Serbia', 'Seszele', 'Sierra Leone', 'Singapur', 'Słowacja',
        'Słowenia', 'Somalia', 'Sri Lanka', 'Stany Zjednoczone', 'USA', 'Sudan',
        'Sudan Południowy', 'Surinam', 'Syria', 'Szwajcaria', 'Szwecja',
        'Tadżykistan', 'Tajlandia', 'Tanzania', 'Timor Wschodni', 'Togo', 'Tonga',
        'Trynidad i Tobago', 'Tunezja', 'Turcja', 'Turkmenistan', 'Tuvalu',
        'Uganda', 'Ukraina', 'Urugwaj', 'Uzbekistan',
        'Vanuatu', 'Watykan', 'Wenezuela', 'Węgry', 'Wielka Brytania', 'Anglia', 'Wietnam',
        'Włochy', 'Wybrzeże Kości Słoniowej', 'Wyspy Marshalla', 'Wyspy Salomona',
        'Zambia', 'Zimbabwe', 'Zjednoczone Emiraty Arabskie',
    ];

    /**
     * Miasta: polskie wojewodzkie i powiatowe oraz stolice i wieksze miasta
     * swiata. Lista jest z zalozenia niepelna - male miejscowosci host uznaje
     * recznie, tak samo jak zawsze przy tej grze.
     */
    private const MIASTA = [
        // Polska - wojewodzkie i wieksze
        'Augustów', 'Będzin', 'Biała Podlaska', 'Białystok', 'Bielsko-Biała', 'Bochnia',
        'Bolesławiec', 'Brzeg', 'Bydgoszcz', 'Bytom', 'Chełm', 'Chorzów', 'Ciechanów',
        'Cieszyn', 'Czeladź', 'Częstochowa', 'Dąbrowa Górnicza', 'Dębica', 'Elbląg', 'Ełk',
        'Gdańsk', 'Gdynia', 'Giżycko', 'Gliwice', 'Głogów', 'Gniezno', 'Gorzów Wielkopolski',
        'Grudziądz', 'Inowrocław', 'Jarosław', 'Jastrzębie-Zdrój', 'Jaworzno', 'Jelenia Góra',
        'Kalisz', 'Katowice', 'Kędzierzyn-Koźle', 'Kielce', 'Kluczbork', 'Kołobrzeg', 'Konin',
        'Koszalin', 'Kraków', 'Krosno', 'Kutno', 'Legnica', 'Leszno', 'Lubin', 'Lublin',
        'Łomża', 'Łódź', 'Malbork', 'Mielec', 'Mikołów', 'Mysłowice', 'Nowy Sącz',
        'Nowy Targ', 'Nysa', 'Olsztyn', 'Opole', 'Ostrołęka', 'Ostrów Wielkopolski',
        'Oświęcim', 'Otwock', 'Piaseczno', 'Piła', 'Piotrków Trybunalski', 'Płock', 'Poznań',
        'Pruszków', 'Przemyśl', 'Puławy', 'Racibórz', 'Radom', 'Ruda Śląska', 'Rumia',
        'Rybnik', 'Rzeszów', 'Sandomierz', 'Sanok', 'Siedlce', 'Siemianowice Śląskie',
        'Sieradz', 'Skierniewice', 'Słupsk', 'Sopot', 'Sosnowiec', 'Stalowa Wola',
        'Starachowice', 'Stargard', 'Starogard Gdański', 'Suwałki', 'Świdnica', 'Świnoujście',
        'Szczecin', 'Tarnobrzeg', 'Tarnów', 'Tczew', 'Tomaszów Mazowiecki', 'Toruń', 'Tychy',
        'Ustka', 'Wadowice', 'Wałbrzych', 'Warszawa', 'Wejherowo', 'Włocławek', 'Wodzisław Śląski',
        'Wrocław', 'Zabrze', 'Zakopane', 'Zamość', 'Zawiercie', 'Zgierz', 'Zielona Góra',
        'Żory', 'Żyrardów',
        // Swiat - stolice i miasta, ktore padaja przy stole
        'Aleksandria', 'Amsterdam', 'Ankara', 'Ateny', 'Bagdad', 'Baku', 'Bangkok',
        'Barcelona', 'Belgrad', 'Berlin', 'Bern', 'Birmingham', 'Bogota', 'Bratysława',
        'Bruksela', 'Budapeszt', 'Buenos Aires', 'Bukareszt', 'Chicago', 'Delhi', 'Dublin',
        'Dubaj', 'Edynburg', 'Frankfurt', 'Genewa', 'Glasgow', 'Hamburg', 'Hanoi', 'Hawana',
        'Helsinki', 'Ho Chi Minh', 'Houston', 'Kair', 'Kapsztad', 'Kijów', 'Kopenhaga',
        'Lagos', 'Lima', 'Lipsk', 'Lizbona', 'Londyn', 'Los Angeles', 'Lyon', 'Madryt',
        'Manila', 'Marsylia', 'Meksyk', 'Mediolan', 'Miami', 'Mińsk', 'Monachium', 'Montreal',
        'Moskwa', 'Mumbaj', 'Nairobi', 'Neapol', 'Nikozja', 'Nowy Jork', 'Oslo', 'Ottawa',
        'Paryż', 'Pekin', 'Praga', 'Ryga', 'Rzym', 'Santiago', 'Sarajewo', 'Seul', 'Sofia',
        'Sztokholm', 'Szanghaj', 'Tallin', 'Teheran', 'Tel Awiw', 'Tokio', 'Toronto',
        'Tunis', 'Turyn', 'Wenecja', 'Wiedeń', 'Wilno', 'Zagrzeb', 'Zurych',
    ];

    /** @var array<string, list<string>>|null */
    private static ?array $index = null;

    /** Czy dla tej rubryki mamy czym sprawdzac. */
    public static function covers(string $column): bool
    {
        return isset(self::index()[$column]);
    }

    /**
     * Czy odpowiedz jest na liscie - dokladnie albo w granicach literowki.
     * "Kanadaa" wciaz jest Kanada, wiec host nie musi jej klikac.
     */
    public static function knows(string $column, string $text): bool
    {
        return null !== self::resolve($column, $text);
    }

    /**
     * Prostuje odpowiedz do wpisu z wykazu - dokladnego albo najblizszego
     * w granicach literowki. Zwraca znormalizowana nazwe ("kanada" dla
     * "Kanadaa") albo null, gdy nic w wykazie nie jest dostatecznie podobne.
     *
     * Dzieki temu dwie rozne literowki tego samego panstwa zjezdzaja na te sama
     * nazwe i licza sie jak jedna odpowiedz - a nie dwa osobne unikaty.
     *
     * Porownujemy postac znormalizowana ("Lodz", "Łódź" i "łódz" to jedno
     * miasto), tak samo jak przy zliczaniu powtorzen miedzy druzynami.
     */
    public static function resolve(string $column, string $text): ?string
    {
        $list = self::index()[$column] ?? null;

        if (null === $list) {
            return null;
        }

        $norm = Punktacja::normalize($text);

        if ('' === $norm) {
            return null;
        }

        if (\in_array($norm, $list, true)) {
            return $norm;
        }

        // Najblizszy wpis, ale tylko gdy miesci sie w granicy literowki. Bez tego
        // warunku "Kongo" prostowaloby sie do czegokolwiek, bo cos zawsze jest
        // najblizej.
        $best = null;
        $bestDistance = \PHP_INT_MAX;

        foreach ($list as $entry) {
            if (abs(\strlen($entry) - \strlen($norm)) > 2) {
                continue;
            }

            $distance = levenshtein($norm, $entry);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $entry;
            }
        }

        return (null !== $best && Punktacja::sameWord($norm, $best)) ? $best : null;
    }

    /** @return array<string, list<string>> */
    private static function index(): array
    {
        return self::$index ??= [
            'Państwo' => array_map(Punktacja::normalize(...), self::PANSTWA),
            'Miasto' => array_map(Punktacja::normalize(...), self::MIASTA),
        ];
    }
}
