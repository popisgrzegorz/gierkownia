<?php

declare(strict_types=1);

namespace App\Content;

use App\Entity\Category;
use App\Entity\Phrase;
use App\Repository\CategoryRepository;
use App\Repository\PhraseRepository;

/**
 * Dostep do slownika hasel - wspolny dla wszystkich gier na slowa.
 *
 * Kazda gra podaje swoj typ i dostaje wylacznie te kategorie, ktore sa dla niej
 * odblokowane. Dzieki temu ta sama kategoria "Bajki" obsluguje kolo fortuny
 * i kalambury, a "Przyslowia" widzi tylko kolo.
 *
 * Losowanie jest po stronie serwera, a zagrane id pamieta kontekst gry -
 * inaczej to samo haslo wracaloby w kolko, bo przy kilkunastu pozycjach
 * powtorka trafia sie szybciej, niz podpowiada intuicja.
 */
final readonly class PhraseDeck
{
    public function __construct(
        private CategoryRepository $categories,
        private PhraseRepository $phrases,
    ) {
    }

    /**
     * Kategorie z choc jednym haslem - puste tylko myla hosta przy wyborze.
     *
     * @return list<array{slug: string, name: string, items: int}>
     */
    public function categoriesFor(string $gameType): array
    {
        $out = [];

        foreach ($this->categories->findForGame($gameType) as $category) {
            $count = $this->phrases->countForCategory($category);

            if ($count > 0) {
                $out[] = ['slug' => $category->getSlug(), 'name' => $category->getName(), 'items' => $count];
            }
        }

        return $out;
    }

    public function hasCategory(string $gameType, string $slug): bool
    {
        return null !== $this->categories->findOneForGame($gameType, $slug);
    }

    public function categoryName(string $gameType, string $slug): ?string
    {
        return $this->categories->findOneForGame($gameType, $slug)?->getName();
    }

    public function countPhrases(string $gameType, string $slug): int
    {
        $category = $this->categories->findOneForGame($gameType, $slug);

        return null === $category ? 0 : $this->phrases->countForCategory($category);
    }

    /**
     * Kolejne haslo z kategorii, z pominieciem juz zagranych.
     *
     * @param list<int> $playedIds
     */
    public function drawNext(string $gameType, string $slug, array $playedIds): ?Phrase
    {
        $category = $this->categories->findOneForGame($gameType, $slug);

        if (null === $category) {
            return null;
        }

        $remaining = array_values(array_filter(
            $this->phrases->findForCategory($category),
            static fn (Phrase $phrase): bool => !\in_array($phrase->getId(), $playedIds, true),
        ));

        // Talia wyczerpana - runda konczy sie sama, zamiast zapetlac te same hasla.
        return [] === $remaining ? null : $remaining[random_int(0, \count($remaining) - 1)];
    }

    /**
     * Trzy nieuzyte hasla do wyboru przez rysujacego (kalambury rysowane) -
     * jedno na kazdy poziom trudnosci.
     *
     * Kategorie w tym slowniku maja w praktyce JEDNA, wspolna trudnosc na
     * cala kategorie (Slownik, difficulty per kategoria, nie per haslo) -
     * dopasowanie "poziom = dokladnie ta trudnosc" trafialoby wiec niemal
     * zawsze tylko w jeden slot z trzech, a rysujacy widzialby jedna oferte
     * zamiast trzech (zgloszenie uzytkownika przy pierwszym realnym teście).
     * Dlatego pierwszy przebieg probuje dokladnych trafien (przyda sie, gdy
     * ktos kiedys zroznicuje trudnosc w obrebie kategorii), a drugi dobiera
     * z reszty puli KAZDY pusty jeszcze slot - etykieta trudnosci staje sie
     * wtedy stawka punktowa ("ile chcesz zaryzykowac"), a nie deklaracja
     * "to jest trudne", ale wybor miedzy trzema realnymi haslami zostaje.
     * Pusty wynikowy slot znaczy wylacznie "hasel w kategorii juz zabraklo",
     * nie "ten poziom trudnosci nie istnieje".
     *
     * @param list<int> $playedIds
     *
     * @return array<string, Phrase> klucz = poziom trudnosci (Phrase::DIFFICULTIES)
     */
    public function drawChoices(string $gameType, string $slug, array $playedIds): array
    {
        $category = $this->categories->findOneForGame($gameType, $slug);

        if (null === $category) {
            return [];
        }

        $remaining = array_values(array_filter(
            $this->phrases->findForCategory($category),
            static fn (Phrase $phrase): bool => !\in_array($phrase->getId(), $playedIds, true),
        ));

        $choices = [];
        $used = [];

        foreach (Phrase::DIFFICULTIES as $level) {
            $pool = array_values(array_filter(
                $remaining,
                static fn (Phrase $phrase): bool => $level === $phrase->getDifficulty()
                    && !\in_array($phrase->getId(), $used, true),
            ));

            if ([] !== $pool) {
                $picked = $pool[random_int(0, \count($pool) - 1)];
                $choices[$level] = $picked;
                $used[] = $picked->getId();
            }
        }

        foreach (Phrase::DIFFICULTIES as $level) {
            if (isset($choices[$level])) {
                continue;
            }

            $pool = array_values(array_filter(
                $remaining,
                static fn (Phrase $phrase): bool => !\in_array($phrase->getId(), $used, true),
            ));

            if ([] === $pool) {
                continue;
            }

            $picked = $pool[random_int(0, \count($pool) - 1)];
            $choices[$level] = $picked;
            $used[] = $picked->getId();
        }

        return $choices;
    }

    public function find(int $id): ?Phrase
    {
        return $this->phrases->find($id);
    }

    public function category(string $gameType, string $slug): ?Category
    {
        return $this->categories->findOneForGame($gameType, $slug);
    }
}
