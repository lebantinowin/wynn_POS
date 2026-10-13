<?php

namespace App\Console\Commands;

use App\Support\DatabaseBackups;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('pos:verify-backup {id : Saved backup ID}')]
#[Description('Verify recovery in an isolated temporary PostgreSQL instance')]
class VerifyBackup extends Command
{
    public function handle(): int
    {
        try {
            $record = app(DatabaseBackups::class)->verify((string) $this->argument('id'));
            $this->info('Recovery verified: '.$record['verifiedTables'].' tables, '.$record['verifiedRows'].' records.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
