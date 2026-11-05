<?php

namespace App\Domain\Rules\Exceptions;

use RuntimeException;
use Throwable;

class RulesetExecutionException extends RuntimeException
{
    /**
     * A short machine-readable code (e.g. BAND_NOT_FOUND).
     */
    public ?string $codeKey;

    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        ?string $codeKey = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->codeKey = $codeKey;
    }
}
