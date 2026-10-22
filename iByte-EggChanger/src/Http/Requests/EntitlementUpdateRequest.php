<?php

namespace IByte\EggChanger\Http\Requests;

use App\Http\Requests\Api\Application\ApplicationApiRequest;
use App\Models\Server;
use App\Services\Acl\Api\AdminAcl;

final class EntitlementUpdateRequest extends ApplicationApiRequest
{
    protected ?string $resource = Server::RESOURCE_NAME;
    protected int $permission = AdminAcl::WRITE;

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'service_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }
}
