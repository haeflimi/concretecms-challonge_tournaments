<?php

namespace ChallongeTournaments\Api;

use RuntimeException;

/**
 * A failed Challonge request: the HTTP status and the error messages of the JSON:API `errors` array, readable enough
 * to show to a moderator.
 */
class ApiException extends RuntimeException
{
    /** @var int */
    protected $status;

    /** @var string[] */
    protected $errors;

    /**
     * @param string[] $errors
     */
    public function __construct(string $message, int $status = 0, array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
        $this->status = $status;
        $this->errors = $errors;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    public function isAuthError(): bool
    {
        return in_array($this->status, [401, 403], true);
    }
}
