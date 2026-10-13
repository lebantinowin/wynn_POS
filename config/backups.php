<?php

return [
    'bin' => env('PG_BACKUP_BIN', PHP_OS_FAMILY === 'Windows' ? 'C:/Program Files/PostgreSQL/18/bin' : '/usr/bin'),
    'schema' => env('PG_BACKUP_SCHEMA', 'public'),
];
