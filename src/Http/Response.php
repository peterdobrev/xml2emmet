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

    public function send(): void {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) header("$k: $v");
        echo $this->body;
    }
}
