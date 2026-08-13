<?php
// api/location-search.php
// Hybrid Intelligent Location Search — one endpoint, ranked results from
// every source in priority order:
//   1. MohToh's own curated locations   (locations table, created by admin)
//   2. Approved driver-submitted spots  (locations table, created by a driver)
//   3. Worldwide external geocoding     (Nominatim/OpenStreetMap — the free,
//      no-billing-risk substitute used for this text-search endpoint. The
//      Google Maps key (integrations table, google_maps configuration JSON)
//      powers the browser autocomplete and server geocoding elsewhere; this
//      endpoint deliberately stays keyless so search never risks Google
//      billing and still works if the key is absent.)
// Nominatim responses are cached (location_search_cache) so repeated/popular
// queries ("Lagos", "Airport") don't re-hit the external API every time.
//
// Every result is normalized to the same shape before ranking, so the
// client (assets/js/helper.js) never needs to know which source a result
// came from to render or select it:
//   { name, category, category_icon, address, latitude, longitude,
//     distance_km, place_id, type, source }
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$rawQuery = trim($_GET['q'] ?? '');
if (strlen($rawQuery) < 2) {
    json_response('success', 'Query too short', ['results' => [], 'query' => $rawQuery]);
}

$lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
$lng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;
$limit = max(1, min(20, (int)($_GET['limit'] ?? 10)));

$normalizedQuery = normalize_search_query($rawQuery);

// ---------------------------------------------------------------
// Category → icon mapping. locations.type covers MohToh's own transit
// taxonomy; the OSM class/type pairs cover everything Nominatim returns
// (schools, hospitals, airports, markets, worship, fuel, parks, ...).
// ---------------------------------------------------------------
function location_search_category_meta($typeOrClass, $osmType = null) {
    $map = [
        'bus_stop' => ['Bus stop', 'bi-signpost-split'],
        'junction' => ['Junction', 'bi-signpost-2'],
        'landmark' => ['Landmark', 'bi-geo-alt-fill'],
        'motor_park' => ['Motor park', 'bi-truck'],
        'popular_area' => ['Popular area', 'bi-star-fill'],
        'school' => ['School', 'bi-mortarboard'],
        'university' => ['University', 'bi-mortarboard'],
        'college' => ['School', 'bi-mortarboard'],
        'hospital' => ['Hospital', 'bi-hospital'],
        'clinic' => ['Clinic', 'bi-hospital'],
        'pharmacy' => ['Pharmacy', 'bi-hospital'],
        'hotel' => ['Hotel', 'bi-building'],
        'restaurant' => ['Restaurant', 'bi-cup-hot'],
        'cafe' => ['Cafe', 'bi-cup-hot'],
        'fast_food' => ['Restaurant', 'bi-cup-hot'],
        'bar' => ['Restaurant', 'bi-cup-hot'],
        'aerodrome' => ['Airport', 'bi-airplane'],
        'airport' => ['Airport', 'bi-airplane'],
        'place_of_worship' => ['Place of worship', 'bi-building'],
        'marketplace' => ['Market', 'bi-shop'],
        'supermarket' => ['Market', 'bi-shop'],
        'mall' => ['Shopping centre', 'bi-shop'],
        'shop' => ['Shop', 'bi-shop'],
        'fuel' => ['Fuel station', 'bi-fuel-pump'],
        'park' => ['Park', 'bi-tree'],
        'garden' => ['Park', 'bi-tree'],
        'attraction' => ['Tourist attraction', 'bi-camera'],
        'museum' => ['Tourist attraction', 'bi-camera'],
        'bank' => ['Bank', 'bi-bank'],
        'city' => ['City', 'bi-buildings'],
        'town' => ['Town', 'bi-buildings'],
        'village' => ['Village', 'bi-buildings'],
        'state' => ['State', 'bi-map'],
        'country' => ['Country', 'bi-globe'],
        'road' => ['Road', 'bi-signpost'],
        'residential' => ['Street', 'bi-signpost'],
        'highway' => ['Road', 'bi-signpost'],
    ];

    $key = strtolower((string)($osmType ?: $typeOrClass));
    if (isset($map[$key])) {
        return $map[$key];
    }
    return ['Place', 'bi-geo-alt'];
}

