<?php

declare(strict_types=1);

namespace App\Core\Room;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Link do dolaczenia + kod QR na ekran hosta.
 *
 * Adres bierze sie z aktualnego zadania, a nie z konfiguracji: host otwiera TV
 * pod adresem LAN, wiec wygenerowany URL jest z definicji tym, ktory dziala
 * w tej samej sieci. Wpisanie tu localhost dalo by QR nie do zeskanowania.
 *
 * QR renderujemy lokalnie do data URI - spec 15 zabrania zewnetrznych CDN,
 * a na imprezie i tak nie ma internetu.
 */
final readonly class JoinLink
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function url(string $code): string
    {
        return $this->urlGenerator->generate(
            'player_join',
            ['code' => $code],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /**
     * Kod QR jako data URI.
     *
     * SVG, a nie PNG: PngWriter wymaga rozszerzenia GD, ktorego na serwerze
     * moze nie byc (i nie bylo - "Unable to generate image" przy zakladaniu
     * pokoju). Wektor jest przy okazji ostrzejszy na duzym telewizorze,
     * bo skaluje sie bez rozmycia niezaleznie od przekatnej.
     */
    public function qrDataUri(string $code, int $size = 320): string
    {
        return (new Builder(
            writer: new SvgWriter(),
            data: $this->url($code),
            // Wyzsza korekcja bledow: QR jest fotografowany z drugiego konca pokoju,
            // pod katem i z odbiciem swiatla od telewizora.
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 12,
        ))->build()->getDataUri();
    }

    /**
     * Link, ktory przenosi uprawnienia hosta na INNE urzadzenie (np. tablet do
     * rysowania w kalamburach rysowanych) - ten sam mechanizm, ktorego
     * HostController::screen() juz uzywa do przeniesienia ekranu z laptopa na
     * telewizor (parametr `t`), tylko teraz swiadomie dzielony miedzy dwa
     * rownoczesne urzadzenia zamiast jednego nastepujacego po drugim.
     *
     * Token daje PELNA kontrole nad pokojem - link nie moze trafic na ekran,
     * ktory widzi cala sala (patrz ostrzezenie w szablonie, ktory go pokazuje).
     */
    /**
     * `$role` to opcjonalna, przezroczysta dla tej klasy podpowiedz dla ekranu
     * docelowego (np. "tablet") - HostController przepuszcza ja bez zmian
     * przez przekierowanie zdejmujace token z adresu, a to, co dana rola
     * znaczy, wie wylacznie kontroler JS konkretnej gry (patrz
     * rysowanki_host_controller.js) - ten link nie wie nic o "tablecie" per se.
     */
    public function hostTransferUrl(string $code, string $hostToken, ?string $role = null): string
    {
        $params = ['code' => $code, 't' => $hostToken];

        if (null !== $role) {
            $params['role'] = $role;
        }

        return $this->urlGenerator->generate('host_screen', $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function hostTransferQrDataUri(string $code, string $hostToken, int $size = 240, ?string $role = null): string
    {
        return (new Builder(
            writer: new SvgWriter(),
            data: $this->hostTransferUrl($code, $hostToken, $role),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 12,
        ))->build()->getDataUri();
    }
}
