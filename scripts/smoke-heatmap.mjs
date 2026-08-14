import fs from 'node:fs';

const port = Number(process.argv[2] || 9227);
const targetUrl = process.argv[3] || 'https://testbibkort.landogbyforeningen.dk/?view=heatmap&minutes=60';
const screenshot = process.argv[4] || '';
const mobile = process.argv[5] === 'mobile';
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

await call('Page.enable');
await call('Runtime.enable');
if (mobile) {
    await call('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
} else {
    await call('Emulation.clearDeviceMetricsOverride');
}
await call('Page.navigate', { url: targetUrl });
const started = Date.now();
let result = null;
while (Date.now() - started < 30000) {
    await new Promise((resolve) => setTimeout(resolve, 300));
    const evaluation = await call('Runtime.evaluate', {
        returnByValue: true,
        expression: `(() => {
            const toggle = document.querySelector('#heatmap-toggle');
            const legend = document.querySelector('#heatmap-legend');
            const canvas = document.querySelector('.leaflet-heat-surface');
            const loading = document.querySelector('#map-loading');
            return {
                ready: document.readyState,
                active: toggle?.getAttribute('aria-pressed'),
                legendVisible: Boolean(legend && !legend.hidden),
                legendMin: document.querySelector('#heatmap-min')?.textContent?.trim(),
                legendMax: document.querySelector('#heatmap-max')?.textContent?.trim(),
                canvas: Boolean(canvas && canvas.width > 0 && canvas.height > 0),
                sliderEnabled: document.querySelector('#time-slider')?.disabled === false,
                descriptionVisible: document.querySelector('#heatmap-description')?.hidden === false,
                loadingHidden: Boolean(loading?.classList.contains('is-hidden')),
            };
        })()`,
    });
    result = evaluation.result.value;
    if (result?.ready === 'complete' && result.active === 'true' && result.legendVisible && result.canvas && result.sliderEnabled && result.descriptionVisible && result.loadingHidden) break;
}

if (screenshot) {
    const capture = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    fs.writeFileSync(screenshot, Buffer.from(capture.data, 'base64'));
}
const interaction = await call('Runtime.evaluate', {
    returnByValue: true,
    expression: `(() => {
        const slider = document.querySelector('#time-slider');
        const current = Number(slider.value);
        slider.value = String(current >= Number(slider.max) ? current - Number(slider.step) : current + Number(slider.step));
        slider.dispatchEvent(new Event('input', { bubbles: true }));
        return Number(slider.value);
    })()`,
});
await new Promise((resolve) => setTimeout(resolve, 350));
const afterInteraction = (await call('Runtime.evaluate', {
    returnByValue: true,
    expression: `({
        minutes: Number(new URL(location.href).searchParams.get('minutes')),
        legendMin: document.querySelector('#heatmap-min')?.textContent?.trim(),
        canvas: Boolean(document.querySelector('.leaflet-heat-surface')),
        loadingHidden: document.querySelector('#map-loading')?.classList.contains('is-hidden'),
    })`,
})).result.value;
socket.close();
console.log(JSON.stringify({ ...result, interaction: afterInteraction, errors }));
if (!result || result.active !== 'true' || !result.legendVisible || !result.canvas || !result.sliderEnabled || !result.descriptionVisible || !result.loadingHidden || afterInteraction.minutes !== interaction.result.value || !afterInteraction.canvas || !afterInteraction.loadingHidden || errors.length) process.exitCode = 1;
