<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Crainios\LeanQr\QrCode;

$qr = new QrCode();
$qr->output('contact@example.com', 'Contact');
