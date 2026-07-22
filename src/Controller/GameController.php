<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Game\Actor;
use App\Core\Game\GameEngine;
use App\Core\Game\GameRegistry;
use App\Core\Room\RoomAccess;
use App\Core\Room\RoomManager;
use App\Core\Score\Scoreboard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Akcje rozgrywki (spec 12). Kontroler nie zna zadnej konkretnej gry -
 * rozpoznaje tylko, kto pyta, i oddaje sprawe silnikowi.
 */
#[Route('/room/{code}/game', requirements: ['code' => '[A-Z]{4}'])]
final class GameController extends AbstractController
{
    public function __construct(
        private readonly RoomManager $rooms,
        private readonly RoomAccess $access,
        private readonly GameEngine $engine,
        private readonly GameRegistry $registry,
        private readonly Scoreboard $scoreboard,
    ) {
    }

    #[Route('/start', name: 'game_start', methods: ['POST'])]
    public function start(Request $request, string $code): JsonResponse
    {
        $room = $this->rooms->getOrFail($code);
        $this->access->requireHost($request, $room->code);

        $gameType = $this->payload($request)['gameType'] ?? null;

        if (!\is_string($gameType) || '' === $gameType) {
            throw new BadRequestHttpException('Podaj gameType.');
        }

        $game = $this->engine->start($room, $gameType);

        return new JsonResponse([
            'gameType' => $game::type(),
            'name' => $game->name(),
            'state' => $game->definition()->initialState,
        ]);
    }

    #[Route('/action', name: 'game_action', methods: ['POST'])]
    public function action(Request $request, string $code): JsonResponse
    {
        $room = $this->rooms->getOrFail($code);
        $payload = $this->payload($request);

        $action = $payload['action'] ?? null;

        if (!\is_string($action) || '' === $action) {
            throw new BadRequestHttpException('Podaj nazwe akcji.');
        }

        $data = $payload['payload'] ?? [];
        $result = $this->engine->handle(
            $room,
            $action,
            \is_array($data) ? $this->stringKeyed($data) : [],
            $this->actor($request, $room->code),
        );

        return new JsonResponse(['ok' => true, ...$result]);
    }

    /**
     * Punktacja jest prymitywem rdzenia (spec 8), nie mechanika gry - host
     * musi moc poprawic wynik takze wtedy, gdy zadna gra nie jest uruchomiona.
     */
    #[Route('/score', name: 'game_score', methods: ['POST'])]
    public function score(Request $request, string $code): JsonResponse
    {
        $room = $this->rooms->getOrFail($code);
        $this->access->requireHost($request, $room->code);

        $payload = $this->payload($request);
        $teamId = $payload['teamId'] ?? null;
        $delta = $payload['delta'] ?? null;

        if (!\is_string($teamId) || null === $this->rooms->team($room->code, $teamId)) {
            throw new BadRequestHttpException('Nie ma takiej druzyny w tym pokoju.');
        }

        if (!\is_int($delta)) {
            throw new BadRequestHttpException('delta musi byc liczba calkowita.');
        }

        return new JsonResponse([
            'teamId' => $teamId,
            'total' => $this->scoreboard->add($room->code, $teamId, $delta),
        ]);
    }

    /**
     * Widok gry dla ekranu hosta.
     *
     * Osobne zadanie, a nie doklejenie do broadcastu: te dane bywaja tajne
     * (tytul utworu, haslo w kole), a broadcast leci na wszystkie telefony
     * w pokoju. Panel hosta odpytuje ten adres po kazdej zmianie fazy.
     */
    #[Route('/view', name: 'game_view', methods: ['GET'])]
    public function view(Request $request, string $code): JsonResponse
    {
        $room = $this->rooms->getOrFail($code);
        $this->access->requireHost($request, $room->code);

        return new JsonResponse($this->engine->hostView($room));
    }

    /** Lista dostepnych modulow - do ekranu wyboru gry. */
    #[Route('/available', name: 'game_available', methods: ['GET'])]
    public function available(string $code): JsonResponse
    {
        $this->rooms->getOrFail($code);

        return new JsonResponse([
            'games' => array_map(
                static fn ($game): array => ['type' => $game::type(), 'name' => $game->name()],
                array_values($this->registry->all()),
            ),
        ]);
    }

    /**
     * Rozpoznaje nadawcę akcji. Host ma pierwszenstwo: gdy ktos otworzy
     * sterownik druzyny na tym samym urzadzeniu co ekran hosta, akcje hosta
     * nadal maja dzialac.
     */
    private function actor(Request $request, string $code): Actor
    {
        if ($this->access->isHost($request, $code)) {
            return Actor::host();
        }

        $team = $this->access->team($request, $code);

        return null !== $team
            ? Actor::team($team)
            : throw new AccessDeniedHttpException('Nie rozpoznano ani hosta, ani druzyny.');
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        if (!str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            return $this->stringKeyed($request->request->all());
        }

        $decoded = json_decode($request->getContent(), true);

        return \is_array($decoded) ? $this->stringKeyed($decoded) : [];
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private function stringKeyed(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (\is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
