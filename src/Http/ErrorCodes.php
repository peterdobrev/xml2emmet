<?php
declare(strict_types=1);
namespace App\Http;

/**
 * Wire-format error codes returned in `{"error": "..."}` JSON bodies.
 *
 * The values are the canonical strings clients (and our HTTP tests) match
 * against. Keeping them as named constants prevents typos and makes it easy
 * to grep call sites when introducing a new code.
 */
final class ErrorCodes {
    public const VALIDATION_FAILED  = 'validation_failed';
    public const PARSE_ERROR        = 'parse_error';
    public const NOT_FOUND          = 'not_found';
    public const UNAUTHENTICATED    = 'unauthenticated';
    public const CONFLICT           = 'conflict';
    public const PAYLOAD_TOO_LARGE  = 'payload_too_large';
    public const INTERNAL_ERROR     = 'internal_error';

    private function __construct() {}
}
