<?php

declare(strict_types=1);

namespace App\Validators;

class RouteValidator
{
    public function validate(array $data): array
    {
        $errors = [];

        if (
            !isset($data['start']['lat'], $data['start']['lon'])
            || !is_numeric($data['start']['lat'])
            || !is_numeric($data['start']['lon'])
        ) {
            $errors[] = "Coordonatele de start (lat, lon) sunt obligatorii și trebuie să fie numerice.";
        }

        if (
            !isset($data['end']['lat'], $data['end']['lon'])
            || !is_numeric($data['end']['lat'])
            || !is_numeric($data['end']['lon'])
        ) {
            $errors[] = "Coordonatele de sosire (lat, lon) sunt obligatorii și trebuie să fie numerice.";
        }

        if (isset($data['truck']['weight']) && !is_numeric($data['truck']['weight'])) {
            $errors[] = "Greutatea camionului trebuie să fie un număr (în tone).";
        }

        if (isset($data['truck']['height']) && !is_numeric($data['truck']['height'])) {
            $errors[] = "Înălțimea camionului trebuie să fie un număr (în metri).";
        }

        if (isset($data['truck']['width']) && !is_numeric($data['truck']['width'])) {
            $errors[] = "Lățimea camionului trebuie să fie un număr (în metri).";
        }

        $maxAccess = $data['end']['max_access_m'] ?? ($data['max_access_m'] ?? ($data['truck']['max_access_m'] ?? null));
        if ($maxAccess !== null && !is_numeric($maxAccess)) {
            $errors[] = "Distanța maximă de acces (max_access_m) trebuie să fie un număr (în metri).";
        }

        return $errors;
    }
}
