<?php

namespace IByte\Restic\Admin;

use App\Filament\Admin\Resources\BackupHosts\BackupHostResource;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use IByte\Restic\ResticSchema;

final class HostForm
{
    public static function register(): void
    {
        BackupHostResource::modifyForm(function (Schema $schema): Schema {
            foreach ($schema->getComponents() as $component) {
                if (! $component instanceof Select || $component->getName() !== 'schema') {
                    continue;
                }

                // The native resource adds provider fields dynamically without filling
                // their defaults. Alpine entanglement requires these paths to exist
                // in the same response that first renders the Restic controls.
                $component->afterStateUpdated(function (?string $state, Set $set): void {
                    if ($state === 'restic') {
                        $set('configuration', ResticSchema::DEFAULT_CONFIGURATION);
                    }
                });
            }

            return $schema;
        });
    }
}
