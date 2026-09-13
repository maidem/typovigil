<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

final readonly class TokenGenerator
{
    /**
     * 32 random bytes, hex encoded. random_bytes throws rather than returning
     * weak output, so there is no silent fallback to guessable tokens.
     */
    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
