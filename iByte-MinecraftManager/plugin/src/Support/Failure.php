<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Support;

use RuntimeException;

final class Failure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly array $context = [])
    {
        // Never include remote bodies, URLs, headers, secrets or node paths.
        parent::__construct($reason);
    }
}
