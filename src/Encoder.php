<?php

declare(strict_types=1);

namespace Crainios\LeanQr;

use InvalidArgumentException;

/**
 * Minimal QR Code Model 2 encoder.
 *
 * Scope intentionally limited to:
 * - byte mode
 * - error correction level M
 * - versions 1 to 10
 */
final class Encoder
{
    private const EC_LEVEL_BITS = 0b00; // M

    /** @var array<int, array<int, int>> */
    private const DATA_BLOCK_LENGTHS = [
        1  => [16],
        2  => [28],
        3  => [44],
        4  => [32, 32],
        5  => [43, 43],
        6  => [27, 27, 27, 27],
        7  => [31, 31, 31, 31],
        8  => [38, 38, 39, 39],
        9  => [36, 36, 36, 37, 37],
        10 => [43, 43, 43, 43, 44],
    ];

    /** @var array<int, int> */
    private const ECC_CODEWORDS_PER_BLOCK = [
        1  => 10,
        2  => 16,
        3  => 26,
        4  => 18,
        5  => 24,
        6  => 16,
        7  => 18,
        8  => 22,
        9  => 22,
        10 => 26,
    ];

    /** @var array<int, array<int, int>> */
    private const ALIGNMENT_CENTERS = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /**
     * @return array<int, array<int, bool>>
     */
    public function encode(string $content): array
    {
        if ($content === '') {
            throw new InvalidArgumentException('QR Code content cannot be empty.');
        }

        $version = $this->chooseVersion(strlen($content));
        $dataCodewords = $this->createDataCodewords($content, $version);
        $allCodewords = $this->addErrorCorrectionAndInterleave($dataCodewords, $version);

        return $this->buildMatrix($allCodewords, $version);
    }

    private function chooseVersion(int $byteLength): int
    {
        for ($version = 1; $version <= 10; $version++) {
            $countBits = $version <= 9 ? 8 : 16;
            $capacityBits = array_sum(self::DATA_BLOCK_LENGTHS[$version]) * 8;
            $requiredBits = 4 + $countBits + ($byteLength * 8);

            if ($requiredBits <= $capacityBits) {
                return $version;
            }
        }

        throw new InvalidArgumentException(
            'Content is too long. LeanQR currently supports QR versions 1 to 10 at error correction level M.'
        );
    }

    /**
     * @return array<int, int>
     */
    private function createDataCodewords(string $content, int $version): array
    {
        $capacityBytes = array_sum(self::DATA_BLOCK_LENGTHS[$version]);
        $capacityBits = $capacityBytes * 8;
        $bits = [];

        // Byte mode indicator: 0100
        $this->appendBits($bits, 0b0100, 4);

        $countBits = $version <= 9 ? 8 : 16;
        $this->appendBits($bits, strlen($content), $countBits);

        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $this->appendBits($bits, ord($content[$i]), 8);
        }

        $terminatorLength = min(4, $capacityBits - count($bits));
        for ($i = 0; $i < $terminatorLength; $i++) {
            $bits[] = 0;
        }

        while ((count($bits) % 8) !== 0) {
            $bits[] = 0;
        }

        $bytes = [];
        for ($i = 0, $bitCount = count($bits); $i < $bitCount; $i += 8) {
            $value = 0;
            for ($j = 0; $j < 8; $j++) {
                $value = ($value << 1) | $bits[$i + $j];
            }
            $bytes[] = $value;
        }

        $pads = [0xEC, 0x11];
        $padIndex = 0;
        while (count($bytes) < $capacityBytes) {
            $bytes[] = $pads[$padIndex % 2];
            $padIndex++;
        }

