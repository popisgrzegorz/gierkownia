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
    public function track(Request $request, string $code, int $id): BinaryFileResponse
    {
        $this->rooms->getOrFail($code);
        $this->access->requireHost($request, $code);

        $track = $this->tracks->find($id)
            ?? throw new NotFoundHttpException(sprintf('Nie ma utworu o id %d.', $id));

        $path = $this->media->absolutePath($track->getPath());

        if (!is_file($path)) {
            throw new NotFoundHttpException(sprintf('Brakuje pliku "%s" w katalogu mediow.', $track->getPath()));
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
