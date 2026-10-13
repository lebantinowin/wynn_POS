<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class RestoreProbe
{
    /** @return array{tables: int, rows: int} */
    public function verify(string $archive, string $schema, array $expected): array
    {
        $backups = app(DatabaseBackups::class);
        $initdb = $backups->tool('initdb');
        $control = $backups->tool('pg_ctl');
        $restore = $backups->tool('pg_restore');
        $directory = $backups->path('recovery-checks/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        $passwordFile = $directory.DIRECTORY_SEPARATOR.'password.tmp';
        $data = $directory.DIRECTORY_SEPARATOR.'cluster';
        $password = bin2hex(random_bytes(32));
        try {
            File::put($passwordFile, $password);
            chmod($passwordFile, 0600);
            $port = $this->availablePort();
            if (! Process::env($backups->environment([]))->timeout(60)->run([$initdb, '--pgdata', $data, '--username=wynn_restore',
                '--pwfile', $passwordFile, '--auth=scram-sha-256', '--encoding=UTF8', '--no-locale'])->successful()) {
                throw new RuntimeException('Temporary database initialization failed.');
            }
            File::delete($passwordFile);
            if (! Process::env($backups->environment([]))->timeout(45)->run([$control, '--pgdata', $data, '--log', $directory.DIRECTORY_SEPARATOR.'server.log',
                '--options', '-h 127.0.0.1 -p '.$port, '--wait', '--timeout=30', 'start'])->successful()) {
                throw new RuntimeException('Temporary database startup failed.');
            }
            $connection = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $port, 'database' => 'postgres',
                'username' => 'wynn_restore', 'password' => $password, 'charset' => 'utf8', 'prefix' => '', 'search_path' => $schema, 'sslmode' => 'disable'];
            if (! Process::env($backups->environment($connection))->timeout(180)->run([
                $restore, '--host=127.0.0.1', '--port='.$port, '--username=wynn_restore', '--dbname=postgres',
                '--no-password', '--no-owner', '--no-privileges', '--no-tablespaces', '--clean', '--if-exists', '--exit-on-error', $archive,
            ])->successful()) {
                throw new RuntimeException('Temporary database restoration failed.');
            }
            config(['database.connections.backup_probe' => $connection]);
            DB::purge('backup_probe');
            $actual = $backups->fingerprints(DB::connection('backup_probe'), $schema);
            if ($actual !== $expected) {
                throw new RuntimeException('Restored records differ from the backup snapshot.');
            }

            return ['tables' => count($actual), 'rows' => array_sum(array_column($actual, 'rows'))];
        } finally {
            DB::purge('backup_probe');
            File::delete($passwordFile);
            if (is_file($data.DIRECTORY_SEPARATOR.'postmaster.pid')) {
                if (! Process::env($backups->environment([]))->timeout(45)->run([$control, '--pgdata', $data, '--mode=fast', '--wait', '--timeout=30', 'stop'])->successful()) {
                    throw new RuntimeException('Temporary recovery database could not be stopped. Its files were preserved.');
                }
            }
            $resolved = realpath($directory);
            $root = realpath($backups->path('recovery-checks'));
            if ($resolved && $root && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) && ! is_link($directory)) {
                File::deleteDirectory($resolved);
            }
        }
    }

    private function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (! $socket) {
            throw new RuntimeException('A temporary recovery port is unavailable.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr(strrchr($address, ':'), 1);
    }
}
