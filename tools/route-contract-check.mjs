#!/usr/bin/env node
/**
 * Every `/v1` route is in the contract, and every contract path is a route.
 *
 * `docs/openapi.yaml` is written before the controllers, on purpose, and it stays true only if
 * something says when it stops being true. Six endpoints had slipped out of it — the whole
 * `/api-clients` family, which is how a shop gets the credentials it signs with, and both `/i18n`
 * catalogue routes, which are the first thing every browser surface asks for. Nothing noticed,
 * because a specification that is merely incomplete still validates.
 *
 * Redocly checks the document is well formed. This checks it describes the API.
 *
 * Run: node tools/route-contract-check.mjs
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/*
 * Paths deliberately outside the contract. Empty today, and kept because the first one will be an
 * operational endpoint somebody adds for a load balancer — documenting that would invite a client
 * to build on it.
 */
const UNDOCUMENTED = new Set();

const routes = JSON.parse(
	execFileSync('php', ['artisan', 'route:list', '--json'], {
		cwd: path.join(root, 'api'),
		encoding: 'utf8',
		maxBuffer: 32 * 1024 * 1024,
	})
);

const spec = fs.readFileSync(path.join(root, 'docs', 'openapi.yaml'), 'utf8');

// Path keys sit at exactly two spaces of indentation under `paths:`.
const documented = new Set(
	[...spec.matchAll(/^ {2}(\/[^\s:]*):$/gm)].map((match) => match[1])
);

const served = new Map();

for (const route of routes) {
	if (!route.uri.startsWith('v1/')) {
		continue;
	}

	const uri = `/${route.uri.slice(3)}`;

	if (UNDOCUMENTED.has(uri)) {
		continue;
	}

	// Optional parameters are one path as far as a reader is concerned.
	served.set(uri.replaceAll('?}', '}'), route.method.split('|')[0]);
}

const missing = [...served.keys()].filter((uri) => !documented.has(uri)).sort();
const phantom = [...documented].filter((uri) => !served.has(uri)).sort();

console.log(`Routes:     ${served.size} under /v1`);
console.log(`Documented: ${documented.size} paths`);
console.log('-'.repeat(68));

if (!missing.length && !phantom.length) {
	console.log('THE CONTRACT DESCRIBES THE API');
	process.exit(0);
}

if (missing.length) {
	console.log('\nServed but not in docs/openapi.yaml:');

	for (const uri of missing) {
		console.log(`  ${served.get(uri).padEnd(7)} ${uri}`);
	}
}

if (phantom.length) {
	console.log('\nIn docs/openapi.yaml but not served — a rename, or an endpoint that went:');

	for (const uri of phantom) {
		console.log(`  ${uri}`);
	}
}

process.exit(1);
