<?php

namespace IByte\EggChanger\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use IByte\EggChanger\Packages\Evaluator;
use IByte\EggChanger\Models\Recommendation;
use Illuminate\Support\Facades\Schema;
use IByte\EggChanger\Support\Readiness;

/** Read-only, authenticated catalog. Never grants, provisions, or switches an egg. */
final class CompatibilityController
{
    public function __invoke(Request $request, Evaluator $evaluator): JsonResponse
    {
        $data = Validator::make($request->query(), [
            'memory_mb' => ['required', 'integer', 'min:1', 'max:1048576'],
            'disk_mb' => ['required', 'integer', 'min:1', 'max:10485760'],
        ])->validate();

        // Missing schema must NOT become an open catalog or an empty success.
        if (!Readiness::ready()) {
            return response()->json(['message' => 'Egg Changer database is not ready.'], 503);
        }

        // Installation guard: publishing incomplete guidance would mislead customers.
        if (!Schema::hasTable('ibyte_egg_changer_recommendations')) {
            return response()->json(['message' => 'Egg Changer recommendation migration is not installed.'], 503);
        }

        $memory = (int) $data['memory_mb'];
        $disk = (int) $data['disk_mb'];
        $games = $evaluator->catalogForResources($memory, $disk);
        $eggIds = array_column($games, 'egg_id');
        $profiles = Recommendation::query()->whereIn('egg_id', $eggIds)
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('egg_id');

        foreach ($games as &$game) {
            // Hard eligibility remains exclusively controlled by Evaluator.
            $game['use_cases'] = [];
            foreach ($profiles->get($game['egg_id'], collect()) as $profile) {
                $extraMemory = max(0, $profile->recommended_memory_mb - $memory);
                $extraDisk = max(0, $profile->recommended_disk_mb - $disk);
                $game['use_cases'][] = [
                    'id' => (int) $profile->id,
                    'name' => (string) $profile->name,
                    'description' => (string) ($profile->description ?? ''),
                    'recommended_memory_mb' => (int) $profile->recommended_memory_mb,
                    'recommended_disk_mb' => (int) $profile->recommended_disk_mb,
                    'additional_memory_mb' => $extraMemory,
                    'additional_disk_mb' => $extraDisk,
                    'status' => !$game['eligible'] ? 'locked'
                        : (($extraMemory === 0 && $extraDisk === 0) ? 'recommended' : 'below_recommendation'),
                ];
            }
            $game['recommendations_configured'] = count($game['use_cases']) > 0;
        }
        unset($game);
        return response()->json([
            'memory_mb' => (int) $data['memory_mb'],
            'disk_mb' => (int) $data['disk_mb'],
            'games' => $games,
            'eligible_count' => count(array_filter($games, fn ($g) => $g['eligible'])),
        ]);
    }
}
