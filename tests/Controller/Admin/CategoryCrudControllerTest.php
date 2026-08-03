<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Admin\AdminAccess;
use App\Entity\Category;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * Kategorie w panelu EasyAdmina: slug unikalny (przeniesiony do
 * CategoryRepository::uniqueSlugFor - jedno miejsce zamiast dwoch), oraz
 * regula "melodia = zawsze dokladnie ['melodia']" i "co najmniej jedna gra" -
 * dotad egzekwowane WYLACZNIE przez stary ContentManager, wiec ten panel je
 * cicho ignorowal.
 */
final class CategoryCrudControllerTest extends WebTestCase
{
    private const PASSWORD = 'panel-category-test';

    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        if (null !== $this->connection && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testCreatingACategoryBuildsAUniqueSlugFromTheName(): void
    {
        $client = $this->loggedInClient();

        $crawler = $client->request('GET', '/panel/category/new');
        $form = $crawler->filter('button[value="saveAndReturn"]')->form();
        $name = 'Piosenki na lato '.bin2hex(random_bytes(4));
        $form['Category[name]'] = $name;
        $form['Category[kind]'] = Category::KIND_HASLA;
        $this->checkGames($form, ['kolo']);

        $client->submit($form);
        self::assertResponseRedirects();

        $slug = $this->slugOf($name);
        self::assertStringStartsWith('piosenki-na-lato', $slug);
    }

    /** Melodia ma dokladnie jednego odbiorce - zaznaczenia w polu "Gry" nic tu nie zmieniaja. */
    public function testAMelodiaCategoryAlwaysEndsUpWithExactlyItsOwnGame(): void
    {
        $client = $this->loggedInClient();

        $crawler = $client->request('GET', '/panel/category/new');
        $form = $crawler->filter('button[value="saveAndReturn"]')->form();
        $name = 'Na lato '.bin2hex(random_bytes(4));
        $form['Category[name]'] = $name;
        $form['Category[kind]'] = Category::KIND_MELODIA;
        $this->checkGames($form, ['kolo', 'kalambury']);

        $client->submit($form);
        self::assertResponseRedirects();

        self::assertSame(['melodia'], $this->gamesOf($name));
    }

    public function testACategoryWithoutAnyGameIsRejected(): void
    {
        $client = $this->loggedInClient();

        $crawler = $client->request('GET', '/panel/category/new');
        $form = $crawler->filter('button[value="saveAndReturn"]')->form();
        $name = 'Powiedzenia '.bin2hex(random_bytes(4));
        $form['Category[name]'] = $name;
        $form['Category[kind]'] = Category::KIND_HASLA;
        $this->checkGames($form, []);

        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * "Gry" to kilka checkboksow o tej samej nazwie ("Category[games][]") -
     * przy tworzeniu domyslnie oba zaznaczone (createEntity() zaklada nowa
     * kategorie jako hasla + wszystkie gry na slowa) - trzeba je jawnie
     * odznaczyc/zaznaczyc, a nie tylko ustawic wartosc pola.
     *
     * @param list<string> $values
     */
    private function checkGames(Form $form, array $values): void
    {
        $checkboxes = $form['Category[games]'];

        if (!\is_array($checkboxes)) {
            throw new \LogicException('Category[games] nie jest grupa checkboksow.');
        }

        foreach ($checkboxes as $checkbox) {
            if (!$checkbox instanceof ChoiceFormField) {
                continue;
            }

            if (\in_array($checkbox->getValue(), $values, true)) {
                $checkbox->tick();
            } else {
                $checkbox->untick();
            }
        }
    }

    /** @return list<string> */
    private function gamesOf(string $name): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $category = $em->getRepository(Category::class)->findOneBy(['name' => $name]);

        self::assertNotNull($category);

        return $category->getGames();
    }

    private function slugOf(string $name): string
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $slug = $connection->fetchOne('SELECT slug FROM category WHERE name = ?', [$name]);

        self::assertIsString($slug);

        return $slug;
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
