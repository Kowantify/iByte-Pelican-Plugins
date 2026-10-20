<?php

if (getenv('IBYTE_DISPOSABLE_LINUX') !== '1' || ! is_file('/opt/ibyte-acceptance/fixture') || PHP_OS_FAMILY !== 'Linux') {
    exit(1);
}
foreach (glob('/var/lib/ibyte-restic/state/job-*.json') as $path) {
    $job = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (($job['operation'] ?? '') === 'restore') {
        echo json_encode(array_intersect_key($job, array_flip(['operation', 'status', 'error', 'request_id']))), PHP_EOL;
    }
}
