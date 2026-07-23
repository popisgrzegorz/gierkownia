<?php

declare(strict_types=1);

namespace App\Game\MelodiaQuiz;

/**
 * Budowanie zestawu odpowiedzi do testu 1 z 4.
 *
 * Osobno od modulu, bo to jedyne miejsce, w ktorym da sie zepsuc pytanie tak,
 * ze nikt tego nie zauwazy przy stole: dwa razy ten sam tytul albo poprawna
 * odpowiedz zawsze pod ta sama litera.
 */
final class Wybory
{
    /**
     * Poprawny tytul plus trzy mylne, wymieszane.
     *
     * Bledne odpowiedzi biora sie z tej samej kategorii - "Sto lat" obok trzech
     * rockowych ballad zdradzaloby sie samo. Porownanie bez wzgledu na rejestr,
     * bo ten sam utwor bywa wpisany raz tak, raz siak.
     *
     * @param list<string> $others tytuly z tej samej kategorii
     *
     * @return array{options: list<string>, correct: int}
     */
    public static function build(string $title, array $others, int $count = MelodiaQuizGame::OPTIONS): array
    {
        $pool = [];

        foreach ($others as $other) {
            if (0 !== strcasecmp($other, $title)) {
                $pool[mb_strtolower($other)] = $other;
            }
        }

        $pool = array_values($pool);
        shuffle($pool);

        $options = [$title, ...\array_slice($pool, 0, max(0, $count - 1))];
        shuffle($options);

        // Indeks poprawnej odpowiedzi ustalamy PO wymieszaniu - inaczej
        // wystarczyloby zapamietac, ze prawda zawsze siedzi pod tym samym
        // miejscem, i gra konczylaby sie po dwoch rundach.
        $correct = array_search($title, $options, true);

        return ['options' => $options, 'correct' => \is_int($correct) ? $correct : 0];
    }
}
