<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_offsite_backups_destinations', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 64);
            $table->string('kind', 8);
            $table->string('format', 8);
            $table->text('config');
            $table->boolean('encrypted')->default(false);
            $table->text('passphrase')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 8)->default('ok');
            $table->text('status_message')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ext_offsite_backups_rules', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 64);
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('target_type', 8)->default('all');
            $table->json('target_ids');
            $table->json('excluded_server_ids');
            $table->string('trigger', 8);
            $table->string('cron', 64)->nullable();
            $table->boolean('skip_idle')->default(false);
            $table->string('when_full', 8)->default('skip');
            $table->json('destination_ids');
            $table->string('retention', 8)->default('newest');
            $table->unsignedSmallInteger('keep_newest')->nullable();
            $table->unsignedSmallInteger('keep_daily')->nullable();
            $table->unsignedSmallInteger('keep_weekly')->nullable();
            $table->unsignedSmallInteger('keep_monthly')->nullable();
            $table->unsignedSmallInteger('max_age_days')->nullable();
            $table->unsignedSmallInteger('keep_local')->nullable();
            $table->boolean('include_databases')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ext_offsite_backups_copies', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('server_id')->nullable();
            $table->char('server_uuid', 36);
            $table->string('server_name', 191);
            $table->unsignedInteger('node_id')->nullable();
            $table->char('backup_uuid', 36)->nullable();
            $table->string('backup_name', 191);
            $table->string('kind', 8)->default('backup');
            $table->string('database_name', 191)->nullable();
            $table->unsignedInteger('destination_id');
            $table->unsignedInteger('rule_id')->nullable();
            $table->string('status', 8)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->unsignedBigInteger('source_bytes')->nullable();
            $table->unsignedBigInteger('done_bytes')->default(0);
            $table->unsignedBigInteger('stored_bytes')->default(0);
            $table->string('checksum', 191)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('path', 512)->nullable();
            $table->json('details')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['server_uuid', 'destination_id', 'status'], 'ext_offsite_backups_copies_server_index');
            $table->index('backup_uuid');
            $table->index(['status', 'next_attempt_at']);
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
            $table->foreign('destination_id')->references('id')->on('ext_offsite_backups_destinations');
            $table->foreign('rule_id')->references('id')->on('ext_offsite_backups_rules')->nullOnDelete();
        });

        Schema::create('ext_offsite_backups_servers', function (Blueprint $table): void {
            $table->unsignedInteger('server_id')->primary();
            $table->boolean('enabled')->nullable();
            $table->unsignedSmallInteger('max_copies')->nullable();
            $table->unsignedInteger('max_storage_gb')->nullable();
            $table->string('storage', 8)->default('default');
            $table->unsignedInteger('storage_destination_id')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('storage_destination_id')->references('id')->on('ext_offsite_backups_destinations')->nullOnDelete();
        });

        // Backups Wings wrote straight to an S3 destination, so later requests use the same bucket.
        Schema::create('ext_offsite_backups_stored', function (Blueprint $table): void {
            $table->char('backup_uuid', 36)->primary();
            $table->unsignedInteger('destination_id');
            $table->timestamp('created_at')->nullable();

            $table->foreign('destination_id')->references('id')->on('ext_offsite_backups_destinations');
        });

        Schema::create('ext_offsite_backups_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('rule_id');
            $table->unsignedInteger('server_id');
            $table->char('backup_uuid', 36)->nullable()->unique();
            $table->string('status', 8)->default('planned');
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->foreign('rule_id')->references('id')->on('ext_offsite_backups_rules')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });

        Schema::create('ext_offsite_backups_repositories', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('destination_id');
            $table->char('server_uuid', 36);
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('dirty')->default(false);
            $table->timestamp('reading_until')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->timestamps();

            $table->unique(['destination_id', 'server_uuid'], 'ext_offsite_backups_repositories_unique');
            $table->foreign('destination_id')->references('id')->on('ext_offsite_backups_destinations')->cascadeOnDelete();
        });

        Schema::create('ext_offsite_backups_packs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('repository_id');
            $table->char('name', 64);
            $table->unsignedBigInteger('size');
            $table->timestamp('created_at')->nullable();

            $table->unique(['repository_id', 'name']);
            $table->foreign('repository_id')->references('id')->on('ext_offsite_backups_repositories')->cascadeOnDelete();
        });

        Schema::create('ext_offsite_backups_blobs', function (Blueprint $table): void {
            $table->unsignedInteger('repository_id');
            $table->binary('hash', 32, true);
            $table->unsignedBigInteger('pack_id');
            $table->unsignedBigInteger('offset');
            $table->unsignedInteger('length');

            $table->primary(['repository_id', 'hash']);
            $table->index('pack_id');
            $table->foreign('pack_id')->references('id')->on('ext_offsite_backups_packs')->cascadeOnDelete();
        });

        Schema::create('ext_offsite_backups_links', function (Blueprint $table): void {
            $table->id();
            $table->char('token', 64)->unique();
            $table->unsignedBigInteger('copy_id');
            $table->string('purpose', 8);
            $table->unsignedInteger('user_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('copy_id')->references('id')->on('ext_offsite_backups_copies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('ext_offsite_backups_restores', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('copy_id')->nullable();
            $table->unsignedInteger('server_id');
            $table->char('backup_uuid', 36);
            $table->string('revived', 8)->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->boolean('truncate')->default(false);
            $table->string('status', 8)->default('running');
            $table->timestamps();

            $table->index(['backup_uuid', 'status']);
            $table->foreign('copy_id')->references('id')->on('ext_offsite_backups_copies')->nullOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['restores', 'links', 'blobs', 'packs', 'repositories', 'runs', 'stored', 'servers', 'copies', 'rules', 'destinations'] as $table) {
            Schema::dropIfExists("ext_offsite_backups_{$table}");
        }
    }
};
