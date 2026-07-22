<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Katalog z plikami muzycznymi. Jedyne miejsce, ktore zamienia sciezke z bazy
 * na sciezke na dysku.
 *
 * Sciezki w bazie sa wzgledne i **musza** zostac wzgledne: wpis "../../etc/passwd"
 * albo "/etc/shadow" w pliku YAML nie moze dac dostepu do niczego poza katalogiem
 * mediow. Kontroler serwujacy audio korzysta z tej samej metody, wiec regula
 * obowiazuje tak samo przy imporcie, jak i przy odtwarzaniu.
 */
final readonly class MediaLibrary
{
    public function __construct(
        private string $mediaDir,
    ) {
    }

    public function directory(): string
    {
        return $this->mediaDir;
    }

    /**
     * Zamienia sciezke wzgledna na bezwzgledna, pilnujac granic katalogu.
     *
     * @throws \InvalidArgumentException gdy sciezka probuje wyjsc poza katalog mediow
     */
    public function absolutePath(string $relative): string
    {
        // Trim przed sprawdzeniem pustki: w YAML-u latwo o wpis zlozony z samych
        // spacji, a taka "sciezka" wskazywalaby plik o nazwie ze spacji zamiast
        // zglosic blad w talii.
        $relative = ltrim(trim(str_replace('\\', '/', $relative)), '/');

        if ('' === trim($relative)) {
            throw new \InvalidArgumentException('Sciezka do pliku jest pusta.');
        }

        foreach (explode('/', $relative) as $segment) {
            if ('..' === $segment) {
                throw new \InvalidArgumentException(sprintf(
                    'Sciezka "%s" probuje wyjsc poza katalog mediow.',
                    $relative,
                ));
            }
        }

        return rtrim($this->mediaDir, '/').'/'.$relative;
    }

    public function exists(string $relative): bool
    {
        try {
            return is_file($this->absolutePath($relative));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
