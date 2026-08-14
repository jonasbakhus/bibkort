const port = Number(process.argv[2] || 9227);
const targetUrl = process.argv[3] || 'https://testbibkort.landogbyforeningen.dk/?minutes=65&origin=baekmarksbro&compare=bonnet';
const pages = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
const page = pages.find((candidate) => candidate.type === 'page');
if (!page) throw new Error('Fandt ingen Chrome-side via CDP.');

const socket = new WebSocket(page.webSocketDebuggerUrl);
let sequence = 0;
const pending = new Map();
const errors = [];
socket.onmessage = ({ data }) => {
    const message = JSON.parse(data);
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails?.text || 'JavaScript-fejl');
    if (message.id && pending.has(message.id)) {
        const { resolve, reject } = pending.get(message.id);
        pending.delete(message.id);
        message.error ? reject(new Error(message.error.message)) : resolve(message.result);
    }
};
await new Promise((resolve, reject) => {
    socket.onopen = resolve;
    socket.onerror = reject;
});
const call = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++sequence;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
});
const evaluate = async (expression) => (await call('Runtime.evaluate', { expression, returnByValue: true })).result.value;
const waitFor = async (expression, timeout = 45000) => {
    const started = Date.now();
    let value;
    while (Date.now() - started < timeout) {
        await new Promise((resolve) => setTimeout(resolve, 350));
        value = await evaluate(expression);
        if (value?.ready) return value;
    }
    return value;
};

await call('Page.enable');
await call('Runtime.enable');
await call('Page.navigate', { url: targetUrl });
const standard = await waitFor(`(() => {
    const a = document.querySelector('#compare-primary-jobs')?.textContent?.trim();
    const b = document.querySelector('#compare-secondary-jobs')?.textContent?.trim();
    const hidden = document.querySelector('#map-loading')?.classList.contains('is-hidden');
    return { ready: a === '190.332' && b === '129.218' && hidden, a, b, hidden };
})()`);

await evaluate(`document.querySelector('#heatmap-toggle').click()`);
const heatmap = await waitFor(`(() => {
    const active = document.querySelector('#heatmap-toggle')?.getAttribute('aria-pressed') === 'true';
    const canvas = document.querySelector('.leaflet-heat-surface');
    const primaryZone = document.querySelector('.leaflet-isochrone-primary-pane path');
    const secondaryZone = document.querySelector('.leaflet-isochrone-secondary-pane path');
    const a = document.querySelector('#compare-primary-jobs')?.textContent?.trim();
    const b = document.querySelector('#compare-secondary-jobs')?.textContent?.trim();
    const comparisonVisible = document.querySelector('#comparison-panel')?.hidden === false;
    const hidden = document.querySelector('#map-loading')?.classList.contains('is-hidden');
    return {
        ready: active && Boolean(canvas) && Boolean(primaryZone) && Boolean(secondaryZone)
            && a === '190.332' && b === '129.218' && comparisonVisible && hidden,
        active,
        canvas: Boolean(canvas),
        primaryZone: Boolean(primaryZone),
        secondaryZone: Boolean(secondaryZone),
        a,
        b,
        comparisonVisible,
        hidden,
    };
})()`);

await evaluate(`document.querySelector('#heatmap-toggle').click()`);
const restored = await waitFor(`(() => {
    const active = document.querySelector('#heatmap-toggle')?.getAttribute('aria-pressed') === 'true';
    const a = document.querySelector('#compare-primary-jobs')?.textContent?.trim();
    const b = document.querySelector('#compare-secondary-jobs')?.textContent?.trim();
    const hidden = document.querySelector('#map-loading')?.classList.contains('is-hidden');
    return { ready: !active && a === '190.332' && b === '129.218' && hidden, active, a, b, hidden };
})()`);

await evaluate(`document.querySelector('#heatmap-toggle').click()`);
await waitFor(`(() => ({
    ready: document.querySelector('#heatmap-toggle')?.getAttribute('aria-pressed') === 'true'
        && Boolean(document.querySelector('.leaflet-heat-surface')),
}))()`);
await evaluate(`(() => {
    const select = document.querySelector('#origin-primary');
    select.value = '';
    select.dispatchEvent(new Event('change', { bubbles: true }));
})()`);
const pureHeatmap = await waitFor(`(() => {
    const url = new URL(location.href);
    const canvas = document.querySelector('.leaflet-heat-surface');
    const loadingHidden = document.querySelector('#map-loading')?.classList.contains('is-hidden');
    const resultsHidden = document.querySelector('#single-results')?.hidden === true
        && document.querySelector('#comparison-panel')?.hidden === true;
    const noZones = !document.querySelector('.leaflet-isochrone-primary-pane path')
        && !document.querySelector('.leaflet-isochrone-secondary-pane path');
    const sliderEnabled = document.querySelector('#time-slider')?.disabled === false;
    const noOrigin = !url.searchParams.has('origin') && !url.searchParams.has('compare');
    return {
        ready: Boolean(canvas) && loadingHidden && resultsHidden && noZones && sliderEnabled && noOrigin,
        canvas: Boolean(canvas), loadingHidden, resultsHidden, noZones, sliderEnabled, noOrigin,
    };
})()`);

socket.close();
console.log(JSON.stringify({ standard, heatmap, restored, pureHeatmap, errors }));
if (!standard?.ready || !heatmap?.ready || !restored?.ready || !pureHeatmap?.ready || errors.length) process.exitCode = 1;
