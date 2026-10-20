<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Operations;

use IByte\MinecraftManager\Catalog\Catalog;
use IByte\MinecraftManager\Support\Failure;

final class PackageBatch
{
    public static function resolve(Catalog $catalog, array $selections): array
    {
        if (!array_is_list($selections) || count($selections) < 1 || count($selections) > 20) { throw new Failure('batch_limit'); }
        $items = []; $context = null; $projects = [];
        foreach ($selections as $selection) {
            $target = [$selection['version'], $selection['loader'], $selection['type']];
            $key = $selection['provider'].':'.$selection['project'];
            if ($selection['kind'] !== 'package' || isset($selection['items']) || isset($projects[$key]) || $context !== null && $context !== $target) { throw new Failure('batch_context_mismatch'); }
            $context = $target; $projects[$key] = true;
            $items[] = ['selection' => $selection, 'release' => $catalog->exact($selection['provider'], $selection['project'], $selection['release'])];
        }
        return ['provider'=>'batch', 'project'=>'selected', 'id'=>PlanBuilder::digest($selections), 'title'=>count($items).' selected projects',
            'version'=>$context[0], 'game_versions'=>[$context[0]], 'loaders'=>[$context[1]], 'files'=>[], 'items'=>$items];
    }
}
