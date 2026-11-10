<?php

declare(strict_types=1);

use App\Controllers\RouteController;
use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

if(file_exists(__DIR__ . '/../.env')){
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

header("Content-Type: application/json");

$method = $_SERVER["REQUEST_METHOD"];
$uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

if($method === "GET" && $uri === "/api/health"){
    http_response_code(200);

    echo json_encode([
        "success" => true,
        "message" => "API is working!",
        "version" => "1.0.0"
    ], JSON_PRETTY_PRINT);

    exit;
}

if($method === "POST" && $uri === "/api/routes"){
    $controller = new RouteController();
    $controller->handle();

    exit;
}

http_response_code(404);

echo json_encode([
    "success" => false,
    "message" => "Endpoint not found"
], JSON_PRETTY_PRINT);