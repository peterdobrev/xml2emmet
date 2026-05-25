<?php
declare(strict_types=1);
namespace App\Stats;

final class CssClassCounter {
    /** @return array<string,int> */
    public static function count(string $css): array {
        $counts = [];
        // Match a literal '.' followed by an identifier (letters, digits, _, -).
        // The leading char must not be alnum, _, or - so we don't match "1.5" or "foo.bar" as a class.
        // v1 limitations: ASCII only (no Unicode), and naive scan picks up class-shaped tokens
        // inside string literals and url() paths (e.g., url(./img.png) yields 'png').
        preg_match_all('/(?<![A-Za-z0-9_\-])\.([A-Za-z_\-][A-Za-z0-9_\-]*)/', $css, $m);
        foreach ($m[1] as $name) {
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        return $counts;
    }
}
