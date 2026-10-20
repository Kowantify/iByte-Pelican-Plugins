<?php

namespace IByte\Restic\Admin;

use App\Enums\HeaderActionPosition;
use App\Filament\Admin\Resources\BackupHosts\Pages\EditBackupHost;
use App\Filament\Admin\Resources\BackupHosts\Pages\ViewBackupHost;
use App\Models\BackupHost;
use App\Models\User;
use Filament\Actions\Action;
use IByte\Restic\Backups\Operations;
use IByte\Restic\Support\Installation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class HealthActions
{
    public static function register(): void
    {
        foreach ([EditBackupHost::class, ViewBackupHost::class] as $page) {
            $page::registerCustomHeaderActions(HeaderActionPosition::After,
                Action::make('ibyte-restic-overview')->label('Restic Backups')->button()
                    ->url(fn () => RecoveryResource::getUrl(panel: 'admin'))
                    ->visible(fn ($livewire) => $livewire->getRecord()->schema === 'restic' && user()?->isRootAdmin())
            );
        }
    }

    public static function queue(User $user, BackupHost $host, string $operation): int
    {
        abort_unless($user->isRootAdmin() && $user->can('update', $host) && in_array($operation, ['check', 'stats', 'prune'], true), 403);
        Installation::assertReady();
        if ($host->schema !== 'restic') {
            throw new RuntimeException('Restic host required.');
        }
        if (DB::table('ibyte_restic_operations')->where('host_id', $host->id)->whereIn('operation', ['check', 'stats', 'prune'])->where('created_at', '>', now()->subMinutes(5))->exists()) {
            throw new RuntimeException('Repository health is rate limited to one batch every five minutes.');
        }
        $count = 0;
        foreach ($host->nodes as $node) {
            // Each repository belongs to a server. Check all successful server repositories on linked nodes.
            $backups = $host->backups()->where('is_successful', true)->whereHas('server', fn ($q) => $q->where('node_id', $node->id))->latest()->get()->unique('server_id');
            foreach ($backups as $backup) {
                app(Operations::class)->queue($backup, $operation, (string) Str::uuid());
                $count++;
            }
        }

        return $count;
    }

    public static function summary(BackupHost $host): string
    {
        if (Installation::missing() !== []) {
            return 'Restic installation is incomplete. Use Finish installation / Repair tables.';
        }
        $rows = DB::table('ibyte_restic_operations')->where('host_id', $host->id)->latest('updated_at')->limit(12)->get();
        if ($rows->isEmpty()) {
            return 'Unknown: no repository operations have been recorded. Checks are never run during page rendering.';
        }

        return $rows->map(fn ($r) => "Node {$r->node_id}, server {$r->server_uuid}: {$r->operation} {$r->status} at {$r->updated_at}; bytes {$r->bytes}. ".($r->error ?? ''))->implode("\n");
    }
}
