'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const workerSource = fs.readFileSync(
    path.join(__dirname, '..', 'public', 'service-worker-v2.js'),
    'utf8',
);

function createWorker(fetchImplementation) {
    const handlers = new Map();
    const stores = new Map();
    const caches = {
        async open(name) {
            if (!stores.has(name)) stores.set(name, new Map());
            const entries = stores.get(name);
            return {
                async add(input) {
                    if (typeof input !== 'string') throw new TypeError('Expected URL string');
                    entries.set(new URL(input).href, { kind: 'offline-page' });
                },
                async match(input) {
                    const key = typeof input === 'string' ? input : input.url;
                    return entries.get(new URL(key).href);
                },
                async put(input, response) {
                    entries.set(new URL(input.url).href, response);
                },
            };
        },
        async keys() {
            return [...stores.keys()];
        },
        async delete(name) {
            return stores.delete(name);
        },
    };
    const scope = 'https://school.example.test/';
    const self = {
        registration: { scope },
        location: new URL(scope),
        clients: { claim: async () => {} },
        skipWaiting: async () => {},
        addEventListener(name, callback) {
            handlers.set(name, callback);
        },
    };

    vm.runInNewContext(workerSource, { self, caches, fetch: fetchImplementation, URL, Promise });
    return { caches, handlers };
}

async function installWorker(worker) {
    let installPromise;
    worker.handlers.get('install')({ waitUntil(promise) { installPromise = promise; } });
    await installPromise;
}

function dispatchFetch(worker, request) {
    let responsePromise;
    worker.handlers.get('fetch')({
        request,
        respondWith(promise) { responsePromise = promise; },
    });
    return responsePromise;
}

test('install caches the data-free offline notice', async () => {
    const worker = createWorker(async () => ({ ok: true, clone() { return this; } }));
    await installWorker(worker);

    const cache = await worker.caches.open('connezer-static-v4');
    assert.deepEqual(
        await cache.match('https://school.example.test/offline.html'),
        { kind: 'offline-page' },
    );
});

test('failed navigation uses the notice without caching the requested page', async () => {
    const worker = createWorker(async () => {
        throw new Error('network unavailable');
    });
    await installWorker(worker);

    const privatePage = 'https://school.example.test/student_profile.php?id=12';
    const response = await dispatchFetch(worker, {
        method: 'GET',
        mode: 'navigate',
        url: privatePage,
    });

    assert.deepEqual(await response, { kind: 'offline-page' });
    const cache = await worker.caches.open('connezer-static-v4');
    assert.equal(await cache.match(privatePage), undefined);
});

test('static assets are cached and reused after a network failure', async () => {
    let online = true;
    const networkResponse = { ok: true, clone() { return this; } };
    const worker = createWorker(async () => {
        if (!online) throw new Error('network unavailable');
        return networkResponse;
    });
    await installWorker(worker);

    const assetUrl = 'https://school.example.test/assets/css/app.css?v=194';
    const request = { method: 'GET', mode: 'no-cors', url: assetUrl };
    assert.equal(await dispatchFetch(worker, request), networkResponse);

    const cache = await worker.caches.open('connezer-static-v4');
    assert.equal(await cache.match(assetUrl), networkResponse);

    online = false;
    assert.equal(await dispatchFetch(worker, request), networkResponse);
});
