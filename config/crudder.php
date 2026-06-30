<?php

declare(strict_types=1);

return [
    'path' => env('CRUDDER_PATH', 'crudder'),
    'generated_file' => env('CRUDDER_GENERATED_FILE', storage_path('app/crudder.php')),
    'middleware' => ['web'],
];
