<?php

declare(strict_types=1);

namespace App\Content;

use App\Entity\Category;
use App\Entity\Phrase;
use App\Repository\CategoryRepository;
use App\Repository\PhraseRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Wsypuje slownik startowy do bazy.
 *
 * Dokłada, nigdy nie kasuje - to jedyny powod, dla ktorego wolno bylo wrocic
 * do tresci trzymanej w kodzie. Stary `app:deck:sync` traktowal plik jak
 * zrodlo prawdy i po cichu usuwal wszystko, czego w nim nie bylo; tutaj baza
 * pozostaje zrodlem prawdy, a slownik jest tylko zestawem na start.
 *
 * Uruchomiony drugi raz nie zrobi nic poza dopisaniem hasel, ktore przybyly
 * w kodzie - istniejacych nie rusza, nawet jesli ktos je w panelu poprawil.
 */
final readonly class DictionarySeeder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CategoryRepository $categories,
        private PhraseRepository $phrases,
    ) {
    }

    /**
     * @return list<array{name: string, games: string, added: int, skipped: int}>
     */
    public function seed(): array
    {
        $report = [];

        foreach (Slownik::CATEGORIES as $spec) {
            $category = $this->categories->findOneBySlug($spec['slug']);

            if (null === $category) {
                $category = new Category($spec['slug'], $spec['name'], Category::KIND_HASLA, $spec['games']);
                $this->entityManager->persist($category);
                // Flush od razu: sprawdzanie duplikatow pyta bazy po kluczu
                // kategorii, a encja bez id nie nadaje sie na parametr zapytania.
                $this->entityManager->flush();
            } else {
                // Kategoria juz jest - dokladamy gry, ale zadnej nie odbieramy.
                // Ktos mogl swiadomie udostepnic ja kolejnej grze i nie po to
                // wsypujemy slownik, zeby mu to cofnac.
                $category->setGames([...$category->getGames(), ...$spec['games']]);
            }

            $added = 0;
            $skipped = 0;
            $position = $this->phrases->countForCategory($category);
            $seen = [];

            foreach ($spec['items'] as $text) {
                $key = mb_strtolower($text);

                // Dwa warunki, bo duplikat moze siedziec i w bazie, i w samym
                // slowniku - a unikalny indeks nie wybacza ani jednego.
                if (isset($seen[$key]) || $this->phrases->existsIn($category, $text)) {
                    ++$skipped;

                    continue;
                }

                $seen[$key] = true;
                $phrase = new Phrase($category, $text);
                $phrase->setHint($spec['hint']);
                $phrase->setDifficulty($spec['difficulty']);
                $phrase->setPosition($position++);

                $this->entityManager->persist($phrase);
                ++$added;
            }

            // Flush po kazdej kategorii: existsIn() pyta bazy, wiec pozycje
            // dopisane w tej samej rundzie musza juz tam byc, zanim policzymy
            // nastepna kategorie.
            $this->entityManager->flush();

            $report[] = [
                'name' => $category->getName(),
                'games' => implode(', ', $category->getGames()),
                'added' => $added,
                'skipped' => $skipped,
            ];
        }

        return $report;
    }
}
