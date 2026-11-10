<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\BadResponseException;


class RoutingService{
    private Client $httpClient;
    private string $valhallaUrl;
    private string $restrictedRoadsPath;

    private const MAX_END_RUN_M = 1500;
    private const MID_TOLERANCE_M = 250;
    private const MIN_EXCLUDE_EDGE_M = 30;
    private const MAX_EXCLUSIONS = 40;
    private const MID_RUN_MAX_M = 250;
    private const MID_TOTAL_MAX_M = 600;
    private const FORBIDDEN_CLASSES = ['tertiary', 'unclassified', 'residential', 'service_other'];
    private const MAX_RETRIES = 4;

    public function __construct(string $valhallaUrl, string $restrictedRoadsPath){
        $this->valhallaUrl = rtrim($valhallaUrl, '/');
        $this->restrictedRoadsPath = $restrictedRoadsPath;
        $this->httpClient = new Client([
            'base_uri' => $this->valhallaUrl,
            'timeout' => 15.0,
        ]);
    }

    public function getTruckRoute(array $start, array $end, array $truckParams = [], array $dynamicAvoidZones = []): array
    {
        $polygons = array_merge($this->loadRestrictedZones(), $this->formatDynamicZones($dynamicAvoidZones));
        $maxEndRunM = isset($end['max_access_m']) ? (float)$end['max_access_m'] : self::MAX_END_RUN_M;

        $excludeLocations = [];
        $candidates  = [];   // rute neconforme din ORICE încercare
        $validRoutes = [];
        $warnings    = [];
        $lastError   = null;

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            $payload = [
                'locations' => [
                    ['lat' => (float)$start['lat'], 'lon' => (float)$start['lon'], 'type' => 'break'],
                    ['lat' => (float)$end['lat'],   'lon' => (float)$end['lon'],   'type' => 'break'],
                ],
                'costing' => 'truck',
                'costing_options' => ['truck' => [
                    'weight' => (float)($truckParams['weight'] ?? 12.0),
                    'height' => (float)($truckParams['height'] ?? 4.0),
                    'width'  => (float)($truckParams['width']  ?? 2.5),
                    'use_highways'       => 1.0,
                    'use_truck_route'    => 1.0,
                    'use_living_streets' => 0.0,
                    'service_penalty'    => 600,
                ]],
                'alternates' => 2,
            ];
            if ($polygons)          $payload['exclude_polygons']  = $polygons;
            if ($excludeLocations)  $payload['exclude_locations'] = array_values($excludeLocations);

            try {
                $response = json_decode(
                    $this->httpClient->post('/route', ['json' => $payload])->getBody()->getContents(),
                    true
                );
            } catch (BadResponseException $e) {
                $lastError = (string)$e->getResponse()->getBody();
                error_log(sprintf('[routing] attempt=%d status=%d body=%s',
                    $attempt, $e->getResponse()->getStatusCode(), $lastError));
                break;
            }

            $trips = [$response['trip']];
            foreach ($response['alternates'] ?? [] as $alt) {
                $trips[] = $alt['trip'];
            }

            $validRoutes   = [];
            $newExclusions = [];

            foreach ($trips as $trip) {
                $a = $this->analyzeRoute($trip['legs'][0]['shape'], $maxEndRunM);
                error_log(sprintf('[routing] attempt=%d valid=%d score=%.0fm',
                    $attempt, (int)$a['valid'], $a['score']));

                if (!$a['verified']) {
                    $warnings[] = 'Clasa drumurilor nu a putut fi verificată pentru unele rute.';
                }
                if ($a['valid']) {
                    $validRoutes[] = $trip;
                    continue;
                }

                $key = round($trip['summary']['length'], 3) . '|' . $trip['summary']['time'];
                $candidates[$key] = ['trip' => $trip, 'score' => $a['score']];

                foreach ($a['points'] as $pt) {
                    $newExclusions[round($pt['lat'], 5) . ',' . round($pt['lon'], 5)] = $pt;
                }
            }

            if ($validRoutes || !$newExclusions) {
                break;
            }

            $excludeLocations = array_slice($excludeLocations + $newExclusions, 0, self::MAX_EXCLUSIONS, true);
        }

