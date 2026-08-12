<?php

declare(strict_types=1);

$config = require __DIR__ . '/config/app.php';
$clientConfig = [
    'name' => $config['name'],
    'defaultOrigin' => $config['default_origin'],
    'origins' => $config['origins'],
    'slider' => $config['slider'],
    'cities' => $config['cities'],
    'endpoints' => [
        'routing' => 'api/routing.php',
        'statbank' => 'api/statbank.php',
    ],
];
$assetVersion = max(
    (int) filemtime(__DIR__ . '/assets/css/app.css'),
    (int) filemtime(__DIR__ . '/assets/js/app.js')
);
?>
<!doctype html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Se hvilke større arbejdsmarkedsbyer og kommunale jobtal der kan nås i bil fra Bækmarksbro.">
    <title>Arbejdsmarkedet omkring Bækmarksbro</title>
    <link rel="preconnect" href="https://unpkg.com">
    <link rel="preconnect" href="https://tile.openstreetmap.org">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= $assetVersion ?>">
    <script id="app-config" type="application/json"><?= json_encode($clientConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>
    <script defer src="assets/js/app.js?v=<?= $assetVersion ?>"></script>
</head>
<body>
    <main class="app-shell">
        <section class="map-region" aria-label="Kort over køretidsområdet">
            <div id="map"></div>
            <div class="map-legend" aria-hidden="true">
                <span><i class="legend-dot origin-dot"></i><span id="legend-origin">Bækmarksbro</span></span>
                <span><i class="legend-dot reached-dot"></i>Nået by</span>
                <span><i class="legend-dot near-dot"></i>Nær grænsen</span>
            </div>
            <div id="map-loading" class="map-loading" role="status">Beregner 45-minutters område…</div>
        </section>

        <aside class="info-panel">
            <header class="hero">
                <p class="eyebrow">Bo i Bækmarksbro</p>
                <h1>Hvor langt rækker arbejdsmarkedet?</h1>
                <p class="intro" id="origin-intro">Udforsk større arbejdsmarkedsbyer, der kan nås i bil fra Bækmarksbro.</p>
            </header>

            <section class="origin-control" aria-labelledby="origin-title">
                <p class="section-kicker" id="origin-title">Sammenlign udgangspunkt</p>
                <div class="origin-options" role="group" aria-labelledby="origin-title">
                    <?php foreach ($config['origins'] as $id => $origin): ?>
                        <button
                            class="origin-option"
                            type="button"
                            data-origin-id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"
                            aria-pressed="<?= $id === $config['default_origin'] ? 'true' : 'false' ?>"
                        >
                            <strong><?= htmlspecialchars($origin['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <small><?= htmlspecialchars($origin['description'], ENT_QUOTES, 'UTF-8') ?></small>
                        </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="time-control" aria-labelledby="time-title">
                <div class="control-heading">
                    <div>
                        <p class="section-kicker" id="time-title">Maksimal køretid</p>
                        <output id="time-output" for="time-slider">45 minutter</output>
                    </div>
                    <span id="reached-summary" class="reach-pill">Indlæser…</span>
                </div>
                <input id="time-slider" type="range" min="15" max="90" step="5" value="45" aria-label="Maksimal køretid i minutter">
                <div class="range-labels" aria-hidden="true"><span>15 min</span><span>90 min</span></div>
            </section>

            <div id="data-status" class="data-status" role="status" aria-live="polite">Henter aktuelle køretider og jobtal…</div>

            <section class="metrics" aria-label="Nøgletal">
                <article class="metric-card metric-primary">
                    <span class="metric-label">Job i nåede kommuner</span>
                    <strong id="metric-jobs">—</strong>
                    <small id="metric-year">ERHV2</small>
                </article>
                <article class="metric-card">
                    <span class="metric-label">Arbejdssteder</span>
                    <strong id="metric-workplaces">—</strong>
                </article>
                <article class="metric-card">
                    <span class="metric-label">Større byer nået</span>
                    <strong id="metric-cities">—</strong>
                </article>
                <article class="metric-card">
                    <span class="metric-label">Største nåede arbejdsmarked</span>
                    <strong id="metric-largest" class="metric-name">—</strong>
                </article>
            </section>

            <section class="panel-section" aria-labelledby="branches-title">
                <div class="section-heading">
                    <h2 id="branches-title">Største brancher</h2>
                    <span>job</span>
                </div>
                <div id="branch-chart" class="branch-chart"><p class="empty-state">Venter på jobtal…</p></div>
            </section>

            <section class="panel-section" aria-labelledby="cities-title">
                <div class="section-heading">
                    <h2 id="cities-title">Byer efter køretid</h2>
                    <span>fra <span id="cities-origin">Bækmarksbro</span></span>
                </div>
                <div id="city-list" class="city-list"><p class="empty-state">Beregner ruter…</p></div>
            </section>

            <section class="method-note" aria-labelledby="method-title">
                <h2 id="method-title">Om tallene</h2>
                <p>Køretidsfladen følger vejnettet og viser det omtrentlige geografiske område, der kan nås inden for den valgte tid.</p>
                <p>Job og arbejdssteder er derimod kommunetal fra ERHV2. En kommunes tal medregnes, når den viste hovedby kan nås. Det er en arbejdsmarkedsindikator – ikke en optælling af job inde i polygonen. Hver kommune tælles kun én gang.</p>
            </section>

            <footer class="sources">
                <strong>Kilder</strong>
                <a href="https://www.statbank.dk/ERHV2" target="_blank" rel="noopener">Danmarks Statistik / ERHV2</a>
                <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>
                <a href="https://valhalla.github.io/valhalla/" target="_blank" rel="noopener">Valhalla routing</a>
            </footer>
        </aside>
    </main>
    <noscript>Denne side kræver JavaScript for at vise kort og dynamiske nøgletal.</noscript>
</body>
</html>
