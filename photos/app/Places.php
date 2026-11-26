<?php
declare(strict_types=1);

/*
 * Place-name suggestions from GeoNames (https://www.geonames.org), a free
 * gazetteer under CC-BY. The owner's Location boxes ask place.php, which asks
 * GeoNames with the account's username, so the username stays on the server.
 * Answers are kept for a month, so repeat searches cost nothing.
 */

const PLACES_CACHE_FILE = 'places-cache.json';

/** The GeoNames account: config.php's geonames_username, else the one saved on the admin page. */
function geonames_username(): string
{
    $u = trim((string) cfg('geonames_username', ''));
    if ($u === '') {
        $u = trim((string) (read_json(DATA_DIR . '/settings.json', [])['geonames_username'] ?? ''));
    }
    return $u;
}

function save_geonames_username(string $u): void
{
    $u = trim($u);
    if ($u !== '' && !preg_match('/^[A-Za-z0-9_.\-]{2,60}$/', $u)) {
        throw new RuntimeException('That doesn’t look like a GeoNames username.');
    }
    $s = read_json(DATA_DIR . '/settings.json', []);
    $s['geonames_username'] = $u;
    write_json(DATA_DIR . '/settings.json', $s);
}

/** "Name, Province, Country" from a GeoNames entry, skipping parts that repeat. */
function place_label(array $g): string
{
    $parts = [];
    foreach ([$g['name'] ?? '', $g['adminName1'] ?? '', $g['countryName'] ?? ''] as $p) {
        $p = trim((string) $p);
        if ($p !== '' && !in_array($p, $parts, true)) {
            $parts[] = $p;
        }
    }
    return implode(', ', $parts);
}

/**
 * Up to 8 places whose names start with $q: towns, provinces and countries,
 * lakes and rivers, parks and regions, hills and mountains. Canada first.
 * @return array<int, array{id:string,label:string,kind:string}>
 */
function place_suggestions(string $q): array
{
    $q = trim((string) preg_replace('/\s+/u', ' ', $q));
    // Only the part before the first comma is the name; "Regina, Sask" searches "Regina".
    $name = trim(explode(',', $q)[0]);
    if (mb_strlen($name) < 2) {
        return [];
    }
    $user = geonames_username();
    if ($user === '') {
        throw new RuntimeException('Add your GeoNames username on the admin page to get place suggestions.');
    }
    $key = mb_strtolower($name);
    $cacheFile = DATA_DIR . '/' . PLACES_CACHE_FILE;
    $cache = read_json($cacheFile, []);
    if (isset($cache[$key]) && ($cache[$key]['at'] ?? 0) > time() - 30 * 86400) {
        return $cache[$key]['r'];
    }
    $query = http_build_query([
        'name_startsWith' => $name,
        'maxRows'         => 8,
        'countryBias'     => 'CA',
        'orderby'         => 'relevance',
        'style'           => 'MEDIUM',
        'lang'            => 'en',
        'username'        => $user,
    ]);
    foreach (['P', 'A', 'H', 'L', 'T'] as $fc) {
        $query .= '&featureClass=' . $fc;
    }
    [$status, $body] = http_get('https://secure.geonames.org/searchJSON?' . $query);
    $json = json_decode((string) $body, true);
    if ($status !== 200 || !is_array($json)) {
        throw new RuntimeException('GeoNames didn’t answer. Try again in a moment.');
    }
    if (isset($json['status'])) {
        // e.g. the free web service not enabled, or the daily limit reached
        throw new RuntimeException('GeoNames: ' . ($json['status']['message'] ?? 'error'));
    }
    $out = [];
    $seen = [];
    foreach ($json['geonames'] ?? [] as $g) {
        $label = place_label($g);
        if ($label === '' || isset($seen[$label])) {
            continue;
        }
        $seen[$label] = true;
        $out[] = ['id' => (string) $g['geonameId'], 'label' => $label, 'kind' => (string) ($g['fcodeName'] ?? '')];
    }
    if (count($cache) > 2000) {
        uasort($cache, fn($a, $b) => ($b['at'] ?? 0) <=> ($a['at'] ?? 0));
        $cache = array_slice($cache, 0, 1500, true);
    }
    $cache[$key] = ['at' => time(), 'r' => $out];
    write_json($cacheFile, $cache);
    return $out;
}

/**
 * Minimal GET helper built on curl.
 * @return array{0:int,1:string}
 */
function http_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => 'stranack-photo-gallery',
    ]);
    $result = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $result === false ? '' : (string) $result];
}
