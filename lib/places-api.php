<?php
if (!defined('AUDIT_ENTRY')) { http_response_code(404); exit; }

// Wrapper around Google Places API (New). Two endpoints used:
//   - searchText:  POST  https://places.googleapis.com/v1/places:searchText
//   - place details: GET  https://places.googleapis.com/v1/places/{place_id}
// Auth: X-Goog-Api-Key header. Field mask required on every call.

define('PLACES_FIELD_MASK', 'id,displayName,formattedAddress,location,rating,userRatingCount,nationalPhoneNumber,internationalPhoneNumber,websiteUri,regularOpeningHours,photos,types,primaryType,businessStatus,reviews,googleMapsUri');

const PLACES_SEARCH_FIELD_MASK = 'places.id,places.displayName,places.location,places.formattedAddress,places.rating,places.userRatingCount';

class PlacesApiException extends RuntimeException {}

function placesTextSearch(string $query, ?array $locationBias = null, int $limit = 5): array {
    $apiKey = config('GOOGLE_API_KEY');
    if (!$apiKey) throw new PlacesApiException('GOOGLE_API_KEY not configured');

    $body = ['textQuery' => $query, 'pageSize' => $limit];
    if ($locationBias) {
        $body['locationBias'] = [
            'circle' => [
                'center' => ['latitude' => $locationBias['lat'], 'longitude' => $locationBias['lng']],
                'radius' => $locationBias['radius_m'] ?? 500.0,
            ],
        ];
    }

    $resp = httpJson(
        'POST',
        'https://places.googleapis.com/v1/places:searchText',
        $body,
        [
            'X-Goog-Api-Key: ' . $apiKey,
            'X-Goog-FieldMask: ' . PLACES_SEARCH_FIELD_MASK,
        ]
    );
    return $resp['places'] ?? [];
}

function placesDetails(string $placeId): array {
    $apiKey = config('GOOGLE_API_KEY');
    if (!$apiKey) throw new PlacesApiException('GOOGLE_API_KEY not configured');

    $url = 'https://places.googleapis.com/v1/places/' . rawurlencode($placeId);
    return httpJson(
        'GET',
        $url,
        null,
        [
            'X-Goog-Api-Key: ' . $apiKey,
            'X-Goog-FieldMask: ' . PLACES_FIELD_MASK,
        ]
    );
}

function httpJson(string $method, string $url, $body, array $headers): array {
    $ch = curl_init($url);
    $h = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    if ($body !== null && $method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new PlacesApiException("curl error: $err");
    }
    $decoded = json_decode($resp, true);
    if ($code >= 400) {
        $detail = $decoded['error']['message'] ?? substr($resp, 0, 200);
        throw new PlacesApiException("Places API HTTP $code: $detail");
    }
    if (!is_array($decoded)) {
        throw new PlacesApiException("Places API returned non-JSON");
    }
    return $decoded;
}
