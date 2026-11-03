<?php

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

http_response_code(404);

echo json_encode([
    "success" => false,
    "message" => "Endpoint not found"
], JSON_PRETTY_PRINT);