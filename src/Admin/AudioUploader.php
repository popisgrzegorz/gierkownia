<?php

declare(strict_types=1);

namespace App\Admin;

use App\Content\MediaLibrary;
use App\Entity\Category;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Wgrywanie nagran do katalogu mediow.
 *
 * Nazwe pliku budujemy sami ze sluga - nazwa od uzytkownika moze zawierac
 * spacje, polskie znaki i sciezki ("../../"), a trafia prosto do systemu plikow.
 * Rozszerzenie bierzemy z listy dozwolonych, a nie z tego, co przyszlo.
 */
final readonly class AudioUploader
{
    /** Formaty, ktore odtworzy <audio> w przegladarce na ekranie hosta. */
    private const EXTENSIONS = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac', 'webm'];

    public function __construct(
        private MediaLibrary $media,
    ) {
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        return self::EXTENSIONS;
    }

    /**
     * Zapisuje plik i zwraca sciezke wzgledna do zapisania w bazie.
     *
     * @throws UploadFailed z komunikatem po polsku - panel pokazuje go wprost
     */
    public function store(UploadedFile $file, Category $category): string
    {
        $this->guard($file);

        $extension = strtolower($file->getClientOriginalExtension());
        $slugger = new AsciiSlugger();

        $base = $slugger->slug(pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME))->lower()->toString();
        $base = '' === $base ? 'nagranie' : $base;

        $dir = $this->media->absolutePath($category->getSlug());

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new UploadFailed(sprintf('Nie moge utworzyc katalogu "%s".', $category->getSlug()));
        }

        // Kolizja nazw: dwa razy "intro.mp3" w jednej kategorii to normalna
        // sytuacja, a nadpisanie cudzego nagrania byloby najgorszym wyjsciem.
        $name = sprintf('%s.%s', $base, $extension);
        $suffix = 1;

        while (is_file($dir.'/'.$name)) {
            $name = sprintf('%s-%d.%s', $base, ++$suffix, $extension);
        }

        try {
            $file->move($dir, $name);
        } catch (FileException $e) {
            throw new UploadFailed('Nie udalo sie zapisac pliku: '.$e->getMessage(), previous: $e);
        }

        return $category->getSlug().'/'.$name;
    }

    /** Kasuje plik, o ile nie uzywa go inny utwor. */
    public function delete(string $relativePath): void
    {
        $path = $this->media->absolutePath($relativePath);

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function guard(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new UploadFailed(match ($file->getError()) {
                \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => sprintf(
                    'Plik jest wiekszy niz limit serwera (%s). Zwieksz upload_max_filesize i post_max_size w php.ini.',
                    ini_get('upload_max_filesize') ?: '?',
                ),
                \UPLOAD_ERR_PARTIAL => 'Plik doszedl tylko czesciowo - sprobuj ponownie.',
                \UPLOAD_ERR_NO_FILE => 'Nie wybrano pliku.',
                default => 'Wgrywanie nie powiodlo sie: '.$file->getErrorMessage(),
            });
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (!\in_array($extension, self::EXTENSIONS, true)) {
            throw new UploadFailed(sprintf(
                'Format "%s" nie jest obslugiwany. Dozwolone: %s.',
                $extension,
                implode(', ', self::EXTENSIONS),
            ));
        }
    }
}
