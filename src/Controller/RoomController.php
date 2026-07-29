<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Realtime\Event;
use App\Core\Realtime\Publisher;
use App\Core\Room\RoomAccess;
use App\Core\Room\RoomManager;
use App\Core\Room\TeamNameTakenException;
use App\Core\Score\Scoreboard;
use App\Core\Tournament\Tournament;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cykl zycia pokoju: utworz, dolacz, resetuj (spec 12).
 */
final class RoomController extends AbstractController
{
    public function __construct(
        private readonly RoomManager $rooms,
        private readonly RoomAccess $access,
        private readonly Publisher $publisher,
        private readonly Scoreboard $scoreboard,
        private readonly Tournament $tournament,
        private readonly RateLimiterFactory $roomJoinLimiter,
        private readonly RateLimiterFactory $roomStateLimiter,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('home.html.twig');
    }

    #[Route('/room', name: 'room_create', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('room', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Sesja wygasła - odśwież stronę i spróbuj ponownie.');

            return $this->redirectToRoute('home');
        }

        $room = $this->rooms->create();
        $this->access->rememberHost($request, $room->code, $room->hostToken);

        return $this->redirectToRoute('host_screen', ['code' => $room->code]);
    }

    /**
     * Wejscie kodem, gdy ktos nie zeskanowal QR. Kod normalizujemy do wielkich
     * liter, bo klawiatura telefonu domyslnie pisze malymi.
     */
    #[Route('/join', name: 'room_join_by_code', methods: ['GET'])]
    public function joinByCode(Request $request): RedirectResponse
    {
        $code = mb_strtoupper(trim((string) $request->query->get('code', '')));

        if (!preg_match('/^[A-Z]{4}$/', $code) || !$this->rooms->exists($code)) {
            $this->addFlash('error', sprintf('Nie ma pokoju o kodzie "%s".', $code));

            return $this->redirectToRoute('home');
        }

        return $this->redirectToRoute('player_join', ['code' => $code]);
    }

    /**
     * Ekran dolaczania na telefonie. Jesli telefon juz jest w tym pokoju,
     * ciasteczko go rozpoznaje i idzie prosto do sterownika - po odswiezeniu
     * strony nikt nie zaklada drugiej druzyny o tej samej nazwie.
     */
    #[Route('/play/{code}', name: 'player_join', methods: ['GET'], requirements: ['code' => '[A-Z]{4}'])]
    public function joinForm(Request $request, string $code): Response
    {
        $room = $this->rooms->getOrFail($code);

        if (null !== $this->access->team($request, $room->code)) {
            return $this->redirectToRoute('player_controller', ['code' => $room->code]);
        }

        return $this->render('player/join.html.twig', [
            'room' => $room,
            'teams' => array_map(static fn ($t) => $t->toPublicArray(), $this->rooms->teams($room->code)),
        ]);
    }

    #[Route('/room/{code}/join', name: 'room_join', methods: ['POST'], requirements: ['code' => '[A-Z]{4}'])]
    public function join(Request $request, string $code): Response
    {
        $room = $this->rooms->getOrFail($code);
        $name = (string) $request->request->get('name', '');

        if (!$this->isCsrfTokenValid('room', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Sesja wygasła - odśwież stronę i spróbuj ponownie.');

            return $this->redirectToRoute('player_join', ['code' => $room->code]);
        }

        // Bez limitu skrypt zasypuje jeden pokoj tysiacami druzyn (P2-7 audytu);
        // prawdziwa impreza dolacza kilkanascie telefonow w minute, wiec limit
        // jej nie dotyka.
        if (!$this->roomJoinLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            $this->addFlash('error', 'Zbyt wiele prob dolaczenia - odczekaj chwile i sprobuj ponownie.');

            return $this->redirectToRoute('player_join', ['code' => $room->code]);
        }

        if ('' === trim($name)) {
            $this->addFlash('error', 'Podaj nazwe druzyny.');

            return $this->redirectToRoute('player_join', ['code' => $room->code]);
        }

        // Nazwa juz zajeta w pokoju: wpuszczamy cicho z powrotem tylko urzadzenie,
        // ktore juz ma tu wazny token JAKIEJS druzyny (np. odswiezenie po zerwanym
        // polaczeniu) - inaczej kazdy, kto zna nazwe cudzej druzyny, przejalby ja
        // samym wpisaniem jej w formularzu.
        $knownDevice = null !== $this->access->team($request, $room->code);

        try {
            $team = $this->rooms->join($room->code, mb_substr(trim($name), 0, 24), $knownDevice);
        } catch (TeamNameTakenException) {
            $this->addFlash('error', 'Ta nazwa jest juz zajeta - wybierz inna.');

            return $this->redirectToRoute('player_join', ['code' => $room->code]);
        }

        // Tablo hosta aktualizuje sie samo - bez tego trzeba by odswiezac TV recznie.
        $this->publisher->broadcast($room->code, new Event(Event::TEAM_JOINED, [
            'team' => $team->toPublicArray(),
            'score' => $this->scoreboard->get($room->code, $team->id),
        ]));

        $response = $this->redirectToRoute('player_controller', ['code' => $room->code]);
        $this->access->rememberTeam($request, $response, $room->code, $team->deviceToken);

        return $response;
    }

    #[Route('/room/{code}/reset', name: 'room_reset', methods: ['POST'], requirements: ['code' => '[A-Z]{4}'])]
    public function reset(Request $request, string $code): Response
    {
        $this->rooms->getOrFail($code);
        $this->access->requireHost($request, $code);

        if (!$this->isCsrfTokenValid('room', (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Token CSRF wygasł lub jest nieprawidłowy - odśwież stronę i spróbuj ponownie.');
        }

        $this->rooms->resetGame($code);
        // Reset to "zaczynamy wieczor od nowa" - kasuje tez tabele turnieju,
        // inaczej punkty za miejsca z poprzedniej imprezy zostalyby doliczone.
        $this->tournament->clear($code);
        $this->publisher->broadcast($code, new Event(Event::RESET));

        return $this->redirectToRoute('host_screen', ['code' => $code]);
    }

    /**
     * Stan pokoju do odtworzenia widoku. Klient siega tu raz - przy wejsciu
     * i po zerwaniu polaczenia SSE. To nie jest polling (spec 15).
     */
    #[Route('/api/room/{code}/state', name: 'room_state', methods: ['GET'], requirements: ['code' => '[A-Z]{4}'])]
    public function state(Request $request, string $code): JsonResponse
    {
        // Kod pokoju to 4 litery - bez limitu przejscie calej puli i podejrzenie
        // nazw druzyn/punktow zajmuje ~40 min (P2-7 audytu).
        if (!$this->roomStateLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Zbyt wiele zapytan - sprobuj ponownie za chwile.');
        }

        $room = $this->rooms->getOrFail($code);
        $scores = $this->scoreboard->all($room->code);

        return new JsonResponse([
            'code' => $room->code,
            'gameType' => $room->gameType,
            'phase' => $room->phase,
            'teams' => array_map(
                static fn ($team) => $team->toPublicArray() + ['score' => $scores[$team->id] ?? 0],
                $this->rooms->teams($room->code),
            ),
        ]);
    }
}
