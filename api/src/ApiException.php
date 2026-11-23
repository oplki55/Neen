<?php
declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
