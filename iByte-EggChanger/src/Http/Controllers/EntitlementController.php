<?php

namespace IByte\EggChanger\Http\Controllers;

use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use IByte\EggChanger\Models\Entitlement;
use IByte\EggChanger\Http\Requests\EntitlementUpdateRequest;

/** Authenticated Application API. Never reads permissions from customer-controlled fields. */
final class EntitlementController
{
    public function update(EntitlementUpdateRequest $request, int $serverId): JsonResponse
    {
        if (!Schema::hasTable('ibyte_egg_changer_entitlements')) {
            return response()->json(['message' => 'Entitlement migration required.'], 503);
        }
        $values = $request->validated();
        $server = Server::query()->findOrFail($serverId);
        // Server ID and service ID must match the Paymenter-owned external ID.
        // Do not allow API callers to assign an unrelated subscription to a server.
        if ((string) $server->external_id !== (string) $values['service_id']) {
            return response()->json(['message' => 'Service ownership mismatch.'], 409);
        }
        DB::transaction(function () use ($server, $values): void {
            Entitlement::query()->updateOrCreate(
                ['server_id' => $server->id],
                ['server_uuid' => (string) $server->uuid,
                 'enabled' => (bool) $values['enabled'], 'source' => 'paymenter',
                 'external_service_id' => (string) $values['service_id'], 'updated_by' => null]
            );
        });
        return response()->json(['server_id' => $server->id, 'enabled' => Entitlement::allowed($server->id)]);
    }
}
