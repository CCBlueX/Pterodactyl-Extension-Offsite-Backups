<?php

declare(strict_types=1);

namespace OffsiteBackups;

use Illuminate\Console\Scheduling\Schedule;
use OffsiteBackups\Console\ExportCommand;
use OffsiteBackups\Console\MaintainCommand;
use OffsiteBackups\Console\TickCommand;
use OffsiteBackups\Console\WorkCommand;
use OffsiteBackups\Listeners\BackupListener;
use OffsiteBackups\Panel\RestoreListener;
use OffsiteBackups\Panel\Storage\StoringCompleter;
use OffsiteBackups\Panel\Storage\StoringDeleter;
use OffsiteBackups\Panel\Storage\StoringDownloadLinks;
use OffsiteBackups\Panel\Storage\StoringInitiator;
use OffsiteBackups\Panel\Storage\StoringPresigner;
use Pterodactyl\Contracts\Backups\CompletesBackups;
use Pterodactyl\Contracts\Backups\DeletesBackups;
use Pterodactyl\Contracts\Backups\GeneratesBackupDownloadLinks;
use Pterodactyl\Contracts\Backups\InitiatesBackups;
use Pterodactyl\Contracts\Backups\PresignsBackupUploads;
use Pterodactyl\Contracts\Backups\RestoresBackupStatuses;
use Pterodactyl\Events\Server\OperationCompleted;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class OffsiteBackupsProvider extends ExtensionProvider
{
    public function boot(): void
    {
        $this->registerApiRoutes();
        $this->registerWebRoutes(__DIR__.'/../routes/web.php');
        $this->loadExtensionMigrations();

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), Settings::definitions()));

        $this->wrapAction(InitiatesBackups::class, static fn (InitiatesBackups $inner): InitiatesBackups => new StoringInitiator($inner));
        $this->wrapAction(CompletesBackups::class, static fn (CompletesBackups $inner): CompletesBackups => new StoringCompleter($inner));
        $this->wrapAction(PresignsBackupUploads::class, static fn (PresignsBackupUploads $inner): PresignsBackupUploads => new StoringPresigner($inner));
        $this->wrapAction(DeletesBackups::class, static fn (DeletesBackups $inner): DeletesBackups => new StoringDeleter($inner));
        $this->wrapAction(GeneratesBackupDownloadLinks::class, static fn (GeneratesBackupDownloadLinks $inner): GeneratesBackupDownloadLinks => new StoringDownloadLinks($inner));
        $this->wrapAction(RestoresBackupStatuses::class, static fn (RestoresBackupStatuses $inner): RestoresBackupStatuses => new RestoreListener($inner));

        $this->listenToServerOperations(fn (OperationCompleted $event) => $this->app->make(BackupListener::class)->handle($event));

        $this->registerCommands([WorkCommand::class, TickCommand::class, MaintainCommand::class, ExportCommand::class]);
        $this->registerSchedule(function (Schedule $schedule): void {
            $schedule->command('offsite-backups:tick')->everyMinute()->withoutOverlapping(5)->runInBackground();
            $schedule->command('offsite-backups:maintain')->dailyAt('04:20')->withoutOverlapping(180)->runInBackground();

            if (! $this->app->make(Settings::class)->dedicatedWorker()) {
                $schedule->command('offsite-backups:work --max-time=3300')->everyMinute()->runInBackground();
            }
        });
    }
}
