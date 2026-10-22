<?php

namespace IByte\EggChanger\Support;

use App\Facades\Activity;

/**
 * Activity log wrapper. Never logs variable values or node tokens; only
 * operation metadata (ids, phases, egg ids) ever reaches the audit trail.
 */
final class Audit
{
    /** @param array<string, mixed> $properties */
    public static function write(string $event, array $properties = []): void
    {
        try {
            Activity::event($event)->property($properties)->log();
        } catch (\Throwable) {
            // Activity logging must never break a switch operation.
        }
    }
}