<?php
declare(strict_types=1);

function normaliseUkPostcode(string $postcode): string
{
    $compact = strtoupper((string)preg_replace('/\s+/', '', trim($postcode)));
    if ($compact === '') {
        return '';
    }
    return strlen($compact) > 3
        ? substr($compact, 0, -3) . ' ' . substr($compact, -3)
        : $compact;
}

function validUkPostcodeFormat(string $postcode): bool
{
    $compact = str_replace(' ', '', normaliseUkPostcode($postcode));
    return (bool)preg_match(
        '/^(GIR0AA|[A-Z]{1,2}[0-9][0-9A-Z]?[0-9][A-Z]{2})$/D',
        $compact
    );
}

function lookupPostcodeCoordinates(array $postcodes): array
{
    $unique = [];
    foreach ($postcodes as $postcode) {
        $value = normaliseUkPostcode((string)$postcode);
        if (validUkPostcodeFormat($value)) {
            $unique[$value] = $value;
        }
    }

    $coordinates = [];
    $url = 'https://api.postcodes.io/postcodes?filter=postcode,longitude,latitude';

    foreach (array_chunk(array_values($unique), 100) as $batch) {
        $body = json_encode(['postcodes' => $batch]);
        if ($body === false) {
            continue;
        }

        $response = false;
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle !== false) {
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);
                $response = curl_exec($handle);
                if (curl_getinfo($handle, CURLINFO_HTTP_CODE) !== 200) {
                    $response = false;
                }
                curl_close($handle);
            }
        } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $body,
                    'timeout' => 5
                ]
            ]);
            $response = @file_get_contents($url, false, $context);
        }

        if (!is_string($response)) {
            continue;
        }

        $payload = json_decode($response, true);
        if (!is_array($payload) || ($payload['status'] ?? null) !== 200
            || !is_array($payload['result'] ?? null)) {
            continue;
        }

        foreach ($payload['result'] as $item) {
            if (!is_array($item) || !is_array($item['result'] ?? null)) {
                continue;
            }
            $postcode = normaliseUkPostcode((string)($item['query'] ?? ''));
            $result = $item['result'];
            $latitude = $result['latitude'] ?? null;
            $longitude = $result['longitude'] ?? null;

            if (!is_numeric($latitude) || !is_numeric($longitude)) {
                continue;
            }
            $latitude = (float)$latitude;
            $longitude = (float)$longitude;
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                continue;
            }
            $coordinates[$postcode] = [$latitude, $longitude];
        }
    }

    return $coordinates;
}

function straightLineMiles(array $from, array $to): float
{
    $fromLat = deg2rad((float)$from[0]);
    $toLat = deg2rad((float)$to[0]);
    $deltaLat = $toLat - $fromLat;
    $deltaLon = deg2rad((float)$to[1] - (float)$from[1]);
    $a = sin($deltaLat / 2) ** 2
        + cos($fromLat) * cos($toLat) * sin($deltaLon / 2) ** 2;
    return 3958.7613 * 2 * asin(min(1.0, sqrt(max(0.0, $a))));
}
