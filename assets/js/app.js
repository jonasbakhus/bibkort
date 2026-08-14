(() => {
    'use strict';

    const configElement = document.getElementById('app-config');
    if (!configElement || typeof L === 'undefined') return;

    const config = JSON.parse(configElement.textContent);
    const CITY_LABEL_MIN_ZOOM = 11;
    const NEAR_MARGIN_MINUTES = Number(config.nearMarginMinutes || 5);
    const RURAL_TAIL_MIN_COVERAGE = 0.95;
    const RURAL_TAIL_MAX_JOBS = 100;
    const params = new URLSearchParams(window.location.search);
    const initialHeatmapActive = params.get('view') === 'heatmap';
    const primaryId = validOrigin(params.get('origin')) ? params.get('origin') : null;
    let secondaryId = primaryId && validOrigin(params.get('compare')) ? params.get('compare') : null;
    if (secondaryId === primaryId) secondaryId = null;
    const requestedMinutes = params.has('minutes') ? Number(params.get('minutes')) : config.slider.default;
    const initialMinutes = Number.isFinite(requestedMinutes)
        ? Math.min(config.slider.max, Math.max(config.slider.min, config.slider.min + Math.round((requestedMinutes - config.slider.min) / config.slider.step) * config.slider.step))
        : config.slider.default;

    const state = {
        minutes: initialMinutes,
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
        cityCatalog: new Map(config.cities.map((city) => [city.id, city])),
        markers: new Map(),
        touchMarkers: new Map(),
        boundaryMarkers: new Map(),
        boundaryTouchMarkers: new Map(),
        municipalityLayer: null,
        contextMunicipalityLayer: null,
        supportingDataStarted: false,
        mobileDisclosureApplied: false,
        heatmap: {
            active: initialHeatmapActive,
            loading: false,
            ready: false,
            error: null,
            data: null,
            layer: null,
            minuteIndex: 0,
            low: 0,
            high: 0,
        },
        scenarios: {
            primary: makeScenario('primary', primaryId),
            secondary: makeScenario('secondary', secondaryId),
        },
    };

    const elements = {
        slider: document.getElementById('time-slider'),
        output: document.getElementById('time-output'),
        mapSlider: document.getElementById('map-time-slider'),
        mapOutput: document.getElementById('map-time-output'),
        reached: document.getElementById('reached-summary'),
        status: document.getElementById('data-status'),
        jobs: document.getElementById('metric-jobs'),
        workplaces: document.getElementById('metric-workplaces'),
        cities: document.getElementById('metric-cities'),
        largest: document.getElementById('metric-largest'),
        year: document.getElementById('metric-year'),
        branches: document.getElementById('branch-chart'),
        municipalityBreakdown: document.getElementById('municipality-breakdown'),
        cityList: document.getElementById('city-list'),
        mapLoading: document.getElementById('map-loading'),
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
        mapSizeToggle: document.getElementById('map-size-toggle'),
        heatmapToggle: document.getElementById('heatmap-toggle'),
        mapHeatmapToggle: document.getElementById('map-heatmap-toggle'),
        heatmapDescription: document.getElementById('heatmap-description'),
        standardLegend: document.querySelector('.map-legend'),
        heatmapLegend: document.getElementById('heatmap-legend'),
        heatmapMin: document.getElementById('heatmap-min'),
        heatmapMax: document.getElementById('heatmap-max'),
        comparisonBranchesCompact: document.getElementById('comparison-branches-compact'),
        comparisonMunicipalities: document.getElementById('comparison-municipalities'),
        comparisonKeyPrimary: document.getElementById('comparison-key-primary'),
        comparisonKeySecondary: document.getElementById('comparison-key-secondary'),
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
    map.createPane('municipality-context');
    map.createPane('municipality-boundary');
    map.createPane('heatmap-surface');
    map.getPane('isochrone-primary').style.zIndex = 350;
    map.getPane('isochrone-secondary').style.zIndex = 351;
    map.getPane('municipality-context').style.zIndex = 340;
    map.getPane('municipality-boundary').style.zIndex = 360;
    map.getPane('heatmap-surface').style.zIndex = 345;

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);
    if (config.variant === 'google') {
        map.attributionControl.addAttribution('<span class="google-maps-attribution" translate="no">Google Maps</span>');
    }

    const HeatSurfaceLayer = L.Layer.extend({
        initialize(points, cellKm) {
            this.points = points;
            this.cellKm = Number(cellKm) || 0.5;
            const latitudes = points.map((point) => Number(point[0])).filter(Number.isFinite);
            const gridLatitude = latitudes.length > 0
                ? (Math.min(...latitudes) + Math.max(...latitudes)) / 2
                : 56.3;
            this.cellLatStep = this.cellKm / 111.32;
            this.cellLonStep = this.cellKm / (111.32 * Math.max(0.2, Math.cos(gridLatitude * Math.PI / 180)));
            this.minuteIndex = 0;
            this.low = 0;
            this.high = 1;
            this.frame = null;
        },
        onAdd(activeMap) {
            this.map = activeMap;
            this.canvas = L.DomUtil.create('canvas', 'leaflet-heat-surface leaflet-zoom-animated');
            activeMap.getPane('heatmap-surface').appendChild(this.canvas);
            activeMap.on('moveend zoomend resize', this.scheduleRedraw, this);
            this.scheduleRedraw();
        },
        onRemove(activeMap) {
            activeMap.off('moveend zoomend resize', this.scheduleRedraw, this);
            if (this.frame) window.cancelAnimationFrame(this.frame);
            this.canvas?.remove();
            this.canvas = null;
            this.map = null;
        },
        setMinuteIndex(index) {
            this.minuteIndex = index;
            const values = this.points
                .map((point) => Number(point[2]?.[index] || 0))
                .filter(Number.isFinite)
                .sort((a, b) => a - b);
            this.low = heatQuantile(values, 0.05);
            this.high = Math.max(this.low + 1, heatQuantile(values, 0.95));
            this.scheduleRedraw();
            return { low: this.low, high: this.high };
        },
        scheduleRedraw() {
            if (!this.map || this.frame) return;
            this.frame = window.requestAnimationFrame(() => {
                this.frame = null;
                this.redraw();
            });
        },
        redraw() {
            if (!this.map || !this.canvas) return;
            const size = this.map.getSize();
            const ratio = Math.min(2, window.devicePixelRatio || 1);
            const topLeft = this.map.containerPointToLayerPoint([0, 0]);
            L.DomUtil.setPosition(this.canvas, topLeft);
            this.canvas.width = Math.round(size.x * ratio);
            this.canvas.height = Math.round(size.y * ratio);
            this.canvas.style.width = `${size.x}px`;
            this.canvas.style.height = `${size.y}px`;
            const context = this.canvas.getContext('2d');
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.clearRect(0, 0, size.x, size.y);
            context.globalAlpha = 0.72;
            for (const point of this.points) {
                const screen = this.map.latLngToContainerPoint([point[0], point[1]]);
                if (screen.x < -20 || screen.y < -20 || screen.x > size.x + 20 || screen.y > size.y + 20) continue;
                const northWest = this.map.latLngToContainerPoint([
                    point[0] + this.cellLatStep / 2,
                    point[1] - this.cellLonStep / 2,
                ]);
                const southEast = this.map.latLngToContainerPoint([
                    point[0] - this.cellLatStep / 2,
                    point[1] + this.cellLonStep / 2,
                ]);
                const left = Math.min(northWest.x, southEast.x) - 0.2;
                const top = Math.min(northWest.y, southEast.y) - 0.2;
                const width = Math.max(1, Math.abs(southEast.x - northWest.x) + 0.4);
                const height = Math.max(1, Math.abs(southEast.y - northWest.y) + 0.4);
                const value = Number(point[2]?.[this.minuteIndex] || 0);
                const fraction = clamp((value - this.low) / (this.high - this.low), 0, 1);
                context.fillStyle = heatColor(fraction);
                context.fillRect(left, top, width, height);
            }
            context.globalAlpha = 1;
        },
        nearest(latlng) {
            let nearest = null;
            let nearestDistance = Infinity;
            for (const point of this.points) {
                const distance = this.map.distance(latlng, L.latLng(point[0], point[1]));
                if (distance < nearestDistance) {
                    nearest = point;
                    nearestDistance = distance;
                }
            }
            return nearestDistance <= Math.max(900, this.cellKm * 1600) ? nearest : null;
        },
    });

    loadMunicipalityBoundary();

    config.cities.forEach(addCityMarker);
    function addCityMarker(city) {
        if (!city?.id || state.markers.has(city.id)) return;
        state.cityCatalog.set(city.id, city);
        const marker = L.circleMarker([city.lat, city.lon], markerStyle('muted', 0)).addTo(map);
        marker.bindPopup(cityPopup(city));
        marker.bindTooltip(city.name, {
            className: 'city-name-label',
            direction: 'top',
            offset: [0, -7],
            opacity: 0.92,
            interactive: false,
        });
        state.markers.set(city.id, marker);
        if (city.municipalityCode === '665' && validOrigin(city.id)) {
            const touchMarker = L.marker([city.lat, city.lon], {
                icon: L.divIcon({ className: 'city-touch-target', iconSize: [36, 36], iconAnchor: [18, 18] }),
                keyboard: true,
                title: city.name,
                alt: city.name,
            }).addTo(map).bindPopup(cityPopup(city));
            state.touchMarkers.set(city.id, touchMarker);
        }
    }

    function syncRoutingCities(cities) {
        cities.forEach((city) => {
            state.cityCatalog.set(city.id, city);
            addCityMarker(city);
        });
    }
    Object.values(config.origins).filter((origin) => origin.type === 'boundary').forEach((origin) => {
        const marker = L.circleMarker([origin.lat, origin.lon], {
            radius: 4.5,
            color: '#fff',
            weight: 2,
            fillColor: '#f2673a',
            fillOpacity: 1,
        }).addTo(map).bindPopup(boundaryPointPopup(origin));
        const touchMarker = L.marker([origin.lat, origin.lon], {
            icon: L.divIcon({ className: 'city-touch-target boundary-touch-target', iconSize: [34, 34], iconAnchor: [17, 17] }),
            keyboard: true,
            title: origin.name,
            alt: origin.name,
        }).addTo(map).bindPopup(boundaryPointPopup(origin));
        state.boundaryMarkers.set(origin.id, marker);
        state.boundaryTouchMarkers.set(origin.id, touchMarker);
    });
    map.on('zoomend', updateCityLabels);
    updateCityLabels();
    map.on('popupopen', ({ popup }) => {
        popup.getElement()?.querySelectorAll('[data-select-origin]').forEach((button) => {
            button.addEventListener('click', () => {
                const key = button.dataset.selectOrigin;
                const originId = button.dataset.originId;
                if (!['primary', 'secondary', 'compare'].includes(key) || !validOrigin(originId)) return;
                if (key === 'compare') {
                    if (!state.scenarios.primary.origin || state.scenarios.primary.originId === originId) return;
                    state.comparing = true;
                    applyComparisonMode();
                    selectOrigin('secondary', originId);
                    map.closePopup();
                    return;
                }
                selectOrigin(key, originId);
                map.closePopup();
            });
        });
    });

    if (state.scenarios.primary.origin) addOriginMarker(state.scenarios.primary);
    if (state.comparing && state.scenarios.secondary.origin) addOriginMarker(state.scenarios.secondary);
    fitMapToOrigins();

    elements.slider.min = config.slider.min;
    elements.slider.max = config.slider.max;
    elements.slider.step = config.slider.step;
    elements.slider.value = state.minutes;
    elements.mapSlider.value = state.minutes;
    elements.primarySelect.value = state.scenarios.primary.originId || '';
    elements.secondarySelect.value = state.scenarios.secondary.originId || '';
    applyComparisonMode();
    updateSliderProgress();
    updateUrl();

    elements.primarySelect.addEventListener('change', () => selectOrigin('primary', elements.primarySelect.value));
    elements.secondarySelect.addEventListener('change', () => selectOrigin('secondary', elements.secondarySelect.value));
    elements.mapSizeToggle?.addEventListener('click', toggleMobileMap);
    elements.heatmapToggle?.addEventListener('click', toggleHeatmap);
    elements.mapHeatmapToggle?.addEventListener('click', toggleHeatmap);
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
        updateUrl();
        if (state.heatmap.active) {
            updateHeatmapMinute();
        }
        activeScenarios().forEach((scenario) => {
            scenario.isochroneRequest += 1;
            scenario.isochroneLoading = true;
            scenario.isochroneError = null;
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
    elements.mapSlider.addEventListener('input', () => {
        elements.slider.value = elements.mapSlider.value;
        elements.slider.dispatchEvent(new Event('input', { bubbles: true }));
    });

    updateHeatmapControls();
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
    if (state.heatmap.active) loadHeatmap();
    render();

    function makeScenario(key, originId) {
        return {
            key,
            originId,
            origin: validOrigin(originId) ? config.origins[originId] : null,
            cities: config.cities.map(emptyRoute),
            routingReady: false,
            routingSettlementScope: false,
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
            analysisWorker: null,
            analysisWorkerReject: null,
        };
    }

    function emptyRoute(city) {
        return { ...city, travelSeconds: null, distanceKm: null };
    }

    function comparisonElements(key) {
        return {
            badge: document.getElementById(`compare-${key}-badge`),
            name: document.getElementById(`compare-${key}-name`),
            jobs: document.getElementById(`compare-${key}-jobs`),
            workplaces: document.getElementById(`compare-${key}-workplaces`),
            cities: document.getElementById(`compare-${key}-cities`),
        };
    }

    function validOrigin(originId) {
        return typeof originId === 'string' && Object.hasOwn(config.origins, originId);
    }

    function selectOrigin(key, originId) {
        if (originId === '') {
            clearOrigin(key);
            return;
        }
        if (!validOrigin(originId)) return;
        const scenario = state.scenarios[key];
        if (scenario.originId === originId) return;
        if (key === 'secondary' && state.scenarios.primary.originId === originId) {
            elements.secondarySelect.value = scenario.originId || '';
            window.alert('Vælg en anden by som udgangspunkt B.');
            return;
        }
        resetScenarioOrigin(scenario, originId);
        if (key === 'primary') elements.primarySelect.value = originId;
        else elements.secondarySelect.value = originId;
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

    function clearOrigin(key) {
        const scenario = state.scenarios[key];
        removeScenarioMap(scenario);
        resetScenarioOrigin(scenario, null);

        if (key === 'primary') {
            elements.primarySelect.value = '';
            removeScenarioMap(state.scenarios.secondary);
            resetScenarioOrigin(state.scenarios.secondary, null);
            elements.secondarySelect.value = '';
            state.comparing = false;
            applyComparisonMode();
        } else {
            elements.secondarySelect.value = '';
        }

        updateUrl();
        fitMapToOrigins();
        render();
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
        scenario.routingSettlementScope = false;
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
            const response = await fetchJson(`${config.endpoints.routing}?action=matrix&scope=settlements&origin=${encodeURIComponent(scenario.originId)}`);
            if (requestId !== scenario.routingRequest) return;
            scenario.cities = response.cities;
            scenario.routingSettlementScope = response.scope === 'settlements';
            syncRoutingCities(response.cities);
            scenario.routingReady = true;
            scenario.routeError = response.warning || null;
            // Jobanalysen bruger rutematricen til at afgøre, hvilke byer der er nået.
            // Genstart derfor altid analysen, når matricen er klar, uanset hvilken
            // rækkefølge routing, geografi og isokrone blev hentet i.
            invalidateAnalysis(scenario);
            queueAnalysis(scenario);
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

    async function loadMunicipalityBoundary() {
        try {
            const response = await fetchJson(config.endpoints.boundary);
            if (state.municipalityLayer) map.removeLayer(state.municipalityLayer);
            state.municipalityLayer = L.geoJSON(response.geojson, {
                pane: 'municipality-boundary',
                interactive: false,
                style: { color: '#f2673a', weight: 3, opacity: 0.95, fill: false, dashArray: '9 6', lineCap: 'round' },
            }).addTo(map);
            if (!state.scenarios.primary.origin) {
                map.fitBounds(state.municipalityLayer.getBounds().pad(0.1), { padding: [24, 24], maxZoom: 10 });
            }
        } catch (error) {
            // Kortet og beregningerne kan fortsat bruges, hvis det dekorative grænselag fejler.
        }
    }

    async function loadGeography() {
        try {
            const response = await fetchJson(config.endpoints.geography);
            state.geography = response;
            indexGeography(response);
            renderContextMunicipalityBoundaries(response);
            state.geographyReady = true;
            state.geographyError = response.warning || null;
            queueActiveAnalyses();
        } catch (error) {
            state.geographyError = error.message;
        }
        render();
    }

    async function toggleHeatmap() {
        state.heatmap.active = !state.heatmap.active;
        updateHeatmapControls();
        updateUrl();
        if (state.heatmap.active) {
            await loadHeatmap();
        } else {
            if (state.heatmap.layer && map.hasLayer(state.heatmap.layer)) map.removeLayer(state.heatmap.layer);
        }
        render();
    }

    async function loadHeatmap() {
        if (state.heatmap.ready) {
            if (state.heatmap.active && state.heatmap.layer && !map.hasLayer(state.heatmap.layer)) state.heatmap.layer.addTo(map);
            updateHeatmapMinute();
            return;
        }
        if (state.heatmap.loading) return;
        state.heatmap.loading = true;
        state.heatmap.error = null;
        render();
        try {
            const response = await fetchJson(config.endpoints.heatmap);
            if (!Array.isArray(response.points) || !Array.isArray(response.minutes) || response.points.length === 0) {
                throw new Error('Heatmap-data har et ugyldigt format.');
            }
            state.heatmap.data = response;
            state.heatmap.layer = new HeatSurfaceLayer(response.points, response.cellKm);
            state.heatmap.ready = true;
            if (state.heatmap.active) state.heatmap.layer.addTo(map);
            updateHeatmapMinute();
        } catch (error) {
            state.heatmap.error = error.message;
        } finally {
            state.heatmap.loading = false;
            updateHeatmapControls();
            render();
        }
    }

    function updateHeatmapMinute() {
        if (!state.heatmap.ready || !state.heatmap.layer) return;
        const available = state.heatmap.data.minutes.map(Number);
        let index = available.indexOf(state.minutes);
        if (index < 0) {
            index = available.reduce((best, minute, candidate) => Math.abs(minute - state.minutes) < Math.abs(available[best] - state.minutes) ? candidate : best, 0);
        }
        state.heatmap.minuteIndex = index;
        const scale = state.heatmap.layer.setMinuteIndex(index);
        state.heatmap.low = scale.low;
        state.heatmap.high = scale.high;
        elements.heatmapMin.textContent = `${numberFormat.format(Math.round(scale.low))} job`;
        elements.heatmapMax.textContent = `${numberFormat.format(Math.round(scale.high))} job eller flere`;
        updateHeatmapControls();
    }

    function updateHeatmapControls() {
        [elements.heatmapToggle, elements.mapHeatmapToggle].forEach((button) => {
            if (button) button.setAttribute('aria-pressed', String(state.heatmap.active));
        });
        const label = elements.heatmapToggle?.querySelector('strong');
        if (label) label.textContent = state.heatmap.active ? 'Skjul heatmap' : 'Vis heatmap for kommunen';
        if (elements.heatmapDescription) elements.heatmapDescription.hidden = !state.heatmap.active;
        if (elements.standardLegend) elements.standardLegend.hidden = false;
        if (elements.heatmapLegend) elements.heatmapLegend.hidden = !state.heatmap.active || !state.heatmap.ready;
    }

    map.on('click', (event) => {
        if (!state.heatmap.active || !state.heatmap.ready || !state.heatmap.layer) return;
        const clickTarget = event.originalEvent?.target;
        if (clickTarget instanceof Element && clickTarget.closest('.leaflet-interactive, .leaflet-marker-icon, .leaflet-popup')) return;
        const point = state.heatmap.layer.nearest(event.latlng);
        if (!point) return;
        const value = Number(point[2]?.[state.heatmap.minuteIndex] || 0);
        L.popup({ closeButton: true, maxWidth: 260 })
            .setLatLng([point[0], point[1]])
            .setContent(`<div class="heatmap-popup"><strong>${numberFormat.format(Math.round(value))} anslåede job</strong><span>kan nås inden for ${state.minutes} minutter fra dette 500-meterfelt.</span><span>Vælg en nærliggende by for den fulde zoneanalyse.</span></div>`)
            .openOn(map);
    });

    function renderContextMunicipalityBoundaries(geography) {
        if (state.contextMunicipalityLayer) map.removeLayer(state.contextMunicipalityLayer);
        const features = (geography.municipalities?.features || [])
            .filter((feature) => String(feature.properties?.code) !== '665');
        state.contextMunicipalityLayer = L.geoJSON({ type: 'FeatureCollection', features }, {
            pane: 'municipality-context',
            interactive: false,
            style: { color: '#526d7a', weight: 1.35, opacity: 0.72, fill: false, dashArray: '4 5', lineCap: 'round' },
        }).addTo(map);
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
            });
            scenario.layer.addTo(map);
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
        elements.slider.disabled = !hasPrimary && !state.heatmap.active;
        elements.mapSlider.disabled = !hasPrimary && !state.heatmap.active;
        elements.compareToggle.disabled = !hasPrimary;
        elements.selectionPrompt.hidden = hasPrimary || state.heatmap.active;
        elements.status.hidden = !hasPrimary;
        elements.singleResults.hidden = !hasPrimary || state.comparing;
        elements.comparisonPanel.hidden = !hasPrimary || !state.comparing;
        elements.citiesSection.hidden = !hasPrimary;
        elements.output.value = `${state.minutes} minutter`;
        elements.output.textContent = `${state.minutes} minutter`;
        elements.mapOutput.value = `${state.minutes} minutter`;
        elements.mapOutput.textContent = `${state.minutes} minutter`;
        updateHeatmapControls();
        if (!hasPrimary) {
            elements.legendPrimary.textContent = 'Vælg udgangspunkt A';
            elements.reached.textContent = state.heatmap.active ? 'Heatmap' : 'Vælg by';
            updateMarkers();
            updateMapLoading();
            return;
        }
        const primaryResult = calculateScenario(primary);
        const secondaryResult = state.comparing && hasSecondary ? calculateScenario(secondary) : null;

        elements.singleOriginName.textContent = `A · ${primary.origin.name}`;
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
                ? `${primaryResult.reachedCities.length} byer nås`
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
        if (!scenario.origin) return { ready: false, selected: false, error: null, reachedCities: [], jobs: null, workplaces: null, largest: null, branches: [], municipalityBreakdown: [], coverage: {} };
        const reachedCities = scenario.routingReady
            ? scenario.cities.filter((city) => cityReached(scenario, city))
            : [];
        const analysisCurrent = scenario.analysisStatus === 'ready'
            && scenario.zoneMinutes === state.minutes
            && scenario.analysis;
        const calculationError = state.statsError || state.geographyError || (!scenario.routingReady ? scenario.routeError : null) || scenario.isochroneError || scenario.analysisError;
        if (!analysisCurrent || !scenario.routingReady) {
            return { ready: false, error: calculationError, reachedCities, jobs: null, workplaces: null, largest: null, branches: [], municipalityBreakdown: [], coverage: {} };
        }
        const largest = [...reachedCities]
            .sort((a, b) => estimatedSettlementJobs(b) - estimatedSettlementJobs(a))[0] || null;
        return { ready: true, reachedCities, largest, ...scenario.analysis };
    }

    async function calculateZoneMetrics(scenario, requestId) {
        const coverage = {};
        const branchTotals = new Map();
        const municipalityBreakdown = [];
        let workplaces = 0;
        let hasJobs = false;
        let hasWorkplaces = false;
        const ruralGeographyByCode = await calculateScenarioCoverage(scenario, requestId);
        if (requestId !== scenario.analysisRequest || !ruralGeographyByCode) return null;

        const municipalityEntries = Object.entries(state.municipalities);
        for (let index = 0; index < municipalityEntries.length; index += 1) {
            if (requestId !== scenario.analysisRequest) return null;
            const [code, municipality] = municipalityEntries[index];
            const settlements = state.settlementsByMunicipality.get(code) || [];
            const totalUrbanWeight = settlements.reduce((sum, settlement) => sum + settlementUrbanWeight(settlement), 0);
            const coveredSettlements = settlements.filter((settlement) => settlementReached(scenario, settlement));
            const coveredUrbanWeight = coveredSettlements.reduce((sum, settlement) => sum + settlementUrbanWeight(settlement), 0);
            const urbanCoverage = totalUrbanWeight > 0 ? coveredUrbanWeight / totalUrbanWeight : 0;
            const ruralGeography = ruralGeographyByCode[code] || { fraction: 0, overlapAreaM2: 0, municipalityAreaM2: 0 };
            const ruralCoverage = ruralGeography.fraction;
            const uncoveredRuralJobs = Number.isFinite(municipality.jobs)
                ? municipality.jobs * state.geography.weights.rural * (1 - ruralCoverage)
                : null;
            const ruralTailAdjusted = settlements.length > 0
                && coveredSettlements.length === settlements.length
                && ruralCoverage >= RURAL_TAIL_MIN_COVERAGE
                && uncoveredRuralJobs > 0
                && uncoveredRuralJobs < RURAL_TAIL_MAX_JOBS;
            const effectiveRuralCoverage = ruralTailAdjusted ? 1 : ruralCoverage;
            const urbanFactor = state.geography.weights.urban * urbanCoverage;
            const ruralFactor = state.geography.weights.rural * effectiveRuralCoverage;
            const factor = clamp(
                urbanFactor + ruralFactor,
                0,
                1
            );
            const urbanJobs = Number.isFinite(municipality.jobs) ? municipality.jobs * urbanFactor : null;
            const ruralJobs = Number.isFinite(municipality.jobs) ? municipality.jobs * ruralFactor : null;
            const urbanWorkplaces = Number.isFinite(municipality.workplaces) ? municipality.workplaces * urbanFactor : null;
            const ruralWorkplaces = Number.isFinite(municipality.workplaces) ? municipality.workplaces * ruralFactor : null;
            coverage[code] = {
                factor,
                urbanCoverage,
                ruralCoverage,
                effectiveRuralCoverage,
                urbanFactor,
                ruralFactor,
                ruralTailAdjusted,
                uncoveredRuralJobs,
                overlapAreaM2: ruralGeography.overlapAreaM2,
                municipalityAreaM2: ruralGeography.municipalityAreaM2,
            };
            if ((ruralCoverage > 0 || coveredSettlements.length > 0) && Number.isFinite(municipality.jobs)) {
                const totalJobs = Math.round(municipality.jobs);
                const urbanBudget = Math.max(0, Math.round(urbanJobs || 0));
                const cityJobs = coveredSettlements
                    .map((settlement) => ({
                        name: settlement.name,
                        municipalityCode: settlement.municipalityCode,
                        rawJobs: totalUrbanWeight > 0
                            ? municipality.jobs * state.geography.weights.urban * settlementUrbanWeight(settlement) / totalUrbanWeight
                            : 0,
                    }))
                    .map((settlement) => ({ ...settlement, jobs: Math.floor(settlement.rawJobs), fraction: settlement.rawJobs % 1 }))
                    .sort((a, b) => b.fraction - a.fraction);
                let urbanRemainder = Math.max(0, urbanBudget - cityJobs.reduce((sum, settlement) => sum + settlement.jobs, 0));
                cityJobs.forEach((settlement) => {
                    if (urbanRemainder <= 0) return;
                    settlement.jobs += 1;
                    urbanRemainder -= 1;
                });
                cityJobs.forEach((settlement) => {
                    delete settlement.rawJobs;
                    delete settlement.fraction;
                });
                cityJobs.sort((a, b) => b.jobs - a.jobs || a.name.localeCompare(b.name, 'da'));
                const roundedUrbanJobs = cityJobs.reduce((sum, settlement) => sum + settlement.jobs, 0);
                const roundedRuralJobs = Math.min(Math.max(0, totalJobs - roundedUrbanJobs), Math.max(0, Math.round(ruralJobs || 0)));
                const rawRoundedRuralJobs = Math.min(
                    Math.max(0, totalJobs - roundedUrbanJobs),
                    Math.max(0, Math.round(municipality.jobs * state.geography.weights.rural * ruralCoverage))
                );
                const inZoneJobs = roundedUrbanJobs + roundedRuralJobs;
                municipalityBreakdown.push({
                    code,
                    name: municipality.name,
                    cityJobs: cityJobs.filter((settlement) => settlement.jobs > 0),
                    urbanJobs: roundedUrbanJobs,
                    ruralJobs: roundedRuralJobs,
                    urbanWorkplaces: urbanWorkplaces === null ? null : Math.round(urbanWorkplaces),
                    ruralWorkplaces: ruralWorkplaces === null ? null : Math.round(ruralWorkplaces),
                    jobs: inZoneJobs,
                    outsideJobs: Math.max(0, totalJobs - inZoneJobs),
                    totalJobs,
                    overlapAreaM2: ruralGeography.overlapAreaM2,
                    municipalityAreaM2: ruralGeography.municipalityAreaM2,
                    overlapPercent: ruralCoverage * 100,
                    ruralTailAdjusted,
                    adjustedRuralJobs: Math.max(0, roundedRuralJobs - rawRoundedRuralJobs),
                });
            }
            if (Number.isFinite(municipality.jobs)) {
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

            // Turf-intersektionerne kan være tunge. Giv browseren tid til input,
            // korttegning og loadingindikator mellem små grupper af kommuner.
            if ((index + 1) % 2 === 0) await yieldToBrowser();
        }

        return {
            jobs: hasJobs ? municipalityBreakdown.reduce((sum, municipality) => sum + municipality.jobs, 0) : null,
            workplaces: hasWorkplaces ? Math.round(workplaces) : null,
            branches: [...branchTotals.values()]
                .map((branch) => ({ ...branch, jobs: Math.round(branch.jobs) }))
                .sort((a, b) => b.jobs - a.jobs)
                .slice(0, 6),
            municipalityBreakdown: municipalityBreakdown.sort((a, b) => (b.jobs || 0) - (a.jobs || 0)),
            coverage,
        };
    }

    function calculateScenarioCoverage(scenario, requestId) {
        if (!config.analysisWorker || typeof Worker === 'undefined') {
            return calculateScenarioCoverageOnMainThread(scenario, requestId);
        }

        const boundaries = [...state.municipalityBoundaries.entries()]
            .map(([code, boundary]) => ({ code, boundary }));
        const worker = new Worker(config.analysisWorker);
        scenario.analysisWorker = worker;

        return new Promise((resolve, reject) => {
            const cleanup = () => {
                worker.terminate();
                if (scenario.analysisWorker === worker) scenario.analysisWorker = null;
                if (scenario.analysisWorkerReject === reject) scenario.analysisWorkerReject = null;
            };
            scenario.analysisWorkerReject = reject;
            worker.addEventListener('message', ({ data }) => {
                cleanup();
                if (requestId !== scenario.analysisRequest) {
                    resolve(null);
                    return;
                }
                if (!data?.ok) {
                    reject(new Error(data?.error || 'Geografiberegningen fejlede.'));
                    return;
                }
                resolve(data.coverage || {});
            }, { once: true });
            worker.addEventListener('error', () => {
                cleanup();
                reject(new Error('Geografiberegningen kunne ikke startes.'));
            }, { once: true });
            worker.postMessage({ boundaries, geojson: scenario.geojson });
        });
    }

    async function calculateScenarioCoverageOnMainThread(scenario, requestId) {
        const coverage = {};
        const entries = [...state.municipalityBoundaries.entries()];
        for (let index = 0; index < entries.length; index += 1) {
            if (requestId !== scenario.analysisRequest) return null;
            const [code, boundary] = entries[index];
            coverage[code] = polygonCoverageMetrics(boundary, scenario.geojson);
            await yieldToBrowser();
        }
        return coverage;
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
        if (scenario.analysisWorker) scenario.analysisWorker.terminate();
        scenario.analysisWorker = null;
        if (scenario.analysisWorkerReject) scenario.analysisWorkerReject(new Error('Beregningen blev afbrudt.'));
        scenario.analysisWorkerReject = null;
        scenario.analysisStatus = 'idle';
        scenario.analysis = null;
        scenario.analysisError = null;
    }

    function queueActiveAnalyses() {
        activeScenarios().forEach(queueAnalysis);
    }

    async function queueAnalysis(scenario) {
        if (!state.statsReady || !state.geographyReady || !scenario.routingReady || !scenario.geojson || scenario.isochroneLoading || scenario.zoneMinutes !== state.minutes || typeof turf === 'undefined') return;
        const requestId = ++scenario.analysisRequest;
        scenario.analysisStatus = 'loading';
        scenario.analysis = null;
        scenario.analysisError = null;
        render();
        await yieldToBrowser();
        if (requestId !== scenario.analysisRequest) return;
        try {
            const analysis = await calculateZoneMetrics(scenario, requestId);
            if (requestId !== scenario.analysisRequest || !analysis) return;
            scenario.analysis = analysis;
            scenario.analysisStatus = 'ready';
        } catch (error) {
            if (requestId !== scenario.analysisRequest) return;
            scenario.analysisError = 'De geografiske tal kunne ikke beregnes.';
            scenario.analysisStatus = 'error';
        }
        render();
    }

    function yieldToBrowser() {
        return new Promise((resolve) => window.setTimeout(resolve, 0));
    }

    function pointInZone(lon, lat, geojson) {
        try {
            const point = turf.point([lon, lat]);
            return (geojson.features || []).some((feature) => turf.booleanPointInPolygon(point, feature));
        } catch (error) {
            return false;
        }
    }

    function polygonCoverageMetrics(boundary, geojson) {
        const empty = { fraction: 0, overlapAreaM2: 0, municipalityAreaM2: 0 };
        if (!boundary) return empty;
        try {
            const boundaryArea = turf.area(boundary);
            if (boundaryArea <= 0) return empty;
            let overlapArea = 0;
            (geojson.features || []).forEach((zone) => {
                const overlap = turf.intersect(turf.featureCollection([boundary, zone]));
                if (overlap) overlapArea += turf.area(overlap);
            });
            const boundedOverlapArea = clamp(overlapArea, 0, boundaryArea);
            return {
                fraction: boundedOverlapArea / boundaryArea,
                overlapAreaM2: Math.round(boundedOverlapArea),
                municipalityAreaM2: Math.round(boundaryArea),
            };
        } catch (error) {
            return empty;
        }
    }

    function settlementUrbanWeight(settlement) {
        const population = Math.max(0, Number(settlement?.population) || 0);
        const exponent = Number(state.geography?.weights?.urbanPopulationExponent) || 1;
        const evidence = cityEmploymentEvidence(settlement);
        const factor = Number(evidence?.factor) > 0 ? Number(evidence.factor) : 1;
        return Math.pow(population, exponent) * factor;
    }

    function cityEmploymentEvidence(city) {
        const evidence = state.geography?.employmentEvidence || {};
        const target = `${city?.municipalityCode}|${normalizedPlaceName(city?.name)}`;
        if (evidence[target]) return evidence[target];
        const match = Object.entries(evidence).find(([key]) => normalizedPlaceName(key) === target);
        return match?.[1] || null;
    }

    function cityModelBasis(city) {
        const evidence = cityEmploymentEvidence(city);
        if (evidence) {
            return `${evidence.source || 'Dokumenteret bykorrektion'} · ${evidence.year || 'år ukendt'} · faktor ${Number(evidence.factor).toLocaleString('da-DK')}`;
        }
        const exponent = Number(state.geography?.weights?.urbanPopulationExponent || 1).toLocaleString('da-DK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return `ERHV2 ${state.statsYear || '—'} / BY3 ${state.geography?.year || '—'} · befolkning^${exponent}`;
    }

    function settlementReached(scenario, settlement) {
        const routeCity = scenario.cities.find((city) => String(city.municipalityCode) === String(settlement.municipalityCode)
            && normalizedPlaceName(city.name) === normalizedPlaceName(settlement.name));
        if (routeCity) return cityReached(scenario, routeCity);
        // Settlement-scope matricen har allerede prøvet alle BY3-steder i analyseområdet.
        // Mangler et sted i det filtrerede svar, ligger det uden for maksimum + nærmargin.
        if (scenario.routingSettlementScope) return false;
        return Boolean(scenario.geojson) && pointInZone(settlement.lon, settlement.lat, scenario.geojson);
    }

    function renderStatus() {
        const active = activeScenarios();
        const errors = [state.statsError, state.geographyError, ...active.flatMap((scenario) => [scenario.routeError, scenario.isochroneError, scenario.analysisError])].filter(Boolean);
        if (errors.length > 0) {
            elements.status.textContent = [...new Set(errors)].join(' ');
            elements.status.className = 'data-status is-warning';
        } else if (state.statsReady && state.geographyReady && active.every((scenario) => scenario.routingReady && scenario.analysisStatus === 'ready')) {
            const exponent = Number(state.geography.weights.urbanPopulationExponent || 1).toLocaleString('da-DK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            elements.status.textContent = `ERHV2 ${state.statsYear} · BY3 ${state.geography.year} · 90/10-model · byvægt ${exponent}.`;
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
        renderMunicipalityBreakdown(elements.municipalityBreakdown, result.municipalityBreakdown, !result.ready && !result.error, result.error);
        elements.singleResults.setAttribute('aria-busy', String(!result.ready));
    }

    function renderComparison(primaryResult, secondaryResult) {
        const results = { primary: primaryResult, secondary: secondaryResult };
        renderComparisonKey();
        const sharedMaximum = Math.max(1, ...primaryResult.branches.map((branch) => branch.jobs), ...secondaryResult.branches.map((branch) => branch.jobs));
        Object.entries(results).forEach(([key, result]) => {
            const target = elements.compare[key];
            target.name.textContent = state.scenarios[key].origin.name;
            const placeholder = result.error ? 'Fejl' : 'Beregner…';
            setValue(target.jobs, result.ready ? formatKnown(result.jobs) : placeholder, !result.ready && !result.error, Boolean(result.error));
            setValue(target.workplaces, result.ready ? formatKnown(result.workplaces) : placeholder, !result.ready && !result.error, Boolean(result.error));
            setValue(target.cities, result.ready ? numberFormat.format(result.reachedCities.length) : placeholder, !result.ready && !result.error, Boolean(result.error));
        });
        renderCompactComparisonBranches(primaryResult, secondaryResult, sharedMaximum);
        renderComparisonMunicipalities(primaryResult, secondaryResult);
        elements.comparisonResults.setAttribute('aria-busy', String(!primaryResult.ready || !secondaryResult.ready));
    }

    function renderComparisonWaiting(primaryResult) {
        renderComparisonKey();
        const primaryTarget = elements.compare.primary;
        primaryTarget.name.textContent = state.scenarios.primary.origin.name;
        const placeholder = primaryResult.error ? 'Fejl' : 'Beregner…';
        setValue(primaryTarget.jobs, primaryResult.ready ? formatKnown(primaryResult.jobs) : placeholder, !primaryResult.ready && !primaryResult.error, Boolean(primaryResult.error));
        setValue(primaryTarget.workplaces, primaryResult.ready ? formatKnown(primaryResult.workplaces) : placeholder, !primaryResult.ready && !primaryResult.error, Boolean(primaryResult.error));
        setValue(primaryTarget.cities, primaryResult.ready ? numberFormat.format(primaryResult.reachedCities.length) : placeholder, !primaryResult.ready && !primaryResult.error, Boolean(primaryResult.error));
        elements.compare.secondary.name.textContent = 'Vælg udgangspunkt B';
        ['jobs', 'workplaces', 'cities'].forEach((field) => setValue(elements.compare.secondary[field], '—', false));
        elements.comparisonBranchesCompact.innerHTML = '<p class="empty-state">Vælg udgangspunkt B for at sammenligne brancher.</p>';
        elements.comparisonMunicipalities.innerHTML = '<p class="empty-state">Vælg udgangspunkt B for at sammenligne kommuner.</p>';
        elements.comparisonResults.setAttribute('aria-busy', String(!primaryResult.ready));
    }

    function renderComparisonKey() {
        const primaryName = state.scenarios.primary.origin?.name || 'Vælg udgangspunkt A';
        const secondaryName = state.scenarios.secondary.origin?.name || 'Vælg udgangspunkt B';
        const entries = [
            ['primary', 'A', primaryName, elements.comparisonKeyPrimary],
            ['secondary', 'B', secondaryName, elements.comparisonKeySecondary],
        ];
        entries.forEach(([key, label, name, target]) => {
            const description = `${label} = ${name}`;
            target.textContent = name;
            target.parentElement.title = description;
            elements.compare[key].badge.title = description;
            elements.compare[key].badge.setAttribute('aria-label', description);
        });
    }

    function comparisonBadgeHtml(label, name) {
        const description = escapeHtml(`${label} = ${name}`);
        return `<b title="${description}" aria-label="${description}">${label}</b>`;
    }

    function renderCompactComparisonBranches(primaryResult, secondaryResult, maximum) {
        if (primaryResult.error || secondaryResult.error) {
            elements.comparisonBranchesCompact.innerHTML = '<p class="empty-state is-error">Branchetallene kunne ikke beregnes.</p>';
            return;
        }
        if (!primaryResult.ready || !secondaryResult.ready) {
            elements.comparisonBranchesCompact.innerHTML = '<div class="loading-chart" role="status"><span></span><span></span><span></span><small>Beregner A/B-brancher…</small></div>';
            return;
        }
        const branches = new Map();
        primaryResult.branches.forEach((branch) => branches.set(branch.code, { code: branch.code, name: branch.name, primary: branch.jobs, secondary: 0 }));
        secondaryResult.branches.forEach((branch) => {
            const item = branches.get(branch.code) || { code: branch.code, name: branch.name, primary: 0, secondary: 0 };
            item.secondary = branch.jobs;
            branches.set(branch.code, item);
        });
        const rows = [...branches.values()]
            .sort((a, b) => Math.max(b.primary, b.secondary) - Math.max(a.primary, a.secondary))
            .slice(0, 6);
        elements.comparisonBranchesCompact.innerHTML = `<h3>Største brancher · A/B</h3>${rows.map((branch) => `
            <div class="compact-branch-row">
                <strong>${escapeHtml(shortBranchName(branch.name))}</strong>
                <div class="compact-comparison-values">
                    <div class="compact-branch-value">${comparisonBadgeHtml('A', state.scenarios.primary.origin.name)}<span class="branch-track"><i style="width:${comparisonWidth(branch.primary, maximum)}%"></i></span><em>${numberFormat.format(branch.primary)}</em></div>
                    <div class="compact-branch-value is-secondary">${comparisonBadgeHtml('B', state.scenarios.secondary.origin.name)}<span class="branch-track"><i style="width:${comparisonWidth(branch.secondary, maximum)}%"></i></span><em>${numberFormat.format(branch.secondary)}</em></div>
                </div>
            </div>
        `).join('')}`;
    }

    function renderComparisonMunicipalities(primaryResult, secondaryResult) {
        if (primaryResult.error || secondaryResult.error) {
            elements.comparisonMunicipalities.innerHTML = '<p class="empty-state is-error">Kommunetallene kunne ikke beregnes.</p>';
            return;
        }
        if (!primaryResult.ready || !secondaryResult.ready) {
            elements.comparisonMunicipalities.innerHTML = '<div class="loading-list" role="status"><span></span><span></span><p>Beregner A/B-kommuner…</p></div>';
            return;
        }
        const rows = new Map();
        primaryResult.municipalityBreakdown.forEach((municipality) => rows.set(municipality.code, { code: municipality.code, name: municipality.name, primary: municipality, secondary: null }));
        secondaryResult.municipalityBreakdown.forEach((municipality) => {
            const row = rows.get(municipality.code) || { code: municipality.code, name: municipality.name, primary: null, secondary: null };
            row.secondary = municipality;
            rows.set(municipality.code, row);
        });
        const municipalities = [...rows.values()]
            .map((row) => ({
                ...row,
                primary: row.primary || emptyMunicipalityBreakdown(row.code, row.name),
                secondary: row.secondary || emptyMunicipalityBreakdown(row.code, row.name),
            }))
            .sort((a, b) => Math.max(b.primary.jobs, b.secondary.jobs) - Math.max(a.primary.jobs, a.secondary.jobs));
        if (!municipalities.length) {
            elements.comparisonMunicipalities.innerHTML = '<p class="empty-state">Zonerne overlapper endnu ingen beregnede kommuner.</p>';
            return;
        }
        const maximum = Math.max(1, ...municipalities.flatMap((row) => [row.primary.jobs, row.secondary.jobs]));
        const primaryName = state.scenarios.primary.origin.name;
        const secondaryName = state.scenarios.secondary.origin.name;
        elements.comparisonMunicipalities.innerHTML = `<h3>Job fordelt på kommuner · A/B</h3>${municipalities.map((row) => `
            <details class="comparison-municipality-row">
                <summary>
                    <strong>${escapeHtml(row.name)} Kommune</strong>
                    <span class="compact-comparison-values">
                        <span class="compact-branch-value">${comparisonBadgeHtml('A', primaryName)}<span class="branch-track"><i style="width:${comparisonWidth(row.primary.jobs, maximum)}%"></i></span><em>${formatKnown(row.primary.jobs)}</em></span>
                        <span class="compact-branch-value is-secondary">${comparisonBadgeHtml('B', secondaryName)}<span class="branch-track"><i style="width:${comparisonWidth(row.secondary.jobs, maximum)}%"></i></span><em>${formatKnown(row.secondary.jobs)}</em></span>
                    </span>
                </summary>
                <div class="comparison-municipality-details">
                    <section><h4>${comparisonBadgeHtml('A', primaryName)}${escapeHtml(primaryName)}</h4>${municipalityCardHtml(row.primary)}</section>
                    <section class="is-secondary"><h4>${comparisonBadgeHtml('B', secondaryName)}${escapeHtml(secondaryName)}</h4>${municipalityCardHtml(row.secondary)}</section>
                </div>
            </details>
        `).join('')}`;
    }

    function emptyMunicipalityBreakdown(code, name) {
        const municipality = state.municipalities[code];
        const totalJobs = Math.round(municipality?.jobs || 0);
        let municipalityAreaM2 = 0;
        try {
            const boundary = state.municipalityBoundaries.get(String(code));
            municipalityAreaM2 = boundary ? Math.round(turf.area(boundary)) : 0;
        } catch (error) {
            municipalityAreaM2 = 0;
        }
        return {
            code,
            name: municipality?.name || name,
            cityJobs: [],
            urbanJobs: 0,
            ruralJobs: 0,
            jobs: 0,
            outsideJobs: totalJobs,
            totalJobs,
            overlapAreaM2: 0,
            municipalityAreaM2,
            overlapPercent: 0,
            ruralTailAdjusted: false,
            adjustedRuralJobs: 0,
        };
    }

    function comparisonWidth(value, maximum) {
        if (!value || maximum <= 0) return '0.0';
        return Math.max(3, (value / maximum) * 100).toFixed(1);
    }

    function renderMunicipalityBreakdown(target, municipalities, loading = false, error = null) {
        if (error) {
            target.innerHTML = '<p class="empty-state is-error">Kommunetallene kunne ikke beregnes.</p>';
            return;
        }
        if (loading) {
            target.innerHTML = '<div class="loading-list" role="status"><span></span><span></span><p>Beregner kommuner, byer og øvrige arealer…</p></div>';
            return;
        }
        if (!municipalities?.length) {
            target.innerHTML = '<p class="empty-state">Zonen dækker endnu ingen beregnede job.</p>';
            return;
        }
        target.innerHTML = municipalities.map(municipalityCardHtml).join('');
    }

    function municipalityCardHtml(municipality) {
            const total = Math.max(1, municipality.totalJobs || 0);
            const urbanWidth = (municipality.urbanJobs / total) * 100;
            const ruralWidth = (municipality.ruralJobs / total) * 100;
            const outsideWidth = Math.max(0, 100 - urbanWidth - ruralWidth);
            const cities = municipality.cityJobs.length
                ? municipality.cityJobs.map((city) => `<span class="municipality-city"><i>${escapeHtml(city.name)}<small>${escapeHtml(cityModelBasis(city))}</small></i><em>${formatKnown(city.jobs)} job</em></span>`).join('')
                : '<span class="municipality-city is-empty"><i>Ingen registrerede bymidter i zonen</i><em>0 job</em></span>';
            const adjustment = municipality.ruralTailAdjusted
                ? `<span class="municipality-adjustment" title="Restreglen anvendes kun, når alle registrerede bypunkter i kommunen nås, mindst 95 % af kommunearealet overlapper zonen, og den beregnede rest af 10 %-puljen er under 100 job."><i>Lille restpulje medregnet · alle bypunkter nås</i><em>+${formatKnown(municipality.adjustedRuralJobs)} job</em></span>`
                : '';
            return `<article class="municipality-row">
                <div class="municipality-heading"><strong>${escapeHtml(municipality.name)} Kommune</strong><b>${formatKnown(municipality.jobs)} job i zonen</b></div>
                <div class="municipality-job-bar" aria-label="Fordeling af kommunens anslåede job"><i style="width:${urbanWidth.toFixed(2)}%"></i><i style="width:${ruralWidth.toFixed(2)}%"></i><i style="width:${outsideWidth.toFixed(2)}%"></i></div>
                <span class="municipality-overlap" title="Det geografiske overlap bruges til at fordele kommunens 10 %-pulje for job uden for byerne."><i>Geografisk overlap · grundlag for 10 %-puljen</i><em>${formatSquareMetres(municipality.overlapAreaM2)} · ${formatCoveragePercent(municipality.overlapPercent)} af kommunen</em></span>
                ${adjustment}
                <div class="municipality-cities"><small>Byer i zonen · del af 90 %</small>${cities}</div>
                <span class="municipality-remainder"><i>Øvrigt område i zonen · del af 10 %</i><em>${formatKnown(municipality.ruralJobs)} job</em></span>
                <span class="municipality-outside"><i>Uden for zonen</i><em>${formatKnown(municipality.outsideJobs)} job</em></span>
                <footer>I alt i kommunen: ${formatKnown(municipality.totalJobs)} anslåede job</footer>
            </article>`;
    }

    function formatSquareMetres(value) {
        const area = Number.isFinite(Number(value)) ? Math.max(0, Math.round(Number(value))) : 0;
        return `${numberFormat.format(area)} m²`;
    }

    function formatCoveragePercent(value) {
        const percent = Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;
        if (percent > 0 && percent < 0.0001) return '< 0,0001 %';
        const maximumFractionDigits = percent >= 10 ? 1 : percent >= 1 ? 2 : percent >= 0.01 ? 3 : 4;
        return `${percent.toLocaleString('da-DK', { minimumFractionDigits: percent === 0 ? 0 : 1, maximumFractionDigits })} %`;
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
        const nearLimit = state.minutes + NEAR_MARGIN_MINUTES;
        const cityCandidates = new Map(primary.cities.map((city) => [city.id, city]));
        if (state.comparing) secondary.cities.forEach((city) => cityCandidates.set(city.id, cityCandidates.get(city.id) || city));
        const cities = [...cityCandidates.values()].filter((city) => {
            const primaryMinutes = cityTravelMinutes(cityFor(primary, city.id));
            const secondaryMinutes = cityTravelMinutes(cityFor(secondary, city.id));
            return [primaryMinutes, secondaryMinutes].some((minutes) => minutes !== null && minutes <= nearLimit);
        }).sort((a, b) => {
            const aTime = Math.min(cityFor(primary, a.id)?.travelSeconds ?? Infinity, cityFor(secondary, a.id)?.travelSeconds ?? Infinity);
            const bTime = Math.min(cityFor(primary, b.id)?.travelSeconds ?? Infinity, cityFor(secondary, b.id)?.travelSeconds ?? Infinity);
            return aTime - bTime;
        });
        const methodNote = `<p class="city-list-method">Alle viste byer har en beregnet tid på højst ${nearLimit} minutter. Jobmodellen vises med kildeår og formel ved hver by.</p>`;
        elements.cityList.innerHTML = methodNote + cities.map((city) => {
            const status = combinedCityStatus(city.id);
            const primaryCity = cityFor(primary, city.id);
            const secondaryCity = cityFor(secondary, city.id);
            const routes = state.comparing
                ? `<span class="city-routes">
                    <span class="city-route"><strong>${formatMinutes(primaryCity?.travelSeconds)}</strong><small>A · ${formatDistance(primaryCity?.distanceKm)}</small></span>
                    <span class="city-route"><strong>${formatMinutes(secondaryCity?.travelSeconds)}</strong><small>B · ${formatDistance(secondaryCity?.distanceKm)}</small></span>
                  </span>`
                : `<span class="city-route"><strong>${formatMinutes(primaryCity?.travelSeconds)}</strong><small>${formatDistance(primaryCity?.distanceKm)}</small></span>`;
            return `<button class="city-row is-${status}" type="button" data-city-id="${escapeHtml(city.id)}">
                <span class="city-status-dot"></span>
                <span class="city-main"><strong>${escapeHtml(city.name)}</strong><small>${escapeHtml(city.municipality)} Kommune</small><small class="city-model-basis">${escapeHtml(cityModelBasis(city))}</small></span>
                ${routes}
            </button>`;
        }).join('');
        elements.cityList.querySelectorAll('[data-city-id]').forEach((button) => {
            button.addEventListener('click', () => {
                const city = state.cityCatalog.get(button.dataset.cityId);
                const marker = state.markers.get(button.dataset.cityId);
                if (city && marker) {
                    map.flyTo([city.lat, city.lon], Math.max(map.getZoom(), 10), { duration: 0.5 });
                    marker.openPopup();
                }
            });
        });
    }

    function updateMarkers() {
        state.cityCatalog.forEach((city) => {
            const marker = state.markers.get(city.id);
            if (!marker) return;
            const status = combinedCityStatus(city.id);
            marker.setStyle(markerStyle(status, status === 'muted' ? 0 : 1));
            marker.setRadius(status === 'muted' ? 3.5 : 6);
            marker.setPopupContent(cityPopup(city));
            state.touchMarkers.get(city.id)?.setPopupContent(cityPopup(city));
        });
        state.boundaryMarkers.forEach((marker, originId) => {
            const origin = config.origins[originId];
            marker.setPopupContent(boundaryPointPopup(origin));
            state.boundaryTouchMarkers.get(originId)?.setPopupContent(boundaryPointPopup(origin));
        });
        updateCityLabels();
    }

    function updateCityLabels() {
        const showLabels = map.getZoom() >= CITY_LABEL_MIN_ZOOM;
        const selectedIds = new Set([
            state.scenarios.primary.originId,
            state.comparing ? state.scenarios.secondary.originId : null,
        ].filter(Boolean));
        state.markers.forEach((marker, cityId) => {
            if (showLabels && !selectedIds.has(cityId)) {
                if (!marker.isTooltipOpen()) marker.openTooltip();
            } else if (marker.isTooltipOpen()) {
                marker.closeTooltip();
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
        const near = [primary, secondary].some((city) => {
            const minutes = cityTravelMinutes(city);
            return minutes !== null && minutes > state.minutes && minutes - state.minutes <= NEAR_MARGIN_MINUTES;
        });
        return near ? 'near' : 'muted';
    }

    function singleCityStatus(scenario, city) {
        if (!city || city.travelSeconds === null) return 'muted';
        if (config.reachability === 'zone') return cityReached(scenario, city) ? 'reached' : 'muted';
        const difference = cityTravelMinutes(city) - state.minutes;
        if (difference <= 0) return 'reached';
        return difference <= NEAR_MARGIN_MINUTES ? 'near' : 'muted';
    }

    function cityReached(scenario, city) {
        if (config.reachability === 'zone' && scenario.geojson && city) return pointInZone(city.lon, city.lat, scenario.geojson);
        const minutes = cityTravelMinutes(city);
        return minutes !== null && minutes <= state.minutes;
    }

    function cityTravelMinutes(city) {
        return city?.travelSeconds === null || city?.travelSeconds === undefined ? null : Math.round(city.travelSeconds / 60);
    }

    function cityFor(scenario, id) {
        if (!scenario?.origin) return null;
        return scenario.cities.find((city) => city.id === id);
    }

    function markerStyle(status, scale) {
        if (status === 'reached') return { radius: 6, color: '#fff', weight: 2, fillColor: '#0f766e', fillOpacity: 0.95 };
        if (status === 'secondary') return { radius: 6, color: '#fff', weight: 2, fillColor: '#6d5bd0', fillOpacity: 0.95 };
        if (status === 'both') return { radius: 6, color: '#0f766e', weight: 2.5, fillColor: '#6d5bd0', fillOpacity: 0.95 };
        if (status === 'near') return { radius: 6, color: '#fff7df', weight: 2.5, fillColor: '#e49a23', fillOpacity: 0.95 };
        return { radius: 3.5, color: '#64727d', weight: 1.25, fillColor: '#89949d', fillOpacity: 0.55 };
    }

    function cityPopup(city) {
        const primary = cityFor(state.scenarios.primary, city.id) || city;
        const secondary = cityFor(state.scenarios.secondary, city.id);
        const routes = state.comparing
            ? `<span>A: ${formatMinutes(primary.travelSeconds)} · ${formatDistance(primary.distanceKm)}</span><span>B: ${formatMinutes(secondary?.travelSeconds)} · ${formatDistance(secondary?.distanceKm)}</span>`
            : `<span>${formatMinutes(primary.travelSeconds)} · ${formatDistance(primary.distanceKm)}</span>`;
        const canSelect = city.municipalityCode === '665' && validOrigin(city.id);
        const cityJobs = estimatedSettlementJobs(city);
        const cityJobsLine = cityJobs === null
            ? ''
            : `<span class="city-model-jobs">Størrelsesvægtet byandel: <strong>${numberFormat.format(cityJobs)} job</strong></span>`;
        const basisLine = state.statsReady && state.geographyReady
            ? `<span class="city-model-basis">Grundlag: ${escapeHtml(cityModelBasis(city))}</span>`
            : '';
        const selection = canSelect ? originSelectionActions(city.id) : '';
        return `<div class="city-popup"><strong>${escapeHtml(city.name)}</strong>${routes}<span>${escapeHtml(city.municipality)} Kommune</span>${cityJobsLine}${basisLine}${selection}</div>`;
    }

    function boundaryPointPopup(origin) {
        return `<div class="city-popup boundary-point-popup"><strong>${escapeHtml(origin.name)}</strong><span>Punkt ved Lemvig Kommunes grænse</span>${originSelectionActions(origin.id)}</div>`;
    }

    function originSelectionActions(originId) {
        if (!state.scenarios.primary.origin) {
            return `<div class="city-popup-actions"><button type="button" data-select-origin="primary" data-origin-id="${escapeHtml(originId)}">Vælg udgangspunkt</button></div>`;
        }
        if (!state.comparing) {
            if (state.scenarios.primary.originId === originId) return '';
            return `<div class="city-popup-actions">
                <button type="button" data-select-origin="primary" data-origin-id="${escapeHtml(originId)}">Nyt udgangspunkt</button>
                <button type="button" data-select-origin="compare" data-origin-id="${escapeHtml(originId)}">Sammenlign</button>
            </div>`;
        }
        return `<div class="city-popup-actions">
            <button type="button" data-select-origin="primary" data-origin-id="${escapeHtml(originId)}">Vælg som A</button>
            <button type="button" data-select-origin="secondary" data-origin-id="${escapeHtml(originId)}">Vælg som B</button>
        </div>`;
    }

    function estimatedSettlementJobs(city) {
        if (!state.statsReady || !state.geographyReady) return null;
        const municipality = state.municipalities[city.municipalityCode];
        const settlements = state.settlementsByMunicipality.get(String(city.municipalityCode)) || [];
        const settlement = settlements.find((item) => normalizedPlaceName(item.name) === normalizedPlaceName(city.name));
        const totalUrbanWeight = settlements.reduce((sum, item) => sum + settlementUrbanWeight(item), 0);
        if (!settlement || !Number.isFinite(municipality?.jobs) || totalUrbanWeight <= 0) return null;
        return Math.round(municipality.jobs * state.geography.weights.urban * settlementUrbanWeight(settlement) / totalUrbanWeight);
    }

    function normalizedPlaceName(name) {
        return String(name).toLocaleLowerCase('da-DK').replace(/\s*\(del af flere kommuner\)$/u, '').trim();
    }

    function toggleMobileMap() {
        const expanded = document.body.classList.toggle('is-map-expanded');
        elements.mapSizeToggle.setAttribute('aria-expanded', String(expanded));
        elements.mapSizeToggle.querySelector('span:last-child').textContent = expanded ? 'Vis indhold' : 'Udvid kort';
        window.setTimeout(() => map.invalidateSize(), 80);
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
        if (state.heatmap.active && state.heatmap.error) {
            elements.mapLoading.textContent = 'Heatmappet kunne ikke indlæses';
            elements.mapLoading.classList.remove('is-hidden', 'is-prompt');
            elements.mapLoading.classList.add('is-error');
            return;
        }
        if (state.heatmap.active && (state.heatmap.loading || !state.heatmap.ready)) {
            elements.mapLoading.textContent = 'Henter forberegnet heatmap…';
            elements.mapLoading.classList.remove('is-hidden', 'is-error', 'is-prompt');
            return;
        }
        const active = activeScenarios();
        const supportingDataLoading = !state.statsReady || !state.geographyReady;
        const routingLoading = active.some((scenario) => !scenario.routingReady);
        const calculationLoading = active.some((scenario) => scenario.isochroneLoading || scenario.analysisStatus !== 'ready');
        const hasError = (!state.statsReady && Boolean(state.statsError))
            || (!state.geographyReady && Boolean(state.geographyError))
            || active.some((scenario) => (!scenario.routingReady && scenario.routeError) || scenario.isochroneError || scenario.analysisError);
        if (!state.scenarios.primary.origin) {
            if (state.heatmap.active && state.heatmap.ready) {
                elements.mapLoading.classList.add('is-hidden');
                elements.mapLoading.classList.remove('is-error', 'is-prompt');
            } else {
                elements.mapLoading.textContent = 'Vælg en by for at starte';
                elements.mapLoading.classList.remove('is-hidden', 'is-error');
                elements.mapLoading.classList.add('is-prompt');
            }
        } else if (state.comparing && !state.scenarios.secondary.origin) {
            elements.mapLoading.textContent = 'Vælg udgangspunkt B';
            elements.mapLoading.classList.remove('is-hidden', 'is-error');
            elements.mapLoading.classList.add('is-prompt');
        } else if (hasError) {
            elements.mapLoading.textContent = 'En beregning kunne ikke gennemføres';
            elements.mapLoading.classList.remove('is-hidden', 'is-prompt');
            elements.mapLoading.classList.add('is-error');
        } else if (supportingDataLoading || routingLoading || calculationLoading) {
            elements.mapLoading.textContent = routingLoading
                ? (state.comparing ? 'Henter køretider for A og B…' : 'Henter køretider…')
                : (state.comparing ? `Beregner to ${state.minutes}-minutters zoner…` : `Beregner ${state.minutes}-minutters zone…`);
            elements.mapLoading.classList.remove('is-hidden', 'is-error', 'is-prompt');
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
        if (state.heatmap.active) url.searchParams.set('view', 'heatmap');
        else url.searchParams.delete('view');
        url.searchParams.set('minutes', String(state.minutes));
        window.history.replaceState({}, '', url);
    }

    function updateSliderProgress() {
        const progress = ((state.minutes - config.slider.min) / (config.slider.max - config.slider.min)) * 100;
        elements.slider.style.setProperty('--progress', `${progress}%`);
        elements.mapSlider.value = state.minutes;
        elements.mapSlider.style.setProperty('--progress', `${progress}%`);
        elements.mapOutput.value = `${state.minutes} minutter`;
        elements.mapOutput.textContent = `${state.minutes} minutter`;
    }

    function originIcon(name, key) {
        return L.divIcon({
            className: `origin-marker-wrap${key === 'secondary' ? ' is-secondary' : ''}`,
            html: `<span class="origin-pulse"></span><span class="origin-marker"></span><span class="origin-label">${escapeHtml(name)}</span>`,
            iconSize: [24, 24],
            iconAnchor: [12, 12],
        });
    }

    function originPopup(origin, key) {
        return `<strong>${escapeHtml(origin.name)}</strong><br>Udgangspunkt ${key === 'secondary' ? 'B' : 'A'} for køretiderne.`;
    }

    function fitMapToOrigins() {
        const primary = state.scenarios.primary.origin;
        const secondary = state.comparing ? state.scenarios.secondary.origin : null;
        if (!primary) {
            if (state.municipalityLayer) map.fitBounds(state.municipalityLayer.getBounds().pad(0.1), { padding: [24, 24], maxZoom: 10 });
            return;
        }
        if (!secondary) {
            map.setView([primary.lat, primary.lon], 8, { animate: false });
            return;
        }
        map.fitBounds(L.latLngBounds([[primary.lat, primary.lon], [secondary.lat, secondary.lon]]).pad(0.65), { padding: [30, 30], maxZoom: 9 });
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

    function heatQuantile(sortedValues, fraction) {
        if (!sortedValues.length) return 0;
        const position = clamp(fraction, 0, 1) * (sortedValues.length - 1);
        const lower = Math.floor(position);
        const upper = Math.ceil(position);
        if (lower === upper) return sortedValues[lower];
        return sortedValues[lower] + (sortedValues[upper] - sortedValues[lower]) * (position - lower);
    }

    function heatColor(fraction) {
        const colors = ['#fff0b8', '#f4c46c', '#eb8a4d', '#d65345', '#772d4c'];
        return colors[Math.min(colors.length - 1, Math.floor(clamp(fraction, 0, 0.9999) * colors.length))];
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
