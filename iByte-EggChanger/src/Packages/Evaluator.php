<?php

namespace IByte\EggChanger\Packages;

use App\Models\Egg;
use App\Models\Server;
use IByte\EggChanger\Models\Package;
use IByte\EggChanger\Models\PackageEgg;
use RuntimeException;

/**
 * Single source of eligibility truth for listing, preview, confirmation and
 * queue-time recheck (AGENTS.md §3 — one shared evaluator).
 *
 * Decision rules live in EvaluatorRules (unit-testable without panel models).
 *
 * Semantics (spec-verified against pinned Panel + Wings sources):
 * - Both thresholds are inclusive; zero minimum = no minimum for that resource.
 * - A server qualifies for a package when allocated RAM >= min RAM AND allocated
 *   disk >= min disk *within the same package* (AND), never mixed across packages.
 * - OR across complete packages: a target egg is eligible if at least one
 *   qualifying package contains it.
 * - Wings treats a stored 0 disk/memory limit as unlimited (verified:
 *   internal/ufs/fs_quota.go "A limit of 0 means unlimited"; DiskSpace() <= 0
 *   skips the space check). Unlimited satisfies every finite minimum. Server
 *   records in the panel carry MiB integers; 0 is a real unlimited allocation.
 */
final class Evaluator extends EvaluatorRules
{
    /**
     * Eligible eggs for a server, deduplicated by egg id, deterministic order,
     * each with the qualifying packages that granted it.
     *
     * @return array<int, array{
     *     egg_id: int, egg_name: string, egg_description: ?string,
     *     packages: array<int, array{id: int, name: string, description: ?string,
     *         min_memory_mb: int, min_disk_mb: int}>
     * }>
     */
    public function eligibleEggs(Server $server): array
    {
        $eligible = [];

        foreach ($this->qualifyingPackages($server) as $package) {
            $eggIds = PackageEgg::query()->where('package_id', $package->id)
                ->orderBy('egg_id')->pluck('egg_id')->all();

            $eggs = Egg::query()->whereIn('id', $eggIds)->orderBy('id')->get();

            foreach ($eggs as $egg) {
                $entry = $eligible[$egg->id] ?? [
                    'egg_id' => $egg->id,
                    'egg_name' => $egg->name,
                    'egg_description' => $egg->description,
                    'packages' => [],
                ];

                $entry['packages'][] = [
                    'id' => $package->id,
                    'name' => $package->name,
                    'description' => $package->description,
                    'min_memory_mb' => $package->min_memory_mb,
                    'min_disk_mb' => $package->min_disk_mb,
                ];

                $eligible[$egg->id] = $entry;
            }
        }

        // Deterministic ordering: smallest requirement first, then egg id.
        usort($eligible, function (array $a, array $b): int {
            $aMin = $this->bestMin($a['packages']);
            $bMin = $this->bestMin($b['packages']);

            return [$aMin, $a['egg_id']] <=> [$bMin, $b['egg_id']];
        });

        return array_values($eligible);
    }

