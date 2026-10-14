<?php

namespace Tests\Feature;

use App\Livewire\Backups;
use App\Support\DatabaseBackups;
use App\Support\RestoreProbe;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ExternalBackupCopiesTest extends TestCase
{
    private function destination(): string
    {
        Storage::fake('external');

        return rtrim(Storage::disk('external')->path(''), '/\\');
    }

    private function saved(): array
    {
        $record = ['id' => (string) Str::uuid(), 'createdAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
            'source' => 'Manual', 'status' => 'Succeeded', 'verification' => 'Not checked', 'bytes' => 6,
            'checksum' => hash('sha256', 'PGDMP!'), 'schema' => 'public', 'tables' => ['sales' => ['rows' => 2, 'checksum' => hash('sha256', 'saved rows')]]];
        $this->saveRecord($record);
        File::put(app(DatabaseBackups::class)->path($record['id'].'.dump'), 'PGDMP!');

        return $record;
    }

    private function saveRecord(array $record): void
    {
        $backups = app(DatabaseBackups::class);
        File::ensureDirectoryExists($backups->path('records'));
        File::put($backups->path('records/'.$record['id'].'.json'), json_encode($record));
    }

    public function test_configure_later_default_and_preferences_preserve_each_other(): void
    {
        $directory = $this->destination();
        $backups = app(DatabaseBackups::class);
        $this->get('/settings')->assertOk()->assertSee('Configure later')->assertSee('Save drive folder');
        $this->assertSame('', $backups->settings()['externalDirectory']);
        Livewire::test(Backups::class)->set('externalDirectory', $directory)->call('saveExternalDirectory')->assertHasNoErrors()->assertSee('Folder configured');
        $backups->saveSettings(false, 6, 30);
        $this->assertSame($directory, $backups->settings()['externalDirectory']);
        Livewire::test(Backups::class)->set('externalDirectory', '')->call('saveExternalDirectory')->assertHasNoErrors()->assertSee('Local backups continue.');
        $this->assertSame(['enabled' => false, 'intervalHours' => 6, 'retentionDays' => 30, 'externalDirectory' => ''], $backups->settings());
        $this->assertSame([], File::files($directory));
    }

    public function test_invalid_or_relative_paths_leave_preferences_unchanged(): void
    {
        $directory = $this->destination();
        $backups = app(DatabaseBackups::class);
        $backups->saveExternalDirectory($directory);
        foreach (['relative/folder', '../backups', 'C:\\\\', $directory.'/../elsewhere', $directory.'/./folder', $directory."\0folder"] as $invalid) {
            Livewire::test(Backups::class)->set('externalDirectory', $invalid)->call('saveExternalDirectory')->assertHasErrors('backup');
            $this->assertSame($directory, $backups->settings()['externalDirectory']);
        }
        Livewire::test(Backups::class)->set('externalDirectory', str_repeat('a', 501))->call('saveExternalDirectory')->assertHasErrors('externalDirectory');
    }

    public function test_copy_matches_bytes_and_metadata_and_retry_preserves_existing_files(): void
    {
        $directory = $this->destination();
        $backups = app(DatabaseBackups::class);
        $backups->saveExternalDirectory($directory);
        $record = $this->saved();
        Livewire::test(Backups::class)->call('copyExternal', $record['id'])->assertHasNoErrors()->assertSee('Archive checksum and recovery metadata match');
        $copy = $backups->history()[0]['externalCopy'];
        $archive = $directory.DIRECTORY_SEPARATOR.$copy['archive'];
        $manifest = $directory.DIRECTORY_SEPARATOR.$copy['manifest'];
        $this->assertSame('PGDMP!', File::get($archive));
        $this->assertSame($record['checksum'], hash_file('sha256', $archive));
        $metadata = json_decode(File::get($manifest), true);
        $this->assertSame($record['id'], $metadata['backupId']);
        $this->assertSame($record['schema'], $metadata['schema']);
        $this->assertSame($record['tables'], $metadata['tables']);
        $before = [File::get($archive), File::get($manifest), filemtime($archive), filemtime($manifest)];
        $backups->copyExternal($record['id']);
        $this->assertSame($before, [File::get($archive), File::get($manifest), filemtime($archive), filemtime($manifest)]);
        $this->assertSame('Succeeded', $backups->history()[0]['status']);
        $this->assertSame('Not checked', $copy['verification']);
        $this->assertSame([], File::glob($directory.'/*.partial-*'));
    }

    public function test_missing_folder_is_not_created_and_retry_works_when_reconnected(): void
    {
        $directory = $this->destination().DIRECTORY_SEPARATOR.'disconnected';
        $backups = app(DatabaseBackups::class);
        $backups->saveExternalDirectory($directory);
        $record = $this->saved();
        Livewire::test(Backups::class)->call('copyExternal', $record['id'])->assertHasErrors('backup')->assertSee('Connect the drive');
        $this->assertDirectoryDoesNotExist($directory);
        $failed = $backups->history()[0];
        $this->assertSame('Succeeded', $failed['status']);
        $this->assertSame('Failed', $failed['externalCopy']['status']);
        $this->assertSame('PGDMP!', File::get($backups->path($record['id'].'.dump')));
        File::ensureDirectoryExists($directory);
        Livewire::test(Backups::class)->call('copyExternal', $record['id'])->assertHasNoErrors();
        $this->assertSame('Succeeded', $backups->history()[0]['externalCopy']['status']);
    }

    public function test_changed_source_or_existing_drive_archive_is_not_overwritten(): void
    {
        $directory = $this->destination();
        $backups = app(DatabaseBackups::class);
        $backups->saveExternalDirectory($directory);
        $record = $this->saved();
        $target = $directory.DIRECTORY_SEPARATOR.'wynn-mart-'.$record['id'].'.dump';
        File::put($backups->path($record['id'].'.dump'), 'changed');
        Livewire::test(Backups::class)->call('copyExternal', $record['id'])->assertHasErrors('backup');
        $this->assertFileDoesNotExist($target);
        File::put($backups->path($record['id'].'.dump'), 'PGDMP!');
        File::put($target, 'do not overwrite');
        Livewire::test(Backups::class)->call('copyExternal', $record['id'])->assertHasErrors('backup');
        $this->assertSame('do not overwrite', File::get($target));
        $this->assertSame('PGDMP!', File::get($backups->path($record['id'].'.dump')));
        $this->assertSame([], File::glob($directory.'/*.partial-*'));
    }

    public function test_existing_wrong_metadata_is_preserved_and_reported_as_failed(): void
    {
        $directory = $this->destination();
        $backups = app(DatabaseBackups::class);
        $backups->saveExternalDirectory($directory);
        $record = $this->saved();
        $manifest = $directory.DIRECTORY_SEPARATOR.'wynn-mart-'.$record['id'].'.json';
        File::put($manifest, '{"version":1,"backupId":"another-backup"}');
        Livewire::test(Backups::class)->call('copyExternal', $record['id'])->assertHasErrors('backup');
        $this->assertSame('{"version":1,"backupId":"another-backup"}', File::get($manifest));
        $this->assertSame('Failed', $backups->history()[0]['externalCopy']['status']);
        $this->assertSame('Succeeded', $backups->history()[0]['status']);
    }

    public function test_recovery_uses_saved_destination_even_after_local_pruning_or_disabling(): void
    {
        $directory = $this->destination();
        $backups = app(DatabaseBackups::class);
        $backups->saveExternalDirectory($directory);
        $record = $backups->copyExternal($this->saved()['id']);
        $record['status'] = 'Pruned';
        $this->saveRecord($record);
        File::delete($backups->path($record['id'].'.dump'));
        $backups->saveExternalDirectory('');
        $this->mock(RestoreProbe::class)->shouldReceive('verify')->once()->with(realpath($directory).DIRECTORY_SEPARATOR.$record['externalCopy']['archive'], 'public', $record['tables'])->andReturn(['tables' => 1, 'rows' => 2]);
        Livewire::test(Backups::class)->call('verifyExternal', $record['id'])->assertHasNoErrors()->assertSee('Drive recovery check passed');
        $checked = $backups->history()[0];
        $this->assertSame('Pruned', $checked['status']);
        $this->assertSame('Passed', $checked['externalCopy']['verification']);
        $this->assertSame(2, $checked['externalCopy']['verifiedRows']);
    }

    public function test_corrupt_drive_archive_or_metadata_fails_before_restore(): void
    {
        $directory = $this->destination();
        $backups = app(DatabaseBackups::class);
        $backups->saveExternalDirectory($directory);
        $record = $backups->copyExternal($this->saved()['id']);
        $this->mock(RestoreProbe::class)->shouldNotReceive('verify');
        $archive = $directory.DIRECTORY_SEPARATOR.$record['externalCopy']['archive'];
        File::put($archive, 'changed');
        Livewire::test(Backups::class)->call('verifyExternal', $record['id'])->assertHasErrors('backup');
        File::put($archive, 'PGDMP!');
        File::put($directory.DIRECTORY_SEPARATOR.$record['externalCopy']['manifest'], '{}');
        Livewire::test(Backups::class)->call('verifyExternal', $record['id'])->assertHasErrors('backup');
        $this->assertSame('Failed', $backups->history()[0]['externalCopy']['verification']);
        $this->assertSame('PGDMP!', File::get($backups->path($record['id'].'.dump')));
    }

    public function test_local_directory_or_ancestor_and_path_traversal_are_rejected(): void
    {
        $backups = app(DatabaseBackups::class);
        $record = $this->saved();
        foreach ([$backups->path(), dirname($backups->path())] as $directory) {
            $backups->saveExternalDirectory($directory);
            Livewire::test(Backups::class)->call('copyExternal', $record['id'])->assertHasErrors('backup')->assertSee('separate from the local backup');
        }
        Livewire::test(Backups::class)->call('copyExternal', '../.env')->assertHasErrors('backup');
        Livewire::test(Backups::class)->call('verifyExternal', '../.env')->assertHasErrors('backup');
        $this->assertCount(1, $backups->history());
    }

    public function test_unconfigured_or_incomplete_backups_do_not_write_drive_files(): void
    {
        $directory = $this->destination();
        $backups = app(DatabaseBackups::class);
        $record = $this->saved();
        Livewire::test(Backups::class)->call('copyExternal', $record['id'])->assertHasErrors('backup')->assertSee('folder in Settings first');
        $backups->saveExternalDirectory($directory);
        $record['status'] = 'Failed';
        $this->saveRecord($record);
        Livewire::test(Backups::class)->call('copyExternal', $record['id'])->assertHasErrors('backup');
        Livewire::test(Backups::class)->call('verifyExternal', $record['id'])->assertHasErrors('backup');
        $this->assertSame([], File::files($directory));
    }

    public function test_interrupted_copy_and_recovery_are_reported_on_next_operation(): void
    {
        $record = $this->saved();
        $record['externalCopy'] = ['status' => 'Running'];
        $this->saveRecord($record);
        $backups = app(DatabaseBackups::class);
        $backups->saveExternalDirectory('');
        $this->assertSame('Failed', $backups->history()[0]['externalCopy']['status']);
        $record['externalCopy'] = ['status' => 'Succeeded', 'verification' => 'Running'];
        $this->saveRecord($record);
        $backups->saveExternalDirectory('');
        $this->assertSame('Failed', $backups->history()[0]['externalCopy']['verification']);
        $this->assertFileExists($backups->path($record['id'].'.dump'));
    }

    public function test_lock_overlap_and_practice_cannot_mutate_main_backups(): void
    {
        $directory = $this->destination();
        $backups = app(DatabaseBackups::class);
        $backups->saveExternalDirectory($directory);
        $record = $this->saved();
        $handle = fopen($backups->path('operation.lock'), 'c');
        flock($handle, LOCK_EX);
        try {
            Livewire::test(Backups::class)->call('copyExternal', $record['id'])->assertHasErrors('backup')->assertSee('already running');
            $this->assertSame([], File::files($directory));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        config(['pos.practice' => true]);
        try {
            $backups->saveExternalDirectory('');
            $this->fail('Practice must not write main settings.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unavailable', $exception->getMessage());
        }
        config(['pos.practice' => false]);
        $this->assertSame($directory, $backups->settings()['externalDirectory']);
    }
}
