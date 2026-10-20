<?php

namespace IByte\EggChanger\Packages;

/**
 * Rule-logic base of the evaluator, extracted so unit tests can exercise the
 * pure decision functions without booting the panel Eloquent models.
 *
 * Evaluator (the real class used by pages/jobs) extends this and adds the
 * model/DB-backed qualifyingPackages(), eligibleEggs(), isEggEligible() etc.
 * The rules here are the single behavioral source; they are NOT duplicated.
 */
abstract class EvaluatorRules
{
    /**
     * AND within one package. Inclusive thresholds. Zero server allocation on a
     * resource is the panel-native "unlimited" for that resource (verified in
     * Wings source) and satisfies any finite minimum. Package min of zero means
     * no minimum, not "unlimited required".
     */
    public function qualifies(object $server, object $package): bool
    {
        $memory = $this->allocated($server->memory);
        $disk = $this->allocated($server->disk);

        if ($memory === null || $disk === null) {
            // Null/negative/invalid allocation values are errors, never zero or
            // unlimited (AGENTS.md §3.4).
            return false;
        }

        return $this->satisfies($memory, $package->min_memory_mb)
            && $this->satisfies($disk, $package->min_disk_mb);
    }

    /**
     * Normalize an allocation value. Returns null on invalid input (negative,
     * non-numeric) — an explicit error state, never coerced to zero/unlimited.
     *
     * Zero handling (AGENTS.md §3.5: "Where a stored zero genuinely represents
     * unlimited RAM/disk"): the panel's own admin UI treats memory/disk == 0 as
     * the checked "Unlimited" state (ServerResource unlimited_mem/unlimited_disk
     * toggles set the value to 0), and Wings skips its space check when
     * DiskSpace() <= 0. So 0 satisfies finite minimums for that resource.
     */
    private function allocated(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $integer = (int) $value;

        if ($integer < 0) {
            return null;
        }

        return $integer;
    }

    /** Inclusive threshold check. 0 minimum = no minimum. Allocated 0 within a
     * resource dimension = native unlimited (the panel admin UI's
     * unlimited_mem/unlimited_disk toggle stores 0, and Wings skips its space
     * check when DiskSpace() <= 0) — satisfies any finite minimum, AGENTS.md §3.5. */
    private function satisfies(int $allocatedMb, int $minimumMb): bool
    {
        if ($minimumMb <= 0 || $allocatedMb === 0) {
            return true;
        }

        return $allocatedMb >= $minimumMb;
    }
}