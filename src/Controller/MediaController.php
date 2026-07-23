<?php

declare(strict_types=1);

namespace App\Controller;

use App\Content\MediaLibrary;
use App\Core\Room\RoomAccess;
use App\Core\Room\RoomManager;
use App\Repository\TrackRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serwuje plik utworu na ekran hosta.
 *
 * Trasa celowo nie zawiera nazwy pliku, tylko id: sciezka "kombii-pokolenie.mp3"
 * w adresie zdradzalaby odpowiedz kazdemu, kto zajrzy do narzedzi
 * deweloperskich - a to caly sens tej gry.
 *
 * Dostep ma wylacznie host pokoju. Dzwiek leci z jednego glosnika przy
 * telewizorze (spec 10), wiec telefon nie ma powodu pobierac utworu.
 *
 * Utwor z odnosnikiem zewnetrznym odsylamy przekierowaniem - przegladarka
 * pobiera go wprost ze zrodla, a my nie trzymamy ani bajta cudzego nagrania.
 * Adres i tak nie zdradza odpowiedzi, bo pliki maja nazwy z samych cyfr.
 */
final class MediaController extends AbstractController
{
    public function __construct(
        private readonly RoomManager $rooms,
        private readonly RoomAccess $access,
        private readonly TrackRepository $tracks,
        private readonly MediaLibrary $media,
    ) {
    }

    #[Route('/host/{code}/media/{id}', name: 'host_media', methods: ['GET'], requirements: ['code' => '[A-Z]{4}', 'id' => '\d+'])]
    public function track(Request $request, string $code, int $id): Response
    {
        $this->rooms->getOrFail($code);
        $this->access->requireHost($request, $code);

        $track = $this->tracks->find($id)
            ?? throw new NotFoundHttpException(sprintf('Nie ma utworu o id %d.', $id));

        $external = $track->getSourceUrl();

        if (null !== $external) {
            // Przekierowanie, nie posrednictwo: przy posredniczeniu kazde
            // odtworzenie szloby przez nasz serwer razem z obsluga naglowka
            // Range, ktory <audio> wysyla przy przewijaniu do startAt.
            return $this->redirect($external);
        }

        $relative = $track->getPath()
            ?? throw new NotFoundHttpException(sprintf('Utwor %d nie ma zadnego zrodla dzwieku.', $id));

        $path = $this->media->absolutePath($relative);

        if (!is_file($path)) {
            throw new NotFoundHttpException(sprintf('Brakuje pliku "%s" w katalogu mediow.', $relative));
        }

        $response = new BinaryFileResponse($path);

        // Range jest obowiazkowy dla <audio>: bez niego przegladarka nie umie
        // przewinac utworu ani zaczac od podanej sekundy (startAt w talii).
        // BinaryFileResponse obsluguje go sam, o ile pozwolimy na cache'owanie
        // po stronie przegladarki.
        $response->setAutoLastModified();
        $response->setAutoEtag();
        $response->setPrivate();

        return $response;
    }
}
