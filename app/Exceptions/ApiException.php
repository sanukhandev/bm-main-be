<?php

namespace App\Exceptions;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly ?array $errors = null,
    ) {
        parent::__construct($message);
    }
}
