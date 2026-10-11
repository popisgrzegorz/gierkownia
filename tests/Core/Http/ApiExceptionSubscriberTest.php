<?php

declare(strict_types=1);

namespace App\Tests\Core\Http;

use App\Core\Game\IllegalActionException;
use App\Core\Http\ApiExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Symfony w produkcji (debug=false) chowa getMessage() KAZDEGO wyjatku za
 * samym tekstem statusu HTTP (Symfony\Component\Serializer\Normalizer\ProblemNormalizer,
 * linia `$debug ? $object->getMessage() : $object->getStatusText()`) - i to
 * niezaleznie od typu wyjatku. Testy HTTP w tym repo licza sie w srodowisku
 * testowym (debug wlaczone), wiec ten problem nigdy sie w nich nie ujawnil -
 * dopiero prawdziwy telefon na produkcji dostawal zamiast "Nie twoja kolej."
 * gole "Bad Request". Test buduje ExceptionEvent recznie (bez pelnego
 * WebTestCase), zeby sprawdzic zachowanie subskrybenta niezaleznie od trybu
 * debug calego jadra.
 */
final class ApiExceptionSubscriberTest extends TestCase
{
    private ApiExceptionSubscriber $subscriber;
    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        $this->subscriber = new ApiExceptionSubscriber();
        $this->kernel = $this->createStub(HttpKernelInterface::class);
    }

    public function testApiPathExceptionCarriesTheRealMessage(): void
    {
        $event = $this->exceptionEvent('/room/ABCD/game/action', new IllegalActionException('Nie twoja kolej.'));

        $this->subscriber->onException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Nie twoja kolej.', $data['detail']);
        self::assertTrue($event->isPropagationStopped());
    }

    public function testApiPrefixIsAlsoCovered(): void
    {
        $event = $this->exceptionEvent('/api/cokolwiek', new IllegalActionException('Szczegoly.'));

        $this->subscriber->onException($event);

        self::assertNotNull($event->getResponse());
    }

    public function testNonApiPathIsLeftToTheDefaultRenderer(): void
    {
        $event = $this->exceptionEvent('/host/ABCD', new IllegalActionException('Nie twoja kolej.'));

        $this->subscriber->onException($event);

        self::assertNull($event->getResponse());
    }

    /** Nie-HttpExceptionInterface (nieoczekiwany blad) zostaje ogolny - to nie regresja, to bezpieczenstwo. */
    public function testNonHttpExceptionIsLeftToTheDefaultRenderer(): void
    {
        $event = $this->exceptionEvent('/room/ABCD/game/action', new \RuntimeException('Szczegoly wewnetrzne.'));

        $this->subscriber->onException($event);

        self::assertNull($event->getResponse());
    }

    public function testNotFoundHttpExceptionAlsoGetsItsRealMessage(): void
    {
        $event = $this->exceptionEvent('/room/ABCD/game/action', new NotFoundHttpException('Nie ma takiego pokoju.'));

        $this->subscriber->onException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Nie ma takiego pokoju.', $data['detail']);
    }

    private function exceptionEvent(string $path, \Throwable $throwable): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->kernel,
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }
}
