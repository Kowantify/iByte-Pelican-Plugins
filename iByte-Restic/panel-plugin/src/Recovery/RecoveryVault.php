<?php

namespace IByte\Restic\Recovery;

use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use IByte\Restic\Nodes\Transport;
use IByte\Restic\Support\Installation;
use IByte\Restic\Support\OperationDiagnostics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class RecoveryVault
{
    public static function authorize(User $user): void
    {
        abort_unless($user->isRootAdmin(), 403);
    }

    public static function audit(string $event, ?int $repository = null, ?int $actor = null): void
    {
        DB::table('ibyte_restic_recovery_audit')->insert(['repository_id' => $repository, 'actor_id' => $actor, 'event' => $event, 'created_at' => now()]);
    }

    public static function owner(Server $server): array
    {
        $owner = $server->user()->firstOrFail();

        return ['id' => $owner->id, 'uuid' => $owner->uuid, 'username' => $owner->username];
    }

    public static function capture(Backup $backup, bool $allowRenewal = false): RecoveryRepository
    {
        // A normal backup may request escrow, but its caller never receives the
        // key. The only decrypt path is the password-confirmed root-admin view.
        $transport = app(Transport::class);
        $context = $transport->context($backup, false);
        if ($backup->server->node->scheme !== 'https') {
            throw new HttpException(409, 'Restic recovery escrow requires trusted HTTPS on Wings. Credentials will not be fetched over HTTP.', headers: ['Retry-After' => 0]);
        }
        try {
            $context = RepositoryGenerations::prepare($backup, $context, $allowRenewal);
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new HttpException(409, 'Restic repository state unavailable. Check the original Wings node; no new key or backup was dispatched.', headers: ['Retry-After' => 0]);
        }
        $stage = 'key preparation';
        try {
            // Preparation is repeat-safe and also repairs a key-file write
            // interrupted after journal persistence. No key is retired here.
            $transport->request($backup, 'server-key', $context);
            $stage = 'recovery response';
            $info = $transport->request($backup, 'recovery', $context);
            $stage = 'recovery response validation';
            if (($info['credential_mode'] ?? null) !== 'server' || ! is_bool($info['key_finalized'] ?? null)) {
                throw new RuntimeException;
            }
            if (($info['retention_safe'] ?? false) !== true) {
                throw new RuntimeException;
            }
            foreach (['host', 'node', 'server', 'profile', 'backend'] as $key) {
                if (($info[$key] ?? null) !== $context[$key]) {
                    throw new RuntimeException;
                }
            }
            if (($info['generation'] ?? 0) !== ($context['generation'] ?? 0)) {
                throw new RuntimeException;
            }
            foreach (['repository', 'original_root', 'password_file'] as $key) {
                if (! is_string($info[$key] ?? null) || $info[$key] === '' || strlen($info[$key]) > 4096 || str_contains($info[$key], "\0")) {
                    throw new RuntimeException;
                }
            }
            $password = base64_decode($info['password_base64'] ?? '', true);
            if ($password === false || $password === '' || strlen($password) > 65536) {
                throw new RuntimeException;
            }
            if ($context['backend'] === 's3') {
                $credentials = base64_decode($info['credentials_base64'] ?? '', true);
                if ($credentials === false || $credentials === '' || strlen($credentials) > 65536 || ! is_string($info['credentials_file'] ?? null)) {
                    throw new RuntimeException;
                }
            }
        } catch (Throwable $exception) {
            // Do not chain HTTP exceptions: their response/body may contain keys.
            $reason = OperationDiagnostics::transport($exception);
            logger()->warning('Restic recovery escrow refused', ['host_id' => $context['host'], 'node_id' => $context['node'], 'stage' => $stage, 'reason' => $reason]);
            throw new HttpException(409, 'Restic recovery escrow unavailable during '.$stage.'. '.$reason.' An administrator can run php artisan restic:diagnose-node '.$context['host'].' '.$context['node'].'. No backup was dispatched; existing keys were preserved.', headers: ['Retry-After' => 0]);
        }

        return DB::transaction(function () use ($backup, $context, $info) {
            $secrets = ['password_base64' => $info['password_base64'], 'credentials_base64' => $info['credentials_base64'] ?? null];
            $identity = ['host_id' => $context['host'], 'node_id' => $context['node'], 'server_uuid' => $context['server'], 'profile' => $context['profile']];
            $repo = RecoveryRepository::where($identity)->lockForUpdate()->first() ?? new RecoveryRepository($identity);
            $renewing = isset($context['previous_generation']) && $context['previous_generation'] === ($repo->metadata['generation'] ?? 0)
                && $context['generation'] === $context['previous_generation'] + 1 && $context['backend'] === 'local';
            if (isset($repo->metadata['delete_request_uuid']) && ! $renewing) {
                throw new HttpException(409, 'Repository deletion is reserved or retired. Existing recovery keys are preserved; retry or reconcile the original cleanup request.', headers: ['Retry-After' => 0]);
            }
            $owner = self::owner($backup->server);
            $history = $repo->owner_history ?? [];
            if (($history[array_key_last($history)]['owner'] ?? null) !== $owner) {
                $history[] = ['owner' => $owner, 'at' => now()->toIso8601String()];
            }
            $metadata = array_intersect_key($info, array_flip(['repository', 'original_root', 'password_file', 'credentials_file', 'retention_safe', 'credential_mode', 'key_finalized']));
            $metadata += ['host_name' => $backup->backupHost->name, 'node_name' => $backup->server->node->name, 'node_fqdn' => $backup->server->node->fqdn, 'server_id' => $backup->server_id];
            $metadata['generation'] = $context['generation'] ?? 0;
            $metadata['generations'] = $repo->metadata['generations'] ?? [];
            if ($repo->exists) {
                $old = json_decode(Crypt::decryptString($repo->secret), true, flags: JSON_THROW_ON_ERROR);
                $same = hash_equals(json_encode($old, JSON_THROW_ON_ERROR), json_encode($secrets, JSON_THROW_ON_ERROR));
                $upgrade = ($repo->metadata['credential_mode'] ?? 'legacy-profile') !== 'server'
                    && is_string($info['previous_password_base64'] ?? null)
                    && hash_equals($old['password_base64'], $info['previous_password_base64'])
                    && ($old['credentials_base64'] ?? null) === $secrets['credentials_base64'];
                if (! $renewing && ($repo->metadata['repository'] !== $metadata['repository'] || (! $same && ! $upgrade))) {
                    throw new HttpException(409, 'Restic recovery credentials/location changed externally. Existing escrow was preserved; reconcile before continuing.', headers: ['Retry-After' => 0]);
                }
                self::version($repo->id, $old, $repo->metadata['credential_mode'] ?? 'legacy-profile');
                if ($renewing) {
                    if ($same || $repo->metadata['repository'] === $metadata['repository']) {
                        throw new RuntimeException('A replacement repository requires a distinct key and location.');
                    }
                    $metadata['generations'] = RepositoryGenerations::preserveHistory($repo);
                }
            }
            $repo->fill(['backend' => $context['backend'], 'owner_id' => $owner['id'], 'owner_username' => $owner['username'], 'server_name' => $backup->server->name,
                'metadata' => $metadata, 'owner_history' => $history,
                'secret' => Crypt::encryptString(json_encode($secrets, JSON_THROW_ON_ERROR)), 'captured_at' => now()])->save();
            self::version($repo->id, $secrets, 'server');
            if (isset($info['previous_password_base64'])) {
                $previous = base64_decode($info['previous_password_base64'], true);
                if ($previous === false || $previous === '' || strlen($previous) > 65536) {
                    throw new RuntimeException('Invalid prior credential; escrow transaction rolled back.');
                }
                self::version($repo->id, ['password_base64' => $info['previous_password_base64'], 'credentials_base64' => $secrets['credentials_base64']], 'legacy-profile');
            }
            // Backfill existing native mappings while the server still exists.
            $rows = DB::table('ibyte_restic_operations')->where('server_uuid', $context['server'])->where('host_id', $context['host'])->where('node_id', $context['node'])->where('operation', 'create')->where('status', 'succeeded')->whereNotNull('snapshot_id')->get();
            foreach ($rows as $row) {
                $existing = Backup::where('uuid', $row->backup_uuid)->first();
                if ($existing) {
                    self::snapshot($existing, $row->snapshot_id, $row->metrics ? json_decode($row->metrics, true) : []);
                }
            }
            self::audit('escrow-captured', $repo->id);

            return $repo;
        });
    }

    private static function version(int $repository, array $secret, string $kind): void
    {
        $json = json_encode($secret, JSON_THROW_ON_ERROR);
        DB::table('ibyte_restic_key_history')->insertOrIgnore(['repository_id' => $repository,
            'fingerprint' => hash('sha256', $json), 'kind' => $kind,
            'secret' => Crypt::encryptString($json), 'captured_at' => now()]);
    }

    public static function finalize(Backup $backup): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Server key activation requires committed recovery escrow.');
        }
        $transport = app(Transport::class);
        $context = $transport->context($backup, false);
        $repo = RecoveryRepository::where('host_id', $context['host'])->where('node_id', $context['node'])->where('server_uuid', $context['server'])->where('profile', $context['profile'])->firstOrFail();
        if (($repo->metadata['credential_mode'] ?? null) !== 'server') {
            throw new RuntimeException('Unique server credential has not been escrowed.');
        }
        if (($repo->metadata['key_finalized'] ?? false) === true) {
            return;
        }
        $secret = json_decode(Crypt::decryptString($repo->secret), true, flags: JSON_THROW_ON_ERROR);
        $transport->request($backup, 'server-key', $context + ['escrow_receipt' => hash('sha256', base64_decode($secret['password_base64'], true))]);
        $metadata = $repo->metadata;
        $metadata['key_finalized'] = true;
        $repo->update(['metadata' => $metadata]);
        self::audit('server-key-activated', $repo->id);
    }

    public static function prepareQueuedBackup(Backup $backup): void
    {
        // Queued requests serialized before per-server credentials were added
        // need the same upgrade as fresh requests, rather than endless finalize
        // retries against original-profile escrow. Never replay an unknown job.
        $repo = RecoveryRepository::where('host_id', $backup->backup_host_id)->where('node_id', $backup->server->node_id)
            ->where('server_uuid', $backup->server->uuid)->where('profile', $backup->backupHost->configuration['profile'])->first();
        if (($repo?->metadata['credential_mode'] ?? null) !== 'server') {
            self::capture($backup);
        }
        self::finalize($backup);
    }

    public static function snapshot(Backup $backup, string $snapshot, array $metrics): void
    {
        $repo = RecoveryRepository::where('host_id', $backup->backup_host_id)->where('node_id', $backup->server->node_id)->where('server_uuid', $backup->server->uuid)->where('profile', $backup->backupHost->configuration['profile'])->first();
        if (! $repo) {
            return;
        } // Older jobs remain compatible; backfill explicitly.
        DB::table('ibyte_restic_recovery_snapshots')->insertOrIgnore(['backup_uuid' => $backup->uuid, 'repository_id' => $repo->id,
            'snapshot_id' => $snapshot, 'backup_name' => $backup->name, 'owner_id' => $backup->server->owner_id, 'owner_username' => $backup->server->user->username,
            'is_locked' => $backup->is_locked,
            'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR), 'backup_created_at' => $backup->created_at]);
    }

    public static function updateOwner(Server $server): void
    {
        if (! Schema::hasTable('ibyte_restic_repositories')) {
            if ($server->backups()->whereHas('backupHost', fn ($query) => $query->where('schema', 'restic'))->exists()) {
                Installation::assertReady();
            }

            return;
        }
        foreach (RecoveryRepository::where('server_uuid', $server->uuid)->get() as $repo) {
            if (isset($repo->metadata['archived_at'])) {
                continue;
            }
            $owner = self::owner($server->fresh());
            $history = $repo->owner_history;
            if (($history[array_key_last($history)]['owner'] ?? null) !== $owner) {
                $history[] = ['owner' => $owner, 'at' => now()->toIso8601String()];
            }
            $repo->update(['owner_id' => $owner['id'], 'owner_username' => $owner['username'], 'owner_history' => $history, 'server_name' => $server->name]);
        }
    }

    public static function confirm(User $user, string $password): void
    {
        self::authorize($user);
        $key = 'ibyte-restic:recovery-confirm:'.$user->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429);
        RateLimiter::hit($key, 60);
        if (! Hash::check($password, $user->getAuthPassword())) {
            self::audit('reauth-denied', actor: $user->id);
            throw ValidationException::withMessages(['password' => 'The current Panel password is incorrect.']);
        }
    }

    public static function issueReveal(User $user, RecoveryRepository $repo, string $password, bool $history = false): string
    {
        self::confirm($user, $password);
        $token = Str::random(48);
        Cache::put('ibyte-restic:reveal:'.hash('sha256', $token), ['user' => $user->id, 'repo' => $repo->id, 'history' => $history], 30);
        self::audit('reveal-authorized', $repo->id, $user->id);

        return route('ibyte-restic.recovery-reveal', ['repository' => $repo->id, 'token' => $token]);
    }

    public static function kit(User $user, RecoveryRepository $repo, string $token): array
    {
        self::authorize($user);
        $key = 'ibyte-restic:reveal:'.hash('sha256', $token);
        $lock = Cache::lock($key.':lock', 5);
        abort_unless($lock->get(), 410);
        try {
            $grant = Cache::get($key);
            abort_unless($grant && $grant['user'] === $user->id && $grant['repo'] === $repo->id, 403);
            Cache::forget($key);
        } finally {
            $lock->release();
        }
        self::audit('key-viewed', $repo->id, $user->id);
        $secret = json_decode(Crypt::decryptString($repo->secret), true, flags: JSON_THROW_ON_ERROR);

        $history = [];
        if ($grant['history'] ?? false) {
            foreach (DB::table('ibyte_restic_key_history')->where('repository_id', $repo->id)->orderBy('id')->get() as $version) {
                $history[] = ['id' => $version->id, 'kind' => $version->kind, 'captured_at' => $version->captured_at,
                    'repository' => $repo->metadata['generations'][$version->fingerprint] ?? ['generation' => $repo->metadata['generation'] ?? 0, 'repository' => $repo->metadata['repository']],
                    'secret' => json_decode(Crypt::decryptString($version->secret), true, flags: JSON_THROW_ON_ERROR)];
            }
            // Pre-upgrade deleted/offline repositories remain recoverable too.
            if ($history === []) {
                $history[] = ['kind' => 'previously-escrowed', 'captured_at' => $repo->captured_at, 'secret' => $secret];
            }
            self::audit('key-history-viewed', $repo->id, $user->id);
        }

        return ['format' => 'ibyte-restic-recovery-v1', 'repository' => $repo->metadata['repository'], 'identity' => $repo->only(['id', 'host_id', 'node_id', 'server_uuid', 'profile', 'backend', 'server_name', 'owner_id', 'owner_username', 'owner_history', 'captured_at', 'server_deleted_at']),
            'metadata' => $repo->metadata, 'secret' => $secret, 'key_history' => $history,
            'snapshots' => DB::table('ibyte_restic_recovery_snapshots')->where('repository_id', $repo->id)->get()->toArray(),
            'instructions' => 'Preserve the complete repository including keys/. Decode secret.password_base64 to a private password file. For S3 also decode credentials_base64 (key/secret JSON). Use Restic snapshots then restore the chosen recorded snapshot into a NEW EMPTY recovery directory. Never restore over a live server. A recreated Pelican server has a different UUID; automatic adoption/reattachment is unsupported. Protect this kit and the Panel APP_KEY/database backup.'];
    }
}
