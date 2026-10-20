<?php

namespace IByte\Restic\Recovery;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RecoveryRevealController
{
    public function __invoke(Request $request, RecoveryRepository $repository): Response
    {
        abort_unless($request->isSecure(), 403, 'HTTPS is required for Restic key disclosure.');
        $kit = RecoveryVault::kit($request->user(), $repository, (string) $request->query('token'));
        if ($request->boolean('download')) {
            return response(json_encode($kit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 200,
                ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="restic-recovery-'.$repository->id.'.json"',
                    'Cache-Control' => 'no-store, private, max-age=0', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff']);
        }
        $password = base64_decode($kit['secret']['password_base64'], true);
        $display = mb_check_encoding($password, 'UTF-8') ? $password : '[Binary password: decode password_base64 from the recovery kit]';
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><title>Restic recovery keys</title><h1>Restic recovery keys</h1><p>Confidential recovery material. This one-use view cannot be reopened without reauthentication.</p><h2>Repository password</h2><pre>'.e($display).'</pre><h2>Recovery kit</h2><p>Save the following JSON securely. It includes repository credentials and former-owner/snapshot history.</p><pre>'.e(json_encode($kit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)).'</pre></html>';

        return response($html, 200, ['Cache-Control' => 'no-store, private, max-age=0', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"]);
    }
}
