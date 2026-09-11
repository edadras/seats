#!/usr/bin/env node
/**
 * A screen's keys and its catalogue have to agree, in both directions.
 *
 * `tools/i18n-check.mjs` keeps the six locales level with each other. It cannot see the screens: a
 * key can be perfectly translated in six languages and looked up nowhere, and a screen can look up
 * a key nobody ever wrote. Both failures are silent — `t()` never throws, it returns the last
 * segment of the key — so a mistyped `panel.venues.desciption` renders the word "desciption" on the
 * screen and nothing anywhere goes red.
 *
 * So this reads `api/public/editor/js/*.js` and asserts, for the panel and for the platform console
 * separately:
 *
 *   1. every key the JavaScript looks up exists in the matching catalogue;
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
const scriptDir = path.join(root, 'api', 'public', 'editor', 'js');
const phpDir = path.join(root, 'api', 'app');
const viewDir = path.join(root, 'api', 'resources', 'views');

/*
 * The console is checked apart from the panel because it is a separate application that happens to
 * live in the same directory: its catalogue is rendered into its own page rather than served from
 * /v1/i18n, and only console.js may read it. Checking them together would let a `console.…` key
 * looked up by the panel pass, which is precisely the mistake worth catching.
 *
 * `team` is listed as a namespace the console may borrow from: role names have one home, and the
 * console page is handed that one sub-array rather than a second translation of the same six words.
 */
const SURFACES = [
	// The panel is not only JavaScript: a handful of its words are written by the server, because
	// the thing they label is a file the server composes — a CSV heading, or the settlement
	// statement's Blade template, is a panel string that the server has to know. Controllers and
	// views are read too, or the check would call those keys dead.
	{ label: 'panel', namespaces: ['panel'], scripts: (file) => file !== 'console.js', php: true },
	{ label: 'console', namespaces: ['console'], borrowed: ['team'], scripts: (file) => file === 'console.js' },
];

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

function catalogueKeys(namespace) {
	const file = path.join(root, 'api', 'lang', 'en', `${namespace}.php`);
	const json = execFileSync(
		'php',
		['-r', 'echo json_encode(require $argv[1], JSON_UNESCAPED_UNICODE);', file],
		{ encoding: 'utf8' }
	);

	return flatten(JSON.parse(json)).map((key) => `${namespace}.${key}`);
}

/** Every PHP file under a directory, so a server-side lookup counts as a lookup. */
function phpFiles(directory) {
	return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
		const full = path.join(directory, entry.name);

		if (entry.isDirectory()) {
			return phpFiles(full);
		}

		return entry.name.endsWith('.php') ? [full] : [];
	});
}

const problems = [];

for (const surface of SURFACES) {
	// Only the surface's own namespaces have to be fully used; a borrowed one is somebody else's
	// catalogue, and the console reading six of `team`'s keys does not make the rest of it dead.
	const owned = new Set(surface.namespaces.flatMap(catalogueKeys));
	const borrowed = new Set((surface.borrowed ?? []).flatMap(catalogueKeys));
	const reachable = [...surface.namespaces, ...(surface.borrowed ?? [])];
	const pattern = new RegExp(`'((?:${reachable.join('|')})\\.[A-Za-z0-9_.]*)'(\\s*\\+)?`, 'g');
	/*
	 * The same thing, for PHP, whose concatenation operator is a dot rather than a plus.
	 *
	 * Without this a key assembled on the server — `__('panel.webhooks.types.'.$type)` — is read as
	 * a whole key called `panel.webhooks.types.`, which is reported as missing, and everything
	 * actually under that prefix is reported as dead. Both halves of the answer are wrong, and the
	 * only reason it was never noticed is that nothing built a panel key in PHP until now.
	 */
	const phpPattern = new RegExp(
		`'((?:${reachable.join('|')})\\.[A-Za-z0-9_.]*)'(\\s*[.+])?`,
		'g'
	);

	const exact = new Set();
	const prefixes = new Set();

	for (const file of fs.readdirSync(scriptDir).filter((f) => f.endsWith('.js') && surface.scripts(f))) {
		const source = fs.readFileSync(path.join(scriptDir, file), 'utf8');

		// 'panel.a.b' followed by a + is a key being assembled; anything else is a whole key.
		for (const match of source.matchAll(pattern)) {
			(match[2] ? prefixes : exact).add(match[1]);
		}
	}

	if (surface.php) {
		for (const file of [...phpFiles(phpDir), ...phpFiles(viewDir)]) {
			for (const match of fs.readFileSync(file, 'utf8').matchAll(phpPattern)) {
				(match[2] ? prefixes : exact).add(match[1]);
			}
		}
	}

	for (const key of [...exact].sort()) {
		if (!owned.has(key) && !borrowed.has(key)) {
			problems.push(`${surface.label}: looked up but not in the catalogue: ${key}`);
		}
	}

	for (const key of [...owned].sort()) {
		const used = exact.has(key) || [...prefixes].some((prefix) => key.startsWith(prefix));

		if (!used) {
			problems.push(`${surface.label}: in the catalogue but nothing looks it up: ${key}`);
		}
	}

	console.log(
		`${surface.label.padEnd(9)} ${owned.size} keys defined, ` +
			`${exact.size} looked up by name, ${prefixes.size} built at run time`
	);
}

console.log('-'.repeat(68));

if (problems.length) {
	for (const problem of problems) {
		console.error(`  ${problem}`);
	}

	console.error('-'.repeat(68));
	console.error(`${problems.length} PROBLEM(S). A screen and its catalogue have drifted apart.`);
	process.exit(1);
}

console.log('EVERY SCREEN AGREES WITH ITS CATALOGUE');
