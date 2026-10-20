<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Support;

final class SchemaInstaller
{
    public static function install(): void
    {
        (require dirname(__DIR__, 2).'/database/schema.php')();
    }
}