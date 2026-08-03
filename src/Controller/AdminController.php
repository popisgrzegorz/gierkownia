<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\AdminAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Logowanie i wylogowanie z panelu tresci.
 *
 * Zarzadzanie kategoriami, nagraniami i haslami jest w /panel (EasyAdmin,
 * App\Controller\Admin\*CrudController) - ten kontroler zostal tylko po to,
 * zeby oba panele mialy wspolna sesje i wspolne haslo (AdminPanelGuard
 * odsyla tutaj, gdy ktos wejdzie na /panel bez logowania).
 */
#[Route('/admin')]
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly AdminAccess $access,
        private readonly RateLimiterFactory $adminLoginLimiter,
    ) {
    }

    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if (!$this->access->isEnabled()) {
            return $this->render('admin/disabled.html.twig');
        }

        // Dokad wrocic po zalogowaniu - np. do listy zapisanych turniejow,
        // skad ktos wpadl tu przez brame TournamentController::guard(),
        // a nie na sztywno do panelu tresci.
        $next = $this->access->safeNextPath($request);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin', (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Sesja wygasła - spróbuj ponownie.');

                return $this->redirectToRoute('admin_login', array_filter(['next' => $next]));
            }

            // Haslo panelu jest jedno, wspolne i slownikowe - bez tego limitu
            // do zlamania brute-force'em starczy kilka godzin (P2-7 audytu).
            if (!$this->adminLoginLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $this->addFlash('error', 'Zbyt wiele prob logowania - sprobuj ponownie za kilkanascie minut.');

                return $this->redirectToRoute('admin_login', array_filter(['next' => $next]));
            }

            if ($this->access->login($request, (string) $request->request->get('password', ''))) {
                return null !== $next ? $this->redirect($next) : $this->redirectToRoute('panel');
            }

            $this->addFlash('error', 'Nieprawidłowe hasło.');
        }

        if ($this->access->isLoggedIn($request)) {
            return null !== $next ? $this->redirect($next) : $this->redirectToRoute('panel');
        }

        return $this->render('admin/login.html.twig', ['next' => $next]);
    }

    /** Formularz wylogowania - link z /panel prowadzi tutaj, bo wylogowanie samo jest akcja POST z CSRF. */
    #[Route('/wyloguj', name: 'admin_logout_page', methods: ['GET'])]
    public function logoutPage(): Response
    {
        return $this->render('admin/logout.html.twig');
    }

    #[Route('/logout', name: 'admin_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        if ($this->isCsrfTokenValid('admin', (string) $request->request->get('_csrf_token'))) {
            $this->access->logout($request);
        }

        return $this->redirectToRoute('admin_login');
    }
}
