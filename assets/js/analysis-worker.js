'use strict';

importScripts('https://unpkg.com/@turf/turf@7.2.0/turf.min.js');

self.addEventListener('message', ({ data }) => {
    try {
        const zoneFeatures = data.geojson?.features || [];
        const coverage = {};

        (data.boundaries || []).forEach(({ code, boundary }) => {
            const boundaryArea = turf.area(boundary);
            if (!Number.isFinite(boundaryArea) || boundaryArea <= 0) {
                coverage[code] = { fraction: 0, overlapAreaM2: 0, municipalityAreaM2: 0 };
                return;
            }

            let overlapArea = 0;
            zoneFeatures.forEach((zone) => {
                const overlap = turf.intersect(turf.featureCollection([boundary, zone]));
                if (overlap) overlapArea += turf.area(overlap);
            });
            const boundedOverlapArea = Math.min(boundaryArea, Math.max(0, overlapArea));
            coverage[code] = {
                fraction: boundedOverlapArea / boundaryArea,
                overlapAreaM2: Math.round(boundedOverlapArea),
                municipalityAreaM2: Math.round(boundaryArea),
            };
        });

        self.postMessage({ ok: true, coverage });
    } catch (error) {
        self.postMessage({ ok: false, error: error instanceof Error ? error.message : 'Geografiberegningen fejlede.' });
    }
});
