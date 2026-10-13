<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

#[Signature('pos:archive-sessions')]
#[Description('Preserve each existing browser POS snapshot separately before database migration')]
class ArchivePosSessions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;
        foreach (File::files(config('session.files')) as $file) {
            $session = @unserialize($file->getContents(), ['allowed_classes' => false]);
            if (! is_array($session) || ! isset($session['demo']) || ! is_array($session['demo'])) {
                continue;
            }
            $path = 'pos-imports/'.hash('sha256', $file->getFilename()).'.json';
            if (! Storage::disk('local')->exists($path)) {
                $saved = Storage::disk('local')->put($path, json_encode(['version' => 1, 'time' => now()->toIso8601String(), 'data' => $session['demo']], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                if (! $saved) {
                    $this->error('An archive could not be saved. No database records were changed.');

                    return self::FAILURE;
                }
                $count++;
            }
        }
        $this->info("Archived $count browser POS snapshots separately. No browser records were deleted or merged.");

        return self::SUCCESS;
    }
}
