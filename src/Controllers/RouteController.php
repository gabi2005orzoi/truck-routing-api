<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RoutingService;
use App\Validators\RouteValidator;
use Throwable;

class RouteController
{
    public function handle(): void
    {
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Corpul cererii trebuie să fie un JSON valid',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return;
        }

        // Validare date de intrare
        $validator = new RouteValidator();
        $errors = $validator->validate($data);

        if (!empty($errors)) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'errors' => $errors
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return;
        }

        // Încărcare configurare și apelare RoutingService
        $config = require __DIR__ . '/../../config/app.php';

        try {
            $routingService = new RoutingService(
                $config['valhalla_url'],
                $config['restricted_roads_path']
            );

            // Permitem max_access_m să fie transmis fie în $data['end'], fie la rădăcină, fie în truck
            $end = $data['end'];
            if (isset($data['max_access_m']) && !isset($end['max_access_m'])) {
                $end['max_access_m'] = (float)$data['max_access_m'];
            } elseif (isset($data['truck']['max_access_m']) && !isset($end['max_access_m'])) {
                $end['max_access_m'] = (float)$data['truck']['max_access_m'];
            }

            $avoidZones = $data['avoid_zones'] ?? ($data['dynamic_avoid_zones'] ?? []);

            $result = $routingService->getTruckRoute(
                $data['start'],
                $end,
                $data['truck'] ?? [],
                $avoidZones
            );

            if (empty($result['routes'])) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Nu a fost găsită nicio rută validă care să respecte restricțiile pentru camioane (străzi înguste/rezidențiale sau zone restricționate).',
                    'data' => [
                        'routes' => []
                    ]
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => $result
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(502);
            echo json_encode([
                'success' => false,
                'message' => 'Eroare la calcularea rutei (Valhalla): ' . $e->getMessage()
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }
}
