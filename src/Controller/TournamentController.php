<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Room\RoomAccess;
use App\Core\Room\RoomManager;
use App\Core\Tournament\TournamentArchive;
use App\Core\Tournament\TournamentNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Zapis i wznowienie turnieju miedzy grami.
 *
 * Zrzut idzie na dysk (jeden plik JSON), nie do bazy - patrz TournamentArchive.
 * Wznowienie zaklada NOWY pokoj z odtworzonymi druzynami i tabela punktow;
 * gracze wracaja skanujac nowy kod i wpisujac te sama nazwe druzyny.
 */
final class TournamentController extends AbstractController
{
    public function __construct(
        private readonly RoomManager $rooms,
        private readonly RoomAccess $access,
        private readonly TournamentArchive $archive,
    ) {
    }

    #[Route('/host/{code}/turniej/zapisz', name: 'tournament_save', methods: ['POST'], requirements: ['code' => '[A-Z]{4}'])]
    public function save(Request $request, string $code): Response
    {
        $room = $this->rooms->getOrFail($code);
        $this->access->requireHost($request, $room->code);

        $name = $request->request->get('name');
        $this->archive->save($room, \is_string($name) ? $name : '');

        $this->addFlash('success', 'Turniej zapisany — wróćcie do niego z listy „Wznów zapisany turniej".');

        // Wracamy na ekran gry, a nie na liste: zapis to migawka w tle, host gra
        // dalej. Zapis czeka na liscie „Wznów zapisany turniej".
        return $this->redirectToRoute('host_screen', ['code' => $room->code]);
    }

    #[Route('/turnieje', name: 'tournament_list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('tournament/list.html.twig', [
            'sessions' => $this->archive->list(),
        ]);
    }

    #[Route('/turnieje/{id}/wznow', name: 'tournament_restore', methods: ['POST'], requirements: ['id' => '[1-9A-HJ-NP-Za-km-z]+'])]
    public function restore(Request $request, string $id): Response
    {
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
}
