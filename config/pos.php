<?php

return [
    'practice' => env('POS_PRACTICE', false),
    'storage' => env('POS_STORAGE', 'session'),
    'connection' => env('POS_DB_CONNECTION', 'pgsql'),
];
