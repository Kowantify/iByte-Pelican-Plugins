<?php

namespace IByte\PrometheusExporter\Admin;

use App\Enums\TablerIcon;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use IByte\PrometheusExporter\Support\Settings;
use IByte\PrometheusExporter\Support\Events;
use Illuminate\Support\Str;

final class ExporterPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::ChartBar;
    protected static ?string $slug = 'prometheus-exporter';
    protected string $view = 'ibyte-prometheus-exporter::admin';

    public ?string $newKey = null;

    public static function getNavigationLabel(): string { return 'Prometheus Exporter'; }
    public function getTitle(): string { return 'Prometheus Exporter'; }
    public static function canAccess(): bool { return user()?->isRootAdmin() ?? false; }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    /** Creating (or rotating) a key is what enables collection. */
    public function createKey(): void
    {
        abort_unless(self::canAccess(), 403);
        $key = Str::random(64);
        $settings = Settings::read();
        $settings['token_hash'] = hash('sha256', $key);
        Settings::write($settings);
        $this->newKey = $key;
        Events::write('ibyte_prometheus_scrape_key_rotated', ['admin_id' => user()?->id]);
        Notification::make()->title('New scrape key shown once below')->success()->send();
    }

    public function revokeKey(): void
    {
        abort_unless(self::canAccess(), 403);
        $settings = Settings::read();
        $settings['token_hash'] = '';
        Settings::write($settings);
        $this->newKey = null;
        Events::write('ibyte_prometheus_scrape_key_revoked', ['admin_id' => user()?->id]);
        Notification::make()->title('Scrape access revoked')->success()->send();
    }

    protected function getViewData(): array
    {
        abort_unless(self::canAccess(), 403);
        $settings = Settings::read();
        return ['active' => $settings['token_hash'] !== '',
            'endpoint' => url('/ibyte-prometheus-exporter/metrics')];
    }
}