// ---------------------------------------------------------------
// Source 0: User search history (high priority).
// ---------------------------------------------------------------
$historyResults = [];
try {
    $stmt = $pdo->prepare("
        SELECT searched_location AS name, latitude, longitude
        FROM location_search_history
        WHERE user_id = ?
        ORDER BY last_used_at DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $historyCandidates = $stmt->fetchAll();

    foreach ($historyCandidates as $row) {
        if (stripos($row['name'], $rawQuery) !== false || calculate_relevance_score($rawQuery, $row['name']) > 30) {
            $historyResults[] = [
                'name' => $row['name'],
                'category' => 'Recent',
                'category_icon' => 'bi-clock-history',
                'address' => $row['name'],
                'latitude' => (float)$row['latitude'],
                'longitude' => (float)$row['longitude'],
                'distance_km' => null,
                'place_id' => 'hist_' . md5($row['name']),
                'type' => 'history',
                'source' => 'history',
                '_score' => 100, // Very high priority
            ];
        }
    }
} catch (Exception $e) {
    // History failing shouldn't break search
}

// ---------------------------------------------------------------
// Source 1 + 2: MohToh's own database (official + driver-submitted).
// ---------------------------------------------------------------
$mohtohResults = array_merge($historyResults, []);
try {
    // Scored entirely in PHP rather than pre-filtered with LIKE: a typo
    // like "Lekii" shares no substring with "Lekki", so a LIKE filter
    // would exclude it before calculate_relevance_score() ever got a
    // chance to fuzzy-match it. The table is small enough (curated +
    // driver-submitted spots, not a general gazetteer) that scoring
    // every approved row per request is cheap.
    $stmt = $pdo->prepare("
        SELECT l.public_id, l.name, l.address, l.city, l.state, l.type, l.latitude, l.longitude,
               u.user_type AS creator_type
        FROM locations l
        LEFT JOIN users u ON u.id = l.created_by
        WHERE l.status = 'approved'
        LIMIT 500
    ");
    $stmt->execute();
    $candidates = $stmt->fetchAll();

    foreach ($candidates as $row) {
        $nameScore = calculate_relevance_score($rawQuery, $row['name']);
        $addressScore = calculate_relevance_score($rawQuery, $row['address'] . ' ' . $row['city'] . ' ' . $row['state']);
        $normalizedScore = calculate_relevance_score($normalizedQuery, $row['name']);
        $relevance = max($nameScore, $addressScore, $normalizedScore);
        if ($relevance <= 0) {
            continue;
        }

        $distanceKm = ($lat !== null && $lng !== null)
            ? calculate_distance_km($lat, $lng, $row['latitude'], $row['longitude'])
            : null;

        [$categoryLabel, $categoryIcon] = location_search_category_meta($row['type']);
        $isOfficial = $row['creator_type'] !== 'driver';

        $mohtohResults[] = [
            'name' => $row['name'],
            'category' => $categoryLabel,
            'category_icon' => $categoryIcon,
            'address' => trim($row['address'] . ($row['city'] ? ', ' . $row['city'] : '') . ($row['state'] ? ', ' . $row['state'] : ''), ', '),
            'latitude' => (float)$row['latitude'],
            'longitude' => (float)$row['longitude'],
            'distance_km' => $distanceKm,
            'place_id' => $row['public_id'],
            'type' => $row['type'],
            'source' => $isOfficial ? 'mohtoh' : 'mohtoh_driver',
            '_score' => $relevance + calculate_distance_bonus($distanceKm) + ($isOfficial ? 10 : 5),
        ];
    }
} catch (Exception $e) {
    // MohToh DB layer failing should never take the whole search down —
    // external results alone are still a useful response.
}

// ---------------------------------------------------------------
// Spelling correction — Nominatim has little tolerance for letter-level
// typos ("Lekii" finds nothing, even though "Lekki" clearly does), and
// our own locations table is too small/specific to lean on for general
// misspelling recovery. This is a short list of well-known Nigerian
// hubs (real place names, not fabricated data) a ride search is most
// likely to be typed against; if the raw query doesn't already match
// anything and it fuzzy-matches one of these well, the corrected term
// is used for the external search instead.
$searchQuery = $rawQuery;
$didYouMean = null;
if (empty($mohtohResults)) {
    static $knownPlaces = [
        'Lagos', 'Lekki', 'Ikeja', 'Ajah', 'Victoria Island', 'Surulere', 'Yaba', 'Ikoyi',
        'Apapa', 'Festac', 'Ojota', 'Oshodi', 'Mile 2', 'Ojuelegba', 'Berger', 'Maryland',
        'Gbagada', 'Ketu', 'Mushin', 'Agege', 'Ikorodu', 'Epe', 'Badagry', 'Ibadan', 'Abuja',
        'Kano', 'Port Harcourt', 'Enugu', 'Kaduna', 'Benin City', 'Computer Village',
    ];

    $bestMatch = null;
    $bestScore = 0;
    foreach ($knownPlaces as $place) {
        if (strcasecmp($place, $rawQuery) === 0) {
            $bestMatch = null; // already spelled correctly — nothing to correct
            break;
        }
        $score = calculate_relevance_score($rawQuery, $place);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $place;
        }
    }

    // Fuzzy tier only (calculate_relevance_score caps that below 56) —
    // an exact/prefix/substring match means the query was already fine.
    if ($bestMatch !== null && $bestScore > 0 && $bestScore <= 56) {
        $searchQuery = $bestMatch;
        $didYouMean = $bestMatch;
    }
}

// ---------------------------------------------------------------
// Source 3/4: worldwide external geocoding (Nominatim), cached.
// ---------------------------------------------------------------
$externalResults = [];
$cacheKey = sha1(normalize_search_query($searchQuery));
$cacheTtlSeconds = 24 * 60 * 60;

try {
    $stmt = $pdo->prepare("SELECT results_json, updated_at FROM location_search_cache WHERE query_hash = ?");
    $stmt->execute([$cacheKey]);
    $cached = $stmt->fetch();

    $isFresh = $cached && (strtotime($cached['updated_at']) > time() - $cacheTtlSeconds);

    if ($isFresh) {
        $externalResults = json_decode($cached['results_json'], true) ?: [];
        $pdo->prepare("UPDATE location_search_cache SET hit_count = hit_count + 1 WHERE query_hash = ?")->execute([$cacheKey]);
    } else {
        $externalResults = location_search_fetch_nominatim($searchQuery);

        if ($cached) {
            $pdo->prepare("UPDATE location_search_cache SET results_json = ?, hit_count = hit_count + 1, updated_at = NOW() WHERE query_hash = ?")
                ->execute([json_encode($externalResults), $cacheKey]);
        } else {
            $pdo->prepare("INSERT INTO location_search_cache (query_hash, query_text, results_json) VALUES (?, ?, ?)")
                ->execute([$cacheKey, $searchQuery, json_encode($externalResults)]);
        }
    }
} catch (Exception $e) {
    // Cache table trouble shouldn't block search — fall back to a
    // direct (uncached) fetch for this one request.
    $externalResults = location_search_fetch_nominatim($searchQuery);
}

function location_search_fetch_nominatim($query) {
    $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=15&countrycodes=ng&q=' . urlencode($query);

    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['User-Agent: MohToh/1.0 (support@meritdrives.com)'],
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    }

    if ($raw === false && ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => ['header' => "User-Agent: MohToh/1.0 (support@meritdrives.com)\r\n", 'timeout' => 4],
        ]);
        $raw = @file_get_contents($url, false, $context);
    }

    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $mapped = [];
    foreach ($decoded as $place) {
        [$categoryLabel, $categoryIcon] = location_search_category_meta($place['class'] ?? '', $place['type'] ?? null);
        $mapped[] = [
            'name' => $place['name'] ?? explode(',', $place['display_name'])[0],
            'category' => $categoryLabel,
            'category_icon' => $categoryIcon,
            'address' => $place['display_name'] ?? '',
            'latitude' => (float)($place['lat'] ?? 0),
            'longitude' => (float)($place['lon'] ?? 0),
            'place_id' => $place['place_id'] ?? null,
            'type' => $place['type'] ?? null,
            'source' => 'external',
            '_importance' => (float)($place['importance'] ?? 0.3),
        ];
    }
    return $mapped;
}

