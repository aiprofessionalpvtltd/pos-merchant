<?php

namespace App\Console\Commands;

use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneOrphanFiles extends Command
{
    protected $signature = 'files:prune';

    protected $description = 'Deletes uploaded files that were never attached to a product, order or merchant';

    public function handle(): int
    {
        $orphans = File::whereNull('attached_at')
            ->where('created_at', '<', now()->subHours(config('exelo.files.orphan_ttl_hours')))
            ->get();

        foreach ($orphans as $file) {
            Storage::disk($file->disk)->delete(array_filter([$file->path, $file->thumb_path]));
            $file->delete();
        }

        $this->info("Pruned {$orphans->count()} orphan file(s).");

        return self::SUCCESS;
    }
}
