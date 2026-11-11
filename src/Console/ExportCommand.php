<?php

declare(strict_types=1);

namespace OffsiteBackups\Console;

use Illuminate\Console\Command;
use OffsiteBackups\Models\Copy;
use OffsiteBackups\Services\Streamer;
use RuntimeException;
use Throwable;

final class ExportCommand extends Command
{
    protected $signature = 'offsite-backups:export {copy : Copy id or uuid} {file : Where to write the tar.gz or sql.gz}';

    protected $description = 'Write the original archive of an off-site copy to a local file, without Wings.';

    public function handle(Streamer $streamer): int
    {
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        $key = (string) $this->argument('copy');
        $copy = Copy::query()->with('destination')->where(ctype_digit($key) ? 'id' : 'uuid', $key)->first();

        if ($copy === null) {
            $this->error("No copy {$key}.");

            return self::FAILURE;
        }

        $file = (string) $this->argument('file');

        if (file_exists($file)) {
            $this->error("{$file} already exists.");

            return self::FAILURE;
        }

        $handle = fopen($file, 'xb');

        if ($handle === false) {
            $this->error("Cannot write {$file}.");

            return self::FAILURE;
        }

        $bytes = 0;

        try {
            foreach ($streamer->stream($copy) as $chunk) {
                if (fwrite($handle, $chunk) === false) {
                    throw new RuntimeException("Writing {$file} failed.");
                }

                $bytes += mb_strlen($chunk, '8bit');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($file);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        fclose($handle);
        $this->info(sprintf('Wrote %s (%d bytes) from %s.', $file, $bytes, $copy->destination->name));

        return self::SUCCESS;
    }
}
