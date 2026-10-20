<?php

namespace IByte\Restic\Admin;

use App\Models\BackupHost;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use IByte\Restic\Support\FailureAlerts;
use IByte\Restic\Support\Installation;
use IByte\Restic\Support\NodeDiagnostics;
use IByte\Restic\Support\RuntimeDiagnostics;

final class RepositoryAdministration extends ResticPage
{
    public array $nodeDiagnosticReport = [];

    protected static ?string $navigationLabel = 'Administrative';

    protected function description(): string
    {
        return 'Keys are captured automatically before backups. Recovery history stays available after server or repository deletion. Key disclosure and destructive overrides require your current Panel password.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('operation-alerts')->label('Needs attention')->button()->color('warning')->authorize(fn () => user()?->isRootAdmin())
                ->disabled(fn () => Installation::missing() !== [])
                ->modalHeading('Recent operations needing attention')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalDescription('These are historical failed or unverified operations, not proof of a new failure. Each notification includes its operation ID. Clearing a delivered alert does not resend it. Node diagnostics can read the saved node result without retrying work. Restore protection remains unchanged.')
                ->schema([RepeatableEntry::make('operations')->state(fn () => FailureAlerts::recent())->columns(2)->schema([
                    TextEntry::make('operation'), TextEntry::make('status')->badge(), TextEntry::make('server_uuid')->label('Server UUID'),
                    TextEntry::make('node_id')->label('Node'), TextEntry::make('updated_at')->label('Updated'), TextEntry::make('request_uuid')->label('Operation ID'),
                    TextEntry::make('message')->columnSpanFull(),
                    TextEntry::make('details')->label('Failure details')->columnSpanFull(),
                ])]),
            Action::make('node-diagnostics')->label('Node diagnostics')->button()->authorize(fn () => user()?->isRootAdmin())
                ->modalDescription('Read addon readiness and optionally the saved result of an operation ID from Needs attention. No keys are fetched and no jobs are submitted. Raw node error text is withheld to protect credentials.')
                ->modalSubmitActionLabel('Run read-only checks')->schema([
                    Select::make('host_id')->label('Restic backup host')->required()->live()
                        ->options(fn () => BackupHost::where('schema', 'restic')->pluck('name', 'id')->all())
                        ->afterStateUpdated(fn (Set $set) => $set('node_id', null)),
                    Select::make('node_id')->label('Linked Wings node')->required()
                        ->options(fn (Get $get) => BackupHost::where('schema', 'restic')->find($get('host_id'))?->nodes()->pluck('name', 'nodes.id')->all() ?? []),
                    TextInput::make('operation_id')->label('Operation ID (optional)')->uuid()->maxLength(36),
                    TextEntry::make('node_diagnostic_report')->label('Results')->state(fn ($livewire) => $livewire->nodeDiagnosticReport === [] ? 'Select a host and node, then run the checks.' : json_encode($livewire->nodeDiagnosticReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                ])->action(function (array $data, Action $action, $livewire) {
                    abort_unless(user()?->isRootAdmin(), 403);
                    $livewire->nodeDiagnosticReport = NodeDiagnostics::inspect((int) $data['host_id'], (int) $data['node_id'], $data['operation_id'] ?? null);
                    $action->halt();
                }),
            StaleBackupActions::action(),
            Action::make('panel-diagnostics')->label('Panel diagnostics')->button()->authorize(fn () => user()?->isRootAdmin())
                ->modalDescription(function () {
                    abort_unless(user()?->isRootAdmin(), 403);

                    return json_encode(RuntimeDiagnostics::snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                })->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            Installation::action()->visible(fn () => Installation::missing() !== []),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Host settings and maintenance')
                ->description('Basic storage configuration and linked nodes are managed in Backup Hosts. Keys and storage measurements synchronize automatically. Integrity checks and reclaiming unreferenced data are optional background maintenance.')
                ->schema([Actions::make([HostActions::settings(), HostActions::maintenance()])]),
            Section::make('Recovery and deletion')
                ->description('View keys and history for active or deleted servers. Remove Panel records clears metadata only, even for stuck backups or offline nodes. More contains recovery export and permanent repository deletion. Deletion retains encrypted key history.')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    public function repositoryTable(Table $table): Table
    {
        return $table->columns([
            ...RepositoryColumns::identity(),
            RepositoryColumns::state(),
            ...RepositoryColumns::optional(),
        ])->recordActionsColumnLabel('Recovery controls')->recordActions(CredentialActions::actions(), position: RecordActionsPosition::BeforeColumns);
    }
}
