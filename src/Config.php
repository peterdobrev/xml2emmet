<?php
declare(strict_types=1);
namespace App;

final class Config {
    public function __construct(
        public readonly string $dbHost,
        public readonly int    $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPass,
        public readonly string $sessionName,
        public readonly bool   $secureCookie,
        public readonly bool   $debug,
    ) {}

    /** @param array<string,string> $env */
    public static function fromEnv(array $env): self {
        foreach (['XML2EMMET_DB_USER', 'XML2EMMET_DB_PASS'] as $r) {
            if (!isset($env[$r]) || $env[$r] === '') {
                throw new \RuntimeException("Missing required env var: $r");
            }
        }
        return new self(
            dbHost:       $env['XML2EMMET_DB_HOST']      ?? '127.0.0.1',
            dbPort:       (int)($env['XML2EMMET_DB_PORT'] ?? 3306),
            dbName:       $env['XML2EMMET_DB_NAME']      ?? 'xml2emmet',
            dbUser:       $env['XML2EMMET_DB_USER'],
            dbPass:       $env['XML2EMMET_DB_PASS'],
            sessionName:  $env['XML2EMMET_SESSION_NAME'] ?? 'xml2emmet_sid',
            secureCookie: ($env['XML2EMMET_SECURE_COOKIE'] ?? '0') === '1',
            debug:        ($env['XML2EMMET_DEBUG']        ?? '0') === '1',
        );
    }
}
