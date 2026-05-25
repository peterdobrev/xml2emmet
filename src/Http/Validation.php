<?php
declare(strict_types=1);
namespace App\Http;

final class Validation {
    /** @var array<string,string> */
    private array $errors = [];

    /** @param array<string,mixed> $data */
    public function __construct(private array $data) {}

    public function requireString(string $field, int $min = 1, int $max = 65535): string {
        $v = $this->data[$field] ?? null;
        if (!is_string($v) || strlen($v) < $min || strlen($v) > $max) {
            $this->errors[$field] = "must be a string of length [$min, $max]";
            return '';
        }
        return $v;
    }

    public function requireInt(string $field, int $min, int $max): int {
        $v = $this->data[$field] ?? null;
        if (!is_int($v) || $v < $min || $v > $max) {
            $this->errors[$field] = "must be an integer in [$min, $max]";
            return 0;
        }
        return $v;
    }

    public function requireMatch(string $field, string $regex): string {
        $v = $this->data[$field] ?? null;
        if (!is_string($v) || !preg_match($regex, $v)) {
            $this->errors[$field] = "must match $regex";
            return '';
        }
        return $v;
    }

    /** @param list<string> $allowed */
    public function requireEnum(string $field, array $allowed): string {
        $v = $this->data[$field] ?? null;
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            $this->errors[$field] = 'must be one of: ' . implode(', ', $allowed);
            return '';
        }
        return $v;
    }

    public function optionalBool(string $field, bool $default): bool {
        $v = $this->data[$field] ?? null;
        if ($v === null) return $default;
        if (!is_bool($v)) {
            $this->errors[$field] = 'must be a boolean';
            return $default;
        }
        return $v;
    }

    /** @return array<string,string> */
    public function errors(): array { return $this->errors; }
    public function ok(): bool { return $this->errors === []; }
}
