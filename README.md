# LeanQR

**Lightweight QR Code generator for PHP.**

LeanQR focuses on one task: generating standard QR Codes without pulling a QR Code library into your project.

- Pure PHP QR encoding
- PNG output
- Optional label below the QR Code
- URL, email and short text support
- Automatic `mailto:` conversion for email addresses
- QR Code Model 2
- Byte mode
- Error correction level M
- Versions 1 to 10
- No third-party package dependencies

The PNG renderer uses the PHP GD extension.

## Requirements

- PHP 8.5 or newer
- GD extension

## Installation

```bash
composer require crainios/leanqr
```

## Basic usage

```php
<?php

require_once 'vendor/autoload.php';

use Crainios\LeanQr\QrCode;

$qr = new QrCode();

$qr->save(
    'https://blogtheque.com',
    'qrcode.png',
    'BlogTheque'
);
```

## Return PNG data

```php
$png = $qr->render('https://blogtheque.com', 'BlogTheque');

file_put_contents('qrcode.png', $png);
```

This is useful when a PDF library accepts image data or when the image must first be written to a temporary file.

## Direct browser output

```php
$qr->output('https://blogtheque.com', 'BlogTheque');
```

LeanQR sends the appropriate `Content-Type: image/png` header and outputs the generated image.

## Email address

```php
$qr->save(
    'contact@example.com',
    'email.png',
    'Contact'
);
```

A plain valid email address is automatically encoded as:

```text
mailto:contact@example.com
```

## Size and margin

```php
$qr = new QrCode();

$qr
    ->setScale(10)
    ->setMargin(4)
    ->save('https://example.com', 'qrcode.png');
```

The margin is expressed in QR modules. Four modules is the standard quiet-zone size and the default.

## Design scope

LeanQR intentionally keeps its feature set small. Version 1.x does not provide logos, gradients, module styling, SVG/EPS output, arbitrary error-correction levels or advanced QR modes.

The encoder implements standard QR Code Model 2 byte-mode generation at error correction level M for versions 1 through 10.

## Running tests

```bash
composer test
```

The encoder tests do not require GD. PNG generation requires GD.

## License

MIT
