@php
/** Render the qualifying package line for one eligible egg entry. */
/** @var array{packages: array<int, array{id: int, name: string, min_memory_mb: int, min_disk_mb: int}>} $egg */
$labels = [];
foreach ($egg['packages'] as $p) {
    $labels[] = $p['name']
        . ' (min RAM ' . ($p['min_memory_mb'] > 0 ? $p['min_memory_mb'] . ' MiB' : 'none')
        . ', min disk ' . ($p['min_disk_mb'] > 0 ? $p['min_disk_mb'] . ' MiB' : 'none') . ')';
}
echo 'Qualifies via: ' . implode(' or ', $labels);
@endphp