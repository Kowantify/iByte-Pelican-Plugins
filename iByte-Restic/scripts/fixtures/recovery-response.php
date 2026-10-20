<?php

// Disposable node response used only by local tests; never imported by runtime.
function fixtureRecovery(array $context): array
{
    $suffix = 'host-'.$context['host'].'/node-'.$context['node'].'/'.$context['server'];
    if (($context['generation'] ?? 0) > 0) {
        $suffix .= '--g'.$context['generation'];
    }

    return array_intersect_key($context, array_flip(['host', 'node', 'server', 'profile', 'backend', 'generation'])) + [
        'repository' => '/srv/disposable-restic/'.$suffix,
        'original_root' => '/srv/disposable-volumes/'.$context['server'],
        'password_file' => '/etc/pelican/disposable.password',
        'password_base64' => base64_encode('disposable-test-password'),
        'retention_safe' => true,
        'credential_mode' => 'server',
        'key_finalized' => true,
    ];
}