        return $bytes;
    }

    /**
     * @param array<int, int> $dataCodewords
     * @return array<int, int>
     */
    private function addErrorCorrectionAndInterleave(array $dataCodewords, int $version): array
    {
        $blockLengths = self::DATA_BLOCK_LENGTHS[$version];
        $eccLength = self::ECC_CODEWORDS_PER_BLOCK[$version];
        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;

        foreach ($blockLengths as $length) {
            $block = array_slice($dataCodewords, $offset, $length);
            $offset += $length;
            $dataBlocks[] = $block;
            $eccBlocks[] = $this->reedSolomonRemainder($block, $eccLength);
        }

        $result = [];
        $maxDataLength = max($blockLengths);

        for ($i = 0; $i < $maxDataLength; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $eccLength; $i++) {
            foreach ($eccBlocks as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    /**
     * @param array<int, int> $data
     * @return array<int, int>
     */
    private function reedSolomonRemainder(array $data, int $degree): array
    {
        $generator = [1];
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);

            foreach ($generator as $j => $coefficient) {
                $next[$j] ^= $coefficient;
                $next[$j + 1] ^= $this->gfMultiply($coefficient, $root);
            }

            $generator = $next;
            $root = $this->gfMultiply($root, 0x02);
        }

        $remainder = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;

            for ($i = 0; $i < $degree; $i++) {
                $remainder[$i] ^= $this->gfMultiply($generator[$i + 1], $factor);
            }
        }

        return $remainder;
    }

    private function gfMultiply(int $x, int $y): int
    {
        $result = 0;

        while ($y > 0) {
            if (($y & 1) !== 0) {
                $result ^= $x;
            }

            $y >>= 1;
            $x <<= 1;
            if (($x & 0x100) !== 0) {
                $x ^= 0x11D;
            }
        }

        return $result;
    }

    /**
     * @param array<int, int> $codewords
     * @return array<int, array<int, bool>>
     */
    private function buildMatrix(array $codewords, int $version): array
    {
        $size = 17 + (4 * $version);
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $isFunction = array_fill(0, $size, array_fill(0, $size, false));

        $this->drawFunctionPatterns($modules, $isFunction, $version);

        $dataBits = [];
        foreach ($codewords as $byte) {
            $this->appendBits($dataBits, $byte, 8);
        }

        $this->drawCodewords($modules, $isFunction, $dataBits);

        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        $bestModules = $modules;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $modules;
            $this->applyMask($candidate, $isFunction, $mask);
            $this->drawFormatBits($candidate, $isFunction, $mask);

            $penalty = $this->penaltyScore($candidate);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
                $bestModules = $candidate;
            }
        }

        // Ensures format bits correspond to the selected mask.
        $this->drawFormatBits($bestModules, $isFunction, $bestMask);

        return $bestModules;
    }

    /**
     * @param array<int, array<int, bool>> $modules
     * @param array<int, array<int, bool>> $isFunction
     */
    private function drawFunctionPatterns(array &$modules, array &$isFunction, int $version): void
    {
        $size = count($modules);

        $this->drawFinderPattern($modules, $isFunction, 3, 3);
        $this->drawFinderPattern($modules, $isFunction, $size - 4, 3);
        $this->drawFinderPattern($modules, $isFunction, 3, $size - 4);

        for ($i = 8; $i < $size - 8; $i++) {
            if (!$isFunction[6][$i]) {
                $this->setFunctionModule($modules, $isFunction, $i, 6, ($i % 2) === 0);
            }
            if (!$isFunction[$i][6]) {
                $this->setFunctionModule($modules, $isFunction, 6, $i, ($i % 2) === 0);
            }
        }

        foreach (self::ALIGNMENT_CENTERS[$version] as $centerY) {
            foreach (self::ALIGNMENT_CENTERS[$version] as $centerX) {
                $overlapsFinder =
                    ($centerX <= 8 && $centerY <= 8) ||
                    ($centerX >= $size - 9 && $centerY <= 8) ||
                    ($centerX <= 8 && $centerY >= $size - 9);

                if (!$overlapsFinder) {
                    $this->drawAlignmentPattern($modules, $isFunction, $centerX, $centerY);
                }
            }
        }

        // Reserve and initialize format information modules.
        $this->drawFormatBits($modules, $isFunction, 0);

        if ($version >= 7) {
            $this->drawVersionBits($modules, $isFunction, $version);
        }
    }

    /**
     * @param array<int, array<int, bool>> $modules
     * @param array<int, array<int, bool>> $isFunction
     */
    private function drawFinderPattern(array &$modules, array &$isFunction, int $centerX, int $centerY): void
    {
        $size = count($modules);

        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $centerX + $dx;
                $y = $centerY + $dy;

                if ($x < 0 || $x >= $size || $y < 0 || $y >= $size) {
                    continue;
                }

                $distance = max(abs($dx), abs($dy));
                $dark = $distance !== 2 && $distance !== 4;
                $this->setFunctionModule($modules, $isFunction, $x, $y, $dark);
            }
        }
    }

    /**
     * @param array<int, array<int, bool>> $modules
     * @param array<int, array<int, bool>> $isFunction
     */
    private function drawAlignmentPattern(array &$modules, array &$isFunction, int $centerX, int $centerY): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $distance = max(abs($dx), abs($dy));
                $this->setFunctionModule(
                    $modules,
                    $isFunction,
                    $centerX + $dx,
                    $centerY + $dy,
                    $distance !== 1
                );
            }
        }
    }

    /**
     * @param array<int, array<int, bool>> $modules
     * @param array<int, array<int, bool>> $isFunction
     */
    private function drawFormatBits(array &$modules, array &$isFunction, int $mask): void
    {
        $size = count($modules);
        $data = (self::EC_LEVEL_BITS << 3) | $mask;
        $remainder = $data;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 9) & 1) * 0x537);
        }

        $bits = (($data << 10) | $remainder) ^ 0x5412;

        // First copy around top-left finder.
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunctionModule($modules, $isFunction, 8, $i, $this->bit($bits, $i));
        }
        $this->setFunctionModule($modules, $isFunction, 8, 7, $this->bit($bits, 6));
        $this->setFunctionModule($modules, $isFunction, 8, 8, $this->bit($bits, 7));
        $this->setFunctionModule($modules, $isFunction, 7, 8, $this->bit($bits, 8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunctionModule($modules, $isFunction, 14 - $i, 8, $this->bit($bits, $i));
        }

        // Second copy near top-right and bottom-left finders.
        for ($i = 0; $i < 8; $i++) {
            $this->setFunctionModule($modules, $isFunction, $size - 1 - $i, 8, $this->bit($bits, $i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunctionModule($modules, $isFunction, 8, $size - 15 + $i, $this->bit($bits, $i));
        }

        // Fixed dark module.
        $this->setFunctionModule($modules, $isFunction, 8, $size - 8, true);
    }

    /**
     * @param array<int, array<int, bool>> $modules
     * @param array<int, array<int, bool>> $isFunction
     */
    private function drawVersionBits(array &$modules, array &$isFunction, int $version): void
    {
        $size = count($modules);
        $remainder = $version;

        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 11) & 1) * 0x1F25);
        }

        $bits = ($version << 12) | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $dark = $this->bit($bits, $i);
            $a = $size - 11 + ($i % 3);
            $b = intdiv($i, 3);
            $this->setFunctionModule($modules, $isFunction, $a, $b, $dark);
            $this->setFunctionModule($modules, $isFunction, $b, $a, $dark);
        }
    }

    /**
     * @param array<int, array<int, bool>> $modules
     * @param array<int, array<int, bool>> $isFunction
     * @param array<int, int> $dataBits
     */
    private function drawCodewords(array &$modules, array $isFunction, array $dataBits): void
    {
        $size = count($modules);
        $bitIndex = 0;
        $right = $size - 1;
        $upward = true;

        while ($right >= 1) {
            if ($right === 6) {
                $right--;
            }

            for ($vert = 0; $vert < $size; $vert++) {
                $y = $upward ? ($size - 1 - $vert) : $vert;

                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if (!$isFunction[$y][$x]) {
                        $modules[$y][$x] = $bitIndex < count($dataBits)
                            ? ($dataBits[$bitIndex] === 1)
                            : false;
                        $bitIndex++;
                    }
                }
            }

            $upward = !$upward;
            $right -= 2;
        }
    }

    /**
     * @param array<int, array<int, bool>> $modules
     * @param array<int, array<int, bool>> $isFunction
     */
    private function applyMask(array &$modules, array $isFunction, int $mask): void
    {
        $size = count($modules);

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (!$isFunction[$y][$x] && $this->maskCondition($mask, $x, $y)) {
                    $modules[$y][$x] = !$modules[$y][$x];
                }
            }
        }
    }

    private function maskCondition(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => (($x + $y) % 2) === 0,
            1 => ($y % 2) === 0,
            2 => ($x % 3) === 0,
            3 => (($x + $y) % 3) === 0,
            4 => ((intdiv($y, 2) + intdiv($x, 3)) % 2) === 0,
            5 => ((($x * $y) % 2) + (($x * $y) % 3)) === 0,
            6 => (((($x * $y) % 2) + (($x * $y) % 3)) % 2) === 0,
            7 => (((($x + $y) % 2) + (($x * $y) % 3)) % 2) === 0,
            default => throw new InvalidArgumentException('Invalid QR mask.'),
        };
    }

    /**
     * @param array<int, array<int, bool>> $modules
     */
    private function penaltyScore(array $modules): int
    {
        $size = count($modules);
        $penalty = 0;

        // N1: long runs in rows and columns.
        for ($y = 0; $y < $size; $y++) {
            $runColor = $modules[$y][0];
            $runLength = 1;
            for ($x = 1; $x < $size; $x++) {
                if ($modules[$y][$x] === $runColor) {
                    $runLength++;
                } else {
                    if ($runLength >= 5) {
                        $penalty += 3 + ($runLength - 5);
                    }
                    $runColor = $modules[$y][$x];
                    $runLength = 1;
                }
            }
            if ($runLength >= 5) {
                $penalty += 3 + ($runLength - 5);
            }
        }

        for ($x = 0; $x < $size; $x++) {
            $runColor = $modules[0][$x];
            $runLength = 1;
            for ($y = 1; $y < $size; $y++) {
                if ($modules[$y][$x] === $runColor) {
                    $runLength++;
                } else {
                    if ($runLength >= 5) {
                        $penalty += 3 + ($runLength - 5);
                    }
                    $runColor = $modules[$y][$x];
                    $runLength = 1;
                }
            }
            if ($runLength >= 5) {
                $penalty += 3 + ($runLength - 5);
            }
        }

        // N2: 2x2 blocks of the same color.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $value = $modules[$y][$x];
                if (
                    $modules[$y][$x + 1] === $value &&
                    $modules[$y + 1][$x] === $value &&
                    $modules[$y + 1][$x + 1] === $value
                ) {
                    $penalty += 3;
                }
            }
        }

        // N3: finder-like 1:1:3:1:1 patterns with four light modules.
        $patternA = '10111010000';
        $patternB = '00001011101';

        for ($y = 0; $y < $size; $y++) {
            $row = '';
            for ($x = 0; $x < $size; $x++) {
                $row .= $modules[$y][$x] ? '1' : '0';
            }
            for ($i = 0; $i <= $size - 11; $i++) {
                $segment = substr($row, $i, 11);
                if ($segment === $patternA || $segment === $patternB) {
                    $penalty += 40;
                }
            }
        }

        for ($x = 0; $x < $size; $x++) {
            $column = '';
            for ($y = 0; $y < $size; $y++) {
                $column .= $modules[$y][$x] ? '1' : '0';
            }
            for ($i = 0; $i <= $size - 11; $i++) {
                $segment = substr($column, $i, 11);
                if ($segment === $patternA || $segment === $patternB) {
                    $penalty += 40;
                }
            }
        }

        // N4: dark/light balance.
        $dark = 0;
        foreach ($modules as $row) {
            foreach ($row as $module) {
                if ($module) {
                    $dark++;
                }
            }
        }

        $total = $size * $size;
        $percentage = ($dark * 100) / $total;
        $penalty += (int) floor(abs($percentage - 50) / 5) * 10;

        return $penalty;
    }

    /**
     * @param array<int, int> $bits
     */
    private function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /**
     * @param array<int, array<int, bool>> $modules
     * @param array<int, array<int, bool>> $isFunction
     */
    private function setFunctionModule(
        array &$modules,
        array &$isFunction,
        int $x,
        int $y,
        bool $dark
    ): void {
        $modules[$y][$x] = $dark;
        $isFunction[$y][$x] = true;
    }

    private function bit(int $value, int $index): bool
    {
        return (($value >> $index) & 1) !== 0;
    }
}
