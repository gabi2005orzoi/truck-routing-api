<?php

declare(strict_types=1);

return [
    'env' => $_ENV['APP_ENV'] ?? 'production',
    'valhalla_url' => $_ENV['VALHALLA_URL'] ?? getenv('VALHALLA_URL') ?: 'http://valhalla:8002',
    'restricted_roads_path' => __DIR__ . '/../' .
($_ENV['RESTRICTED_ROADS_PATH'] ?? 'config/restricted_roads.json')
];