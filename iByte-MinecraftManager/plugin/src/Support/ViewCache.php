<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Support;

use Illuminate\View\Compilers\BladeCompiler;

final class ViewCache
{
    public static function refresh(BladeCompiler $compiler): void
    {
        $directory = plugin_path('ibyte-minecraft-manager', 'resources', 'views');
        foreach (glob($directory . '/*.blade.php') ?: [] as $source) {
            $compiled = $compiler->getCompiledPath($source);
            $marker = $compiled . '.imm-sha256';
            $hash = hash_file('sha256', $source);
            if (!is_file($compiled) || !is_file($marker) || file_get_contents($marker) !== $hash) {
                // Deterministic ZIP mtimes can predate an existing compiled template.
                // Refresh only our templates; never clear another plugin's cache.
                $compiler->compile($source);
                if (file_put_contents($marker, $hash, LOCK_EX) === false) {
                    throw new Failure('view_cache_unwritable');
                }
            }
        }
    }
}
