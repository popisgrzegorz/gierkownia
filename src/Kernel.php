<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug)
    {
        // Bez tego PHP liczy wszystko w UTC (domyslne, gdy date.timezone nie
        // jest ustawione w php.ini serwera) - daty zapisanych turniejow
        // (Tournament\TournamentArchive, filtr Twig `date`) wychodzily
        // przesuniete o 1-2h wzgledem prawdziwej pory imprezy. Ustawione tu,
        // a nie w php.ini, zeby dzialalo tak samo po przenosinach na kazda
        // nowa maszyne bez recznej konfiguracji.
        date_default_timezone_set('Europe/Warsaw');

        parent::__construct($environment, $debug);
    }
}
