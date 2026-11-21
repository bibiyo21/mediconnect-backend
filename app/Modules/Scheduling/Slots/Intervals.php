<?php

namespace App\Modules\Scheduling\Slots;

/**
 * Half-open intervals [start, end) as pairs of Unix seconds. Lists are kept
 * sorted and non-overlapping, so the generator's set arithmetic is exact.
 */
final class Intervals
{
    /**
     * @param  list<array{int, int}>  $list
     * @return list<array{int, int}>
     */
    public static function normalize(array $list): array
    {
        $list = array_values(array_filter($list, fn (array $i) => $i[1] > $i[0]));
        usort($list, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($list as $i) {
            $last = count($out) - 1;
            if ($last >= 0 && $i[0] <= $out[$last][1]) {
                $out[$last][1] = max($out[$last][1], $i[1]);
            } else {
                $out[] = $i;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{int, int}>  $a
     * @param  list<array{int, int}>  $b
     * @return list<array{int, int}>
     */
    public static function intersect(array $a, array $b): array
    {
        $out = [];
        foreach (self::normalize($a) as [$s1, $e1]) {
            foreach (self::normalize($b) as [$s2, $e2]) {
                $s = max($s1, $s2);
                $e = min($e1, $e2);
                if ($e > $s) {
                    $out[] = [$s, $e];
                }
            }
        }

        return self::normalize($out);
    }

    /**
     * @param  list<array{int, int}>  $a
     * @param  list<array{int, int}>  $remove
     * @return list<array{int, int}>
     */
    public static function subtract(array $a, array $remove): array
    {
        $result = self::normalize($a);
        foreach (self::normalize($remove) as [$rs, $re]) {
            $next = [];
            foreach ($result as [$s, $e]) {
                if ($re <= $s || $rs >= $e) {
                    $next[] = [$s, $e];

                    continue;
                }
                if ($rs > $s) {
                    $next[] = [$s, $rs];
                }
                if ($re < $e) {
                    $next[] = [$re, $e];
                }
            }
            $result = $next;
        }

        return $result;
    }
}
