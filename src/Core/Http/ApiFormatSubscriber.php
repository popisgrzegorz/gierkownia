<?php

declare(strict_types=1);

namespace App\Core\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Wymusza format JSON na trasach API.
 *
 * Bez tego Symfony wybiera format renderowania bledu na podstawie naglowka
 * Accept - a klient, ktory go nie wysle, dostaje w odpowiedzi strone HTML
 * zamiast bledu do sparsowania. Telefon sterujacy jeszcze da sie tego nauczyc,
 * ale fizyczny przycisk ESP32 (spec 10) strzela golym POST-em i nic o Accept
 * nie wie, a musi rozpoznac, czy buzz zostal przyjety.
 */
final class ApiFormatSubscriber implements EventSubscriberInterface
{
    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        // Priorytet powyzej routingu: format ma byc ustawiony, zanim cokolwiek
        // zdazy rzucic wyjatkiem - lacznie z samym dopasowaniem trasy.
        return [KernelEvents::REQUEST => ['onRequest', 40]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (self::isApiPath($event->getRequest()->getPathInfo())) {
            $event->getRequest()->setRequestFormat('json');
        }
    }

    public static function isApiPath(string $path): bool
    {
        return str_starts_with($path, '/api/')
            || 1 === preg_match('#^/room/[A-Z]{4}/game(/|$)#', $path);
    }
}
