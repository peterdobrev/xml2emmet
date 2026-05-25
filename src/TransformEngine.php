<?php
namespace App;
final class TransformEngine {
    public static function emmetParse(string $abbr): Node {
        $p = new EmmetParser($abbr);
        return $p->parse();
    }
}
