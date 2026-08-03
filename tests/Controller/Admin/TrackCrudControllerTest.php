<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Admin\AdminAccess;
use App\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * Wgrywanie nagran przez panel EasyAdmina - jedyna funkcjonalna luka,
 * ktora trzymala przy zyciu stary panel (/admin). Prawdziwy plik, prawdziwe
 * zapisanie na dysku pod [rok]/[miesiac]/[slug]-[hash] (decyzja uzytkownika:
 * wg daty wgrania, nie wg kategorii - patrz plan migracji panelu).
 */
final class TrackCrudControllerTest extends WebTestCase
{
    private const PASSWORD = 'panel-track-test';
    private const FIXTURE = 'fixture-audio.mp3';

    /** @var list<string> */
    private array $uploadedFiles = [];

    private ?\Doctrine\DBAL\Connection $connection = null;

    protected function tearDown(): void
    {
        // Transakcja wycofywana na koniec (jak DoctrineTestCase) - bez tego
        // wiersze z jednego przebiegu WebTestCase zostaja w bazie testowej
        // na zawsze i psuja kolejne uruchomienia (ta sama trescia fixture'a =
        // ten sam hash = ta sama "kanoniczna" nazwa pliku na dysku, wiec stary
        // wiersz w bazie wskazujacy na juz posprzatany plik blokuje kasowanie
        // nowego, bo TrackAudioCleaner widzi go jako wciaz "uzywany").
        if (null !== $this->connection && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        foreach ($this->uploadedFiles as $absolute) {
            if (is_file($absolute)) {
                unlink($absolute);
            }
        }

        // Plik moze wyladowac na dysku, zanim walidacja encji go odrzuci
        // (upload przetwarza sie w FileUploadType przed uruchomieniem
        // walidatora) - zamiatamy wiec kazda pozostalosc po tym stalym
        // fixture'cie, niezaleznie od tego, czy dany test skonczyl sie sukcesem.
        foreach (glob($this->mediaDir().'/*/*/fixture-audio-*.mp3') ?: [] as $stray) {
            unlink($stray);
        }

        parent::tearDown();
    }

    public function testUploadingAFileCreatesATrackAndStoresItByUploadDate(): void
    {
        $client = $this->loggedInClient();
        $category = $this->createMelodiaCategory('Na lato');
        $title = $this->uniqueTitle('Testowy utwor');

        $crawler = $client->request('GET', '/panel/track/new');
        self::assertResponseIsSuccessful();

        $form = $this->fillTrackForm($crawler, $category, $title, 'Testowy wykonawca');
        $this->fileField($form)->upload($this->fixture());

        $client->submit($form);
        self::assertResponseRedirects();

        $path = $this->pathOf($title);
        self::assertMatchesRegularExpression('#^\d{4}/\d{2}/fixture-audio-[0-9a-f]+(_\d+)?\.mp3$#', $path);

        $absolute = $this->mediaDir().'/'.$path;
        self::assertFileExists($absolute);
        $this->uploadedFiles[] = $absolute;
    }

    public function testAFileWithADisallowedExtensionIsRejected(): void
    {
        $client = $this->loggedInClient();
        $category = $this->createMelodiaCategory('Na lato');

        $crawler = $client->request('GET', '/panel/track/new');
        $form = $this->fillTrackForm($crawler, $category, 'Zly format', 'X');

        $badFile = tempnam(sys_get_temp_dir(), 'nie-audio').'.txt';
        file_put_contents($badFile, 'to nie jest dzwiek');
        $this->fileField($form)->upload($badFile);

        $client->submit($form);
        unlink($badFile);

        self::assertResponseStatusCodeSame(422);
    }

    public function testNeitherAFileNorALinkIsRejected(): void
    {
        $client = $this->loggedInClient();
        $category = $this->createMelodiaCategory('Na lato');

        $crawler = $client->request('GET', '/panel/track/new');
        $form = $this->fillTrackForm($crawler, $category, 'Bez zrodla', 'X');

        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAFileAndALinkTogetherAreRejected(): void
    {
        $client = $this->loggedInClient();
        $category = $this->createMelodiaCategory('Na lato');

        $crawler = $client->request('GET', '/panel/track/new');
        $form = $this->fillTrackForm($crawler, $category, 'Oba naraz', 'X');
        $form['Track[sourceUrl]'] = 'https://przyklad.pl/a.mp3';
        $this->fileField($form)->upload($this->fixture());

        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
    }

    public function testDeletingATrackRemovesTheUnusedFileFromDisk(): void
    {
        $client = $this->loggedInClient();
        $category = $this->createMelodiaCategory('Na lato');
        $title = $this->uniqueTitle('Do skasowania');

        $crawler = $client->request('GET', '/panel/track/new');
        $form = $this->fillTrackForm($crawler, $category, $title, 'X');
        $this->fileField($form)->upload($this->fixture());
        $client->submit($form);
        self::assertResponseRedirects();

        $id = $this->idOf($title);
        $absolute = $this->mediaDir().'/'.$this->pathOf($title);
        self::assertFileExists($absolute);

        // Kasowanie w EasyAdminie: link "Delete" ma tylko "formaction", a JS
        // podstawia go do wspolnego, ukrytego formularza potwierdzenia (jeden
        // token CSRF na cala strone, nie per-wiersz) - odtwarzamy to samo bez JS-a.
        $indexCrawler = $client->request('GET', '/panel/track');
        $token = (string) $indexCrawler->filter('#action-confirmation-form input[name="token"]')->attr('value');

        $client->request('POST', \sprintf('/panel/track/%d/delete', $id), ['token' => $token]);

        self::assertFileDoesNotExist($absolute, 'Kasowanie przez panel ma zabrac plik z dysku, nie tylko wiersz z bazy.');
    }

    private function fillTrackForm(Crawler $crawler, Category $category, string $title, string $artist): Form
    {
        $form = $crawler->filter('button[value="saveAndReturn"]')->form();
        $form['Track[category]'] = (string) $category->getId();
        $form['Track[title]'] = $title;
        $form['Track[artist]'] = $artist;

        return $form;
    }

    private function fileField(Form $form): FileFormField
    {
        $field = $form['Track[path][file]'];

        if (!$field instanceof FileFormField) {
            throw new \LogicException('Track[path][file] nie jest polem pliku.');
        }

        return $field;
    }

    private function createMelodiaCategory(string $name): Category
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $category = new Category('slug-'.bin2hex(random_bytes(4)), $name, Category::KIND_MELODIA);
        $em->persist($category);
        $em->flush();

        return $category;
    }

    private function uniqueTitle(string $base): string
    {
        return $base.' '.bin2hex(random_bytes(4));
    }

    private function fixture(): string
    {
        return \dirname(__DIR__, 3).'/tests/Fixtures/'.self::FIXTURE;
    }

    private function mediaDir(): string
    {
        return \dirname(__DIR__, 3).'/var/media';
    }

    private function pathOf(string $title): string
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $path = $connection->fetchOne('SELECT path FROM track WHERE title = ?', [$title]);

        self::assertIsString($path);

        return $path;
    }

    private function idOf(string $title): int
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $id = $connection->fetchOne('SELECT id FROM track WHERE title = ?', [$title]);

        self::assertIsInt($id);

        return $id;
    }

    /**
     * IP losowe: limiter logowania zyje w pliku na dysku i przezywa miedzy
     * osobnymi uruchomieniami PHPUnit - dzielony adres dawalby falszywe 429
     * po kilku kolejnych przebiegach tego pliku testow.
     */
    private function loggedInClient(): KernelBrowser
    {
        $ip = sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254));

        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(AdminAccess::class, new AdminAccess(self::PASSWORD));

        $client->request('GET', '/admin/login', server: ['REMOTE_ADDR' => $ip]);
        $client->submitForm('Wejdź', ['password' => self::PASSWORD], 'POST', ['REMOTE_ADDR' => $ip]);

        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection->beginTransaction();

        return $client;
    }
}
