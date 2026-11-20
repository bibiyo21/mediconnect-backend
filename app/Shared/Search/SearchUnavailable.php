<?php

namespace App\Shared\Search;

use RuntimeException;

/** The search engine could not be reached or answered with a server error. */
final class SearchUnavailable extends RuntimeException
{
    public static function because(string $reason, ?\Throwable $previous = null): self
    {
        return new self('Search engine unavailable: '.$reason, 0, $previous);
    }
}
