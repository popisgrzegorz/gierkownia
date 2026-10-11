<?php

declare(strict_types=1);

namespace App\Game\OstatniaLitera;

use Symfony\Component\Process\Process;

/**
 * Sprawdzenie slowa w slowniku jezyka polskiego przez hunspell (`apt install
 * hunspell hunspell-pl`).
 *
 * Tryb `-l` wypisuje WYLACZNIE slowa nierozpoznane, jedno na linie - puste
 * wyjscie znaczy "slowo poprawne". Brak hunspella na maszynie (albo jakikolwiek
 * inny blad procesu) nie wywala rundy - slowo trafia wtedy zawsze do sedziego,
 * bo to bezpieczniejsza usterka niz udawanie, ze slownik czegos nie zna.
 */
final readonly class HunspellWordJudge implements WordJudge
{
    public function isKnown(string $word): bool
    {
        try {
            // -i utf-8: PHP-FPM i cron startuja z LANG=C, a w tej lokalizacji hunspell
            // tnie wyrazy na kazdej polskiej literze ("straz" -> "stra"). Slowa
            // typu "waz" czy "zolw" przechodzily tylko dlatego, ze okruchy ("w")
            // sa poprawne, a "straz" trafialo do sedziego. Kodowanie wejscia
            // podajemy wprost, zamiast liczyc na lokalizacje maszyny.
            $process = new Process(['hunspell', '-d', 'pl_PL', '-i', 'utf-8', '-l']);
            $process->setInput($word);
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        if (!$process->isSuccessful()) {
            return false;
        }

        return '' === trim($process->getOutput());
    }
}
