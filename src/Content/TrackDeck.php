<?php

declare(strict_types=1);

namespace App\Content;

use App\Entity\Category;
use App\Entity\Track;
use App\Repository\CategoryRepository;
use App\Repository\TrackRepository;

/**
 * Wybor nagran z kategorii - wspolny dla wszystkich gier na melodie.
 *
 * Stoi w `src/Content`, a nie w module gry, bo korzystaja z niego dwa warianty
 * melodii (buzzer i test 1 z 4), a niedlugo moze i trzeci. Modul gry nie ma
 * prawa siegac do wnetrza innego modulu - tak samo jak przy slowniku hasel
 * (PhraseDeck).
 *
 * Losowanie jest po stronie serwera i pamieta, co juz bylo - inaczej ten sam
 * kawalek wracalby po dwoch rundach, co przy kilkunastu utworach zdarza sie
 * czesciej, niz podpowiada intuicja.
 */
final readonly class TrackDeck
{
    public function __construct(
        private CategoryRepository $categories,
        private TrackRepository $tracks,
    ) {
    }

    /**
     * Kategorie z nagraniami - do listy wyboru na ekranie hosta.
     *
     * @return list<array{slug: string, name: string, items: int}>
     */
    public function availableCategories(): array
    {
        $out = [];

        foreach ($this->categories->findByKind(Category::KIND_MELODIA) as $category) {
            $out[] = [
                'slug' => $category->getSlug(),
                'name' => $category->getName(),
                'items' => $category->getTracks()->count(),
            ];
        }

        return $out;
    }

    /**
     * Odsiewa nieistniejace kategorie i powtorki, zachowujac kolejnosc wyboru.
     *
     * Wybor jest zawsze lista, nawet gdy padnie na jedna pozycje: host czesto
     * miesza "Hity" z "Dance", zeby wieczor nie brzmial jednostajnie, a gra nie
     * ma powodu wiedziec, ile ich jest.
     *
     * @param list<string> $slugs
     *
     * @return list<string>
     */
    public function known(array $slugs): array
    {
        $out = [];

        foreach ($slugs as $slug) {
            if (!\in_array($slug, $out, true) && null !== $this->categories->findOneBySlug($slug)) {
                $out[] = $slug;
            }
        }

        return $out;
    }

    /**
     * Podpis wyboru dla ekranu hosta.
     *
     * @param list<string> $slugs
     */
    public function name(array $slugs): string
    {
        $names = [];

        foreach ($slugs as $slug) {
            $name = $this->categories->findOneBySlug($slug)?->getName();

            if (null !== $name) {
                $names[] = $name;
            }
        }

        return implode(' + ', $names);
    }

    /** @param list<string> $slugs */
    public function countTracks(array $slugs): int
    {
        $total = 0;

        foreach ($slugs as $slug) {
            $total += $this->categories->findOneBySlug($slug)?->getTracks()->count() ?? 0;
        }

        return $total;
    }

    /**
     * Losuje utwor, ktorego jeszcze nie bylo w tej rozgrywce.
     *
     * Losujemy ze wspolnej puli wszystkich zaznaczonych kategorii, a nie
     * najpierw kategorie, potem utwor: przy 900 nagraniach dance i 150
     * balladach ten drugi sposob dawalby ballady sesc razy czesciej,
     * niz wynika to z ich udzialu w puli.
     *
     * @param list<string> $slugs
     * @param list<int>    $playedIds
     */
    public function drawNext(array $slugs, array $playedIds): ?Track
    {
        $remaining = [];

        foreach ($slugs as $slug) {
            $category = $this->categories->findOneBySlug($slug);

            if (null === $category) {
                continue;
            }

            foreach ($category->getTracks() as $track) {
                if (!\in_array($track->getId(), $playedIds, true)) {
                    $remaining[] = $track;
                }
            }
        }

        if ([] === $remaining) {
            return null;
        }

        return $remaining[random_int(0, \count($remaining) - 1)];
    }

    /**
     * Podpisy pozostalych nagran z kategorii - material na blednne odpowiedzi
     * w tescie 1 z 4. Podpis to "Wykonawca - Tytul", bo sam tytul bywa
     * bezuzyteczny: coverow tej samej piosenki jest kilka, a zgadujacy ma
     * wiedziec, ktore konkretnie wykonanie wlasnie leci.
     *
     * Odsiewamy po identyfikatorze, nie po tytule: dwa rozne wykonania tego
     * samego utworu to dwie poprawne odpowiedzi do wyboru, a nie duplikat.
     *
     * @param list<string> $slugs
     *
     * @return list<string>
     */
    public function otherLabels(array $slugs, int $exceptId): array
    {
        $labels = [];

        foreach ($slugs as $slug) {
            $category = $this->categories->findOneBySlug($slug);

            if (null === $category) {
                continue;
            }

            foreach ($category->getTracks() as $track) {
                if ($track->getId() !== $exceptId) {
                    $labels[] = $track->getLabel();
                }
            }
        }

        return $labels;
    }

    public function find(int $id): ?Track
    {
        return $this->tracks->find($id);
    }
}
