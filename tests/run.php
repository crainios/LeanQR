<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Encoder.php';

use Crainios\LeanQr\Encoder;

$encoder = new Encoder();
$tests = 0;

$assert = static function (bool $condition, string $message) use (&$tests): void {
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$matrix = $encoder->encode('https://example.com');
$assert(count($matrix) >= 21, 'Matrix must have a valid QR size.');
$assert((count($matrix) - 17) % 4 === 0, 'Matrix size must match QR Model 2 dimensions.');
$assert(count($matrix) === count($matrix[0]), 'Matrix must be square.');

foreach ($matrix as $row) {
    $assert(count($row) === count($matrix), 'Every matrix row must have the same size.');
    foreach ($row as $module) {
        $assert(is_bool($module), 'Every matrix module must be boolean.');
    }
}

$matrixV1 = $encoder->encode('A');
$assert(count($matrixV1) === 21, 'Short content must fit in version 1.');

$matrixLonger = $encoder->encode(str_repeat('A', 100));
$assert(count($matrixLonger) > 21, 'Longer content must select a larger version.');

try {
    $encoder->encode('');
    $assert(false, 'Empty content must be rejected.');
} catch (InvalidArgumentException) {
    $assert(true, 'Empty content is rejected.');
}

try {
    $encoder->encode(str_repeat('A', 1000));
    $assert(false, 'Oversized content must be rejected.');
} catch (InvalidArgumentException) {
    $assert(true, 'Oversized content is rejected.');
}

echo "OK - {$tests} assertions\n";
