<?php

declare(strict_types=1);

namespace Crainios\LeanQr;

use RuntimeException;

final class PngRenderer
{
    /**
     * @param array<int, array<int, bool>> $matrix
     */
    public function render(array $matrix, ?string $label = null, int $scale = 8, int $margin = 4): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('LeanQR requires the PHP GD extension to generate PNG images.');
        }

        if ($scale < 1 || $scale > 50) {
            throw new RuntimeException('Scale must be between 1 and 50.');
        }

        if ($margin < 0 || $margin > 20) {
            throw new RuntimeException('Margin must be between 0 and 20 modules.');
        }

        $moduleCount = count($matrix);
        $qrSize = ($moduleCount + ($margin * 2)) * $scale;
        $font = 5;
        $label = $this->normalizeLabel($label);
        $labelWidth = $label !== null ? imagefontwidth($font) * strlen($label) : 0;
        $labelHeight = $label !== null ? imagefontheight($font) : 0;
        $labelPadding = $label !== null ? max(8, $scale * 2) : 0;

        $width = max($qrSize, $labelWidth + 16);
        $height = $qrSize + $labelPadding + $labelHeight;

        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('Unable to create PNG image.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);

        $offsetX = intdiv($width - $qrSize, 2) + ($margin * $scale);
        $offsetY = $margin * $scale;

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if (!$dark) {
                    continue;
                }

                $x1 = $offsetX + ($x * $scale);
                $y1 = $offsetY + ($y * $scale);
                imagefilledrectangle(
                    $image,
                    $x1,
                    $y1,
                    $x1 + $scale - 1,
                    $y1 + $scale - 1,
                    $black
                );
            }
        }

        if ($label !== null) {
            $textX = intdiv($width - $labelWidth, 2);
            $textY = $qrSize + $labelPadding;
            imagestring($image, $font, $textX, $textY, $label, $black);
        }

        ob_start();
        imagepng($image, null, 9);
        $png = ob_get_clean();
        imagedestroy($image);

        if (!is_string($png)) {
            throw new RuntimeException('Unable to render PNG image.');
        }

        return $png;
    }

    private function normalizeLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = trim($label);
        if ($label === '') {
            return null;
        }

        // GD's built-in bitmap font is single-byte. Transliterate UTF-8 labels when possible.
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
            if (is_string($converted) && $converted !== '') {
                $label = $converted;
            }
        }

        return substr($label, 0, 100);
    }
}
