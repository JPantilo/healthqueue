<?php
// OpenWeatherMap 5-day / 3-hour forecast (free plan). Cached 30 min per center in MySQL.
require_once __DIR__ . '/init.php';

function weather_configured()
{
    $k = cfg('openweather')['api_key'] ?? '';
    return $k !== '' && strpos($k, 'xxxx') === false;
}

function weather_forecast($centerId)
{
    if (!weather_configured()) {
        return null;
    }
    $st = db()->prepare('SELECT payload, fetched_at FROM weather_cache WHERE center_id=?');
    $st->execute([$centerId]);
    $row = $st->fetch();
    if ($row && strtotime($row['fetched_at']) > time() - 1800) {
        return json_decode($row['payload'], true);
    }

    $c = db()->prepare('SELECT lat, lng FROM centers WHERE id=?');
    $c->execute([$centerId]);
    $center = $c->fetch();
    if (!$center) {
        return null;
    }

    $url = 'https://api.openweathermap.org/data/2.5/forecast?' . http_build_query([
        'lat'   => $center['lat'],
        'lon'   => $center['lng'],
        'units' => 'metric',
        'appid' => cfg('openweather')['api_key'],
    ]);
    $r = http_request('GET', $url);
    if ($r['code'] === 200 && !empty($r['json']['list'])) {
        db()->prepare('REPLACE INTO weather_cache (center_id, payload, fetched_at) VALUES (?,?,?)')
            ->execute([$centerId, $r['body'], date('Y-m-d H:i:s')]);
        return $r['json'];
    }

    return $row ? json_decode($row['payload'], true) : null;
}

function weather_for($centerId, $date, $time = '09:00:00')
{
    $f = weather_forecast($centerId);
    if (!$f || empty($f['list'])) {
        return null;
    }
    $target = strtotime($date . ' ' . $time);
    $best = null;
    $bestDiff = PHP_INT_MAX;
    foreach ($f['list'] as $it) {
        $diff = abs($it['dt'] - $target);
        if ($diff < $bestDiff) {
            $bestDiff = $diff;
            $best = $it;
        }
    }
    if (!$best) {
        return null;
    }
    $w = $best['weather'][0] ?? [];
    return [
        'temp'        => round($best['main']['temp'] ?? 0),
        'desc'        => ucfirst($w['description'] ?? 'Fair'),
        'icon'        => $w['icon'] ?? '01d',
        'pop'         => round(($best['pop'] ?? 0) * 100),
        'main'        => strtolower($w['main'] ?? ''),
        'dt_txt'      => $best['dt_txt'] ?? '',
    ];
}

function weather_advisory($wx)
{
    if (!$wx) {
        return null;
    }
    if ($wx['pop'] >= 70 || in_array($wx['main'], ['rain', 'thunderstorm', 'drizzle'], true)) {
        return "Rain is likely ({$wx['pop']}% chance). Bring an umbrella or raincoat and allow extra travel time.";
    }
    if ($wx['temp'] >= 33) {
        return "High temperatures ({$wx['temp']}°C) expected. Stay hydrated and bring drinking water.";
    }
    return null;
}