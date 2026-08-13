(() => {
    'use strict';

    const configElement = document.getElementById('app-config');
    if (!configElement || typeof L === 'undefined') return;

    const config = JSON.parse(configElement.textContent);
    const params = new URLSearchParams(window.location.search);
    const primaryId = validOrigin(params.get('origin')) ? params.get('origin') : null;
    let secondaryId = primaryId && validOrigin(params.get('compare')) ? params.get('compare') : null;
    if (secondaryId === primaryId) secondaryId = null;

    const state = {
        minutes: config.slider.default,
        comparing: Boolean(primaryId && secondaryId),
        municipalities: {},
        statsYear: null,
        statsReady: false,
        statsError: null,
        geography: null,
        geographyReady: false,
        geographyError: null,
        settlementsByMunicipality: new Map(),
        municipalityBoundaries: new Map(),
        markers: new Map(),
        supportingDataStarted: false,
        mobileDisclosureApplied: false,
        scenarios: {
            primary: makeScenario('primary', primaryId),
            secondary: makeScenario('secondary', secondaryId),
        },
    };

    const elements = {
        slider: document.getElementById('time-slider'),
        output: document.getElementById('time-output'),
        reached: document.getElementById('reached-summary'),
        status: document.getElementById('data-status'),
        jobs: document.getElementById('metric-jobs'),
        workplaces: document.getElementById('metric-workplaces'),
        cities: document.getElementById('metric-cities'),
        largest: document.getElementById('metric-largest'),
        year: document.getElementById('metric-year'),
        branches: document.getElementById('branch-chart'),
        cityList: document.getElementById('city-list'),
        mapLoading: document.getElementById('map-loading'),
        originIntro: document.getElementById('origin-intro'),
        legendPrimary: document.getElementById('legend-primary'),
        legendSecondary: document.getElementById('legend-secondary'),
        legendSecondaryWrap: document.getElementById('legend-secondary-wrap'),
        citiesContext: document.getElementById('cities-context'),
        primarySelect: document.getElementById('origin-primary'),
        secondarySelect: document.getElementById('origin-secondary'),
        secondaryControl: document.getElementById('secondary-control'),
        compareToggle: document.getElementById('compare-toggle'),
        singleResults: document.getElementById('single-results'),
        comparisonResults: document.getElementById('comparison-results'),
        comparisonPanel: document.getElementById('comparison-panel'),
        citiesSection: document.getElementById('cities-section'),
        selectionPrompt: document.getElementById('selection-prompt'),
        singleOriginName: document.getElementById('single-origin-name'),
        compare: {
            primary: comparisonElements('primary'),
            secondary: comparisonElements('secondary'),
        },
    };

    const numberFormat = new Intl.NumberFormat('da-DK');
    const map = L.map('map', {
        zoomControl: false,
        minZoom: 7,
        fadeAnimation: false,
        zoomAnimation: false,
        markerZoomAnimation: false,
    }).setView([56.25, 8.65], 8);
    L.control.zoom({ position: 'topright' }).addTo(map);
    map.createPane('isochrone-primary');
    map.createPane('isochrone-secondary');
    map.getPane('isochrone-primary').style.zIndex = 350;
    map.getPane('isochrone-secondary').style.zIndex = 351;

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);
    if (config.variant === 'google') {
        map.attributionControl.addAttribution('<span class="google-maps-attribution" translate="no">Google Maps</span>');
    }

    config.cities.forEach((city) => {
        const marker = L.circleMarker([city.lat, city.lon], markerStyle('muted', 0)).addTo(map);
        marker.bindTooltip(city.name, {
            permanent: true,
            direction: 'top',
            offset: [0, -7],
            className: 'city-label is-muted',
        });
        marker.bindPopup(cityPopup(city));
        state.markers.set(city.id, marker);
    });

    if (state.scenarios.primary.origin) addOriginMarker(state.scenarios.primary);
    if (state.comparing && state.scenarios.secondary.origin) addOriginMarker(state.scenarios.secondary);
    fitMapToOrigins();

    elements.slider.min = config.slider.min;
    elements.slider.max = config.slider.max;
    elements.slider.step = config.slider.step;
    elements.slider.value = state.minutes;
    elements.primarySelect.value = state.scenarios.primary.originId || '';
    elements.secondarySelect.value = state.scenarios.secondary.originId || '';
    applyComparisonMode();
    updateSliderProgress();

    elements.primarySelect.addEventListener('change', () => selectOrigin('primary', elements.primarySelect.value));
    elements.secondarySelect.addEventListener('change', () => selectOrigin('secondary', elements.secondarySelect.value));
    elements.compareToggle.addEventListener('click', () => {
        if (!state.scenarios.primary.origin) return;
        state.comparing = !state.comparing;
        applyComparisonMode();
        updateUrl();
        if (state.comparing && state.scenarios.secondary.origin) {
            addOriginMarker(state.scenarios.secondary);
            loadRouting(state.scenarios.secondary);
            loadIsochrone(state.scenarios.secondary);
        } else {
            removeScenarioMap(state.scenarios.secondary);
        }
        fitMapToOrigins();
        render();
    });

    let isochroneTimer = null;
    elements.slider.addEventListener('input', () => {
        state.minutes = Number(elements.slider.value);
        updateSliderProgress();
        activeScenarios().forEach((scenario) => {
            scenario.isochroneRequest += 1;
            scenario.isochroneLoading = true;
            scenario.isochroneError = null;
            scenario.geojson = null;
            scenario.zoneMinutes = null;
            removeScenarioLayer(scenario);
            invalidateAnalysis(scenario);
        });
        render();
        window.clearTimeout(isochroneTimer);
        const requestedMinutes = state.minutes;
        isochroneTimer = window.setTimeout(() => {
            loadIsochrone(state.scenarios.primary, requestedMinutes);
            if (state.comparing && state.scenarios.secondary.origin) loadIsochrone(state.scenarios.secondary, requestedMinutes);
        }, 220);
    });

    if (state.scenarios.primary.origin) {
        applyMobileDisclosureDefaults();
        ensureSupportingData();
        loadRouting(state.scenarios.primary);
        loadIsochrone(state.scenarios.primary);
    }
    if (state.comparing && state.scenarios.secondary.origin) {
        loadRouting(state.scenarios.secondary);
        loadIsochrone(state.scenarios.secondary);
    }
    render();

    function makeScenario(key, originId) {
        return {
            key,
            originId,
            origin: validOrigin(originId) ? config.origins[originId] : null,
            cities: config.cities.map(emptyRoute),
            routingReady: false,
            routeError: null,
            geojson: null,
            layer: null,
            marker: null,
            routingRequest: 0,
            isochroneRequest: 0,
            isochroneLoading: false,
            isochroneError: null,
            zoneMinutes: null,
            analysisRequest: 0,
            analysisStatus: 'idle',
            analysis: null,
            analysisError: null,
        };
    }

    function emptyRoute(city) {
        return { ...city, travelSeconds: null, distanceKm: null };
    }

    function comparisonElements(key) {
        return {
            name: document.getElementById(`compare-${key}-name`),
            jobs: document.getElementById(`compare-${key}-jobs`),
            workplaces: document.getElementById(`compare-${key}-workplaces`),
            cities: document.getElementById(`compare-${key}-cities`),
            branches: document.getElementById(`compare-${key}-branches`),
        };
    }

    function validOrigin(originId) {
        return typeof originId === 'string' && Object.hasOwn(config.origins, originId);
    }

    function selectOrigin(key, originId) {
        if (!validOrigin(originId)) return;
        const scenario = state.scenarios[key];
        if (scenario.originId === originId) return;
        if (key === 'secondary' && state.scenarios.primary.originId === originId) {
            elements.secondarySelect.value = scenario.originId || '';
            window.alert('Vælg en anden by som udgangspunkt B.');
            return;
        }
        resetScenarioOrigin(scenario, originId);
        if (key === 'primary' && state.scenarios.secondary.originId === originId) {
            removeScenarioMap(state.scenarios.secondary);
            resetScenarioOrigin(state.scenarios.secondary, null);
            elements.secondarySelect.value = '';
        }
        addOriginMarker(scenario);
        applyMobileDisclosureDefaults();
        ensureSupportingData();
        updateUrl();
        fitMapToOrigins();
        render();
        loadRouting(scenario);
        loadIsochrone(scenario);
    }

    function ensureSupportingData() {
        if (state.supportingDataStarted) return;
        state.supportingDataStarted = true;
        Promise.allSettled([loadStatistics(), loadGeography()]).finally(render);
    }

    function applyMobileDisclosureDefaults() {
        if (state.mobileDisclosureApplied || !window.matchMedia('(max-width: 700px)').matches) return;
        state.mobileDisclosureApplied = true;
        document.querySelectorAll('#single-results .sub-fold, #cities-section').forEach((details) => details.removeAttribute('open'));
    }

    function resetScenarioOrigin(scenario, originId) {
        scenario.originId = originId;
        scenario.origin = validOrigin(originId) ? config.origins[originId] : null;
        scenario.cities = config.cities.map(emptyRoute);
        scenario.routingReady = false;
        scenario.routeError = null;
        scenario.geojson = null;
        scenario.zoneMinutes = null;
        invalidateAnalysis(scenario);
        scenario.routingRequest += 1;
        scenario.isochroneRequest += 1;
        removeScenarioLayer(scenario);
    }

    function addOriginMarker(scenario) {
        if (!scenario.origin) return;
        if (scenario.marker) {
            scenario.marker
                .setLatLng([scenario.origin.lat, scenario.origin.lon])
                .setIcon(originIcon(scenario.origin.name, scenario.key))
                .setPopupContent(originPopup(scenario.origin, scenario.key));
            if (!map.hasLayer(scenario.marker)) scenario.marker.addTo(map);
            return;
        }
        scenario.marker = L.marker([scenario.origin.lat, scenario.origin.lon], {
            icon: originIcon(scenario.origin.name, scenario.key),
            zIndexOffset: scenario.key === 'secondary' ? 1100 : 1000,
        }).addTo(map).bindPopup(originPopup(scenario.origin, scenario.key));
    }

    function removeScenarioMap(scenario) {
        removeScenarioLayer(scenario);
        if (scenario.marker && map.hasLayer(scenario.marker)) map.removeLayer(scenario.marker);
    }

    function removeScenarioLayer(scenario) {
        if (scenario.layer && map.hasLayer(scenario.layer)) map.removeLayer(scenario.layer);
        scenario.layer = null;
    }

    async function loadRouting(scenario) {
        if (!scenario.origin || scenario.routingReady) return;
        const requestId = ++scenario.routingRequest;
        try {
            const response = await fetchJson(`${config.endpoints.routing}?action=matrix&origin=${encodeURIComponent(scenario.originId)}`);
            if (requestId !== scenario.routingRequest) return;
            scenario.cities = response.cities;
            scenario.routingReady = true;
            scenario.routeError = response.warning || null;
        } catch (error) {
            if (requestId !== scenario.routingRequest) return;
            scenario.routeError = error.message;
        }
        render();
    }

    async function loadStatistics() {
        try {
            const response = await fetchJson(config.endpoints.statbank);
            state.municipalities = response.municipalities;
            state.statsYear = response.year;
            state.statsReady = true;
            state.statsError = response.warning || null;
            queueActiveAnalyses();
        } catch (error) {
            state.statsError = error.message;
        }
        render();
    }

    async function loadGeography() {
        try {
            const response = await fetchJson(config.endpoints.geography);
            state.geography = response;
            indexGeography(response);
            state.geographyReady = true;
            state.geographyError = response.warning || null;
            queueActiveAnalyses();
        } catch (error) {
            state.geographyError = error.message;
        }
        render();
    }

    async function loadIsochrone(scenario, requestedMinutes = state.minutes) {
        if (!scenario.origin) return;
        const requestId = ++scenario.isochroneRequest;
        scenario.isochroneLoading = true;
        scenario.isochroneError = null;
        invalidateAnalysis(scenario);
        render();
        try {
            const response = await fetchJson(`${config.endpoints.routing}?action=isochrone&minutes=${requestedMinutes}&origin=${encodeURIComponent(scenario.originId)}`);
            if (requestId !== scenario.isochroneRequest) return;
            scenario.geojson = response.geojson;
            scenario.zoneMinutes = requestedMinutes;
            removeScenarioLayer(scenario);
            scenario.layer = L.geoJSON(response.geojson, {
                pane: `isochrone-${scenario.key}`,
                interactive: false,
                style: scenario.key === 'primary'
                    ? { color: '#0f766e', weight: 2.2, opacity: 0.95, fillColor: '#2a9d8f', fillOpacity: state.comparing ? 0.14 : 0.18 }
                    : { color: '#6d5bd0', weight: 2.2, opacity: 0.95, fillColor: '#8776e6', fillOpacity: 0.14 },
            }).addTo(map);
        } catch (error) {
            if (requestId === scenario.isochroneRequest) scenario.isochroneError = error.message;
        } finally {
            if (requestId === scenario.isochroneRequest) scenario.isochroneLoading = false;
            render();
            if (requestId === scenario.isochroneRequest && scenario.geojson) queueAnalysis(scenario);
        }
    }

    async function fetchJson(url) {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('Serveren returnerede et ugyldigt svar.');
        }
        if (!response.ok || payload.ok !== true) throw new Error(payload.error || 'Data kunne ikke hentes.');
        return payload;
    }

    function render() {
        const primary = state.scenarios.primary;
        const secondary = state.scenarios.secondary;
        const hasPrimary = Boolean(primary.origin);
        const hasSecondary = Boolean(secondary.origin);
        elements.slider.disabled = !hasPrimary;
        elements.compareToggle.disabled = !hasPrimary;
        elements.selectionPrompt.hidden = hasPrimary;
        elements.status.hidden = !hasPrimary;
        elements.singleResults.hidden = !hasPrimary || state.comparing;
        elements.comparisonPanel.hidden = !hasPrimary || !state.comparing;
        elements.citiesSection.hidden = !hasPrimary;
        if (!hasPrimary) {
            elements.originIntro.textContent = 'Udforsk og sammenlign arbejdsmarkedsoplande fra alle kommunens byer og lokalsamfund.';
            elements.legendPrimary.textContent = 'Vælg udgangspunkt A';
            elements.reached.textContent = 'Vælg by';
            updateMarkers();
            updateMapLoading();
            return;
        }
        const primaryResult = calculateScenario(primary);
        const secondaryResult = state.comparing && hasSecondary ? calculateScenario(secondary) : null;

        elements.output.value = `${state.minutes} minutter`;
        elements.output.textContent = `${state.minutes} minutter`;
        elements.singleOriginName.textContent = `A · ${primary.origin.name}`;
        elements.originIntro.textContent = state.comparing && hasSecondary
            ? `Sammenlign arbejdsmarkedsoplandet fra ${primary.origin.name} og ${secondary.origin.name}.`
            : `Udforsk arbejdsmarkedsoplandet fra ${primary.origin.name}.`;
        elements.legendPrimary.textContent = primary.origin.name;
        elements.legendSecondary.textContent = hasSecondary ? secondary.origin.name : 'Vælg B';
        elements.citiesContext.textContent = state.comparing && hasSecondary
            ? `${primary.origin.name} / ${secondary.origin.name}`
            : `fra ${primary.origin.name}`;
        elements.reached.textContent = state.comparing && !hasSecondary
            ? 'Vælg by B'
            : state.comparing
            ? primaryResult.ready && secondaryResult?.ready
                ? `A: ${primaryResult.reachedCities.length} · B: ${secondaryResult.reachedCities.length}`
                : 'Beregner begge zoner…'
            : primaryResult.ready
                ? `${primaryResult.reachedCities.length} af ${primary.cities.length} byer nås`
                : 'Beregner zone og tal…';

        renderStatus();
        if (state.comparing && secondaryResult) {
            renderComparison(primaryResult, secondaryResult);
        } else if (state.comparing) {
            renderComparisonWaiting(primaryResult);
        } else {
            renderSingle(primaryResult);
        }
        renderCityList();
        updateMarkers();
        updateMapLoading();
    }

    function calculateScenario(scenario) {
        if (!scenario.origin) return { ready: false, selected: false, error: null, reachedCities: [], jobs: null, workplaces: null, largest: null, branches: [], coverage: {} };
        const reachedCities = scenario.routingReady
            ? scenario.cities.filter((city) => config.reachability === 'zone' && scenario.geojson
                ? pointInZone(city.lon, city.lat, scenario.geojson)
                : city.travelSeconds !== null && city.travelSeconds <= state.minutes * 60)
            : [];
        const analysisCurrent = scenario.analysisStatus === 'ready'
            && scenario.zoneMinutes === state.minutes
            && scenario.analysis;
        const calculationError = state.statsError || state.geographyError || (!scenario.routingReady ? scenario.routeError : null) || scenario.isochroneError || scenario.analysisError;
        if (!analysisCurrent || !scenario.routingReady) {
            return { ready: false, error: calculationError, reachedCities, jobs: null, workplaces: null, largest: null, branches: [], coverage: {} };
        }
        const largest = [...reachedCities]
            .sort((a, b) => estimatedCityJobs(b, scenario.analysis.coverage) - estimatedCityJobs(a, scenario.analysis.coverage))[0] || null;
        return { ready: true, reachedCities, largest, ...scenario.analysis };
    }

    function calculateZoneMetrics(scenario) {
        const coverage = {};
        const branchTotals = new Map();
        let jobs = 0;
        let workplaces = 0;
        let hasJobs = false;
        let hasWorkplaces = false;

        Object.entries(state.municipalities).forEach(([code, municipality]) => {
            const settlements = state.settlementsByMunicipality.get(code) || [];
            const totalPopulation = settlements.reduce((sum, settlement) => sum + settlement.population, 0);
            const coveredPopulation = settlements
                .filter((settlement) => pointInZone(settlement.lon, settlement.lat, scenario.geojson))
                .reduce((sum, settlement) => sum + settlement.population, 0);
            const urbanCoverage = totalPopulation > 0 ? coveredPopulation / totalPopulation : 0;
            const ruralCoverage = polygonCoverage(state.municipalityBoundaries.get(code), scenario.geojson);
            const factor = clamp(
                state.geography.weights.urban * urbanCoverage + state.geography.weights.rural * ruralCoverage,
                0,
                1
            );
            coverage[code] = { factor, urbanCoverage, ruralCoverage };
            if (Number.isFinite(municipality.jobs)) {
                jobs += municipality.jobs * factor;
                hasJobs = true;
            }
            if (Number.isFinite(municipality.workplaces)) {
                workplaces += municipality.workplaces * factor;
                hasWorkplaces = true;
            }
            Object.values(municipality.branches || {}).forEach((branch) => {
                if (!Number.isFinite(branch.jobs)) return;
                const existing = branchTotals.get(branch.code) || { code: branch.code, name: branch.name, jobs: 0 };
                existing.jobs += branch.jobs * factor;
                branchTotals.set(branch.code, existing);
            });
        });

        return {
            jobs: hasJobs ? Math.round(jobs) : null,
            workplaces: hasWorkplaces ? Math.round(workplaces) : null,
            branches: [...branchTotals.values()]
                .map((branch) => ({ ...branch, jobs: Math.round(branch.jobs) }))
                .sort((a, b) => b.jobs - a.jobs)
                .slice(0, 6),
            coverage,
        };
    }

    function indexGeography(geography) {
        state.settlementsByMunicipality = new Map();
        (geography.settlements || []).forEach((settlement) => {
            const code = String(settlement.municipalityCode);
            const group = state.settlementsByMunicipality.get(code) || [];
            group.push(settlement);
            state.settlementsByMunicipality.set(code, group);
        });
        state.municipalityBoundaries = new Map(
            (geography.municipalities?.features || []).map((feature) => [String(feature.properties.code), feature])
        );
    }

    function activeScenarios() {
        return (state.comparing ? Object.values(state.scenarios) : [state.scenarios.primary]).filter((scenario) => scenario.origin);
    }

    function invalidateAnalysis(scenario) {
        scenario.analysisRequest += 1;
        scenario.analysisStatus = 'idle';
        scenario.analysis = null;
        scenario.analysisError = null;
    }

    function queueActiveAnalyses() {
        activeScenarios().forEach(queueAnalysis);
    }

    function queueAnalysis(scenario) {
        if (!state.statsReady || !state.geographyReady || !scenario.geojson || scenario.isochroneLoading || scenario.zoneMinutes !== state.minutes || typeof turf === 'undefined') return;
        const requestId = ++scenario.analysisRequest;
        scenario.analysisStatus = 'loading';
        scenario.analysis = null;
        scenario.analysisError = null;
        render();
        window.setTimeout(() => {
            if (requestId !== scenario.analysisRequest) return;
            try {
                scenario.analysis = calculateZoneMetrics(scenario);
                scenario.analysisStatus = 'ready';
            } catch (error) {
                scenario.analysisError = 'De geografiske tal kunne ikke beregnes.';
                scenario.analysisStatus = 'error';
            }
            render();
        }, 0);
    }

    function pointInZone(lon, lat, geojson) {
        try {
            const point = turf.point([lon, lat]);
            return (geojson.features || []).some((feature) => turf.booleanPointInPolygon(point, feature));
        } catch (error) {
            return false;
        }
    }

    function polygonCoverage(boundary, geojson) {
        if (!boundary) return 0;
        try {
            const boundaryArea = turf.area(boundary);
            if (boundaryArea <= 0) return 0;
            let overlapArea = 0;
            (geojson.features || []).forEach((zone) => {
                const overlap = turf.intersect(turf.featureCollection([boundary, zone]));
                if (overlap) overlapArea += turf.area(overlap);
            });
            return clamp(overlapArea / boundaryArea, 0, 1);
        } catch (error) {
            return 0;
        }
    }

    function estimatedCityJobs(city, coverage) {
        const municipality = state.municipalities[city.municipalityCode];
        return (municipality?.jobs || 0) * (coverage[city.municipalityCode]?.factor || 0);
    }

    function renderStatus() {
        const active = activeScenarios();
        const errors = [state.statsError, state.geographyError, ...active.flatMap((scenario) => [scenario.routeError, scenario.isochroneError, scenario.analysisError])].filter(Boolean);
        if (errors.length > 0) {
            elements.status.textContent = [...new Set(errors)].join(' ');
            elements.status.className = 'data-status is-warning';
        } else if (state.statsReady && state.geographyReady && active.every((scenario) => scenario.routingReady && scenario.analysisStatus === 'ready')) {
            elements.status.textContent = `ERHV2 ${state.statsYear} · BY3 ${state.geography.year} · geografisk 90/10-model.`;
            elements.status.className = 'data-status is-ready';
        } else {
            elements.status.textContent = 'Henter køretider, geografi og jobtal…';
            elements.status.className = 'data-status';
        }
    }

    function renderSingle(result) {
        const placeholder = result.error ? 'Kan ikke beregnes' : 'Beregner…';
        setValue(elements.jobs, result.ready ? formatKnown(result.jobs) : placeholder, !result.ready && !result.error, Boolean(result.error));
        setValue(elements.workplaces, result.ready ? formatKnown(result.workplaces) : placeholder, !result.ready && !result.error, Boolean(result.error));
        setValue(elements.cities, result.ready ? numberFormat.format(result.reachedCities.length) : placeholder, !result.ready && !result.error, Boolean(result.error));
        setValue(elements.largest, result.ready ? (result.largest?.name || 'Ingen endnu') : placeholder, !result.ready && !result.error, Boolean(result.error));
        elements.year.textContent = result.ready && state.statsYear ? `ERHV2 · ${state.statsYear} · anslået` : (result.error ? 'Datafejl' : 'Beregner grundlag…');
        renderBranchChart(elements.branches, result.branches, undefined, !result.ready && !result.error, result.error);
        elements.singleResults.setAttribute('aria-busy', String(!result.ready));
    }

    function renderComparison(primaryResult, secondaryResult) {
        const results = { primary: primaryResult, secondary: secondaryResult };
        const sharedMaximum = Math.max(1, ...primaryResult.branches.map((branch) => branch.jobs), ...secondaryResult.branches.map((branch) => branch.jobs));
        Object.entries(results).forEach(([key, result]) => {
            const target = elements.compare[key];
            target.name.textContent = state.scenarios[key].origin.name;
            const placeholder = result.error ? 'Fejl' : 'Beregner…';
            setValue(target.jobs, result.ready ? formatKnown(result.jobs) : placeholder, !result.ready && !result.error, Boolean(result.error));
            setValue(target.workplaces, result.ready ? formatKnown(result.workplaces) : placeholder, !result.ready && !result.error, Boolean(result.error));
            setValue(target.cities, result.ready ? numberFormat.format(result.reachedCities.length) : placeholder, !result.ready && !result.error, Boolean(result.error));
            renderBranchChart(target.branches, result.branches, sharedMaximum, !result.ready && !result.error, result.error);
        });
        elements.comparisonResults.setAttribute('aria-busy', String(!primaryResult.ready || !secondaryResult.ready));
    }

    function renderComparisonWaiting(primaryResult) {
        const primaryTarget = elements.compare.primary;
        primaryTarget.name.textContent = state.scenarios.primary.origin.name;
        const placeholder = primaryResult.error ? 'Fejl' : 'Beregner…';
        setValue(primaryTarget.jobs, primaryResult.ready ? formatKnown(primaryResult.jobs) : placeholder, !primaryResult.ready && !primaryResult.error, Boolean(primaryResult.error));
        setValue(primaryTarget.workplaces, primaryResult.ready ? formatKnown(primaryResult.workplaces) : placeholder, !primaryResult.ready && !primaryResult.error, Boolean(primaryResult.error));
        setValue(primaryTarget.cities, primaryResult.ready ? numberFormat.format(primaryResult.reachedCities.length) : placeholder, !primaryResult.ready && !primaryResult.error, Boolean(primaryResult.error));
        renderBranchChart(primaryTarget.branches, primaryResult.branches, undefined, !primaryResult.ready && !primaryResult.error, primaryResult.error);
        elements.compare.secondary.name.textContent = 'Vælg udgangspunkt B';
        ['jobs', 'workplaces', 'cities'].forEach((field) => setValue(elements.compare.secondary[field], '—', false));
        elements.compare.secondary.branches.innerHTML = '<p class="empty-state">Vælg en anden by som B.</p>';
        elements.comparisonResults.setAttribute('aria-busy', String(!primaryResult.ready));
    }

    function renderBranchChart(target, branches, maximum = branches[0]?.jobs || 1, loading = false, error = null) {
        if (error) {
            target.innerHTML = '<p class="empty-state is-error">Tallene kunne ikke beregnes.</p>';
            return;
        }
        if (loading) {
            target.innerHTML = '<div class="loading-chart" role="status"><span></span><span></span><span></span><small>Beregner brancher…</small></div>';
            return;
        }
        if (!state.statsReady || branches.length === 0) {
            target.innerHTML = `<p class="empty-state">${state.statsReady ? 'Zonen dækker endnu ingen beregnede job.' : 'Venter på jobtal…'}</p>`;
            return;
        }
        target.innerHTML = branches.map((branch) => `
            <div class="branch-row">
                <div class="branch-meta"><span>${escapeHtml(shortBranchName(branch.name))}</span><strong>${numberFormat.format(branch.jobs)}</strong></div>
                <div class="branch-track"><span style="width:${Math.max(3, (branch.jobs / maximum) * 100).toFixed(1)}%"></span></div>
            </div>
        `).join('');
    }

    function renderCityList() {
        const primary = state.scenarios.primary;
        const secondary = state.scenarios.secondary;
        if (!primary.origin) return;
        const failed = activeScenarios().some((scenario) => (!scenario.routingReady && scenario.routeError) || scenario.isochroneError || scenario.analysisError) || state.statsError || state.geographyError;
        if (failed) {
            elements.cityList.innerHTML = '<p class="empty-state is-error">Bydata kunne ikke beregnes lige nu.</p>';
            elements.cityList.setAttribute('aria-busy', 'false');
            return;
        }
        const pending = activeScenarios().some((scenario) => !scenario.routingReady || scenario.isochroneLoading || scenario.analysisStatus !== 'ready' || scenario.zoneMinutes !== state.minutes);
        if (pending) {
            elements.cityList.innerHTML = '<div class="loading-list" role="status"><span></span><span></span><span></span><p>Beregner byer og køretider…</p></div>';
            elements.cityList.setAttribute('aria-busy', 'true');
            return;
        }
        elements.cityList.setAttribute('aria-busy', 'false');
        const cities = [...primary.cities].sort((a, b) => {
            const aTime = state.comparing ? Math.min(a.travelSeconds ?? Infinity, cityFor(secondary, a.id)?.travelSeconds ?? Infinity) : a.travelSeconds ?? Infinity;
            const bTime = state.comparing ? Math.min(b.travelSeconds ?? Infinity, cityFor(secondary, b.id)?.travelSeconds ?? Infinity) : b.travelSeconds ?? Infinity;
            return aTime - bTime;
        });
        elements.cityList.innerHTML = cities.map((city) => {
            const status = combinedCityStatus(city.id);
            const secondaryCity = cityFor(secondary, city.id);
            const routes = state.comparing
                ? `<span class="city-routes">
                    <span class="city-route"><strong>${formatMinutes(city.travelSeconds)}</strong><small>A · ${formatDistance(city.distanceKm)}</small></span>
                    <span class="city-route"><strong>${formatMinutes(secondaryCity?.travelSeconds)}</strong><small>B · ${formatDistance(secondaryCity?.distanceKm)}</small></span>
                  </span>`
                : `<span class="city-route"><strong>${formatMinutes(city.travelSeconds)}</strong><small>${formatDistance(city.distanceKm)}</small></span>`;
            return `<button class="city-row is-${status}" type="button" data-city-id="${escapeHtml(city.id)}">
                <span class="city-status-dot"></span>
                <span class="city-main"><strong>${escapeHtml(city.name)}</strong><small>${escapeHtml(city.municipality)} Kommune</small></span>
                ${routes}
            </button>`;
        }).join('');
        elements.cityList.querySelectorAll('[data-city-id]').forEach((button) => {
            button.addEventListener('click', () => {
                const city = config.cities.find((item) => item.id === button.dataset.cityId);
                const marker = state.markers.get(button.dataset.cityId);
                if (city && marker) {
                    map.flyTo([city.lat, city.lon], Math.max(map.getZoom(), 10), { duration: 0.5 });
                    marker.openPopup();
                }
            });
        });
    }

    function updateMarkers() {
        config.cities.forEach((city) => {
            const marker = state.markers.get(city.id);
            if (!marker) return;
            const status = combinedCityStatus(city.id);
            marker.setStyle(markerStyle(status, status === 'muted' ? 0 : 1));
            marker.setRadius(status === 'muted' ? 5 : 9);
            marker.setPopupContent(cityPopup(city));
            const tooltip = marker.getTooltip();
            if (tooltip) {
                tooltip.options.offset = L.point(0, -(marker.getRadius() + 2));
                const node = tooltip.getElement();
                if (node) node.className = `leaflet-tooltip city-label is-${status}`;
            }
        });
    }

    function combinedCityStatus(cityId) {
        if (activeScenarios().some((scenario) => !scenario.routingReady || scenario.isochroneLoading || scenario.analysisStatus !== 'ready' || scenario.zoneMinutes !== state.minutes)) return 'muted';
        const primary = cityFor(state.scenarios.primary, cityId);
        const secondary = cityFor(state.scenarios.secondary, cityId);
        const primaryReached = cityReached(state.scenarios.primary, primary);
        if (!state.comparing) return singleCityStatus(state.scenarios.primary, primary);
        const secondaryReached = cityReached(state.scenarios.secondary, secondary);
        if (primaryReached && secondaryReached) return 'both';
        if (primaryReached) return 'reached';
        if (secondaryReached) return 'secondary';
        const near = [primary, secondary].some((city) => city && city.travelSeconds !== null && Math.abs(city.travelSeconds / 60 - state.minutes) <= 5);
        return near ? 'near' : 'muted';
    }

    function singleCityStatus(scenario, city) {
        if (!city || city.travelSeconds === null) return 'muted';
        if (config.reachability === 'zone') return cityReached(scenario, city) ? 'reached' : 'muted';
        const difference = city.travelSeconds / 60 - state.minutes;
        if (Math.abs(difference) <= 5) return 'near';
        return difference <= 0 ? 'reached' : 'muted';
    }

    function cityReached(scenario, city) {
        if (config.reachability === 'zone' && scenario.geojson && city) return pointInZone(city.lon, city.lat, scenario.geojson);
        return city?.travelSeconds !== null && city?.travelSeconds !== undefined && city.travelSeconds <= state.minutes * 60;
    }

    function cityFor(scenario, id) {
        if (!scenario?.origin) return null;
        return scenario.cities.find((city) => city.id === id);
    }

    function markerStyle(status, scale) {
        if (status === 'reached') return { radius: 7 + scale * 2, color: '#fff', weight: 2.5, fillColor: '#0f766e', fillOpacity: 0.95 };
        if (status === 'secondary') return { radius: 7 + scale * 2, color: '#fff', weight: 2.5, fillColor: '#6d5bd0', fillOpacity: 0.95 };
        if (status === 'both') return { radius: 7 + scale * 2, color: '#0f766e', weight: 3, fillColor: '#6d5bd0', fillOpacity: 0.95 };
        if (status === 'near') return { radius: 8, color: '#fff7df', weight: 3, fillColor: '#e49a23', fillOpacity: 0.95 };
        return { radius: 5, color: '#64727d', weight: 1.5, fillColor: '#89949d', fillOpacity: 0.55 };
    }

    function cityPopup(city) {
        const primary = cityFor(state.scenarios.primary, city.id) || city;
        const secondary = cityFor(state.scenarios.secondary, city.id);
        const routes = state.comparing
            ? `<span>A: ${formatMinutes(primary.travelSeconds)} · ${formatDistance(primary.distanceKm)}</span><span>B: ${formatMinutes(secondary?.travelSeconds)} · ${formatDistance(secondary?.distanceKm)}</span>`
            : `<span>${formatMinutes(primary.travelSeconds)} · ${formatDistance(primary.distanceKm)}</span>`;
        return `<div class="city-popup"><strong>${escapeHtml(city.name)}</strong>${routes}<span>${escapeHtml(city.municipality)} Kommune</span></div>`;
    }

    function applyComparisonMode() {
        document.body.classList.toggle('is-comparing', state.comparing);
        elements.secondaryControl.hidden = !state.comparing;
        elements.legendSecondaryWrap.hidden = !state.comparing;
        elements.singleResults.hidden = state.comparing || !state.scenarios.primary.origin;
        elements.comparisonPanel.hidden = !state.comparing || !state.scenarios.primary.origin;
        elements.compareToggle.setAttribute('aria-pressed', String(state.comparing));
        elements.compareToggle.textContent = state.comparing ? 'Luk sammenligning' : 'Sammenlign A/B';
        window.setTimeout(() => map.invalidateSize(), 0);
    }

    function updateMapLoading() {
        const active = activeScenarios();
        if (!state.scenarios.primary.origin) {
            elements.mapLoading.textContent = 'Vælg en by for at starte';
            elements.mapLoading.classList.remove('is-hidden', 'is-error');
            elements.mapLoading.classList.add('is-prompt');
        } else if (state.comparing && !state.scenarios.secondary.origin) {
            elements.mapLoading.textContent = 'Vælg udgangspunkt B';
            elements.mapLoading.classList.remove('is-hidden', 'is-error');
            elements.mapLoading.classList.add('is-prompt');
        } else if (active.some((scenario) => scenario.isochroneLoading || scenario.analysisStatus === 'loading')) {
            elements.mapLoading.textContent = state.comparing ? `Beregner to ${state.minutes}-minutters zoner…` : `Beregner ${state.minutes}-minutters zone…`;
            elements.mapLoading.classList.remove('is-hidden', 'is-error', 'is-prompt');
        } else if (active.some((scenario) => scenario.isochroneError)) {
            elements.mapLoading.textContent = 'En køretidszone kunne ikke hentes';
            elements.mapLoading.classList.remove('is-hidden');
            elements.mapLoading.classList.add('is-error');
        } else {
            elements.mapLoading.classList.add('is-hidden');
            elements.mapLoading.classList.remove('is-error', 'is-prompt');
        }
    }

    function updateUrl() {
        const url = new URL(window.location.href);
        if (state.scenarios.primary.originId) url.searchParams.set('origin', state.scenarios.primary.originId);
        else url.searchParams.delete('origin');
        if (state.comparing && state.scenarios.secondary.originId) url.searchParams.set('compare', state.scenarios.secondary.originId);
        else url.searchParams.delete('compare');
        window.history.replaceState({}, '', url);
    }

    function updateSliderProgress() {
        const progress = ((state.minutes - config.slider.min) / (config.slider.max - config.slider.min)) * 100;
        elements.slider.style.setProperty('--progress', `${progress}%`);
    }

    function originIcon(name, key) {
        return L.divIcon({
            className: `origin-marker-wrap${key === 'secondary' ? ' is-secondary' : ''}`,
            html: `<span class="origin-pulse"></span><span class="origin-marker"></span><span class="origin-label">${escapeHtml(name)}</span>`,
            iconSize: [18, 18],
            iconAnchor: [9, 9],
        });
    }

    function originPopup(origin, key) {
        return `<strong>${escapeHtml(origin.name)}</strong><br>Udgangspunkt ${key === 'secondary' ? 'B' : 'A'} for køretiderne.`;
    }

    function fitMapToOrigins() {
        const points = [...config.cities.map((city) => [city.lat, city.lon])];
        if (state.scenarios.primary.origin) points.push([state.scenarios.primary.origin.lat, state.scenarios.primary.origin.lon]);
        if (state.comparing && state.scenarios.secondary.origin) points.push([state.scenarios.secondary.origin.lat, state.scenarios.secondary.origin.lon]);
        map.fitBounds(L.latLngBounds(points).pad(0.08), { padding: [30, 30] });
    }

    function formatMinutes(seconds) {
        return seconds === null || seconds === undefined ? '—' : `${Math.round(seconds / 60)} min`;
    }

    function formatDistance(distance) {
        return distance === null || distance === undefined ? '—' : `${numberFormat.format(distance)} km`;
    }

    function formatKnown(value) {
        return value === null ? '—' : numberFormat.format(value);
    }

    function setValue(element, value, loading, error = false) {
        element.textContent = value;
        element.classList.toggle('is-loading-value', loading);
        element.classList.toggle('is-error-value', error);
    }

    function shortBranchName(name) {
        return name
            .replace('Industri, råstofindvinding og forsyningsvirksomhed', 'Industri og forsyning')
            .replace('Offentlig administration, undervisning og sundhed', 'Offentlig service, undervisning og sundhed');
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }
})();
