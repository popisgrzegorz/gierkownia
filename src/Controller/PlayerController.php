<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Game\GameEngine;
use App\Core\Realtime\Subscription;
use App\Core\Realtime\Topics;
use App\Core\Room\RoomAccess;
use App\Core\Room\RoomManager;
use App\Core\Score\Scoreboard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sterownik druzyny - telefon w rece gracza (spec 10).
 */
final class PlayerController extends AbstractController
{
    public function __construct(
        private readonly RoomManager $rooms,
        private readonly RoomAccess $access,
        private readonly Scoreboard $scoreboard,
        private readonly GameEngine $engine,
        private readonly Subscription $subscription,
    ) {
    }

    #[Route('/play/{code}/controller', name: 'player_controller', methods: ['GET'], requirements: ['code' => '[A-Z]{4}'])]
    public function controller(Request $request, string $code): Response
    {
        $room = $this->rooms->getOrFail($code);

        // Link z tokenem druzyny - pozwala przeniesc sterownik na inne urzadzenie
        // (padla bateria, telefon przejmuje ktos inny) bez zakladania druzyny
        // od nowa. Token zapamietujemy w ciasteczku i czyscimy adres, zeby nie
        // zostal w historii ani nie trafil komus przez ramie.
        $token = (string) $request->query->get('t', '');

        if ('' !== $token && null !== $this->rooms->teamByDeviceToken($room->code, $token)) {
            $response = $this->redirectToRoute('player_controller', ['code' => $room->code]);
            $this->access->rememberTeam($response, $room->code, $token);

            return $response;
        }

        $team = $this->access->team($request, $room->code);

        if (null === $team) {
            return $this->redirectToRoute('player_join', ['code' => $room->code]);
        }

        // Broadcast pokoju + prywatny topic tej druzyny (np. slowo dla aktora
        // w kalamburach - reszta pokoju nie moze go zobaczyc).
        $topics = [Topics::room($room->code), Topics::team($room->code, $team->id)];

        // Widok modulu dociagamy tylko wtedy, gdy gra faktycznie trwa - poza
        // rozgrywka telefon pokazuje sam wynik i nie potrzebuje nic wiecej.
        $running = $this->engine->hasGame($room);

        $response = $this->render('player/controller.html.twig', [
            'room' => $room,
            'team' => $team->toPublicArray(),
            'score' => $this->scoreboard->get($room->code, $team->id),
            'hubUrl' => $this->subscription->url($request, $topics),
            'gameType' => $running ? $this->rooms->getOrFail($room->code)->gameType : null,
            'game' => $running ? $this->engine->playerView($room, $team) : [],
        ]);

        $response->headers->setCookie($this->subscription->cookie($request, $topics));

        return $response;
    }
}
