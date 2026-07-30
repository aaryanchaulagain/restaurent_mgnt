<?php

namespace App\Domain\Payments\Exceptions;

use Exception;

class PaymentException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