        if ($validRoutes) {
            return ['routes' => $validRoutes, 'warnings' => array_values(array_unique($warnings))];
        }

        if ($candidates) {
            uasort($candidates, fn($a, $b) => $a['score'] <=> $b['score']);
            $warnings[] = 'Nicio rută fără drumuri de clasă joasă; sunt afișate rutele cu cele mai puține încălcări.';
            return [
                'routes'   => array_slice(array_column(array_values($candidates), 'trip'), 0, 3),
                'warnings' => array_values(array_unique($warnings)),
            ];
        }

        return ['routes' => [], 'warnings' => $warnings, 'error' => $lastError];
    }
    
    public function loadRestrictedZones(): array{
        if(!file_exists($this->restrictedRoadsPath))
            return[];

        $content = json_decode(file_get_contents($this->restrictedRoadsPath), true);

        if(!$content)
            return[];

        $polygons = [];

        foreach($content as $item){
            if(($item['type'] ?? '') === 'polygon' && !empty($item['polygon'])){
                $ring = [];
                foreach($item['polygon'] as $pt){
                    $ring[] = [$pt['lon'], $pt['lat']];
                }

                if(count($ring) >=3){
                    if($ring[0] !== $ring[count($ring) - 1])
                        $ring[] = $ring[0];
                }

                $polygons[] = $ring;
            }
        }

        return $polygons;
    }

    private function formatDynamicZones(array $zones): array{
        $polygons = [];
        foreach($zones as $zone){
            $ring = [];
            foreach($zone as $pt){
                if(isset($pt['lat'], $pt['lon'])){
                    $ring[] = [(float)$pt['lon'], (float)$pt['lat']];
                }
            }

            if(count($ring) >= 3){
                if($ring[0] !== $ring[count($ring) - 1])
                    $ring[] = $ring[0];
                $polygons[] = $ring;
            }
        }

        return $polygons;
    }

    private function decodePolyline6(string $str): array
    {
        $index = 0; $lat = 0; $lon = 0; $coords = [];
        $len = strlen($str);
        while ($index < $len) {
            foreach (['lat', 'lon'] as $k) {
                $shift = 0; $result = 0;
                do {
                    $b = ord($str[$index++]) - 63;
                    $result |= ($b & 0x1f) << $shift;
                    $shift += 5;
                } while ($b >= 0x20);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                $$k += $delta;
            }
            $coords[] = [$lat / 1e6, $lon / 1e6];
        }
        return $coords;
    }

    private function analyzeRoute(string $encodedShape, float $maxEndRunM): array
    {
        $resp = $this->traceAttributes($encodedShape, 'edge_walk')
            ?? $this->traceAttributes($encodedShape, 'map_snap');

        // Dacă nu putem verifica, nu pretindem că ruta e greșită, dar avertizăm
        if ($resp === null || empty($resp['edges'])) {
            return ['valid' => true, 'verified' => false, 'score' => 0.0, 'points' => []];
        }

        $shape = $this->decodePolyline6($resp['shape'] ?? $encodedShape);
        $edges = $resp['edges'];
        foreach ($edges as $i => $e) {
            error_log(sprintf('[routing] edge=%d class=%s len=%.0fm names=%s',
                $i, $e['road_class'] ?? '?', ($e['length'] ?? 0) * 1000,
                implode('/', $e['names'] ?? [])));
        }
        $n = count($edges);
        $isBad = fn(array $e) => in_array($e['road_class'] ?? '', self::FORBIDDEN_CLASSES, true);

        $headEnd = -1; $headLen = 0.0;
        for ($i = 0; $i < $n && $isBad($edges[$i]); $i++) {
            $headLen += $edges[$i]['length'] * 1000;
            $headEnd = $i;
        }

        $tailStart = $n; $tailLen = 0.0;
        for ($i = $n - 1; $i > $headEnd && $isBad($edges[$i]); $i--) {
            $tailLen += $edges[$i]['length'] * 1000;
            $tailStart = $i;
        }

        $headOk = $headLen <= $maxEndRunM;
        $tailOk = $tailLen <= $maxEndRunM;

        // Grupăm edge-urile rele din MIJLOC în tronsoane consecutive
        $runs = []; $cur = null;
        foreach ($edges as $i => $e) {
            $bad = $i > $headEnd && $i < $tailStart && $isBad($e);
            if ($bad) {
                $cur ??= ['len' => 0.0, 'edges' => [], 'names' => []];
                $cur['len']     += $e['length'] * 1000;
                $cur['edges'][]  = $i;
                foreach ($e['names'] ?? [] as $nm) $cur['names'][$nm] = true;
            } elseif ($cur) {
                $runs[] = $cur; $cur = null;
            }
        }
        if ($cur) $runs[] = $cur;

        $middleLen = array_sum(array_column($runs, 'len'));
        $badRuns   = array_filter($runs, fn($r) => $r['len'] > self::MID_RUN_MAX_M);

        // Dacă doar totalul e depășit, excludem cel mai lung tronson
        if (!$badRuns && $middleLen > self::MID_TOTAL_MAX_M && $runs) {
            usort($runs, fn($a, $b) => $b['len'] <=> $a['len']);
            $badRuns = [$runs[0]];
        }

        $points = [];
        foreach ($badRuns as $r) {
            error_log(sprintf('[routing] tronson rău %.0fm: %s',
                $r['len'], implode(', ', array_keys($r['names'])) ?: '(fără nume)'));
            $added = 0;
            foreach ($r['edges'] as $i) {
                $e = $edges[$i];
                if ($e['length'] * 1000 < self::MIN_EXCLUDE_EDGE_M) continue;
                $mid = (int)(($e['begin_shape_index'] + $e['end_shape_index']) / 2);
                $points[] = ['lat' => $shape[$mid][0], 'lon' => $shape[$mid][1]];
                $added++;
            }
            // Tronson format doar din edge-uri scurte: excludem cel mai lung edge
            if (!$added) {
                $longest = $r['edges'][0];
                foreach ($r['edges'] as $i) {
                    if ($edges[$i]['length'] > $edges[$longest]['length']) $longest = $i;
                }
                $e = $edges[$longest];
                $mid = (int)(($e['begin_shape_index'] + $e['end_shape_index']) / 2);
                $points[] = ['lat' => $shape[$mid][0], 'lon' => $shape[$mid][1]];
            }
        }

        error_log(sprintf('[routing] head=%.0fm tail=%.0fm middle=%.0fm tronsoane=%d',
            $headLen, $tailLen, $middleLen, count($runs)));

        return [
            'valid'    => !$badRuns && $middleLen <= self::MID_TOTAL_MAX_M && $headOk && $tailOk,
            'verified' => true,
            'score'    => $middleLen + ($headOk ? 0 : $headLen) + ($tailOk ? 0 : $tailLen),
            'points'   => $points,
        ];
    }

    private function traceAttributes(string $shape, string $mode): ?array{
        try {
            return json_decode($this->httpClient->post('/trace_attributes', ['json' => [
                'encoded_polyline' => $shape,
                'costing'     => 'truck',
                'shape_match' => $mode,
                'filters' => [
                    'action'     => 'include',
                    'attributes' => ['edge.road_class', 'edge.length', 'edge.names',
                                    'edge.begin_shape_index', 'edge.end_shape_index', 'shape'],
                ],
            ]])->getBody()->getContents(), true);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            error_log("[routing] trace_attributes $mode failed: " . $e->getMessage());
            return null;
        }
    }
}