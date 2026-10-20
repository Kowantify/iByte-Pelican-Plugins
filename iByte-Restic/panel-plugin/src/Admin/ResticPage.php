<?php

namespace IByte\Restic\Admin;

use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use IByte\Restic\Support\Installation;

abstract class ResticPage extends ListRecords
{
    protected static string $resource = RecoveryResource::class;

    public function getTitle(): string
    {
        return 'Restic Backups';
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }

    public function getSubNavigation(): array
    {
        return $this->generateNavigationItems([
            ListRecoveryRepositories::class,
            RepositoryStatistics::class,
            RepositoryAdministration::class,
        ]);
    }

    public function getBreadcrumb(): string
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return Installation::missing() !== []
            ? 'Restic installation is incomplete. Open Administrative and use Finish installation / Repair tables. Existing backups have not been removed.'
            : $this->description();
    }

    abstract protected function description(): string;

    abstract public function repositoryTable(Table $table): Table;
}
