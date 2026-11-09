<?php

namespace Dj\MysqlVector;

/**
 * Turns a float vector into a binary code that can be compared with XOR/BIT_COUNT in MySQL.
 *
 *   1. subtract the dataset mean          (makes every bit ~50/50, so each bit carries information)
 *   2. random rotation (sign flips + Walsh-Hadamard transform, seeded, no matrix to store)
 *   3. keep the sign of every output      (1 bit per output)
 *
 * With $bits larger than the number of dimensions you get several independent rotations,
 * which is the SimHash idea: more bits = a better estimate of the angle between two vectors.
 * Pure PHP, no database, no extensions.
 */
final class BinaryCodec
{
    private int $dims;
    private int $bits;
    private int $rounds;
    private string $seed;
    private int $P;        // dims padded up to a power of two
    private int $blocks;   // number of independent rotations needed to reach $bits
    private array $mean;
    private array $signs = [];

    public function __construct(int $dims, int $bits = 1024, int $rounds = 2, string $seed = '7', ?array $mean = null)
    {
        if ($bits % 8 !== 0 || $bits < 8) {
            throw new \InvalidArgumentException('bits must be a multiple of 8');
        }
        $this->dims = $dims;
        $this->bits = $bits;
        $this->rounds = max(1, $rounds);
        $this->seed = $seed;
        $this->P = 1;
        while ($this->P < $dims) {
            $this->P <<= 1;
        }
        $this->blocks = intdiv($bits + $this->P - 1, $this->P);
        $this->setMean($mean ?? array_fill(0, $dims, 0.0));
    }

    public function bytes(): int { return intdiv($this->bits, 8); }
    public function mean(): array { return $this->mean; }

    public function setMean(array $mean): void
    {
        if (count($mean) !== $this->dims) {
            throw new \InvalidArgumentException('mean has wrong length');
        }
        $this->mean = array_values(array_map('floatval', $mean));
    }

    /** Average of (already normalized) sample vectors. 5-20k samples is plenty. */
    public static function fitMean(array $vectors): array
    {
        $sum = null;
        foreach ($vectors as $v) {
            if ($sum === null) {
                $sum = array_fill(0, count($v), 0.0);
            }
            foreach ($v as $i => $x) {
                $sum[$i] += $x;
            }
        }
        $n = max(1, count($vectors));
        return array_map(fn($s) => $s / $n, $sum ?? []);
    }

    /** First $k dimensions, re-normalized (needed for Matryoshka-style truncation). */
    public static function cropNormalize(array $v, int $k): array
    {
        return self::normalize(array_slice(array_values($v), 0, $k));
    }

    public static function normalize(array $v): array
    {
        $s = 0.0;
        foreach ($v as $x) {
            $s += $x * $x;
        }
        $s = sqrt($s) ?: 1e-10;
        $out = [];
        foreach (array_values($v) as $x) {
            $out[] = $x / $s;
        }
        return $out;
    }

    /** Deterministic +1/-1 sequence from sha256, so every PHP version/platform gives the same codes. */
    public static function signs(int $P, string $seed): array
    {
        $out = [];
        for ($i = 0; count($out) < $P; $i++) {
            $h = hash('sha256', $seed . ':' . $i, true);
            for ($k = 0; $k < 32 && count($out) < $P; $k++) {
                $byte = ord($h[$k]);
                for ($b = 0; $b < 8 && count($out) < $P; $b++) {
                    $out[] = (($byte >> $b) & 1) ? 1.0 : -1.0;
                }
            }
        }
        return $out;
    }

    private function signsFor(int $block, int $round): array
    {
        $key = $block . '-' . $round;
        return $this->signs[$key] ??= self::signs($this->P, $this->seed . '-' . $block . '-' . $round);
    }

    /** In-place fast Walsh-Hadamard transform (unnormalized; only the signs matter). */
    private static function fwht(array &$a, int $P): void
    {
        for ($h = 1; $h < $P; $h <<= 1) {
            for ($i = 0; $i < $P; $i += $h << 1) {
                $end = $i + $h;
                for ($j = $i; $j < $end; $j++) {
                    $x = $a[$j];
                    $y = $a[$j + $h];
                    $a[$j] = $x + $y;
                    $a[$j + $h] = $x - $y;
                }
            }
        }
    }

    /** @param float[] $v a normalized vector (0-indexed list). Returns $bits/8 raw bytes. */
    public function encode(array $v): string
    {
        $P = $this->P;
        $x = array_fill(0, $P, 0.0);
        for ($i = 0; $i < $this->dims; $i++) {
            $x[$i] = $v[$i] - $this->mean[$i];
        }
        $bytes = array_fill(0, $this->bytes(), 0);
        $bit = 0;
        for ($blk = 0; $blk < $this->blocks && $bit < $this->bits; $blk++) {
            $a = $x;
            for ($r = 0; $r < $this->rounds; $r++) {
                $s = $this->signsFor($blk, $r);
                for ($i = 0; $i < $P; $i++) {
                    $a[$i] *= $s[$i];
                }
                self::fwht($a, $P);
            }
            for ($i = 0; $i < $P && $bit < $this->bits; $i++, $bit++) {
                if ($a[$i] > 0) {
                    $bytes[$bit >> 3] |= 1 << ($bit & 7);
                }
            }
        }
        return pack('C*', ...$bytes);
    }

    /** Hamming distance between two codes (PHP side; MySQL does this with BIT_COUNT(a ^ b)). */
    public static function hamming(string $a, string $b): int
    {
        static $pop = null;
        $pop ??= array_map(fn($i) => substr_count(decbin($i), '1'), range(0, 255));
        $d = 0;
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            $d += $pop[ord($a[$i]) ^ ord($b[$i])];
        }
        return $d;
    }
}
