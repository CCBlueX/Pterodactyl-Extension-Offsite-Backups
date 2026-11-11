<?php

declare(strict_types=1);

namespace OffsiteBackups\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use OffsiteBackups\Models\Copy;
use OffsiteBackups\Models\Link;
use OffsiteBackups\Settings;
use OffsiteBackups\Storage\Stores;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Single-use links that stream a copy to a browser (download) or to Wings (restore).
 */
final readonly class Links
{
    public const string ROUTE = '/extensions/offsite-backups/stream/';

    public function __construct(private Settings $settings, private Stores $stores) {}

    /** The file name a browser saves the copy as. */
    public static function filename(Copy $copy): string
    {
        $name = $copy->kind === 'database'
            ? Str::slug($copy->server_name).'-'.Str::slug((string) $copy->database_name).'-'.$copy->created_at->format('Ymd-His').'.sql.gz'
            : Str::slug($copy->server_name).'-'.Str::slug($copy->backup_name).'.tar.gz';

        return mb_ltrim($name, '-') ?: "{$copy->uuid}.tar.gz";
    }

    public function downloadUrl(Copy $copy, User $user): string
    {
        return $this->presigned($copy) ?? mb_rtrim((string) config('app.url'), '/').self::ROUTE.$this->create($copy, 'download', $user->id);
    }

    public function restoreUrl(Copy $copy): string
    {
        return $this->presigned($copy) ?? $this->settings->nodePanelUrl().self::ROUTE.$this->create($copy, 'restore', null);
    }

    public function consume(string $secret, ?User $sessionUser): Link
    {
        $link = Link::query()->with('copy.destination')->where('token', hash('sha256', $secret))->first() ?? throw new NotFoundHttpException('This link does not exist.');

        if ($link->used_at !== null || $link->expires_at->isPast()) {
            throw new GoneHttpException('This link was already used or has expired.');
        }

        if ($link->purpose === 'download' && $this->settings->linksRequireUser() && $sessionUser?->id !== $link->user_id) {
            throw new AccessDeniedHttpException('Sign in as the user who asked for this download.');
        }

        if (Link::query()->whereKey($link->id)->whereNull('used_at')->update(['used_at' => CarbonImmutable::now()]) !== 1) {
            throw new GoneHttpException('This link was already used.');
        }

        return $link;
    }

    /** Unencrypted archive copies on S3 are handed out as a presigned URL, so the panel does not proxy them. */
    private function presigned(Copy $copy): ?string
    {
        if ($copy->status !== 'verified' || $copy->path === null) {
            throw new ConflictHttpException('This copy is not finished.');
        }

        $destination = $copy->destination;

        if ($destination->kind !== 's3' || $destination->isDedup() || $destination->encrypted) {
            return null;
        }

        try {
            return $this->stores->for($destination)->presignedGet($copy->path, $this->settings->linkLifetime() * 60, self::filename($copy));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function create(Copy $copy, string $purpose, ?int $userId): string
    {
        $secret = Str::random(64);

        Link::query()->create([
            'token' => hash('sha256', $secret),
            'copy_id' => $copy->id,
            'purpose' => $purpose,
            'user_id' => $userId,
            'expires_at' => CarbonImmutable::now()->addMinutes($this->settings->linkLifetime()),
        ]);

        return $secret;
    }
}
