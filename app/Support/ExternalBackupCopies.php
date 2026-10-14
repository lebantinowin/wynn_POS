<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class ExternalBackupCopies
{
    public function validateDirectory(string $directory): string
    {
        $directory = trim($directory);
        if ($directory === '') {
            return '';
        }
        $absolute = PHP_OS_FAMILY === 'Windows' ? preg_match('/^[A-Za-z]:[\\\\\/]/', $directory) : str_starts_with($directory, '/');
        $parts = preg_split('/[\\\\\/]/', $directory);
        if (! $absolute || strlen(rtrim($directory, '/\\')) <= 3 || preg_match('/[\x00-\x1F]/', $directory)
            || in_array('..', $parts, true) || in_array('.', $parts, true)
            || (PHP_OS_FAMILY === 'Windows' && preg_match('/[:*?"<>|]/', substr($directory, 3)))) {
            throw new RuntimeException('Choose an absolute folder path on your USB or external drive, such as E:\\WynnsMartBackups.');
        }

        return rtrim($directory, '/\\');
    }

    private function directory(string $directory): string
    {
        $directory = $this->validateDirectory($directory);
        $resolved = $directory === '' ? false : realpath($directory);
        if (! $resolved || ! is_dir($resolved)) {
            throw new RuntimeException('The backup folder is unavailable. Connect the drive to the shop computer and create the selected folder, then retry.');
        }
        $local = realpath(app(DatabaseBackups::class)->path());
        $targetKey = $this->pathKey($resolved);
        $localKey = $local ? $this->pathKey($local) : '';
        if ($localKey !== '' && ($targetKey === $localKey || str_starts_with($targetKey, $localKey.'/') || str_starts_with($localKey, $targetKey.'/'))) {
            throw new RuntimeException('Choose a dedicated folder separate from the local backup folder.');
        }

        return $resolved;
    }

    private function pathKey(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private function filenames(array $record): array
    {
        if (! isset($record['id'], $record['checksum'], $record['bytes'], $record['schema'], $record['tables'])
            || ! Str::isUuid($record['id']) || ! preg_match('/^[a-f0-9]{64}$/', $record['checksum'])) {
            throw new RuntimeException('The saved backup metadata is incomplete.');
        }

        return ['archive' => 'wynn-mart-'.$record['id'].'.dump', 'manifest' => 'wynn-mart-'.$record['id'].'.json'];
    }

    private function assertArchive(string $file, array $record): void
    {
        clearstatcache(true, $file);
        if (! is_file($file) || is_link($file) || filesize($file) !== $record['bytes']
            || ! hash_equals($record['checksum'], hash_file('sha256', $file))) {
            throw new RuntimeException('The archive is missing or its checksum does not match. The local backup was not removed.');
        }
    }

    public function copy(array $record, string $directory): array
    {
        if (($record['status'] ?? '') !== 'Succeeded') {
            throw new RuntimeException('Only a complete local backup can be copied.');
        }
        $names = $this->filenames($record);
        $directory = $this->directory($directory);
        $source = app(DatabaseBackups::class)->path($record['id'].'.dump');
        $this->assertArchive($source, $record);
        $target = $directory.DIRECTORY_SEPARATOR.$names['archive'];
        if (file_exists($target) || is_link($target)) {
            $this->assertArchive($target, $record);
        } else {
            $partial = $target.'.partial-'.Str::uuid();
            $input = null;
            $output = null;
            try {
                $input = fopen($source, 'rb');
                $output = fopen($partial, 'xb');
                if (! $input || ! $output || stream_copy_to_stream($input, $output) !== $record['bytes'] || ! fflush($output)) {
                    throw new RuntimeException('The drive copy could not be completed. Check drive space and write access, then retry.');
                }
                fclose($output);
                $output = null;
                $this->assertArchive($partial, $record);
                if (file_exists($target) || is_link($target) || ! rename($partial, $target)) {
                    throw new RuntimeException('The drive copy could not be published. The local backup is safe.');
                }
            } finally {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                if (is_file($partial)) {
                    File::delete($partial);
                }
            }
        }
        $this->assertArchive($target, $record);
        $manifestFile = $directory.DIRECTORY_SEPARATOR.$names['manifest'];
        if (file_exists($manifestFile) || is_link($manifestFile)) {
            $this->readManifest($manifestFile, $record, $names);
        } else {
            $manifest = ['version' => 1, 'backupId' => $record['id'], 'archive' => $names['archive'],
                'createdAt' => $record['createdAt'], 'schema' => $record['schema'], 'bytes' => $record['bytes'],
                'sha256' => $record['checksum'], 'tables' => $record['tables']];
            $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            $partialManifest = $manifestFile.'.partial-'.Str::uuid();
            $handle = null;
            try {
                $handle = fopen($partialManifest, 'x');
                if (! $handle) {
                    throw new RuntimeException('The archive was copied, but its recovery metadata could not be saved. Retry the copy.');
                }
                if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                    throw new RuntimeException('The recovery metadata could not be saved completely.');
                }
                fclose($handle);
                $handle = null;
                $this->readManifest($partialManifest, $record, $names);
                if (file_exists($manifestFile) || is_link($manifestFile) || ! rename($partialManifest, $manifestFile)) {
                    throw new RuntimeException('Recovery metadata could not be published. Existing drive files were preserved.');
                }
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                if (is_file($partialManifest)) {
                    File::delete($partialManifest);
                }
            }
        }
        $this->readManifest($manifestFile, $record, $names);

        return ['status' => 'Succeeded', 'directory' => $directory, 'archive' => $names['archive'], 'manifest' => $names['manifest'],
            'copiedAt' => now()->toIso8601String(), 'bytes' => $record['bytes'], 'checksum' => $record['checksum'], 'verification' => 'Not checked'];
    }

    private function readManifest(string $file, array $record, array $names): array
    {
        if (! is_file($file) || is_link($file)) {
            throw new RuntimeException('Recovery metadata is missing or is not a regular file.');
        }
        $manifest = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['version'] ?? null) !== 1 || ($manifest['backupId'] ?? null) !== $record['id']
            || ($manifest['archive'] ?? null) !== $names['archive'] || ($manifest['sha256'] ?? null) !== $record['checksum']
            || ($manifest['bytes'] ?? null) !== $record['bytes'] || ($manifest['schema'] ?? null) !== $record['schema']
            || ($manifest['tables'] ?? null) !== $record['tables']) {
            throw new RuntimeException('Recovery metadata does not match this backup. Existing drive files were preserved.');
        }

        return $manifest;
    }

    public function verify(array $record): array
    {
        $copy = $record['externalCopy'] ?? [];
        if (($copy['status'] ?? '') !== 'Succeeded') {
            throw new RuntimeException('Copy this backup to the drive before checking its recovery.');
        }
        $directory = $this->directory($copy['directory']);
        $names = $this->filenames($record);
        $file = $directory.DIRECTORY_SEPARATOR.$names['archive'];
        $this->assertArchive($file, $record);
        $this->readManifest($directory.DIRECTORY_SEPARATOR.$names['manifest'], $record, $names);
        $result = app(RestoreProbe::class)->verify($file, $record['schema'], $record['tables']);

        return array_replace($copy, ['verification' => 'Passed', 'verifiedAt' => now()->toIso8601String(),
            'verifiedTables' => $result['tables'], 'verifiedRows' => $result['rows']]);
    }
}