    /**
     * Is this specific target egg eligible for the server right now?
     * Same rules as eligibleEggs, no short-circuiting across packages.
     */
    public function isEggEligible(Server $server, int $eggId): bool
    {
        foreach ($this->qualifyingPackages($server) as $package) {
            $contains = PackageEgg::query()
                ->where('package_id', $package->id)
                ->where('egg_id', $eggId)
                ->exists();

            if ($contains) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first qualifying package that contains the egg (for journal snapshots).
     */
    public function grantingPackage(Server $server, int $eggId): ?Package
    {
        foreach ($this->qualifyingPackages($server) as $package) {
            $contains = PackageEgg::query()
                ->where('package_id', $package->id)
                ->where('egg_id', $eggId)
                ->exists();

            if ($contains) {
                return $package;
            }
        }

        return null;
    }

    /**
     * Enabled packages the server qualifies for, deterministic order.
     *
     * @return array<int, Package>
     */
    public function qualifyingPackages(Server $server): array
    {
        $packages = Package::query()
            ->where('enabled', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $qualifying = [];

        foreach ($packages as $package) {
            if ($this->qualifies($server, $package)) {
                $qualifying[] = $package;
            }
        }

        return $qualifying;
    }

    /**
     * Minimum (sorted first) across granting packages — used by the list sort.
     *
     * @param  array<int, array{min_memory_mb: int, min_disk_mb: int}>  $packages
     * @return array{0: int, 1: int}
     */
    private function bestMin(array $packages): array
    {
        $memory = PHP_INT_MAX;
        $disk = PHP_INT_MAX;

        foreach ($packages as $p) {
            [$memory, $disk] = [
                min($memory, $p['min_memory_mb']),
                min($disk, $p['min_disk_mb']),
            ];
        }

        return [$memory, $disk];
    }

    /**
     * Read-only checkout catalog for hypothetical, finite allocations.
     * Uses the SAME qualifies() rule used by owner-side switching; no server
     * entitlement is created or modified. All enabled-package eggs are shown.
     * Paymenter must recheck with its PAID limits at provisioning time.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalogForResources(int $memoryMb, int $diskMb): array
    {
        if ($memoryMb <= 0 || $diskMb <= 0) {
            throw new RuntimeException('Checkout memory and storage must be finite positive MiB values.');
        }

        $packages = Package::query()->where('enabled', true)
            ->orderBy('sort_order')->orderBy('id')->get();
        $packageIds = $packages->pluck('id')->all();
        if (!$packageIds) {
            return [];
        }
        $links = PackageEgg::query()->whereIn('package_id', $packageIds)->get();
        $eggIds = $links->pluck('egg_id')->unique()->all();
        $eggs = Egg::query()->whereIn('id', $eggIds)->get()->keyBy('id');
        $packagesById = $packages->keyBy('id');
        $allocation = (object) ['memory' => $memoryMb, 'disk' => $diskMb];
        $candidates = [];

        foreach ($links as $link) {
            $package = $packagesById->get($link->package_id);
            $egg = $eggs->get($link->egg_id);
            if (!$package || !$egg) {
                continue;
            }
            $neededMemory = max(0, (int) $package->min_memory_mb - $memoryMb);
            $neededDisk = max(0, (int) $package->min_disk_mb - $diskMb);
            $candidates[$egg->id][] = [
                'package_id' => (int) $package->id,
                'package_name' => (string) $package->name,
                'eligible' => $this->qualifies($allocation, $package),
                'minimum_memory_mb' => (int) $package->min_memory_mb,
                'minimum_disk_mb' => (int) $package->min_disk_mb,
                'additional_memory_mb' => $neededMemory,
                'additional_disk_mb' => $neededDisk,
            ];
        }

        $result = [];
        foreach ($candidates as $eggId => $rules) {
            $eligibleRules = array_values(array_filter($rules, fn ($r) => $r['eligible']));
            // Choose the least-upgrade candidate as ONE package, never combine
            // RAM from one package with disk from another.
            usort($rules, fn ($a, $b) => [
                $a['additional_memory_mb'] + $a['additional_disk_mb'],
                $a['additional_memory_mb'],
                $a['additional_disk_mb'],
                $a['package_id'],
            ] <=> [
                $b['additional_memory_mb'] + $b['additional_disk_mb'],
                $b['additional_memory_mb'],
                $b['additional_disk_mb'],
                $b['package_id'],
            ]);
            $best = $eligibleRules ? $eligibleRules[0] : $rules[0];
            $reasons = [];
            if (!$eligibleRules) {
                if ($best['additional_memory_mb'] > 0) {
                    $reasons[] = 'Needs ' . $best['minimum_memory_mb'] . ' MiB RAM (+ ' . $best['additional_memory_mb'] . ' MiB)';
                }
                if ($best['additional_disk_mb'] > 0) {
                    $reasons[] = 'Needs ' . $best['minimum_disk_mb'] . ' MiB storage (+ ' . $best['additional_disk_mb'] . ' MiB)';
                }
            }
            $result[] = [
                'egg_id' => (int) $eggId,
                'egg_name' => (string) $eggs->get($eggId)->name,
                'eligible' => (bool) $eligibleRules,
                'minimum_memory_mb' => $best['minimum_memory_mb'],
                'minimum_disk_mb' => $best['minimum_disk_mb'],
                'additional_memory_mb' => $best['additional_memory_mb'],
                'additional_disk_mb' => $best['additional_disk_mb'],
                'reasons' => $reasons,
                'qualifying_packages' => array_column($eligibleRules, 'package_name'),
            ];
        }
        usort($result, fn ($a, $b) => [!$a['eligible'], strtolower($a['egg_name']), $a['egg_id']]
            <=> [!$b['eligible'], strtolower($b['egg_name']), $b['egg_id']]);
        return $result;
    }

    /**
     * Admin-only diagnostic: evaluate eligibility for hypothetical allocations
     * without touching a real entitlement source. Never used by the client flow.
     *
     * @return array<int, array{egg_id: int, qualified: bool, reasons: string[]}>
     */
    public function previewFor(Package $package, int $memoryMb, int $diskMb): array
    {
        $reasons = [];

        if ($package->min_memory_mb > 0 && $memoryMb < $package->min_memory_mb) {
            $reasons[] = sprintf('memory %d MiB < package minimum %d MiB', $memoryMb, $package->min_memory_mb);
        }

        if ($package->min_disk_mb > 0 && $diskMb < $package->min_disk_mb) {
            $reasons[] = sprintf('disk %d MiB < package minimum %d MiB', $diskMb, $package->min_disk_mb);
        }

        return [[
            'egg_id' => $package->id,
            'qualified' => $reasons === [],
            'reasons' => $reasons,
        ]];
    }

    /** Throw when an egg cannot be granted — used at confirmation and queue time. */
    public function assertEligible(Server $server, int $eggId): void
    {
        if (!$this->isEggEligible($server, $eggId)) {
            throw new RuntimeException(
                'The selected egg is not currently eligible for this server under any enabled package.'
            );
        }
    }
}