<?php

declare(strict_types=1);

namespace App\Core\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Doklada tresc wyjatku do JSON-owej odpowiedzi bledu na trasach API.
 *
 * Symfony w produkcji (debug=false) swiadomie chowa getMessage() kazdego
 * wyjatku za samym tekstem statusu HTTP ("Bad Request", "Forbidden") -
 * patrz Symfony\Component\Serializer\Normalizer\ProblemNormalizer, ktora dla
 * debug=false zawsze bierze $object->getStatusText() zamiast wiadomosci
 * wyjatku. To sluszne zabezpieczenie dla nieoczekiwanych bledow - ale
 * IllegalActionException (i inne HttpExceptionInterface rzucane w tej
 * aplikacji: zla kolej, zle haslo, zbyt krotkie slowo, brak druzyny) sa
 * PISANE z mysla o pokazaniu ich tresci graczowi. Bez tego kazdy telefon,
 * niezaleznie od modulu gry, dostawalby zamiast tego samo "Bad Request" -
 * cichy blad, ktory wyszedl dopiero przy realnym uzyciu, bo testy HTTP w tym
 * repo licza sie w srodowisku testowym (debug wlaczone), a nie na serwerze
 * produkcyjnym z JSON-em.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        // Priorytet wyzej niz domyslny ErrorListener frameworka (-128) - nasza
        // odpowiedz ma zdazyc, zanim frameworkowy renderer zdąrzy zbudowac
        // swoja (ogolna) wersje i zatrzymac dalsze przetwarzanie zdarzenia.
        return [KernelEvents::EXCEPTION => ['onException', -1]];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!ApiFormatSubscriber::isApiPath($event->getRequest()->getPathInfo())) {
            return;
        }

        $exception = $event->getThrowable();

        if (!$exception instanceof HttpExceptionInterface) {
            return;
        }

        $event->setResponse(new JsonResponse(
            [
                'type' => 'https://tools.ietf.org/html/rfc2616#section-10',
                'title' => 'An error occurred',
                'status' => $exception->getStatusCode(),
                'detail' => $exception->getMessage(),
            ],
            $exception->getStatusCode(),
            $exception->getHeaders(),
        ));

        $event->stopPropagation();
    }
}
