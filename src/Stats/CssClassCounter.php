<?php
declare(strict_types=1);
namespace App\Stats;

final class CssClassCounter {
    /** @return array<string,int> */
    public static function count(string $css): array {
        if ($css === '') return [];
        $counts = [];
        // Match a literal '.' followed by an identifier (letters, digits, _, -).
        // The leading char must not be alnum, _, or - so we don't match "1.5" or "foo.bar" as a class.
        if (preg_match_all('/(?<![A-Za-z0-9_\-])\.([A-Za-z_\-][A-Za-z0-9_\-]*)/', $css, $m)) {
            foreach ($m[1] as $name) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }
        return $counts;
    }
}
