<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\AdminAccess;
use App\Core\Room\RoomAccess;
use App\Core\Room\RoomManager;
use App\Core\Tournament\TournamentArchive;
use App\Core\Tournament\TournamentNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Zapis i wznowienie turnieju miedzy grami.
 *
 * Zrzut idzie na dysk (jeden plik JSON), nie do bazy - patrz TournamentArchive.
 * Wznowienie zaklada NOWY pokoj z odtworzonymi druzynami i tabela punktow;
 * gracze wracaja skanujac nowy kod i wpisujac te sama nazwe druzyny.
 *
 * Lista zapisow i wznowienie NIE maja pokoju do sprawdzenia przez RoomAccess
 * (wznowienie dopiero zaklada nowy) - a bez zadnej bramy kazdy w internecie
 * widzialby nazwy druzyn z minionych imprez i mogl jednym POST-em wznowic
 * cudzy turniej, stajac sie jego hostem. Chronimy tym samym haslem co /panel:
 * appka i tak nie ma kont uzytkownikow, a dokladanie drugiego mechanizmu tylko
 * dla tej jednej strony byloby zamiana jednego sekretu na dwa.
 */
final class TournamentController extends AbstractController
{
    public function __construct(
        private readonly RoomManager $rooms,
        private readonly RoomAccess $access,
        private readonly TournamentArchive $archive,
        private readonly AdminAccess $adminAccess,
    ) {
    }

    #[Route('/host/{code}/turniej/zapisz', name: 'tournament_save', methods: ['POST'], requirements: ['code' => '[A-Z]{4}'])]
    public function save(Request $request, string $code): Response
    {
        $room = $this->rooms->getOrFail($code);
        $this->access->requireHost($request, $room->code);

        if (!$this->isCsrfTokenValid('room', (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Token CSRF wygasł lub jest nieprawidłowy - odśwież stronę i spróbuj ponownie.');
        }

        $name = $request->request->get('name');
        $this->archive->save($room, \is_string($name) ? $name : '');

        $this->addFlash('success', 'Turniej zapisany — wróćcie do niego z listy „Wznów zapisany turniej".');

        // Wracamy na ekran gry, a nie na liste: zapis to migawka w tle, host gra
        // dalej. Zapis czeka na liscie „Wznów zapisany turniej".
        return $this->redirectToRoute('host_screen', ['code' => $room->code]);
    }

    #[Route('/turnieje', name: 'tournament_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        return $this->render('tournament/list.html.twig', [
            'sessions' => $this->archive->list(),
        ]);
    }

    #[Route('/turnieje/{id}/wznow', name: 'tournament_restore', methods: ['POST'], requirements: ['id' => '[1-9A-HJ-NP-Za-km-z]+'])]
    public function restore(Request $request, string $id): Response
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        if (!$this->isCsrfTokenValid('admin', (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Token CSRF wygasł lub jest nieprawidłowy - odśwież stronę i spróbuj ponownie.');
        }

        try {
            $room = $this->archive->restore($id);
        } catch (TournamentNotFoundException) {
            $this->addFlash('error', 'Nie ma już takiego zapisu.');

            return $this->redirectToRoute('tournament_list');
        }

        // Wznawiajacy staje sie hostem nowego pokoju - jak przy zakladaniu gry.
        $this->access->rememberHost($request, $room->code, $room->hostToken);

        return $this->redirectToRoute('host_screen', ['code' => $room->code]);
    }

    /**
     * Odsyla na logowanie panelu, jesli haslo administratora nie zostalo podane.
     * Zwraca null, gdy wszystko w porzadku i akcja moze isc dalej. Ta sama
     * konwencja co AdminController::guard().
     *
     * Doklejamy "next", zeby po zalogowaniu wrocic na LISTE TURNIEJOW, a nie
     * na sztywno do panelu tresci - inaczej klikniecie "Wznow zapisany
     * turniej" na stronie glownej ladowalo po zalogowaniu w zupelnie innym
     * miejscu, a admin musial klikac dalej sam.
     */
    private function guard(Request $request): ?Response
    {
        if (!$this->adminAccess->isEnabled()) {
            return $this->render('admin/disabled.html.twig');
        }

        if ($this->adminAccess->needsLogin($request)) {
            $next = $request->isMethod('GET') ? $request->getRequestUri() : null;

            return $this->redirectToRoute('admin_login', array_filter(['next' => $next]));
        }

        return null;
    }
}
