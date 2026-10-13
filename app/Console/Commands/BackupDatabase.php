<?php

namespace App\Console\Commands;

use App\Support\DatabaseBackups;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('pos:backup {--scheduled : Back up only when enabled and due}')]
#[Description('Save a consistent private PostgreSQL backup')]
class BackupDatabase extends Command
{
    public function handle(): int
    {
        try {
            $record = app(DatabaseBackups::class)->create((bool) $this->option('scheduled'));
            $this->info($record ? 'Backup saved: '.$record['id'] : 'No backup is due.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
