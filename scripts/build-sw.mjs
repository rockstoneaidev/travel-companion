import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';

/**
 * Stamp the service worker with the identity of the build it belongs to.
 *
 * The worker caches this build's assets and this build's Inertia responses. If its
 * cache name does not change when the build changes, a worker from an old deploy
 * goes on serving that deploy's cached bodies for the same URLs — and because the
 * activate handler only evicts caches whose name is NOT the current one, a constant
 * name evicts nothing, ever. That is what wedged in-app navigation on staging: full
 * page loads worked, tapping a menu item did nothing, and only a hard refresh cured
 * it.
 *
 * So: hash the Vite manifest (which changes whenever any asset does) and write it
 * into the worker. Two consequences, both wanted:
 *
 *   1. The cache name is unique per build, so a new build cannot read an old build's
 *      cache. There is nothing stale left to serve.
 *   2. The worker FILE is byte-different every deploy, which is what makes the
 *      browser notice it at all — it compares bytes, and an unchanged sw.js is an
 *      unchanged worker no matter what the app around it did.
 */
const manifest = 'public/build/manifest.json';
const template = 'resources/sw/sw.template.js';
const output = 'public/sw.js';

const buildId = createHash('sha256').update(readFileSync(manifest)).digest('hex').slice(0, 12);

writeFileSync(output, readFileSync(template, 'utf8').replaceAll('__BUILD_ID__', buildId));

console.log(`sw.js → app-shell-${buildId}`);
