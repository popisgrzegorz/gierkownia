<?php

declare(strict_types=1);

namespace App\Content;

use App\Entity\Deck;
use App\Repository\DeckRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Import talii z plikow YAML (spec 5.2): idempotentny upsert po kluczu naturalnym.
 *
 * Ta klasa zna wylacznie talie i kategorie. Co siedzi w talii - utwory, hasla,
 * karty kalambur - wie juz tylko DeckItemSyncer wybrany po typie gry. Dolozenie
 * kolejnej gry nie wymaga tu zmiany.
 *
 * YAML jest zrodlem prawdy: pozycje usuniete z pliku znikaja z bazy przy kolejnym
 * imporcie. Inaczej talia po kilku poprawkach zawieralaby wszystkie posrednie
 * wersje naraz i nikt nie wiedzialby, co poleci na imprezie.
 */
final readonly class DeckSynchronizer
{
    /** @var array<string, DeckItemSyncer> */
    private array $syncers;

    /** @param iterable<DeckItemSyncer> $syncers */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DeckRepository $decks,
        private CategoryResolver $categories,
        iterable $syncers,
    ) {
        $byType = [];

        foreach ($syncers as $syncer) {
            $byType[$syncer::gameType()] = $syncer;
        }

        $this->syncers = $byType;
    }

    /**
     * @throws DeckSyncException gdy plik jest niepoprawny
     */
    public function syncFile(string $path, bool $dryRun = false): DeckSyncResult
    {
        if (!is_file($path)) {
            throw new DeckSyncException(sprintf('Nie ma pliku talii "%s".', $path));
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new DeckSyncException(sprintf('Plik "%s" nie jest poprawnym YAML-em: %s', $path, $e->getMessage()), previous: $e);
        }

        if (!\is_array($parsed)) {
            throw new DeckSyncException(sprintf('Plik "%s" nie zawiera talii.', $path));
        }

        return $this->sync($parsed, basename($path), $dryRun);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws DeckSyncException
     */
    public function sync(array $data, string $origin, bool $dryRun = false): DeckSyncResult
    {
        $slug = Fields::string($data, 'deck', $origin);
        $name = Fields::string($data, 'name', $origin);
        $gameType = Fields::string($data, 'game', $origin);

        $syncer = $this->syncers[$gameType] ?? throw new DeckSyncException(sprintf(
            '%s: nie umiem wczytac talii dla gry "%s". Znane gry: %s.',
            $origin,
            $gameType,
            implode(', ', array_keys($this->syncers)),
        ));

        $deck = $this->decks->findOneBySlug($slug);
        $result = new DeckSyncResult($slug, null === $deck);

        if (null === $deck) {
            $deck = new Deck($slug, $name, $gameType);
            $this->entityManager->persist($deck);
        } else {
            if ($deck->getName() !== $name) {
                $deck->setName($name);
                ++$result->updated;
            }
            if ($deck->getGameType() !== $gameType) {
                $deck->setGameType($gameType);
                ++$result->updated;
            }
        }

        $this->categories->reset($this->categoryNames($data, $origin));

        $syncer->sync(
            $deck,
            Fields::itemList($data, $syncer->itemsKey(), $origin),
            $origin,
            $result,
            $this->categories,
        );

        if ($dryRun) {
            $this->entityManager->clear();
        } else {
            $this->entityManager->flush();
        }

        return $result;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private function categoryNames(array $data, string $origin): array
    {
        $names = $data['categories'] ?? [];

        if (!\is_array($names)) {
            throw new DeckSyncException(sprintf('%s: "categories" musi byc mapa slug => nazwa.', $origin));
        }

        $result = [];
        foreach ($names as $slug => $name) {
            if (\is_string($slug) && \is_string($name)) {
                $result[$slug] = $name;
            }
        }

        return $result;
    }
}
