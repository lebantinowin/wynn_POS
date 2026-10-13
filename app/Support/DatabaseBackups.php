<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DatabaseBackups
{
    public function settings(): array
    {
        $defaults = ['enabled' => true, 'intervalHours' => 24, 'retentionDays' => 14];
        $path = $this->path('settings.json');

        return is_file($path) ? array_replace($defaults, $this->read($path)) : $defaults;
    }

    public function saveSettings(bool $enabled, int $intervalHours, int $retentionDays): void
    {
        if (! in_array($intervalHours, [1, 6, 24], true) || ! in_array($retentionDays, [7, 14, 30, 90], true)) {
            throw new RuntimeException('Choose a supported backup frequency and retention period.');
        }
        $this->locked(fn () => $this->write($this->path('settings.json'), ['enabled' => $enabled, 'intervalHours' => $intervalHours, 'retentionDays' => $retentionDays]));
    }

    public function history(): array
    {
        $records = [];
        foreach (File::glob($this->path('records/*.json')) as $file) {
            $record = $this->read($file);
            if (! isset($record['id']) || ! Str::isUuid($record['id']) || basename($file) !== $record['id'].'.json') {
                throw new RuntimeException('Backup history could not be read. Check the private backup folder.');
            }
            $records[] = $record;
        }
        usort($records, fn (array $left, array $right): int => strcmp($right['createdAt'], $left['createdAt']));

        return $records;
    }

    public function scheduler(): array
    {
        $path = $this->path('scheduler.json');

        return is_file($path) ? $this->read($path) : ['installed' => false];
    }

    public function available(): bool
    {
        return PosStore::database() && config('database.connections.'.config('pos.connection').'.driver') === 'pgsql';
    }

    public function tool(string $name): string
    {
        if (! in_array($name, ['pg_dump', 'pg_restore', 'initdb', 'pg_ctl'], true)) {
            throw new RuntimeException('Unsupported PostgreSQL tool.');
        }
        $path = rtrim(config('backups.bin'), '/\\').DIRECTORY_SEPARATOR.$name.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        if (! is_file($path)) {
            throw new RuntimeException('PostgreSQL backup tools are unavailable. Check PG_BACKUP_BIN.');
        }

        return $path;
    }

    public function create(bool $scheduled = false): ?array
    {
        return $this->locked(function () use ($scheduled): ?array {
            $settings = $this->settings();
            $history = $this->history();
            if ($scheduled) {
                $this->write($this->path('scheduler.json'), array_replace($this->scheduler(), ['lastCheckedAt' => now()->toIso8601String()]));
            }
            $latest = collect($history)->firstWhere('status', 'Succeeded');
            if ($scheduled && (! $settings['enabled'] || ($latest && Carbon::parse($latest['completedAt'])->addHours($settings['intervalHours'])->isFuture()))) {
                return null;
            }
            if (! $this->available()) {
                throw new RuntimeException('Backups require the shared PostgreSQL workspace.');
            }
            $id = (string) Str::uuid();
            $record = ['id' => $id, 'createdAt' => now()->toIso8601String(), 'source' => $scheduled ? 'Automatic' : 'Manual',
                'status' => 'Running', 'verification' => 'Not checked', 'bytes' => 0];
            $file = $this->path($id.'.dump');
            $partial = $file.'.partial';
            $this->save($record);
            $stage = 'opening a consistent database snapshot';
            try {
                $dump = $this->tool('pg_dump');
                $restore = $this->tool('pg_restore');
                $source = PosStore::connection()->getConfig();
                config(['database.connections.backup_snapshot' => $source]);
                DB::purge('backup_snapshot');
                $connection = DB::connection('backup_snapshot');
                $connection->beginTransaction();
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                $snapshot = $connection->selectOne('SELECT pg_export_snapshot() AS snapshot')->snapshot;
                $schema = config('backups.schema');
                $record['schema'] = $schema;
                $record['tables'] = $this->fingerprints($connection, $schema);
                $stage = 'exporting the database';
                $result = Process::path(base_path())->env($this->environment($source))->timeout(180)->run([
                    $dump, '--host', (string) $source['host'], '--port', (string) ($source['port'] ?? 5432),
                    '--username', $source['username'], '--dbname', $source['database'], '--no-password',
                    '--format=custom', '--encoding=UTF8', '--schema='.$schema, '--strict-names',
                    '--no-owner', '--no-privileges', '--no-tablespaces', '--lock-wait-timeout=10000',
                    '--snapshot='.$snapshot, '--file', $partial,
                ]);
                if (! $result->successful() || ! is_file($partial) || filesize($partial) < 5) {
                    throw new RuntimeException('Database export failed.');
                }
                $connection->commit();
                $stage = 'checking the archive';
                if (! Process::env($this->environment([]))->timeout(60)->run([$restore, '--list', $partial])->successful()) {
                    throw new RuntimeException('The archive is unreadable.');
                }
                if (! rename($partial, $file)) {
                    throw new RuntimeException('The archive could not be saved.');
                }
                $record['checksum'] = hash_file('sha256', $file);
                $record['bytes'] = filesize($file);
                $record['completedAt'] = now()->toIso8601String();
                $record['status'] = 'Succeeded';
                $this->save($record);
            } catch (Throwable $exception) {
                if (isset($connection) && $connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
                File::delete([$partial, $file]);
                $record['status'] = 'Failed';
                $record['completedAt'] = now()->toIso8601String();
                $record['error'] = 'Backup failed while '.$stage.'. Check PostgreSQL tools, the connection and disk space.';
                $record['failureType'] = class_basename($exception);
                if (isset($result)) {
                    $record['processExitCode'] = $result->exitCode();
                    $errorOutput = strtolower($result->errorOutput());
                    $record['failureReason'] = match (true) {
                        str_contains($errorOutput, 'snapshot') => 'Snapshot unavailable',
                        str_contains($errorOutput, 'password authentication') => 'Database authentication failed',
                        str_contains($errorOutput, 'permission denied') => 'Permission denied',
                        str_contains($errorOutput, 'lock timeout') => 'Table lock timed out',
                        str_contains($errorOutput, 'connection') => 'Database connection failed',
                        default => 'Export process failed',
                    };
                }
                $this->save($record);

                throw new RuntimeException($record['error']);
            } finally {
                DB::purge('backup_snapshot');
            }

            try {
                $this->prune($id, $settings['retentionDays']);
            } catch (Throwable) {
                $record['retentionWarning'] = 'The new backup is safe, but older archives could not be cleaned up.';
                $this->save($record);
            }

            return $record;
        });
    }

    public function verify(string $id): array
    {
        return $this->locked(function () use ($id): array {
            if (! Str::isUuid($id)) {
                throw new RuntimeException('Choose a saved backup.');
            }
            $record = $this->read($this->path('records/'.$id.'.json'));
            if (($record['status'] ?? null) !== 'Succeeded') {
                throw new RuntimeException('Only a complete saved backup can be checked.');
            }
            $record['verification'] = 'Running';
            $this->save($record);
            try {
                $file = $this->path($id.'.dump');
                if (! is_file($file) || ! hash_equals($record['checksum'], hash_file('sha256', $file))) {
                    throw new RuntimeException('The archive is missing or its checksum does not match.');
                }
                $result = app(RestoreProbe::class)->verify($file, $record['schema'], $record['tables']);
                $record['verification'] = 'Passed';
                $record['verifiedAt'] = now()->toIso8601String();
                $record['verifiedTables'] = $result['tables'];
                $record['verifiedRows'] = $result['rows'];
                unset($record['verificationError']);
                $this->save($record);

                return $record;
            } catch (Throwable) {
                $record['verification'] = 'Failed';
                $record['verificationError'] = 'Recovery check failed. Check the archive integrity, PostgreSQL tools and disk space.';
                $this->save($record);

                throw new RuntimeException($record['verificationError']);
            }
        });
    }

    /** @return array<string, array{rows: int, checksum: string}> */
    public function fingerprints(Connection $connection, string $schema): array
    {
        $this->identifier($schema);
        $tables = $connection->select("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name", [$schema]);
        if ($tables === []) {
            throw new RuntimeException('The backup schema has no tables.');
        }
        $result = [];
        foreach ($tables as $table) {
            $name = $table->table_name;
            $this->identifier($name);
            $hash = hash_init('sha256');
            $rows = 0;
            foreach ($connection->cursor('SELECT to_jsonb(backup_row)::text AS data FROM "'.$schema.'"."'.$name.'" AS backup_row ORDER BY to_jsonb(backup_row)::text COLLATE "C"') as $row) {
                hash_update($hash, strlen($row->data).':'.$row->data);
                $rows++;
            }
            $result[$name] = ['rows' => $rows, 'checksum' => hash_final($hash)];
        }

        return $result;
    }

    /** @return array<string, string|false> */
    public function environment(array $connection): array
    {
        $environment = ['PGPASSWORD' => (string) ($connection['password'] ?? ''), 'PGCONNECT_TIMEOUT' => '10',
            'PGSERVICE' => false, 'PGSERVICEFILE' => false, 'PGOPTIONS' => false,
            'PGHOST' => false, 'PGPORT' => false, 'PGUSER' => false, 'PGDATABASE' => false,
            'PGSSLMODE' => $connection['sslmode'] ?? 'prefer'];
        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['SystemRoot', 'WINDIR', 'PATH', 'TEMP', 'TMP', 'COMSPEC', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA'] as $name) {
                $value = getenv($name, true);
                if ($value !== false) {
                    $environment[$name] = $value;
                }
            }
        }

        return $environment;
    }

    public function path(string $relative = ''): string
    {
        return Storage::disk('backups')->path($relative);
    }

    private function prune(string $keepId, int $days): void
    {
        foreach ($this->history() as $record) {
            if ($record['id'] === $keepId || $record['status'] !== 'Succeeded' || Carbon::parse($record['completedAt'])->greaterThanOrEqualTo(now()->subDays($days))) {
                continue;
            }
            $file = $this->path($record['id'].'.dump');
            if (is_file($file) && ! File::delete($file)) {
                throw new RuntimeException('Archive cleanup failed.');
            }
            $record['status'] = 'Pruned';
            $record['prunedAt'] = now()->toIso8601String();
            $this->save($record);
        }
    }

    private function locked(callable $operation): mixed
    {
        File::ensureDirectoryExists($this->path('records'), 0700);
        $handle = fopen($this->path('operation.lock'), 'c');
        if (! $handle) {
            throw new RuntimeException('The private backup folder is not writable.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Another backup or recovery check is already running.');
        }
        try {
            $this->recoverInterrupted();

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function read(string $path): array
    {
        try {
            $value = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($value)) {
                throw new RuntimeException;
            }

            return $value;
        } catch (Throwable) {
            throw new RuntimeException('Backup metadata could not be read. Check the private backup folder.');
        }
    }

    private function recoverInterrupted(): void
    {
        foreach ($this->history() as $record) {
            if ($record['status'] === 'Running') {
                File::delete([$this->path($record['id'].'.dump.partial'), $this->path($record['id'].'.dump')]);
                $record['status'] = 'Failed';
                $record['completedAt'] = now()->toIso8601String();
                $record['error'] = 'The previous backup was interrupted before completion.';
                $this->save($record);
            } elseif (($record['verification'] ?? '') === 'Running') {
                $record['verification'] = 'Failed';
                $record['verificationError'] = 'The previous recovery check was interrupted.';
                $this->save($record);
            }
        }
    }

    private function write(string $path, array $data): void
    {
        File::replace($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 0600);
    }

    private function save(array $record): void
    {
        $this->write($this->path('records/'.$record['id'].'.json'), $record);
    }

    private function identifier(string $identifier): void
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier) || str_starts_with($identifier, 'pg_') || $identifier === 'information_schema') {
            throw new RuntimeException('Unsupported backup schema or table name.');
        }
    }
}
