<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Brama do panelu EasyAdmina (/panel).
 *
 * Stary panel sprawdza dostep w kazdej akcji kontrolera, ale kontrolerow
 * EasyAdmina nie piszemy sami - trasy generuje bundle. Dlatego pilnujemy
 * sciezki, a nie metody: inaczej kazda nowa akcja CRUD-a wchodzilaby do
 * aplikacji bez zabezpieczenia i nikt by tego nie zauwazyl.
 *
 * Uzywamy tej samej sesji i tego samego hasla co /admin - aplikacja nie ma
 * uzytkownikow w rozumieniu security-bundle, a dokladanie ich tylko po to,
 * zeby chronic jeden panel, byloby zamiana jednego mechanizmu na dwa.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
final readonly class AdminPanelGuard
{
    private const PREFIX = '/panel';

    public function __construct(
        private AdminAccess $access,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        if (self::PREFIX !== $path && !str_starts_with($path, self::PREFIX.'/')) {
            return;
        }

        if (!$this->access->needsLogin($event->getRequest())) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urls->generate($this->access->isEnabled() ? 'admin_login' : 'admin_categories'),
        ));
    }
}
