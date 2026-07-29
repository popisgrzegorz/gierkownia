<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Game\GameEngine;
use App\Core\Game\GameRegistry;
use App\Core\Realtime\Subscription;
use App\Core\Realtime\Topics;
use App\Core\Room\JoinLink;
use App\Core\Room\RoomAccess;
use App\Core\Room\RoomManager;
use App\Core\Score\Scoreboard;
use App\Core\Tournament\Tournament;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ekran hosta - telewizor w salonie (spec 10).
 */
final class HostController extends AbstractController
{
    public function __construct(
        private readonly RoomManager $rooms,
        private readonly RoomAccess $access,
        private readonly JoinLink $joinLink,
        private readonly Scoreboard $scoreboard,
        private readonly GameEngine $engine,
        private readonly GameRegistry $registry,
        private readonly Subscription $subscription,
        private readonly Tournament $tournament,
    ) {
    }

    #[Route('/host/{code}', name: 'host_screen', methods: ['GET'], requirements: ['code' => '[A-Z]{4}'])]
    public function screen(Request $request, string $code): Response
    {
        $room = $this->rooms->getOrFail($code);

        // Link z tokenem: pozwala przeniesc ekran hosta na inne urzadzenie
        // (laptop -> telewizor) bez zakladania pokoju od nowa. Token zapisujemy
        // w sesji i od razu przekierowujemy na czysty adres, zeby nie zostal
        // w historii przegladarki ani na pasku adresu przy gosciach.
        $token = (string) $request->query->get('t', '');

        if ('' !== $token && $this->rooms->isHostToken($room->code, $token)) {
            $this->access->rememberHost($request, $room->code, $token);

            return $this->redirectToRoute('host_screen', ['code' => $room->code]);
        }

        $this->access->requireHost($request, $room->code);

        // TTL liczy sie od ostatniej aktywnosci - dluga impreza nie moze
        // wygasnac pokoju w polowie gry.
        $this->rooms->touch($room->code);

        // Broadcast pokoju + prywatny topic hosta (podglad hasla, ktorego
        // zgadujacy widziec nie moga).
        $topics = [Topics::room($room->code), Topics::host($room->code)];
        $scores = $this->scoreboard->all($room->code);

        $response = $this->render('host/screen.html.twig', [
            'room' => $room,
            'hubUrl' => $this->subscription->url($request, $topics),
            // Panel gry dociagamy tylko gdy gra trwa; poza tym host wybiera
            // ja z listy modulow, ktorych rdzen nie zna z nazwy (spec 7).
            'gameType' => $this->engine->hasGame($room) ? $room->gameType : null,
            'games' => array_map(
                static fn ($game): array => ['type' => $game::type(), 'name' => $game->name()],
                array_values($this->registry->all()),
            ),
            'joinUrl' => $this->joinLink->url($room->code),
            'qr' => $this->joinLink->qrDataUri($room->code),
            'teams' => array_map(
                static fn ($team) => $team->toPublicArray() + ['score' => $scores[$team->id] ?? 0],
                $this->rooms->teams($room->code),
            ),
            // Tabela turnieju (punkty za miejsca) - do modala z wynikami. Tablo
            // z boku pokazuje surowy wynik biezacej gry, turniej sumuje miejsca.
            'tournament' => $this->tournament->standings($room),
            'playedGames' => $this->tournament->playedGames($room->code),
        ]);

        $response->headers->setCookie($this->subscription->cookie($request, $topics));

        return $response;
    }

    /**
     * Tablica wyników - sama lista uczestnikow posortowana po punktach, na zywo.
     *
     * Osobny, lekki widok do rzucenia na drugi ekran albo pokazania rankingu bez
     * planszy gry. Slucha wylacznie broadcastu pokoju (punkty sa jawne, nie ma
     * tu nic tajnego), wiec nie wymaga tokenu hosta - wystarczy znac kod pokoju.
     */
    #[Route('/host/{code}/tablo', name: 'host_standings', methods: ['GET'], requirements: ['code' => '[A-Z]{4}'])]
    public function standings(Request $request, string $code): Response
    {
        $room = $this->rooms->getOrFail($code);
        $this->rooms->touch($room->code);

        // Tylko broadcast pokoju: punkty lecą tędy do wszystkich, bez topicu hosta -
        // WYJATEK: jesli to sam host otworzyl te karte obok ekranu glownego,
        // dostaje ten sam (szerszy) zestaw topicow. Ciasteczko Mercure jest
        // jedno na przegladarke (nazwa i sciezka na sztywno w protokole) -
        // gdyby ta karta zapisala wezsze uprawnienia, nadpisalaby ciasteczko
        // ekranu hosta i po najblizszym reconnekcie TV przestalby dostawac
        // prywatne eventy (podglad hasla) bez zadnego komunikatu.
        $topics = $this->access->isHost($request, $room->code)
            ? [Topics::room($room->code), Topics::host($room->code)]
            : [Topics::room($room->code)];

        $response = $this->render('host/standings.html.twig', [
            'room' => $room,
            'hubUrl' => $this->subscription->url($request, $topics),
            'tournament' => $this->tournament->standings($room),
            'playedGames' => $this->tournament->playedGames($room->code),
        ]);

        $response->headers->setCookie($this->subscription->cookie($request, $topics));

        return $response;
    }
}
