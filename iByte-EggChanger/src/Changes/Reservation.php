<?php

namespace IByte\EggChanger\Changes;

use Illuminate\Support\Facades\Cache;
use IByte\EggChanger\Models\Operation;

/**
 * Durable per-server reservation.
 *
 * One active operation per server at a time, enforced with both the journal
 * phase column and a cache lock so double clicks, retries and queue workers in
 * different processes cannot run two destructive switches concurrently.
 * Locks are best-effort fencing for panel-side work; destructive work always
 * re-verifies the durable journal state first (a lock alone proves nothing
 * about a previous node task, per AGENTS.md §7).
 */
final class Reservation
{
    private const KEY_PREFIX = 'ibyte-egg-changer:reservation:';

    public static function key(int $serverId): string
    {
        return self::KEY_PREFIX . $serverId;
    }

    /**
     * Try to acquire the per-server switch lock. False when another operation
     * still owns it (another queued switch, a live switch, or a stale claim
     * within its TTL).
     */
    public static function acquire(int $serverId, ?int $ttlSeconds = 3600): bool
    {
        return Cache::lock(self::key($serverId), $ttlSeconds)->get();
    }

    /** Release the per-server lock (safe to call when not held by this call). */
    public static function release(int $serverId): void
    {
        try {
            Cache::lock(self::key($serverId))->release();
        } catch (\Throwable) {
            // Lock may already be expired/released — never mask release failures.
        }
    }

    /**
     * Journal-level check: any non-final phase operation for this server?
     * This is the durable gate (survives cache/queue restarts); the cache lock
     * is the short-term fence on top.
     */
    public static function journalHasActive(int $serverId): bool
    {
        return Operation::query()
            ->where('server_id', $serverId)
            ->whereIn('phase', [
                Operation::PHASE_QUEUED,
                Operation::PHASE_BACKUP,
                Operation::PHASE_CLEANING,
                Operation::PHASE_CONFIGURING,
                Operation::PHASE_REINSTALLING,
            ])
            ->exists();
    }
}