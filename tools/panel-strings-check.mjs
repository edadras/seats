#!/usr/bin/env node
/**
 * The panel's keys and its catalogue have to agree, in both directions.
 *
 * `tools/i18n-check.mjs` keeps the six locales level with each other. It cannot see the panel: a key
 * can be perfectly translated in six languages and looked up nowhere, and a screen can look up a key
 * nobody ever wrote. Both failures are silent — `t()` never throws, it returns the last segment of
 * the key — so a mistyped `panel.venues.desciption` renders the word "desciption" on the screen and
 * nothing anywhere goes red.
 *
 * So this reads `api/public/editor/js/*.js` and asserts:
 *
 *   1. every `panel.…` key the panel looks up exists in api/lang/en/panel.php;
 *   2. every key in that catalogue is looked up by something.
 *
 * Keys built at run time — `'panel.tools.' + tool.key` — are counted as a prefix, which marks
 * everything under it as used. That is deliberately loose: the alternative is enumerating each
 * `tool.key` here, which is a second copy of the tool list.
 *
 * Run: node tools/panel-strings-check.mjs
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const catalogue = path.join(root, 'api', 'lang', 'en', 'panel.php');
const scriptDir = path.join(root, 'api', 'public', 'editor', 'js');

function flatten(value, prefix = '', out = []) {
	for (const [key, entry] of Object.entries(value)) {
		const full = prefix ? `${prefix}.${key}` : key;

		if (entry && typeof entry === 'object' && !Array.isArray(entry)) {
			flatten(entry, full, out);
		} else {
			out.push(full);
		}
	}

	return out;
}

const defined = new Set(
	flatten(
		JSON.parse(
			execFileSync('php', ['-r', 'echo json_encode(require $argv[1], JSON_UNESCAPED_UNICODE);', catalogue], {
				encoding: 'utf8',
			})
		)
	).map((key) => `panel.${key}`)
);

const exact = new Set();
const prefixes = new Set();

for (const file of fs.readdirSync(scriptDir).filter((f) => f.endsWith('.js'))) {
	const source = fs.readFileSync(path.join(scriptDir, file), 'utf8');

	// 'panel.a.b' followed by a + is a key being assembled; anything else is a whole key.
	for (const match of source.matchAll(/'(panel\.[A-Za-z0-9_.]*)'(\s*\+)?/g)) {
		(match[2] ? prefixes : exact).add(match[1]);
	}
}

const problems = [];

for (const key of [...exact].sort()) {
	if (!defined.has(key)) {
		problems.push(`looked up but not in the catalogue: ${key}`);
	}
}

for (const key of [...defined].sort()) {
	const used = exact.has(key) || [...prefixes].some((prefix) => key.startsWith(prefix));

	if (!used) {
		problems.push(`in the catalogue but nothing looks it up: ${key}`);
	}
}

console.log(`Catalogue:  ${defined.size} keys in api/lang/en/panel.php`);
console.log(`Looked up:  ${exact.size} keys, ${prefixes.size} built at run time`);
console.log('-'.repeat(68));

if (problems.length) {
	for (const problem of problems) {
		console.error(`  ${problem}`);
	}

	console.error('-'.repeat(68));
	console.error(`${problems.length} PROBLEM(S). The panel and its catalogue have drifted apart.`);
	process.exit(1);
}

console.log('THE PANEL AND ITS CATALOGUE AGREE');
