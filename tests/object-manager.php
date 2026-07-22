<?php

declare(strict_types=1);

// Wczytuje EntityManagera dla rozszerzenia PHPStan-Doctrine: dzieki niemu
// analiza zna mapowanie encji i typy zwracane przez repozytoria.

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__.'/../.env');

$kernel = new Kernel('dev', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
