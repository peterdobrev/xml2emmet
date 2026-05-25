<?php
declare(strict_types=1);
namespace App\Http;

final class Request {
    public readonly ?array $json;

    /**
     * @param array<string,string>     $query
     * @param array<string,mixed>|null $json
     * @param array<string,string>     $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array  $query,
        ?array $json,
        public readonly array  $headers,
        public readonly string $rawBody,
    ) {
        if ($json === null && $rawBody !== '' && str_contains($headers['Content-Type'] ?? '', 'application/json')) {
            $decoded = Json::decode($rawBody);
            $json = is_array($decoded) ? $decoded : null;
        }
        $this->json = $json;
    }

    public static function fromGlobals(): self {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri    = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path   = parse_url($uri, PHP_URL_PATH) ?: '/';
        $query  = [];
        foreach ($_GET as $k => $v) $query[(string)$k] = (string)$v;
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with((string)$k, 'HTTP_')) {
                $name = str_replace('_', '-', substr((string)$k, 5));
                $name = ucwords(strtolower($name), '-');
                $headers[$name] = (string)$v;
            } elseif ($k === 'CONTENT_TYPE') {
                $headers['Content-Type'] = (string)$v;
            } elseif ($k === 'CONTENT_LENGTH') {
                $headers['Content-Length'] = (string)$v;
            }
        }
        $raw = file_get_contents('php://input') ?: '';
        return new self($method, $path, $query, null, $headers, $raw);
    }
}
