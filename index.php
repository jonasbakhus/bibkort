<?php

declare(strict_types=1);

$config = require __DIR__ . '/config/app.php';
$originOptionLabel = static function (array $origin): string {
    $description = trim((string) ($origin['description'] ?? ''));
    return (string) $origin['name'] . ($description === '' ? '' : ' · ' . $description);
};
$originSortKey = static function (array $origin): string {
    $name = (string) ($origin['name'] ?? '');
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);

    // Dansk alfabetisk rækkefølge: æ, ø og å følger efter z.
    return strtr($name, ['æ' => '{a', 'ø' => '{b', 'å' => '{c']);
};
$originOptions = $config['origins'];
uasort($originOptions, static function (array $left, array $right) use ($originSortKey): int {
    $leftIsBoundary = ($left['type'] ?? '') === 'boundary';
    $rightIsBoundary = ($right['type'] ?? '') === 'boundary';
    if ($leftIsBoundary !== $rightIsBoundary) {
        return $leftIsBoundary ? 1 : -1;
    }

    return $originSortKey($left) <=> $originSortKey($right);
});
$clientConfig = [
    'name' => $config['name'],
    'variant' => $config['variant'],
    'reachability' => $config['routing']['reachability'],
    'nearMarginMinutes' => $config['routing']['near_margin_minutes'],
    'defaultOrigin' => null,
    'comparisonOrigin' => null,
    'origins' => $config['origins'],
    'slider' => $config['slider'],
    'cities' => $config['cities'],
    'endpoints' => [
        'routing' => 'api/routing.php',
        'statbank' => 'api/statbank.php',
        'geography' => 'api/geography.php',
        'boundary' => 'api/boundary.php',
        'heatmap' => 'assets/data/job-heatmap.json',
    ],
];
$assetFiles = [
    __DIR__ . '/assets/css/app.css',
    __DIR__ . '/assets/js/app.js',
    __DIR__ . '/assets/js/analysis-worker.js',
    __DIR__ . '/assets/brand/land-og-by-logo.svg',
];
$heatmapDataFile = __DIR__ . '/assets/data/job-heatmap.json';
if (is_file($heatmapDataFile)) {
    $assetFiles[] = $heatmapDataFile;
}
$assetVersion = substr(hash('sha256', implode('|', array_map(
    static fn (string $file): string => (string) hash_file('sha256', $file),
    $assetFiles
))), 0, 12);
$clientConfig['analysisWorker'] = 'assets/js/analysis-worker.js?v=' . $assetVersion;
$clientConfig['endpoints']['heatmap'] .= '?v=' . $assetVersion;
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isStaging = str_starts_with($host, 'testbibkort.') || str_starts_with($host, 'testbibg.');
$isGoogle = $config['variant'] === 'google';
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
?>
<!doctype html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Sammenlign job og arbejdsmarkedsoplande inden for valgt køretid.">
    <?php if ($isStaging): ?><meta name="robots" content="noindex, nofollow, noarchive"><?php endif; ?>
    <title>Joboplandskort for Lemvig Kommune · Land og By</title>
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
            <div class="map-mobile-controls">
                <a class="map-mini-brand" href="https://landogbyforeningen.dk/" target="_blank" rel="noopener" aria-label="Land og By">
                    <img src="assets/brand/land-og-by-logo.svg?v=<?= $assetVersion ?>" alt="Land og By">
                </a>
                <button id="map-size-toggle" class="map-size-toggle" type="button" aria-expanded="false" aria-controls="map">
                    <span class="map-size-icon" aria-hidden="true"></span><span>Udvid kort</span>
                </button>
                <button id="map-heatmap-toggle" class="map-heatmap-toggle" type="button" aria-pressed="false">
                    <span class="heatmap-icon" aria-hidden="true"></span><span>Heatmap</span>
                </button>
            </div>
            <section class="map-expanded-time-control" aria-label="Køretid på det udvidede kort">
                <div><span>Maksimal køretid</span><output id="map-time-output" for="map-time-slider"><?= (int) $config['slider']['default'] ?> minutter</output></div>
                <input id="map-time-slider" type="range" min="<?= (int) $config['slider']['min'] ?>" max="<?= (int) $config['slider']['max'] ?>" step="<?= (int) $config['slider']['step'] ?>" value="<?= (int) $config['slider']['default'] ?>" aria-label="Maksimal køretid i minutter på kortet" disabled>
                <div class="range-labels" aria-hidden="true"><span><?= (int) $config['slider']['min'] ?> min</span><span><?= (int) $config['slider']['max'] ?> min</span></div>
            </section>
            <div class="map-legends">
                <div class="map-legend" aria-hidden="true">
                    <span><i class="legend-dot origin-dot"></i><span id="legend-primary">Vælg udgangspunkt A</span></span>
                    <span id="legend-secondary-wrap" hidden><i class="legend-dot secondary-dot"></i><span id="legend-secondary">Vælg B</span></span>
                    <span><i class="legend-dot reached-dot"></i>Nået by</span>
                    <span><i class="legend-dot near-dot"></i>Nær grænsen</span>
                    <span><i class="legend-dot boundary-point-dot"></i>Kommunegrænsepunkt</span>
                    <span><i class="legend-line municipality-line"></i>Lemvig Kommune</span>
                    <span><i class="legend-line context-municipality-line"></i>Andre kommuner</span>
                </div>
                <div id="heatmap-legend" class="heatmap-legend" aria-hidden="true" hidden>
                    <strong>Anslåede job inden for køretiden</strong>
                    <span class="heatmap-gradient"></span>
                    <span class="heatmap-scale"><i id="heatmap-min">Lavere</i><i id="heatmap-max">Højere</i></span>
                </div>
            </div>
            <div id="map-loading" class="map-loading is-prompt" role="status">Vælg en by for at starte</div>
        </section>

        <aside class="info-panel">
            <header class="hero">
                <a class="brand" href="https://landogbyforeningen.dk/" target="_blank" rel="noopener" aria-label="Land og By">
                    <img src="assets/brand/land-og-by-logo.svg?v=<?= $assetVersion ?>" alt="">
                    <span><strong>Land og By</strong><small>Sammen om udvikling</small></span>
                </a>
                <p class="eyebrow"><?= $isGoogle ? 'Google-test · ' : '' ?>For hele Lemvig Kommune</p>
                <h1>Joboplandskort for Lemvig Kommune</h1>
                <p class="intro">Sammenlign job og arbejdsmarkedsoplande inden for valgt køretid.</p>
            </header>

            <section class="origin-control" aria-labelledby="origin-title">
                <div class="origin-control-heading">
                    <p class="section-kicker" id="origin-title">Vælg udgangspunkt</p>
                    <button id="compare-toggle" class="compare-toggle" type="button" aria-pressed="false" disabled>Sammenlign A/B</button>
                </div>
                <div class="primary-control">
                    <label class="origin-field" for="origin-primary">
                        <span>Udgangspunkt A</span>
                        <select id="origin-primary" required>
                            <option value="" selected>Ingen valgt</option>
                            <?php foreach ($originOptions as $id => $origin): ?>
                                <option value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($originOptionLabel($origin), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div id="secondary-control" class="secondary-control" hidden>
                    <label class="origin-field" for="origin-secondary">
                        <span>Udgangspunkt B</span>
                        <select id="origin-secondary">
                            <option value="" selected>Ingen valgt</option>
                            <?php foreach ($originOptions as $id => $origin): ?>
                                <option value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($originOptionLabel($origin), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
            </section>

            <section class="time-control" aria-labelledby="time-title">
                <div class="control-heading">
                    <div><p class="section-kicker" id="time-title">Maksimal køretid</p><output id="time-output" for="time-slider"><?= (int) $config['slider']['default'] ?> minutter</output></div>
                    <span id="reached-summary" class="reach-pill">Vælg by</span>
                </div>
                <input id="time-slider" type="range" min="<?= (int) $config['slider']['min'] ?>" max="<?= (int) $config['slider']['max'] ?>" step="<?= (int) $config['slider']['step'] ?>" value="<?= (int) $config['slider']['default'] ?>" aria-label="Maksimal køretid i minutter" disabled>
                <div class="range-labels" aria-hidden="true"><span><?= (int) $config['slider']['min'] ?> min</span><span><?= (int) $config['slider']['max'] ?> min</span></div>
            </section>

            <section class="heatmap-control" aria-labelledby="heatmap-title">
                <button id="heatmap-toggle" type="button" aria-pressed="false">
                    <span class="heatmap-toggle-icon" aria-hidden="true"></span>
                    <span><strong id="heatmap-title">Vis heatmap for kommunen</strong><small>Se hvor mange job der kan nås fra et tæt 500-meter net.</small></span>
                </button>
                <p id="heatmap-description" hidden>Heatmappet er en forberegnet screeningsvisning. Klik på kortet for et anslået jobtal; vælg en by for den fulde zoneanalyse.</p>
            </section>

            <div id="selection-prompt" class="selection-prompt" role="status"><strong>Start med at vælge en by</strong><span>Kortet favoriserer ikke et bestemt udgangspunkt. Alle byer starter på lige fod.</span></div>
            <div id="data-status" class="data-status" role="status" aria-live="polite" hidden></div>

            <details id="single-results" class="fold-card" aria-busy="false" hidden open>
                <summary>Resultater for <strong id="single-origin-name">valgt by</strong></summary>
                <div class="fold-content">
                    <section class="metrics" aria-label="Nøgletal">
                        <article class="metric-card metric-primary"><span class="metric-label">A · Anslåede job i zonen</span><strong id="metric-jobs">—</strong><small id="metric-year">Modelberegning</small></article>
                        <article class="metric-card"><span class="metric-label">A · Anslåede arbejdssteder</span><strong id="metric-workplaces">—</strong></article>
                        <article class="metric-card"><span class="metric-label">A · Arbejdsmarkedsbyer nået</span><strong id="metric-cities">—</strong></article>
                        <article class="metric-card"><span class="metric-label">A · Største nåede by</span><strong id="metric-largest" class="metric-name">—</strong></article>
                    </section>
                    <details class="sub-fold municipality-fold"><summary>Job fordelt på kommuner <span>byer / øvrigt / udenfor</span></summary><div id="municipality-breakdown" class="municipality-breakdown"><p class="empty-state">Venter på kommunetal…</p></div></details>
                    <details class="sub-fold" open><summary>Største brancher <span>anslåede job</span></summary><div id="branch-chart" class="branch-chart"><p class="empty-state">Venter på jobtal…</p></div></details>
                </div>
            </details>

            <details id="comparison-panel" class="fold-card" hidden open>
                <summary>Sammenligning af A og B</summary>
                <section id="comparison-results" class="comparison-results" aria-label="Sammenligning">
                    <?php foreach (['primary' => 'A', 'secondary' => 'B'] as $key => $label): ?>
                        <article class="comparison-column is-<?= $key ?>">
                            <h2><b id="compare-<?= $key ?>-badge" class="compare-badge"><?= $label ?></b><i></i><span id="compare-<?= $key ?>-name">Vælg udgangspunkt <?= $label ?></span></h2>
                            <div class="comparison-metrics">
                                <div><span><?= $label ?> · Job i zonen</span><strong id="compare-<?= $key ?>-jobs">—</strong></div>
                                <div><span><?= $label ?> · Arbejdssteder</span><strong id="compare-<?= $key ?>-workplaces">—</strong></div>
                                <div><span><?= $label ?> · Byer nået</span><strong id="compare-<?= $key ?>-cities">—</strong></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
                <div id="comparison-key" class="comparison-key" aria-label="Forklaring af A og B">
                    <span class="is-primary"><b aria-hidden="true">A</b><span id="comparison-key-primary">Vælg udgangspunkt A</span></span>
                    <span class="is-secondary"><b aria-hidden="true">B</b><span id="comparison-key-secondary">Vælg udgangspunkt B</span></span>
                </div>
                <div id="comparison-branches-compact" class="comparison-branches-compact" aria-label="Sammenligning af brancher"></div>
                <div id="comparison-municipalities" class="comparison-municipalities-combined" aria-label="Sammenligning af kommuner"></div>
            </details>

            <details id="cities-section" class="fold-card" hidden>
                <summary>Byer efter køretid <span id="cities-context">fra valgt udgangspunkt</span></summary>
                <div id="city-list" class="city-list"><p class="empty-state">Vælg en by…</p></div>
            </details>

            <aside class="disclaimer" aria-labelledby="disclaimer-title">
                <strong id="disclaimer-title">Vigtig disclaimer</strong>
                <p>Dette er et transparent analyseværktøj – ikke officiel statistik, navigation eller en myndighedsvurdering. Jobtal, arbejdssteder og zoner er modelberegnede skøn med usikkerhed og bør ikke stå alene ved økonomiske, juridiske, trafikale eller planmæssige beslutninger.</p>
                <p>Projektets praktiske udgangspunkt er, at brugerens kontrolture i forbrugerproduktet <span class="google-maps-attribution" translate="no">Google Maps</span> opleves som korrekte. Standardmodellens TravelTime-resultater er derfor justeret med en bred, statisk Google Routes-kontrolmatrix. Det er en kalibrering – ikke en uafhængig validering eller garanti for samme resultat som Google Maps.</p>
                <a class="text-link" href="metode.php">Læs den fulde metode, formler, usikkerheder, API’er, datakilder og licenser</a>
            </aside>

            <footer class="sources">
                <div class="source-copy"><strong>Kilder og kreditering</strong><span>Kilde: Egne beregninger baseret på tal fra Danmarks Statistik, Statistikbanken ERHV2 og BY3.</span><span>Indeholder data fra Styrelsen for Dataforsyning og Infrastruktur: kommunegrænser og steddata, leveret via Dataforsyningens API-datatjenester.</span></div>
                <div class="source-links"><a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">© OpenStreetMap-bidragsydere · ODbL</a><a href="https://traveltime.com/" target="_blank" rel="noopener">TravelTime</a><?php if ($isGoogle): ?><a href="https://developers.google.com/maps/documentation/isochrones" target="_blank" rel="noopener"><span class="google-maps-attribution" translate="no">Google Maps</span></a><?php endif; ?></div>
                <nav aria-label="Juridisk og dokumentation"><a href="metode.php">Metode</a><a href="vilkaar.php">Vilkår</a><a href="privatliv.php">Privatliv</a></nav>
                <p class="publisher"><strong>Udgivet af Land og By</strong><span>Sammen om udvikling · For hele Lemvig Kommune</span><span>Udvikler: Jonas Munkholm Jensen</span></p>
            </footer>
        </aside>
    </main>
    <noscript>Denne side kræver JavaScript for at vise kort og dynamiske nøgletal.</noscript>
</body>
</html>
