<?php

declare(strict_types=1);

namespace Crainios\LeanQr;

use InvalidArgumentException;
use RuntimeException;

final class QrCode
{
    private Encoder $encoder;
    private PngRenderer $renderer;
    private int $scale = 8;
    private int $margin = 4;

    public function __construct(?Encoder $encoder = null, ?PngRenderer $renderer = null)
    {
        $this->encoder = $encoder ?? new Encoder();
        $this->renderer = $renderer ?? new PngRenderer();
    }

    public function setScale(int $scale): self
    {
        if ($scale < 1 || $scale > 50) {
            throw new InvalidArgumentException('Scale must be between 1 and 50.');
        }

        $this->scale = $scale;
        return $this;
    }

    public function setMargin(int $margin): self
    {
        if ($margin < 0 || $margin > 20) {
            throw new InvalidArgumentException('Margin must be between 0 and 20 modules.');
        }

        $this->margin = $margin;
        return $this;
    }

    public function render(string $content, ?string $label = null): string
    {
        $content = $this->normalizeContent($content);
        $matrix = $this->encoder->encode($content);

        return $this->renderer->render($matrix, $label, $this->scale, $this->margin);
    }

    public function save(string $content, string $filename, ?string $label = null): void
    {
        if ($filename === '') {
            throw new InvalidArgumentException('Filename cannot be empty.');
        }

        $png = $this->render($content, $label);

        if (file_put_contents($filename, $png) === false) {
            throw new RuntimeException(sprintf('Unable to write QR Code to "%s".', $filename));
        }
    }

    public function output(string $content, ?string $label = null): never
    {
        if (headers_sent()) {
            throw new RuntimeException('HTTP headers have already been sent.');
        }

        header('Content-Type: image/png');
        header('Content-Disposition: inline; filename="qrcode.png"');
        header('Cache-Control: no-store, max-age=0');

        echo $this->render($content, $label);
        exit;
    }

    private function normalizeContent(string $content): string
    {
        $content = trim($content);

        if ($content === '') {
            throw new InvalidArgumentException('QR Code content cannot be empty.');
        }

        if (filter_var($content, FILTER_VALIDATE_EMAIL) !== false) {
            return 'mailto:' . $content;
        }

        return $content;
    }
}
