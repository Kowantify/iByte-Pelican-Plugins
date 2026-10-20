<?php

namespace IByte\Restic\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

final class OperationDiagnostics
{
    public static function transport(Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            return match ($exception->response->status()) {
                401, 403 => 'Wings rejected authentication or access. Verify the Panel node credentials and access policy.',
                404 => 'The requested Wings addon endpoint or server was not found. Verify the node addon and server assignment.',
                409 => 'Wings refused the request because its repository identity, key or operation state could not be accepted. Preserve private node state and reconcile it.',
                503 => 'Wings reports the addon or credential profile unavailable. Check the node addon configuration and private file permissions.',
                default => 'Wings returned an unsuccessful HTTP response. Inspect the protected node logs.',
            }.' (HTTP '.$exception->response->status().')';
        }
        if ($exception instanceof ConnectionException) {
            return 'A trusted connection to Wings could not be established. Check connectivity, DNS and the TLS certificate; do not disable TLS verification.';
        }
        if ($exception->getMessage() === 'Restic node identity/version handshake failed.') {
            return 'The Wings identity/version header does not match the supported addon. Verify the node ID and install Wings addon preview.2.';
        }

        return 'The response could not be validated. Check node diagnostics and protected logs; existing keys remain preserved.';
    }

    public static function nodeFailure(mixed $error): string
    {
        // Never echo arbitrary node output, paths, exception messages or URLs.
        if (is_string($error) && preg_match('/^restic (check|stats|prune|backup|restore|forget) failed: exit status ([0-9]{1,3}) \(output redacted\)$/D', $error, $match)) {
            return 'Restic '.$match[1].' exited with status '.$match[2].'. This does not by itself prove repository corruption. Verify the repository credentials, availability and locks on the source node; raw command output is intentionally withheld.';
        }

        return match ($error) {
            'server must be stopped', 'cannot prove server stopped' => 'Wings could not prove the server was stopped. Keep restore protection in place.',
            'restore journal retained; keep server stopped and reconcile' => 'A restore journal remains on the node. Keep the server stopped and reconcile before retrying.',
            'daemon interrupted; operator reconciliation required' => 'Wings restarted while work was active. Inspect protected node state before retrying.',
            'cannot persist completion' => 'Wings could not persist completion. Verify private state storage and permissions before reconciliation.',
            'mapped snapshot is absent', 'snapshot deleted' => 'The recorded snapshot is absent on the node. Review repository inventory before removing Panel metadata.',
            'repository lacks fresh provisioning marker' => 'The repository provisioning marker is unavailable. Preserve the repository and private node state; do not initialize over existing data.',
            'unique server key requires committed Panel escrow before backup', 'unique server key and committed recovery escrow required before backup' => 'The node requires a committed server key escrow. Check node readiness and escrow diagnostics before retrying.',
            default => 'No recognized safe failure detail is available. Use Node diagnostics with this operation ID to read its saved node result, or inspect protected logs. No automatic repair or deletion was performed.',
        };
    }

    public static function storedFailure(mixed $error): string
    {
        if (in_array($error, ['Server deleted; original repository destruction remains pending. Check source node availability, safe path and active work. No physical success is claimed.', 'Server deleted; repository cleanup needs retry through the existing scheduler.'], true)) {
            return $error;
        }
        if (is_string($error) && preg_match('/^Restic (check|stats|prune|backup|restore|forget) exited with status ([0-9]{1,3})\./', $error, $match)) {
            $expected = self::nodeFailure('restic '.$match[1].' failed: exit status '.$match[2].' (output redacted)');
            if ($error === $expected) {
                return $expected;
            }
        }
        foreach (['server must be stopped', 'restore journal retained; keep server stopped and reconcile', 'daemon interrupted; operator reconciliation required', 'cannot persist completion', 'mapped snapshot is absent', 'repository lacks fresh provisioning marker', 'unique server key requires committed Panel escrow before backup'] as $known) {
            if ($error === self::nodeFailure($known)) {
                return self::nodeFailure($known);
            }
        }

        return self::nodeFailure(null);
    }
}