// ---------------------------------------------------------------
// Merge, dedupe (an external result within ~300m of a MohToh result
// with a similar name is redundant — MohToh's own entry is more
// authoritative and already richer), rank, and trim.
// ---------------------------------------------------------------
$merged = $mohtohResults;

foreach ($externalResults as $ext) {
    $distanceKm = ($lat !== null && $lng !== null)
        ? calculate_distance_km($lat, $lng, $ext['latitude'], $ext['longitude'])
        : null;

    $isDuplicate = false;
    foreach ($mohtohResults as $known) {
        $apartKm = calculate_distance_km($known['latitude'], $known['longitude'], $ext['latitude'], $ext['longitude']);
        if ($apartKm <= 0.3 && calculate_relevance_score($known['name'], $ext['name']) >= 65) {
            $isDuplicate = true;
            break;
        }
    }
    if ($isDuplicate) {
        continue;
    }

    $relevance = max(
        calculate_relevance_score($searchQuery, $ext['name']),
        calculate_relevance_score($searchQuery, $ext['address'])
    );
    // A place can still rank on Nominatim's own importance signal when
    // our substring/fuzzy check scores it 0 (e.g. the query matches a
    // middle word of a long display_name) — but only once it clears a
    // floor, so barely-related "importance" noise doesn't drown out
    // genuine text matches for short/ambiguous queries like acronyms.
    $importanceScore = $ext['_importance'] >= 0.4 ? $ext['_importance'] * 60 : 0;
    $baseScore = max($relevance, $importanceScore);

    // MohToh is a Nigeria-first service — when the same name exists in
    // multiple countries (e.g. "Lagos, Nigeria" vs "Lagos, Portugal"),
    // nudge the Nigerian match up without excluding the rest of the
    // world (London/New York must still resolve for a global search).
    if (stripos($ext['address'], 'nigeria') !== false) {
        $baseScore += 15;
    }

    $ext['distance_km'] = $distanceKm;
    $ext['_score'] = $baseScore + calculate_distance_bonus($distanceKm);
    unset($ext['_importance']);
    $merged[] = $ext;
}

usort($merged, function ($a, $b) {
    return $b['_score'] <=> $a['_score'];
});

$merged = array_slice($merged, 0, $limit);
foreach ($merged as &$result) {
    unset($result['_score']);
    if ($result['distance_km'] !== null) {
        $result['distance_km'] = round($result['distance_km'], 1);
    }
}
unset($result);

json_response('success', 'Locations found', ['results' => $merged, 'query' => $rawQuery, 'did_you_mean' => $didYouMean]);
