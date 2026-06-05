<?php
declare(strict_types=1);
namespace App\Http;

final class Response {
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int    $status,
        public readonly array  $headers,
        public readonly string $body,
    ) {}

    public static function json(int $status, mixed $payload, array $extraHeaders = []): self {
        $headers = ['Content-Type' => 'application/json; charset=utf-8'] + $extraHeaders;
        return new self($status, $headers, Json::encode($payload));
    }

    /** @param array<string,mixed> $details */
    public static function error(int $status, string $code, string $message, array $details = []): self {
        return self::json($status, ['error' => $code, 'message' => $message, 'details' => $details]);
    }

    /** Convenience wrapper for the common 422 + validation_failed pattern. */
    public static function validationFailed(string $message, array $details = []): self {
        return self::error(422, ErrorCodes::VALIDATION_FAILED, $message, $details);
    }

    /** Convenience wrapper for the common 404 + not_found pattern. */
    public static function notFound(string $message, array $details = []): self {
        return self::error(404, ErrorCodes::NOT_FOUND, $message, $details);
    }

    /**
     * Convenience wrapper for the common 422 + parse_error pattern, mapping a
     * thrown XmlParseError / EmmetParseError to its public message.
     */
    public static function parseError(\Throwable $e, array $details = []): self {
        return self::error(422, ErrorCodes::PARSE_ERROR, $e->getMessage(), $details);
    }

    /** Return a copy of this Response with the given header set/overridden. */
    public function withHeader(string $name, string $value): self {
        return new self($this->status, [$name => $value] + $this->headers, $this->body);
    }

    public function send(): void {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) header("$k: $v");
        echo $this->body;
    }
}
