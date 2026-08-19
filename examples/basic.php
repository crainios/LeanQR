<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Crainios\LeanQr\QrCode;

$qr = new QrCode();
$qr->save(
    'https://blogtheque.com',
    __DIR__ . '/blogtheque.png',
    'BlogTheque'
);
