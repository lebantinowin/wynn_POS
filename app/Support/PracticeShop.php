<?php

namespace App\Support;

use RuntimeException;

final class PracticeShop
{
    public const SOURCE = 'owner-practice-v1';

    private static function normalized(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    public static function databasePath(bool $requireFile = true): string
    {
        if (! config('pos.practice') || ! app()->environment('local', 'testing')
            || config('pos.storage') !== 'database' || config('session.cookie') !== 'wynn_practice_session') {
            throw new RuntimeException('Practice requires local database storage and its own session cookie. Use run-practice.ps1.');
        }
        $root = storage_path('app/practice');
        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false || self::normalized($canonicalRoot) !== self::normalized($root)) {
            throw new RuntimeException('Practice storage must be a regular directory inside this project.');
        }
        $paths = [];
        foreach ([config('database.default'), config('pos.connection')] as $connection) {
            $settings = config('database.connections.'.$connection);
            if (! is_array($settings) || ($settings['driver'] ?? null) !== 'sqlite' || ! empty($settings['url'])) {
                throw new RuntimeException('Practice refuses PostgreSQL and database URL overrides.');
            }
            $path = $settings['database'] ?? '';
            $parent = realpath(dirname($path));
            $resolved = realpath($path);
            if ($parent === false || self::normalized($parent) !== self::normalized($canonicalRoot)
                || pathinfo($path, PATHINFO_EXTENSION) !== 'sqlite'
                || ($resolved !== false && self::normalized(dirname($resolved)) !== self::normalized($canonicalRoot))
                || ($requireFile && ($resolved === false || ! is_file($resolved)))) {
                throw new RuntimeException('Practice requires a SQLite file directly inside storage/app/practice.');
            }
            $paths[] = self::normalized($resolved ?: $canonicalRoot.DIRECTORY_SEPARATOR.basename($path));
        }
        if ($paths[0] !== $paths[1]) {
            throw new RuntimeException('Practice login and shop records must use the same isolated database.');
        }

        return $paths[0];
    }

    public static function assertPrepared(): void
    {
        self::databasePath();
        if (! PosStore::connection()->getSchemaBuilder()->hasTable('pos_workspace') || ! PosStore::ready()
            || PosStore::connection()->table('pos_workspace')->where('id', 1)->value('import_source') !== hash('sha256', self::SOURCE)) {
            throw new RuntimeException('This database is not the prepared practice shop. Use run-practice.ps1.');
        }
    }
}
