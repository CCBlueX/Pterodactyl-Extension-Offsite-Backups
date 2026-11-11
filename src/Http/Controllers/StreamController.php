<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Controllers;

use Illuminate\Http\Request;
use OffsiteBackups\Services\Links;
use OffsiteBackups\Services\Streamer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a copy through a single-use link: to a browser for downloads, to Wings for restores.
 */
final readonly class StreamController
{
    public function __invoke(Request $request, string $secret, Links $links, Streamer $streamer): StreamedResponse
    {
        set_time_limit(0);
        ini_set('memory_limit', '256M');

        $link = $links->consume($secret, $request->user());
        $copy = $link->copy;
        $chunks = $streamer->stream($copy);

        // A browser gets a clean error page if the copy cannot be read; Wings needs its headers within 30 seconds.
        if ($link->purpose === 'download') {
            $chunks->current();
        }

        $headers = [
            'Content-Type' => 'application/x-gzip',
            'Content-Disposition' => 'attachment; filename="'.Links::filename($copy).'"',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-store',
        ];

        if (! $copy->destination->isDedup() && ($copy->source_bytes ?? 0) > 0) {
            $headers['Content-Length'] = (string) $copy->source_bytes;
        }

        return response()->stream(static function () use ($chunks): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            foreach ($chunks as $chunk) {
                echo $chunk;
                flush();

                if (connection_aborted() === 1) {
                    return;
                }
            }
        }, 200, $headers);
    }
}
