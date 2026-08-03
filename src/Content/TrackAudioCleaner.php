<?php

declare(strict_types=1);

namespace App\Content;

use App\Entity\Track;
use App\Repository\TrackRepository;

/**
 * Kasuje plik nagrania z dysku, o ile nie korzysta z niego zaden inny utwor.
 *
 * Ten sam plik moze siedziec w kilku kategoriach - usuniecie go przy pierwszym
 * kasowaniu zabiloby pozostale. Wspolne dla obu miejsc, ktore kasuja Tracki
 * (panel EasyAdmina, kasowanie calej kategorii) - jedna reguła zamiast dwoch.
 */
final readonly class TrackAudioCleaner
{
    public function __construct(
        private MediaLibrary $media,
        private TrackRepository $tracks,
    ) {
    }

    public function deleteIfUnused(Track $track): void
    {
        $path = $track->getPath();

        // Nagranie z sieci nie ma naszego pliku - nie ma czego kasowac.
        if (null === $path) {
            return;
        }

        if (1 >= $this->tracks->countByPath($path)) {
            $absolute = $this->media->absolutePath($path);

            if (is_file($absolute)) {
                unlink($absolute);
            }
        }
    }
}
