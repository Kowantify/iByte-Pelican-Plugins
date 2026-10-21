<?php

namespace IByte\Restic\Integration;

use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Detects that the current request arrived through Pelican's Application API
 * using a TYPE_APPLICATION key (the path hosting providers take external
 * billing systems such as Paymenter).
 *
 * Detection rationale:
 * - The application-api middleware group runs ONLY on routes under
 *   /api/application and authenticates a sanctum token belonging to an
 *   administrator (bootstrap/app.php group 'application-api'; middleware
 *   AuthenticateApplicationUser requires user->isAdmin()).
 * - ApiKey extends Sanctum PersonalAccessToken; key_type 2 is
 *   ApiKey::TYPE_APPLICATION. A UI session has no currentAccessToken, and an
 *   account-scoped token (key_type 1) may not call admin application routes
 *   when it lacks admin scope checks; we additionally require the acting user
 *   to be an admin.
 */
final class ApplicationApiCall
{
    public static function detect(): bool
    {
        try {
            /** @var Request $request */
            $request = App::make('request');

            if (! $request instanceof Request || ! $request->user()) {
                return false;
            }

            if (! $request->user()->isAdmin()) {
                return false;
            }

            $token = $request->user()->currentAccessToken();

            return $token instanceof ApiKey && (int) $token->key_type === ApiKey::TYPE_APPLICATION;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The authenticated admin driving the API call. Downstream authorization
     * still verifies root-admin on every destructive step.
     */
    public static function user(): \App\Models\User
    {
        /** @var \App\Models\User $user */
        $user = App::make('request')->user();

        return $user->isRootAdmin() ? $user : throw new ConflictHttpException(
            'Automatic Restic retention on API deletion requires the request to authenticate with a root-admin Application API key.'
        );
    }
}