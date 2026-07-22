<?php

declare(strict_types=1);

/**
 * Router dla wbudowanego serwera PHP (bin/dev).
 *
 * Uzywamy go zamiast `symfony server:start`, bo tamten na tej maszynie chodzi
 * przez PHP-FPM i gubi kod statusu przekierowan - 302 wraca jako 200 z samym
 * naglowkiem Location. Docelowo (Proxmox/LAN) i tak stoi przed tym Caddy
 * albo Nginx, wiec ten plik dotyczy wylacznie developmentu.
 *
 * Zwrocenie false oddaje obsluge serwerowi, ktory serwuje istniejacy plik
 * z public/ (skompilowane assety). Reszta idzie do front controllera.
 */
$file = __DIR__.'/../public'.parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH);

if ('/' !== $_SERVER['REQUEST_URI'] && is_file($file)) {
    return false;
}

// return, nie samo require: przy wbudowanym serwerze PHP to ten plik jest
// skryptem wejsciowym, wiec Symfony Runtime oczekuje od niego aplikacji.
// Bez tego dostaje int i wywala sie na "callable object expected".
return require __DIR__.'/../public/index.php';
