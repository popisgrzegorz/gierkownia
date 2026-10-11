<?php

declare(strict_types=1);

namespace App\Tests\Content\Deezer;

use App\Content\Deezer\DeezerClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Do prawdziwego Deezera w testach nie wychodzimy (siec, limity, zmiany
 * katalogu), wiec odpowiedzi API sa atrapa - ale to, co z nich robimy, czyli
 * wybor wlasciwego utworu, sprawdzamy na prawdziwej logice.
 */
final class DeezerClientTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, bool}> */
    public static function matchCases(): iterable
    {
        yield 'ten sam utwor' => ['Maanam', 'Boskie Buenos', 'Maanam', 'Boskie Buenos', true];
        yield 'dopisek remaster w nawiasie' => ['Maanam', 'Boskie Buenos', 'Maanam', 'Boskie Buenos (2011 Remaster)', true];
        yield 'dopisek remaster po myslniku' => ['Maanam', 'Boskie Buenos', 'Maanam', 'Boskie Buenos - 2011 Remaster', true];
        yield 'wielkosc liter i ogonki' => ['Maanam', 'Kocham cię jak Irlandię', 'MAANAM', 'Kocham Cie Jak Irlandie', true];
        yield 'feat. u wykonawcy' => ['Doda', 'Dziewczyny', 'Doda feat. Ktos', 'Dziewczyny', true];
        yield 'inny tytul' => ['Maanam', 'Boskie Buenos', 'Maanam', 'Krakowski spleen', false];
        yield 'inny wykonawca' => ['Maanam', 'Boskie Buenos', 'Zespol Cover', 'Boskie Buenos', false];
        yield 'pusty tytul' => ['Maanam', '', 'Maanam', 'Boskie Buenos', false];
    }

    #[DataProvider('matchCases')]
    public function testMatchingToleratesDecorationsButNotAnotherSong(
        string $wantedArtist,
        string $wantedTitle,
        string $foundArtist,
        string $foundTitle,
        bool $expected,
    ): void {
        self::assertSame($expected, DeezerClient::matches($wantedArtist, $wantedTitle, $foundArtist, $foundTitle));
    }

    public function testFindPicksTheMatchingResultNotJustTheFirstOne(): void
    {
        $client = new DeezerClient(new MockHttpClient(fn (): MockResponse => $this->json([
            $this->row(1, 'Cover Band', 'Boskie Buenos', 'https://cdn.test/cover.mp3'),
            $this->row(2, 'Maanam', 'Boskie Buenos (2011 Remaster)', 'https://cdn.test/maanam.mp3'),
        ])));

        $hit = $client->find('Maanam', 'Boskie Buenos');

        self::assertNotNull($hit);
        // Pierwszy wynik to cover - wziecie go zamiast oryginalu psuje quiz.
        self::assertSame(2, $hit['id']);
        self::assertSame('https://cdn.test/maanam.mp3', $hit['preview']);
    }

    public function testStudioVersionBeatsALiveOneEvenWhenLiveComesFirst(): void
    {
        $client = new DeezerClient(new MockHttpClient(fn (): MockResponse => $this->json([
            $this->row(1, 'Republika', 'Biała flaga (Live)', 'https://cdn.test/live.mp3'),
            $this->row(2, 'Republika', 'Biała flaga', 'https://cdn.test/studio.mp3'),
        ])));

        $hit = $client->find('Republika', 'Biała flaga');

        self::assertSame(2, $hit['id'] ?? null);
    }

    public function testLiveVersionIsUsedWhenItIsTheOnlyOne(): void
    {
        $client = new DeezerClient(new MockHttpClient(fn (): MockResponse => $this->json([
            $this->row(1, 'Republika', 'Biała flaga (Live)', 'https://cdn.test/live.mp3'),
        ])));

        self::assertSame(1, $client->find('Republika', 'Biała flaga')['id'] ?? null);
    }

    public function testAskingForLiveExplicitlyIsNotTreatedAsAVariant(): void
    {
        self::assertFalse(DeezerClient::isVariant('Biała flaga (Live)', 'Biała flaga (Live)'));
        self::assertTrue(DeezerClient::isVariant('Biała flaga (Live)', 'Biała flaga'));
    }

    public function testFindFallsBackToLooseQueryWhenStrictOneFindsNothing(): void
    {
        $queries = [];
        $client = new DeezerClient(new MockHttpClient(function (string $method, string $url) use (&$queries): MockResponse {
            $queries[] = $url;

            return str_contains(urldecode($url), 'artist:')
                ? $this->json([])
                : $this->json([$this->row(7, 'Lady Pank', 'Zawsze tam gdzie ty', 'https://cdn.test/lp.mp3')]);
        }));

        $hit = $client->find('Lady Pank', 'Zawsze tam gdzie ty');

        self::assertSame(7, $hit['id'] ?? null);
        self::assertCount(2, $queries);
    }

    public function testFindReturnsNullWhenNothingMatches(): void
    {
        $client = new DeezerClient(new MockHttpClient(fn (): MockResponse => $this->json([
            $this->row(3, 'Ktos Inny', 'Zupelnie inna piosenka', 'https://cdn.test/x.mp3'),
        ])));

        self::assertNull($client->find('Maanam', 'Boskie Buenos'));
    }

    public function testQuotaErrorIsReportedNotSwallowed(): void
    {
        $client = new DeezerClient(new MockHttpClient(fn (): MockResponse => new MockResponse(
            (string) json_encode(['error' => ['type' => 'Exception', 'message' => 'Quota limit exceeded', 'code' => 4]]),
        )));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('limit');

        $client->find('Maanam', 'Boskie Buenos');
    }

    public function testEmptyDownloadIsAnError(): void
    {
        $client = new DeezerClient(new MockHttpClient(fn (): MockResponse => new MockResponse('')));

        $this->expectException(\RuntimeException::class);

        $client->download('https://cdn.test/pusty.mp3');
    }

    /** @param list<array<string, mixed>> $rows */
    private function json(array $rows): MockResponse
    {
        return new MockResponse((string) json_encode(['data' => $rows]), ['response_headers' => ['content-type: application/json']]);
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $artist, string $title, string $preview): array
    {
        return ['id' => $id, 'title' => $title, 'artist' => ['name' => $artist], 'preview' => $preview];
    }
}
