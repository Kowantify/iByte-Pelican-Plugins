<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Catalog;

interface JsonTransport
{
    public function post(string $provider, string $path, array $body): array;

    public function get(string $provider, string $path, array $query = []): array;
}
