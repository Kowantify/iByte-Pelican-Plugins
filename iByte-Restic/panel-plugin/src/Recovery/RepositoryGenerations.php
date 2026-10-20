<?php

namespace IByte\Restic\Recovery;

use App\Models\Backup;
use IByte\Restic\Nodes\Transport;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RepositoryGenerations
{
    public static function prepare(Backup $backup, array $context, bool $allowRenewal): array
    {
        if (! $allowRenewal || $context['backend'] !== 'local' || DB::table('ibyte_restic_operations')->where('backup_uuid', $backup->uuid)->exists()) {
            return $context;
        }
        $repo = RecoveryRepository::where('host_id', $context['host'])->where('node_id', $context['node'])->where('server_uuid', $context['server'])->where('profile', $context['profile'])->first();
        if (! $repo) {
            return $context;
        }
        $reject = fn ($message) => throw new HttpException(409, $message, headers: ['Retry-After' => 0]);
        if (isset($repo->metadata['delete_request_uuid']) && ! isset($repo->metadata['repository_deleted_at'])) {
            $reject('Repository deletion is not confirmed yet. Retry its administrator cleanup before creating another backup.');
        }
        $transport = app(Transport::class);
        $ready = $transport->client($backup->server->node)->get('/api/servers/'.$backup->server->uuid.'/restic/readiness')->throw()->json();
        if (($ready['repository_generations'] ?? null) !== 'local-v1') {
            if (isset($repo->metadata['repository_deleted_at'])) {
                $reject('Update this Wings node with the matching installer so a deleted repository can receive a fresh key automatically.');
            }

            return $context;
        }
        $state = $transport->request($backup, 'repository-state', $context);
        foreach (['host', 'node', 'server', 'profile'] as $key) {
            if (($state[$key] ?? null) !== $context[$key]) {
                $reject('Repository state identity could not be verified. Existing keys were preserved.');
            }
        }
        $generation = (int) ($context['generation'] ?? 0);
        if (($state['generation'] ?? null) !== $generation || ! is_bool($state['exists'] ?? null)) {
            $reject('Repository generation/absence could not be verified. Existing keys were preserved.');
        }
        if ($state['exists']) {
            if (isset($repo->metadata['repository_deleted_at'])) {
                $reject('The deleted repository path still exists. Complete node cleanup before recreating it.');
            }

            return $context;
        }
        // Escrow precedes first initialization. A queued/rolled-back first
        // create must reuse its prepared key rather than generating another.
        if (! isset($repo->metadata['repository_deleted_at']) && ! ($state['initialized'] ?? false)) {
            $previouslyCreated = DB::table('ibyte_restic_operations')->where('server_uuid', $context['server'])->where('host_id', $context['host'])->where('node_id', $context['node'])
                ->where('operation', 'create')->where('status', 'succeeded')->whereNotNull('snapshot_id')
                ->when($generation > 0, fn ($query) => $query->where('payload->generation', $generation), fn ($query) => $query->whereNull('payload->generation'))->exists();
            if (! $previouslyCreated) {
                return $context;
            }
        }
        if ($generation >= 1000000000) {
            $reject('Repository generation limit reached.');
        }

        return array_replace($context, ['generation' => $generation + 1, 'previous_generation' => $generation]);
    }

    public static function preserveHistory(RecoveryRepository $repo): array
    {
        $history = $repo->metadata['generations'] ?? [];
        $location = ['generation' => $repo->metadata['generation'] ?? 0, 'repository' => $repo->metadata['repository'],
            'password_file' => $repo->metadata['password_file'] ?? null, 'captured_at' => $repo->captured_at?->toIso8601String(),
            'retired_at' => now()->toIso8601String(), 'owner_id' => $repo->owner_id, 'owner_username' => $repo->owner_username];
        foreach (DB::table('ibyte_restic_key_history')->where('repository_id', $repo->id)->get(['fingerprint']) as $version) {
            $history[$version->fingerprint] ??= $location;
        }

        return $history;
    }
}
