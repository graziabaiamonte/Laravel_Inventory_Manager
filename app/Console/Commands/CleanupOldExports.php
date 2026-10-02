<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupOldExports extends Command
{
    protected $signature = 'records-export:cleanup {--days=1 : Number of days to keep record export files}';

    protected $description = 'Clean up old record export files from storage';

    public function handle()
    {
        $days = (int) $this->option('days');
        $cutoffDate = Carbon::now()->subDays($days);

        $this->info("Cleaning up export files older than {$days} days (before {$cutoffDate->format('Y-m-d H:i:s')})...");

        $disk = Storage::disk('local');
        $exportPath = 'exports';

        if (! $disk->exists($exportPath)) {
            $this->info('No exports directory found.');

            return;
        }

        $directories = $disk->directories($exportPath);
        $deletedCount = 0;
        $totalSize = 0;

        foreach ($directories as $directory) {
            // Get directory modification time
            $lastModified = Carbon::createFromTimestamp($disk->lastModified($directory));

            if ($lastModified->lt($cutoffDate)) {
                // Calculate directory size before deletion
                $size = $this->getDirectorySize($disk, $directory);
                $totalSize += $size;

                // Delete the directory
                $disk->deleteDirectory($directory);
                $deletedCount++;

                $this->line("Deleted: {$directory} ({$this->formatBytes($size)}) - Last modified: {$lastModified->format('Y-m-d H:i:s')}");
            }
        }

        if ($deletedCount > 0) {
            $this->info("Cleanup completed! Deleted {$deletedCount} export directories, freed {$this->formatBytes($totalSize)} of disk space.");
        } else {
            $this->info('No old export files found to clean up.');
        }
    }

    private function getDirectorySize($disk, $directory): int
    {
        $size = 0;
        $files = $disk->allFiles($directory);

        foreach ($files as $file) {
            $size += $disk->size($file);
        }

        return $size;
    }

    private function formatBytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2).' '.$units[$unitIndex];
    }
}
