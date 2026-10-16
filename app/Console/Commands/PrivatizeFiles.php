<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\File\FileService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Move every file referenced by a private `*_file_id` column
 * ({@see FileService::PRIVATE_FILE_COLUMNS}) off the public disk onto the
 * private one and flag it. Run once after deploying the private-files change;
 * safe to re-run (already-private files are skipped).
 */
class PrivatizeFiles extends Command
{
    protected $signature = 'files:privatize {--dry-run : List the files that would move without touching them}';

    protected $description = 'Move KYC scans, contracts, invoices and acts to the private disk';

    public function handle(FileService $files): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $privateDisk = $files->diskFor(true);

        $ids = collect();

        foreach (FileService::PRIVATE_FILE_COLUMNS as $class => $columns) {
            /** @var class-string<Model> $class */
            foreach ($columns as $column) {
                $ids = $ids->merge(
                    $class::query()->whereNotNull($column)->pluck($column),
                );
            }
        }

        $ids = $ids->map(fn (mixed $id): int => (int) $id)->unique()->values();

        $pending = File::query()
            ->whereIn('id', $ids)
            ->where(function ($query) use ($privateDisk): void {
                $query->where('is_private', false)->orWhere('disk', '!=', $privateDisk);
            })
            ->orderBy('id')
            ->get();

        $this->line(sprintf(
            '%d referenced file(s), %d still public%s.',
            $ids->count(),
            $pending->count(),
            $dryRun ? ' (dry run — nothing changes)' : '',
        ));

        $moved = 0;
        $missing = 0;

        foreach ($pending as $file) {
            $exists = $file->existsOnDisk();

            $this->line(sprintf(
                '#%d  %s:%s  %s%s',
                $file->id,
                $file->disk,
                $file->path,
                $file->original_name,
                $exists ? '' : '  [bytes missing on disk]',
            ));

            if (! $exists) {
                $missing++;
            }

            if (! $dryRun) {
                $files->makePrivate($file);
                $moved++;
            }
        }

        $this->info($dryRun
            ? "{$pending->count()} file(s) would be moved to the '{$privateDisk}' disk."
            : "{$moved} file(s) moved to the '{$privateDisk}' disk.");

        if ($missing > 0) {
            $this->warn("{$missing} file(s) had no bytes on their disk — flagged private anyway, see the log.");
        }

        return self::SUCCESS;
    }
}
