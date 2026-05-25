<?php
declare(strict_types=1);
namespace App\Http;

final class Json {
    public static function encode(mixed $v): string {
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    public static function decode(string $s): mixed {
        try { return json_decode($s, true, flags: JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return null; }
    }
}
