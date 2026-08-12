(() => {
    'use strict';

    const configElement = document.getElementById('app-config');
    if (!configElement || typeof L === 'undefined') {
        return;
    }

    const config = JSON.parse(configElement.textContent);
    const requestedOrigin = new URLSearchParams(window.location.search).get('origin');
    const initialOriginId = Object.hasOwn(config.origins, requestedOrigin) ? requestedOrigin : config.defaultOrigin;
    const state = {
        originId: initialOriginId,
        origin: config.origins[initialOriginId],
        minutes: config.slider.default,
        cities: config.cities.map((city) => ({ ...city, travelSeconds: null, distanceKm: null })),
        municipalities: {},
        statsYear: null,
        routingReady: false,
        statsReady: false,
        routeError: null,
        statsError: null,
        markers: new Map(),
        isochroneLayer: null,
        isochroneRequest: 0,
        routingRequest: 0,
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
        legendOrigin: document.getElementById('legend-origin'),
        citiesOrigin: document.getElementById('cities-origin'),
        originOptions: [...document.querySelectorAll('[data-origin-id]')],
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
    map.createPane('isochrone');
    map.getPane('isochrone').style.zIndex = 350;

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    const originMarker = L.marker([state.origin.lat, state.origin.lon], {
        icon: originIcon(state.origin.name),
        zIndexOffset: 1000,
    })
        .addTo(map)
        .bindPopup(originPopup(state.origin));

    config.cities.forEach((city) => {
        const marker = L.circleMarker([city.lat, city.lon], markerStyle('muted', 0));
        marker.addTo(map);
        marker.bindTooltip(city.name, {
            permanent: true,
            direction: 'top',
            offset: [0, -7],
            className: 'city-label is-muted',
        });
        marker.bindPopup(cityPopup(city));
        state.markers.set(city.id, marker);
    });

    fitMapToOrigin();

    elements.slider.min = config.slider.min;
    elements.slider.max = config.slider.max;
    elements.slider.step = config.slider.step;
    elements.slider.value = state.minutes;
    updateSliderProgress();

    elements.originOptions.forEach((button) => {
        button.addEventListener('click', () => selectOrigin(button.dataset.originId));
    });

    let isochroneTimer = null;
    elements.slider.addEventListener('input', () => {
        state.minutes = Number(elements.slider.value);
        updateSliderProgress();
        render();
        window.clearTimeout(isochroneTimer);
        isochroneTimer = window.setTimeout(() => loadIsochrone(state.minutes), 220);
    });

    Promise.allSettled([loadRouting(state.originId), loadStatistics()]).finally(() => render());
    loadIsochrone(state.minutes, state.originId);

    function selectOrigin(originId) {
        if (!Object.hasOwn(config.origins, originId) || originId === state.originId) {
            return;
        }

        state.originId = originId;
        state.origin = config.origins[originId];
        state.cities = config.cities.map((city) => ({ ...city, travelSeconds: null, distanceKm: null }));
        state.routingReady = false;
        state.routeError = null;
        state.routingRequest += 1;
        state.isochroneRequest += 1;
        window.clearTimeout(isochroneTimer);

        if (state.isochroneLayer) {
            map.removeLayer(state.isochroneLayer);
            state.isochroneLayer = null;
        }

        originMarker
            .setLatLng([state.origin.lat, state.origin.lon])
            .setIcon(originIcon(state.origin.name))
            .setPopupContent(originPopup(state.origin));

        const url = new URL(window.location.href);
        if (originId === config.defaultOrigin) {
            url.searchParams.delete('origin');
        } else {
            url.searchParams.set('origin', originId);
        }
        window.history.replaceState({}, '', url);

        fitMapToOrigin();
        render();
        loadRouting(originId);
        loadIsochrone(state.minutes, originId);
    }

    async function loadRouting(originId) {
        const requestId = ++state.routingRequest;
        try {
            const response = await fetchJson(`${config.endpoints.routing}?action=matrix&origin=${encodeURIComponent(originId)}`);
            if (requestId !== state.routingRequest) return;
            state.cities = response.cities;
            state.routingReady = true;
            state.routeError = response.warning || null;
        } catch (error) {
            if (requestId !== state.routingRequest) return;
            state.routeError = error.message;
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
        } catch (error) {
            state.statsError = error.message;
        }
        render();
    }

    async function loadIsochrone(minutes, originId = state.originId) {
        const requestId = ++state.isochroneRequest;
        elements.mapLoading.textContent = `Beregner ${minutes}-minutters område…`;
        elements.mapLoading.classList.remove('is-hidden', 'is-error');

        try {
            const response = await fetchJson(`${config.endpoints.routing}?action=isochrone&minutes=${minutes}&origin=${encodeURIComponent(originId)}`);
            if (requestId !== state.isochroneRequest) {
                return;
            }
            if (state.isochroneLayer) {
                map.removeLayer(state.isochroneLayer);
            }
            state.isochroneLayer = L.geoJSON(response.geojson, {
                pane: 'isochrone',
                interactive: false,
                style: {
                    color: '#0f766e',
                    weight: 2,
                    opacity: 0.9,
                    fillColor: '#2a9d8f',
                    fillOpacity: 0.16,
                },
            }).addTo(map);
            elements.mapLoading.classList.add('is-hidden');
        } catch (error) {
            if (requestId === state.isochroneRequest) {
                elements.mapLoading.textContent = 'Køretidsområdet kunne ikke hentes';
                elements.mapLoading.classList.add('is-error');
            }
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
        if (!response.ok || payload.ok !== true) {
            throw new Error(payload.error || 'Data kunne ikke hentes.');
        }
        return payload;
    }

    function render() {
        elements.output.value = `${state.minutes} minutter`;
        elements.output.textContent = `${state.minutes} minutter`;
        elements.originIntro.textContent = `Udforsk større arbejdsmarkedsbyer, der kan nås i bil fra ${state.origin.name}.`;
        elements.legendOrigin.textContent = state.origin.name;
        elements.citiesOrigin.textContent = state.origin.name;
        elements.originOptions.forEach((button) => {
            button.setAttribute('aria-pressed', String(button.dataset.originId === state.originId));
        });

        const reachedCities = state.routingReady
            ? state.cities.filter((city) => city.travelSeconds !== null && city.travelSeconds <= state.minutes * 60)
            : [];
        const reachedMunicipalities = uniqueMunicipalities(reachedCities);

        elements.reached.textContent = state.routingReady
            ? `${reachedCities.length} af ${state.cities.length} byer nås`
            : 'Beregner ruter…';
        elements.cities.textContent = state.routingReady ? numberFormat.format(reachedCities.length) : '—';

        renderStatus();
        renderMetrics(reachedMunicipalities, reachedCities);
        renderBranches(reachedMunicipalities);
        renderCityList();
        updateMarkers();
    }

    function renderStatus() {
        const errors = [state.routeError, state.statsError].filter(Boolean);
        if (errors.length > 0) {
            elements.status.textContent = errors.join(' ');
            elements.status.className = 'data-status is-warning';
        } else if (state.routingReady && state.statsReady) {
            elements.status.textContent = `Køretider og ERHV2-tal for ${state.statsYear} er indlæst.`;
            elements.status.className = 'data-status is-ready';
        } else {
            elements.status.textContent = 'Henter aktuelle køretider og jobtal…';
            elements.status.className = 'data-status';
        }
    }

    function renderMetrics(municipalities, reachedCities) {
        if (!state.statsReady) {
            elements.jobs.textContent = '—';
            elements.workplaces.textContent = '—';
            elements.largest.textContent = reachedCities.length > 0 ? 'Afventer jobtal' : '—';
            elements.year.textContent = 'ERHV2';
            return;
        }

        const stats = municipalities
            .map((code) => state.municipalities[code])
            .filter(Boolean);
        const jobs = sumKnown(stats.map((item) => item.jobs));
        const workplaces = sumKnown(stats.map((item) => item.workplaces));
        const largest = [...reachedCities].sort((a, b) => municipalityJobs(b) - municipalityJobs(a))[0];

        elements.jobs.textContent = formatKnown(jobs);
        elements.workplaces.textContent = formatKnown(workplaces);
        elements.largest.textContent = largest ? largest.name : 'Ingen endnu';
        elements.year.textContent = `ERHV2 · ${state.statsYear}`;
    }

    function renderBranches(municipalityCodes) {
        if (!state.statsReady || municipalityCodes.length === 0) {
            elements.branches.innerHTML = `<p class="empty-state">${state.statsReady ? 'Ingen kommuner er nået endnu.' : 'Venter på jobtal…'}</p>`;
            return;
        }

        const branchTotals = new Map();
        municipalityCodes.forEach((code) => {
            const municipality = state.municipalities[code];
            if (!municipality) return;
            Object.values(municipality.branches).forEach((branch) => {
                if (branch.jobs === null) return;
                const existing = branchTotals.get(branch.code) || { name: branch.name, jobs: 0 };
                existing.jobs += branch.jobs;
                branchTotals.set(branch.code, existing);
            });
        });

        const branches = [...branchTotals.values()].sort((a, b) => b.jobs - a.jobs).slice(0, 6);
        const maximum = branches[0]?.jobs || 1;
        elements.branches.innerHTML = branches.map((branch) => `
            <div class="branch-row">
                <div class="branch-meta"><span>${escapeHtml(shortBranchName(branch.name))}</span><strong>${numberFormat.format(branch.jobs)}</strong></div>
                <div class="branch-track"><span style="width:${Math.max(3, (branch.jobs / maximum) * 100).toFixed(1)}%"></span></div>
            </div>
        `).join('');
    }

    function renderCityList() {
        if (!state.routingReady) {
            elements.cityList.innerHTML = '<p class="empty-state">Beregner ruter…</p>';
            return;
        }

        const cities = [...state.cities].sort((a, b) => (a.travelSeconds ?? Infinity) - (b.travelSeconds ?? Infinity));
        elements.cityList.innerHTML = cities.map((city) => {
            const status = cityStatus(city);
            const stats = state.municipalities[city.municipalityCode];
            return `
                <button class="city-row is-${status}" type="button" data-city-id="${escapeHtml(city.id)}">
                    <span class="city-status-dot"></span>
                    <span class="city-main">
                        <strong>${escapeHtml(city.name)}</strong>
                        <small>${escapeHtml(city.municipality)} Kommune · ${formatJobs(stats?.jobs)}</small>
                    </span>
                    <span class="city-route">
                        <strong>${formatMinutes(city.travelSeconds)}</strong>
                        <small>${formatDistance(city.distanceKm)}</small>
                    </span>
                </button>`;
        }).join('');

        elements.cityList.querySelectorAll('[data-city-id]').forEach((button) => {
            button.addEventListener('click', () => {
                const city = state.cities.find((item) => item.id === button.dataset.cityId);
                const marker = state.markers.get(button.dataset.cityId);
                if (city && marker) {
                    map.flyTo([city.lat, city.lon], Math.max(map.getZoom(), 10), { duration: 0.5 });
                    marker.openPopup();
                }
            });
        });
    }

    function updateMarkers() {
        const maxJobs = Math.max(1, ...state.cities.map((city) => municipalityJobs(city)));
        state.cities.forEach((city) => {
            const marker = state.markers.get(city.id);
            if (!marker) return;
            const status = cityStatus(city);
            const jobs = municipalityJobs(city);
            const scale = jobs > 0 ? Math.sqrt(jobs / maxJobs) : 0;
            marker.setStyle(markerStyle(status, scale));
            marker.setRadius(status === 'muted' ? 5 : 7 + scale * 7);
            marker.setPopupContent(cityPopup(city));
            const tooltip = marker.getTooltip();
            if (tooltip) {
                tooltip.options.offset = L.point(0, -(marker.getRadius() + 2));
                const node = tooltip.getElement();
                if (node) node.className = `leaflet-tooltip city-label is-${status}`;
            }
        });
    }

    function markerStyle(status, scale) {
        if (status === 'reached') {
            return { radius: 7 + scale * 7, color: '#ffffff', weight: 2.5, fillColor: '#0f766e', fillOpacity: 0.95 };
        }
        if (status === 'near') {
            return { radius: 8 + scale * 6, color: '#fff7df', weight: 3, fillColor: '#e49a23', fillOpacity: 0.95 };
        }
        return { radius: 5, color: '#64727d', weight: 1.5, fillColor: '#89949d', fillOpacity: 0.55 };
    }

    function cityStatus(city) {
        if (!state.routingReady || city.travelSeconds === null) return 'muted';
        const difference = city.travelSeconds / 60 - state.minutes;
        if (Math.abs(difference) <= 5) return 'near';
        return difference <= 0 ? 'reached' : 'muted';
    }

    function uniqueMunicipalities(cities) {
        return [...new Set(cities.map((city) => city.municipalityCode))];
    }

    function municipalityJobs(city) {
        return state.municipalities[city.municipalityCode]?.jobs || 0;
    }

    function cityPopup(city) {
        const stats = state.municipalities[city.municipalityCode];
        return `<div class="city-popup">
            <strong>${escapeHtml(city.name)}</strong>
            <span>${formatMinutes(city.travelSeconds)} · ${formatDistance(city.distanceKm)}</span>
            <span>${escapeHtml(city.municipality)} Kommune</span>
            <span>${formatJobs(stats?.jobs)}${state.statsYear ? ` (${state.statsYear})` : ''}</span>
        </div>`;
    }

    function updateSliderProgress() {
        const progress = ((state.minutes - config.slider.min) / (config.slider.max - config.slider.min)) * 100;
        elements.slider.style.setProperty('--progress', `${progress}%`);
    }

    function originIcon(name) {
        return L.divIcon({
            className: 'origin-marker-wrap',
            html: `<span class="origin-pulse"></span><span class="origin-marker"></span><span class="origin-label">${escapeHtml(name)}</span>`,
            iconSize: [18, 18],
            iconAnchor: [9, 9],
        });
    }

    function originPopup(origin) {
        return `<strong>${escapeHtml(origin.name)}</strong><br>Valgt udgangspunkt for køretiderne.`;
    }

    function fitMapToOrigin() {
        const bounds = L.latLngBounds(
            [[state.origin.lat, state.origin.lon], ...config.cities.map((city) => [city.lat, city.lon])]
        );
        map.fitBounds(bounds.pad(0.08), { padding: [30, 30] });
    }

    function formatMinutes(seconds) {
        return seconds === null || seconds === undefined ? '—' : `${Math.round(seconds / 60)} min`;
    }

    function formatDistance(distance) {
        return distance === null || distance === undefined ? '—' : `${numberFormat.format(distance)} km`;
    }

    function formatJobs(jobs) {
        return jobs === null || jobs === undefined ? 'jobtal afventer' : `${numberFormat.format(jobs)} job`;
    }

    function sumKnown(values) {
        const known = values.filter((value) => Number.isFinite(value));
        return known.length > 0 ? known.reduce((sum, value) => sum + value, 0) : null;
    }

    function formatKnown(value) {
        return value === null ? '—' : numberFormat.format(value);
    }

    function shortBranchName(name) {
        return name
            .replace('Industri, råstofindvinding og forsyningsvirksomhed', 'Industri og forsyning')
            .replace('Offentlig administration, undervisning og sundhed', 'Offentlig service, undervisning og sundhed');
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
