<?php

declare(strict_types=1);

namespace App\Content;

use App\Entity\Deck;
use App\Entity\Track;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import utworow do "Jaka to melodia". Klucz naturalny: sciezka pliku w talii.
 */
final readonly class TrackSyncer implements DeckItemSyncer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MediaLibrary $media,
    ) {
    }

    public static function gameType(): string
    {
        return 'melodia';
    }

    public function itemsKey(): string
    {
        return 'tracks';
    }

    public function sync(Deck $deck, array $items, string $origin, DeckSyncResult $result, CategoryResolver $categories): void
    {
        $seen = [];

        foreach ($items as $index => $item) {
            $where = sprintf('%s, pozycja #%d', $origin, $index + 1);
            $path = Fields::string($item, 'file', $where);
            $seen[] = $path;

            // Brak pliku nie przerywa importu, ale musi trafic do raportu -
            // inaczej talia zawiedzie dopiero przy odtwarzaniu, czyli na imprezie.
            if (!$this->media->exists($path)) {
                $result->missingFiles[] = $path;
            }

            $this->upsert($deck, $item, $path, $where, $index, $result, $categories);
        }

        foreach ($deck->getTracks() as $track) {
            if (!\in_array($track->getPath(), $seen, true)) {
                $deck->removeTrack($track);
                $this->entityManager->remove($track);
                ++$result->removed;
            }
        }
    }

    /** @param array<mixed> $item */
    private function upsert(
        Deck $deck,
        array $item,
        string $path,
        string $where,
        int $index,
        DeckSyncResult $result,
        CategoryResolver $categories,
    ): void {
        $title = Fields::string($item, 'title', $where);
        $artist = Fields::string($item, 'artist', $where);
        $startAt = Fields::optionalInt($item, 'startAt', $where, 'liczba sekund');
        $category = $categories->resolve($item, $where);

        $track = null;
        foreach ($deck->getTracks() as $existing) {
            if ($existing->getPath() === $path) {
                $track = $existing;
                break;
            }
        }

        if (null === $track) {
            $track = new Track($deck, $path, $title, $artist);
            $track->setStartAt($startAt);
            $track->setPosition($index);
            $track->setCategory($category);
            $this->entityManager->persist($track);
            ++$result->created;

            return;
        }

        $changed = $track->getTitle() !== $title
            || $track->getArtist() !== $artist
            || $track->getStartAt() !== $startAt
            || $track->getPosition() !== $index
            || $track->getCategory()?->getSlug() !== $category?->getSlug();

        if (!$changed) {
            return;
        }

        $track->setTitle($title);
        $track->setArtist($artist);
        $track->setStartAt($startAt);
        $track->setPosition($index);
        $track->setCategory($category);
        ++$result->updated;
    }
}
