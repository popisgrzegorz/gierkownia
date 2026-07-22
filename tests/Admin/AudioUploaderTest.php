<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\AudioUploader;
use App\Admin\UploadFailed;
use App\Content\MediaLibrary;
use App\Entity\Category;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Wgrywanie nagran. Nazwa pliku pochodzi od uzytkownika i trafia prosto
 * do systemu plikow - to jedyne miejsce w panelu, gdzie blad ma konsekwencje
 * poza baza danych.
 */
final class AudioUploaderTest extends TestCase
{
    private string $mediaDir;
    private string $uploadDir;
    private AudioUploader $uploader;
    private Category $category;

    protected function setUp(): void
    {
        $this->mediaDir = sys_get_temp_dir().'/gierki-media-'.bin2hex(random_bytes(4));
        $this->uploadDir = sys_get_temp_dir().'/gierki-upload-'.bin2hex(random_bytes(4));
        mkdir($this->mediaDir, 0o775, true);
        mkdir($this->uploadDir, 0o775, true);

        $this->uploader = new AudioUploader(new MediaLibrary($this->mediaDir));
        $this->category = new Category('na-lato', 'Na lato', 'melodia');
    }

    protected function tearDown(): void
    {
        foreach ([$this->mediaDir, $this->uploadDir] as $dir) {
            if (is_dir($dir)) {
                exec(sprintf('rm -rf %s', escapeshellarg($dir)));
            }
        }
    }

    public function testFileLandsInTheCategoryDirectoryWithASafeName(): void
    {
        $path = $this->uploader->store($this->upload('Lato, Lato CZEKA!.mp3'), $this->category);

        self::assertSame('na-lato/lato-lato-czeka.mp3', $path);
        self::assertFileExists($this->mediaDir.'/'.$path);
    }

    /** Polskie znaki i spacje w nazwie to norma, a nie wyjatek. */
    public function testPolishCharactersAreTransliterated(): void
    {
        $path = $this->uploader->store($this->upload('Zażółć gęślą.mp3'), $this->category);

        self::assertSame('na-lato/zazolc-gesla.mp3', $path);
    }

    /**
     * Dwa razy "intro.mp3" w jednej kategorii to normalna sytuacja.
     * Nadpisanie cudzego nagrania byloby najgorszym mozliwym wyjsciem.
     */
    public function testSecondFileWithTheSameNameDoesNotOverwriteTheFirst(): void
    {
        $first = $this->uploader->store($this->upload('intro.mp3'), $this->category);
        $second = $this->uploader->store($this->upload('intro.mp3'), $this->category);

        self::assertSame('na-lato/intro.mp3', $first);
        self::assertSame('na-lato/intro-2.mp3', $second);
        self::assertFileExists($this->mediaDir.'/'.$first);
        self::assertFileExists($this->mediaDir.'/'.$second);
    }

    public function testUnsupportedFormatIsRefused(): void
    {
        $this->expectException(UploadFailed::class);
        $this->expectExceptionMessage('nie jest obslugiwany');

        $this->uploader->store($this->upload('notatka.txt'), $this->category);
    }

    /** Nazwa zlozona z samych znakow specjalnych nie moze dac pustej nazwy pliku. */
    public function testFileWithoutUsableNameStillGetsOne(): void
    {
        $path = $this->uploader->store($this->upload('!!!.mp3'), $this->category);

        self::assertSame('na-lato/nagranie.mp3', $path);
    }

    /** Sciezka w nazwie pliku nie moze wyprowadzic zapisu poza katalog mediow. */
    public function testPathInTheFileNameCannotEscape(): void
    {
        $path = $this->uploader->store($this->upload('../../etc/passwd.mp3'), $this->category);

        self::assertSame('na-lato/passwd.mp3', $path);
        self::assertFileExists($this->mediaDir.'/na-lato/passwd.mp3');
    }

    public function testDeletingRemovesTheFile(): void
    {
        $path = $this->uploader->store($this->upload('intro.mp3'), $this->category);

        $this->uploader->delete($path);

        self::assertFileDoesNotExist($this->mediaDir.'/'.$path);
    }

    private function upload(string $name): UploadedFile
    {
        $tmp = $this->uploadDir.'/'.bin2hex(random_bytes(6));
        file_put_contents($tmp, 'udawane audio');

        // test: true - poza testami UploadedFile wymaga, by plik przyszedl
        // przez upload HTTP i inaczej odmawia przeniesienia.
        return new UploadedFile($tmp, $name, null, null, true);
    }
}
