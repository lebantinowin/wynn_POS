<?php

return [
    'storage' => env('POS_STORAGE', 'session'),
    'connection' => env('POS_DB_CONNECTION', 'pgsql'),
];
