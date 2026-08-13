<?php

declare(strict_types=1);

$config = require __DIR__ . '/config/app.php';
$clientConfig = [
    'name' => $config['name'],
    'defaultOrigin' => $config['default_origin'],
    'comparisonOrigin' => $config['comparison_origin'],
    'origins' => $config['origins'],
    'slider' => $config['slider'],
    'cities' => $config['cities'],
    'endpoints' => [
        'routing' => 'api/routing.php',
        'statbank' => 'api/statbank.php',
        'geography' => 'api/geography.php',
    ],
];
$assetVersion = max(
    (int) filemtime(__DIR__ . '/assets/css/app.css'),
    (int) filemtime(__DIR__ . '/assets/js/app.js')
);
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isStaging = str_starts_with($host, 'testbibkort.');
?>
<!doctype html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Sammenlign arbejdsmarkedsoplande for byer og lokalsamfund i Lemvig Kommune.">
    <?php if ($isStaging): ?><meta name="robots" content="noindex, nofollow, noarchive"><?php endif; ?>
    <title>Arbejdsmarkedskort for Lemvig Kommune</title>
    <link rel="preconnect" href="https://unpkg.com">
    <link rel="preconnect" href="https://tile.openstreetmap.org">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= $assetVersion ?>">
    <script id="app-config" type="application/json"><?= json_encode($clientConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>
    <script defer src="https://unpkg.com/@turf/turf@7.2.0/turf.min.js"></script>
    <script defer src="assets/js/app.js?v=<?= $assetVersion ?>"></script>
</head>
<body>
    <main class="app-shell">
        <section class="map-region" aria-label="Kort over køretidsområdet">
            <div id="map"></div>
            <div class="map-legend" aria-hidden="true">
                <span><i class="legend-dot origin-dot"></i><span id="legend-primary">Valgt udgangspunkt</span></span>
                <span id="legend-secondary-wrap" hidden><i class="legend-dot secondary-dot"></i><span id="legend-secondary">Lemvig</span></span>
                <span><i class="legend-dot reached-dot"></i>Nået by</span>
                <span><i class="legend-dot near-dot"></i>Nær grænsen</span>
            </div>
            <div id="map-loading" class="map-loading" role="status">Beregner 45-minutters område…</div>
        </section>

        <aside class="info-panel">
            <header class="hero">
                <p class="eyebrow">Lemvig Kommune</p>
                <h1>Arbejdsmarkedskort</h1>
                <p class="intro" id="origin-intro">Udforsk og sammenlign arbejdsmarkedsoplande fra kommunens byer og lokalsamfund.</p>
            </header>

            <section class="origin-control" aria-labelledby="origin-title">
                <p class="section-kicker" id="origin-title">Vælg udgangspunkt</p>
                <div class="origin-toolbar">
                    <label class="origin-field" for="origin-primary">
                        <span>Udgangspunkt A</span>
                        <select id="origin-primary">
                            <?php foreach ($config['origins'] as $id => $origin): ?>
                                <option value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" <?= $id === $config['default_origin'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($origin['name'] . ' · ' . $origin['description'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button id="compare-toggle" class="compare-toggle" type="button" aria-pressed="false">Sammenlign</button>
                </div>
                <div id="secondary-control" class="secondary-control" hidden>
                    <label class="origin-field" for="origin-secondary">
                        <span>Udgangspunkt B</span>
                        <select id="origin-secondary">
                            <?php foreach ($config['origins'] as $id => $origin): ?>
                                <option value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" <?= $id === $config['comparison_origin'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($origin['name'] . ' · ' . $origin['description'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
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

            <div id="single-results">
                <section class="metrics" aria-label="Nøgletal">
                    <article class="metric-card metric-primary">
                        <span class="metric-label">Anslåede job i zonen</span>
                        <strong id="metric-jobs">—</strong>
                        <small id="metric-year">ERHV2</small>
                    </article>
                    <article class="metric-card">
                        <span class="metric-label">Anslåede arbejdssteder</span>
                        <strong id="metric-workplaces">—</strong>
                    </article>
                    <article class="metric-card">
                        <span class="metric-label">Arbejdsmarkedsbyer nået</span>
                        <strong id="metric-cities">—</strong>
                    </article>
                    <article class="metric-card">
                        <span class="metric-label">Største nåede by</span>
                        <strong id="metric-largest" class="metric-name">—</strong>
                    </article>
                </section>

                <section class="panel-section" aria-labelledby="branches-title">
                    <div class="section-heading">
                        <h2 id="branches-title">Største brancher</h2>
                        <span>anslåede job</span>
                    </div>
                    <div id="branch-chart" class="branch-chart"><p class="empty-state">Venter på jobtal…</p></div>
                </section>
            </div>

            <section id="comparison-results" class="comparison-results" aria-label="Sammenligning" hidden>
                <?php foreach (['primary' => 'A', 'secondary' => 'B'] as $key => $label): ?>
                    <article class="comparison-column is-<?= $key ?>">
                        <h2><i></i><span id="compare-<?= $key ?>-name">Udgangspunkt <?= $label ?></span></h2>
                        <div class="comparison-metrics">
                            <div><span>Job i zonen</span><strong id="compare-<?= $key ?>-jobs">—</strong></div>
                            <div><span>Arbejdssteder</span><strong id="compare-<?= $key ?>-workplaces">—</strong></div>
                            <div><span>Byer nået</span><strong id="compare-<?= $key ?>-cities">—</strong></div>
                        </div>
                        <h3>Største brancher</h3>
                        <div id="compare-<?= $key ?>-branches" class="branch-chart"><p class="empty-state">Beregner…</p></div>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="panel-section" aria-labelledby="cities-title">
                <div class="section-heading">
                    <h2 id="cities-title">Byer efter køretid</h2>
                    <span id="cities-context">fra valgt udgangspunkt</span>
                </div>
                <div id="city-list" class="city-list"><p class="empty-state">Beregner ruter…</p></div>
            </section>

            <section class="method-note" aria-labelledby="method-title">
                <h2 id="method-title">Om tallene</h2>
                <p>Køretidsfladen følger vejnettet. TravelTime-tiderne er kalibreret med faktor 0,942 mod den kendte tur Bækmarksbro–Gødstrup på 44 minutter.</p>
                <p>Præcise jobtal pr. adresse er ikke offentligt tilgængelige. Derfor fordeles 90 % af kommunens ERHV2-tal på officielle byområder efter deres BY3-befolkning; en byandel tæller, når bymidten ligger i zonen. De sidste 10 % fordeles efter, hvor stor en del af kommunens landareal zonen dækker. Resultaterne er modelberegnede anslåede tal.</p>
            </section>

            <footer class="sources">
                <strong>Kilder</strong>
                <a href="https://www.statbank.dk/ERHV2" target="_blank" rel="noopener">Danmarks Statistik / ERHV2</a>
                <a href="https://www.statbank.dk/BY3" target="_blank" rel="noopener">Danmarks Statistik / BY3</a>
                <a href="https://dataforsyningen.dk/" target="_blank" rel="noopener">Dataforsyningen</a>
                <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>
                <?php if ($config['routing']['provider'] === 'TravelTime'): ?>
                    <a href="https://traveltime.com/" target="_blank" rel="noopener">TravelTime routing</a>
                <?php else: ?>
                    <a href="https://valhalla.github.io/valhalla/" target="_blank" rel="noopener">Valhalla routing</a>
                <?php endif; ?>
            </footer>
        </aside>
    </main>
    <noscript>Denne side kræver JavaScript for at vise kort og dynamiske nøgletal.</noscript>
</body>
</html>
