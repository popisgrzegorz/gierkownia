<?php

declare(strict_types=1);

namespace App\Core\Room;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
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

    public function qrDataUri(string $code, int $size = 320): string
    {
        return (new Builder(
            writer: new PngWriter(),
            data: $this->url($code),
            // Wyzsza korekcja bledow: QR jest fotografowany z drugiego konca pokoju,
            // pod katem i z odbiciem swiatla od telewizora.
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 12,
        ))->build()->getDataUri();
    }
}
