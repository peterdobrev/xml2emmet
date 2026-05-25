<?php
declare(strict_types=1);
namespace App;

final class ClickOpError extends \RuntimeException {
    private readonly string $errorCode;

    public function __get(string $name): mixed {
        if ($name === 'code') {
            return $this->errorCode;
        }
        throw new \Error("Undefined property: " . static::class . "::\$$name");
    }

    public function __construct(
        string $code,
        string $message,
        ?\Throwable $previous = null,
    ) {
        $this->errorCode = $code;
        parent::__construct($message, 0, $previous);
    }
}
