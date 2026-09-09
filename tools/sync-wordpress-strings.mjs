#!/usr/bin/env node
/**
 * Builds the WordPress plugin's translations from the platform's catalogues.
 *
 * The plugin speaks gettext, because that is what WordPress speaks: `__( 'Total', 'seatmap-connect' )`
 * in the source, a `.pot` template, a `.po` per locale and a compiled `.mo` beside it. What is
 * unusual here is where the words come from. They are not typed into the `.po` files; they are
 * generated, from the same two catalogues everything else on this platform reads:
 *
 *   - the seat picker's own vocabulary, from `api/lang/<locale>/site.php` under `picker`. This is
 *     the whole point: the picker is one component with one set of words, and a buyer choosing a
 *     seat on a WooCommerce shop reads what a buyer on a hosted site reads.
 *   - everything the plugin alone says — its settings screen, its cart and order notices, its
 *     block — from `api/lang/<locale>/wordpress.php`, keyed by the English msgid, because in
 *     gettext the English string *is* the key.
 *
 * A picker string is taken from `site.php` only where the plugin's English is *identical* to the
 * site's. Where the two deliberately differ they are different strings and get their own
 * translation: the plugin's button says "Reserve and add to cart" because WooCommerce has a cart,
 * and the hosted site's says "Reserve these seats" because it does not. Matching those by key
 * quietly put the wrong sentence on the button in five languages, which is why the rule is what it
 * is rather than the other way round.
 *
 * That trade is deliberate and worth naming: a translator cannot edit a `.po` by hand and keep
 * their edit, which is the normal WordPress workflow. In exchange, no word on this platform is
 * translated twice, and `tools/i18n-check.mjs` guards the plugin's six languages with the same rule
 * it applies to everything else. CI runs this and fails on a diff.
 *
 * Run: node tools/sync-wordpress-strings.mjs
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const plugin = path.join(root, 'wordpress-plugin', 'seatmap-connect');
const languages = path.join(plugin, 'languages');
const DOMAIN = 'seatmap-connect';

/* WordPress locale codes. The platform speaks six languages; English is the source. */
const LOCALES = { fa: 'fa_IR', ar: 'ar', de: 'de_DE', fr: 'fr_FR', it: 'it_IT' };

/* The block editor's script, whose translations WordPress wants as JSON rather than as a .mo. */
const SCRIPT_FILE = 'blocks/editor.js';

/** Read a PHP catalogue as JSON, by asking PHP — never by half-parsing it here. */
function catalogue(locale, namespace) {
	return JSON.parse(
		execFileSync(
			'php',
			[
				'-r',
				'echo json_encode(require $argv[1], JSON_UNESCAPED_UNICODE);',
				path.join(root, 'api', 'lang', locale, `${namespace}.php`),
			],
			{ encoding: 'utf8' }
		)
	);
}

/* ------------------------------------------------------------------------------- extraction */

const unescape = (value) => value.replace(/\\'/g, "'").replace(/\\\\/g, '\\');

/** The `translators:` note directly above a call, which is what a translator needs to work. */
function comment(lines, index) {
	const above = (lines[index - 1] ?? '').trim();
	const match = above.match(/^\/\*\s*(translators:.*?)\s*\*\/$/i);

	return match ? match[1] : null;
}

function remember(found, msgid, file, line, lines, index) {
	if (!found.has(msgid)) {
		found.set(msgid, { msgid, references: [], comment: comment(lines, index) });
	}

	const entry = found.get(msgid);

	entry.references.push(`${file}:${line}`);
	entry.comment ??= comment(lines, index);

	return entry;
}

/**
 * Every translatable string in the plugin, with where it came from.
 *
 * A hand-rolled scan rather than `wp i18n make-pot`: that needs WP-CLI and a PHP parser, and what
 * is needed here is one regex over a plugin whose call sites are all written the same way. It is
 * strict about that on purpose — a gettext call with a variable in it would be missed in silence,
 * so the scan refuses any call whose first argument is not a plain single-quoted string.
 */
function extract() {
	const files = [
		'seatmap-connect.php',
		...fs.readdirSync(path.join(plugin, 'includes')).sort().map((f) => `includes/${f}`),
		SCRIPT_FILE,
	];

	const found = new Map();
	const loose = [];

	for (const file of files) {
		const lines = fs.readFileSync(path.join(plugin, file), 'utf8').split('\n');

		lines.forEach((line, index) => {
			for (const call of line.matchAll(
				/\b(esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e|_n|_x)\s*\(\s*([^\s)])/g
			)) {
				if (call[2] !== "'") {
					loose.push(`${file}:${index + 1}  ${call[1]}() does not start with a literal`);
				}
			}

			for (const call of line.matchAll(
				/\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e)\s*\(\s*'((?:[^'\\]|\\.)*)'/g
			)) {
				remember(found, unescape(call[1]), file, index + 1, lines, index);
			}

			for (const call of line.matchAll(
				/\b_n\s*\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'/g
			)) {
				remember(found, unescape(call[1]), file, index + 1, lines, index).plural = unescape(call[2]);
			}
		});
	}

	if (loose.length) {
		throw new Error(`Strings that cannot be extracted:\n  ${loose.join('\n  ')}`);
	}

	return found;
}

/**
 * Picker msgids the plugin shares word for word with the hosted site, mapped to their key.
 *
 * The plugin hands the picker a keyed array and `api/lang/*` holds the same keys — but a key is
 * only enough to share a *translation* when the two say the same thing in English. Where they
 * differ on purpose they are two strings, and this map deliberately leaves those out so the tool
 * demands a translation of the plugin's own wording instead.
 */
function sharedPickerKeys() {
	const source = fs.readFileSync(path.join(plugin, 'includes', 'class-seatmap-widget.php'), 'utf8');
	const block = source.match(/'i18n'\s*=>\s*array\(([\s\S]*?)\n\t{6}\),/);

	if (!block) {
		throw new Error('Could not find the picker string array in class-seatmap-widget.php');
	}

	const english = catalogue('en', 'site').picker;
	const shared = new Map();
	const diverged = [];

	for (const line of block[1].matchAll(/'(\w+)'\s*=>\s*__\(\s*'((?:[^'\\]|\\.)*)'/g)) {
		const key = line[1];
		const msgid = unescape(line[2]);

		if (english[key] === msgid) {
			shared.set(msgid, key);
		} else {
			diverged.push(`${key}: plugin "${msgid}" vs site "${english[key] ?? '(absent)'}"`);
		}
	}

	return { shared, diverged };
}

/* ------------------------------------------------------------------------------ po and mo */

const escape = (value) =>
	String(value)
		.replace(/\\/g, '\\\\')
		.replace(/"/g, '\\"')
		.replace(/\n/g, '\\n')
		.replace(/\t/g, '\\t');

function poFile(entries, { translated, header }) {
	const lines = [
		'# Generated by tools/sync-wordpress-strings.mjs — DO NOT EDIT.',
		'# The words come from api/lang/<locale>/site.php and api/lang/<locale>/wordpress.php.',
		'msgid ""',
		'msgstr ""',
		...header.map((line) => `"${escape(line)}\\n"`),
		'',
	];

	for (const entry of entries) {
		if (entry.comment) {
			lines.push(`#. ${entry.comment}`);
		}

		lines.push(`#: ${entry.references.join(' ')}`);
		lines.push(`msgid "${escape(entry.msgid)}"`);

		if (entry.plural) {
			lines.push(`msgid_plural "${escape(entry.plural)}"`);
			lines.push(`msgstr[0] "${escape(translated ? entry.translations[0] : '')}"`);
			lines.push(`msgstr[1] "${escape(translated ? entry.translations[1] : '')}"`);
		} else {
			lines.push(`msgstr "${escape(translated ? entry.translation : '')}"`);
		}

		lines.push('');
	}

	return lines.join('\n');
}

/**
 * Compile to the binary catalogue WordPress actually loads.
 *
 * Written out rather than shelled to `msgfmt`, which is not installed everywhere this runs and is
 * one more thing a contributor would have to have. The format is a small header, two string tables
 * and two offset tables — the GNU gettext manual, "The Format of GNU MO Files". A plural is one
 * entry whose halves are separated by a NUL byte, which is why they are joined rather than listed.
 */
function moFile({ header, list }) {
	const NUL = String.fromCharCode(0);
	const rows = [['', header]];

	for (const entry of list) {
		rows.push(
			entry.plural
				? [entry.msgid + NUL + entry.plural, entry.translations.join(NUL)]
				: [entry.msgid, entry.translation]
		);
	}

	// Sorted by msgid, as the format requires: the loader binary-searches this table.
	rows.sort((a, b) => Buffer.from(a[0], 'utf8').compare(Buffer.from(b[0], 'utf8')));

	const ids = rows.map((row) => Buffer.from(row[0], 'utf8'));
	const values = rows.map((row) => Buffer.from(row[1], 'utf8'));

	const count = rows.length;
	const idTableAt = 28;
	const valueTableAt = idTableAt + count * 8;
	let offset = valueTableAt + count * 8;

	const head = Buffer.alloc(28);

	head.writeUInt32LE(0x950412de, 0); // Magic, little-endian.
	head.writeUInt32LE(0, 4); // Revision.
	head.writeUInt32LE(count, 8);
	head.writeUInt32LE(idTableAt, 12);
	head.writeUInt32LE(valueTableAt, 16);
	head.writeUInt32LE(0, 20); // No hash table, so the loader scans.
	head.writeUInt32LE(offset, 24);

	const idTable = Buffer.alloc(count * 8);
	const valueTable = Buffer.alloc(count * 8);

	ids.forEach((id, index) => {
		idTable.writeUInt32LE(id.length, index * 8);
		idTable.writeUInt32LE(offset, index * 8 + 4);
		offset += id.length + 1; // Every string is NUL-terminated.
	});

	values.forEach((value, index) => {
		valueTable.writeUInt32LE(value.length, index * 8);
		valueTable.writeUInt32LE(offset, index * 8 + 4);
		offset += value.length + 1;
	});

	const terminator = Buffer.alloc(1);

	return Buffer.concat([
		head,
		idTable,
		valueTable,
		...ids.flatMap((id) => [id, terminator]),
		...values.flatMap((value) => [value, terminator]),
	]);
}

/* ------------------------------------------------------------------------------------ run */

const entries = [...extract().values()];
const { shared: pickerKeys, diverged } = sharedPickerKeys();
const written = [];

function write(file, contents) {
	const next = Buffer.isBuffer(contents) ? contents : Buffer.from(contents, 'utf8');
	const before = fs.existsSync(file) ? fs.readFileSync(file) : null;

	if (!before || !before.equals(next)) {
		fs.writeFileSync(file, next);
		written.push(path.relative(root, file));
	}
}

const commonHeader = [
	'Project-Id-Version: Seatmap Connect 1.0.0',
	'Report-Msgid-Bugs-To: https://github.com/edadras/seats/issues',
	'MIME-Version: 1.0',
	'Content-Type: text/plain; charset=UTF-8',
	'Content-Transfer-Encoding: 8bit',
	'Plural-Forms: nplurals=2; plural=(n != 1);',
	`X-Domain: ${DOMAIN}`,
];

write(
	path.join(languages, `${DOMAIN}.pot`),
	poFile(entries, { translated: false, header: commonHeader })
);

const missing = [];
const scriptHash = execFileSync('php', ['-r', 'echo md5($argv[1]);', SCRIPT_FILE], {
	encoding: 'utf8',
});

for (const [locale, wpLocale] of Object.entries(LOCALES)) {
	const picker = catalogue(locale, 'site').picker;
	const own = catalogue(locale, 'wordpress');

	const translated = entries.map((entry) => {
		const key = pickerKeys.get(entry.msgid);
		const value = key ? picker[key] : own[entry.msgid];

		if ('string' !== typeof value || '' === value.trim()) {
			missing.push(
				`${wpLocale}: ${key ? `site.picker.${key}` : `wordpress.php["${entry.msgid}"]`}`
			);
		}

		// A plural keeps both shapes in one catalogue entry, separated by a pipe: they are one
		// sentence said two ways, and splitting them across keys invites one to be forgotten.
		const parts = String(value ?? '').split('|');

		return entry.plural
			? { ...entry, translations: [parts[0] ?? '', parts[1] ?? parts[0] ?? ''] }
			: { ...entry, translation: value ?? '' };
	});

	const header = [...commonHeader, `Language: ${wpLocale}`];

	write(
		path.join(languages, `${DOMAIN}-${wpLocale}.po`),
		poFile(translated, { translated: true, header })
	);

	write(
		path.join(languages, `${DOMAIN}-${wpLocale}.mo`),
		moFile({ header: header.map((line) => `${line}\n`).join(''), list: translated })
	);

	/*
	 * The block editor's strings, as WordPress wants them for JavaScript: a JED 1.x fragment named
	 * after the md5 of the script's path relative to the plugin. `wp_set_script_translations` looks
	 * for exactly this file and falls back to English in silence if it is not there.
	 */
	const script = translated.filter((entry) =>
		entry.references.some((reference) => reference.startsWith(SCRIPT_FILE))
	);

	write(
		path.join(languages, `${DOMAIN}-${wpLocale}-${scriptHash}.json`),
		`${JSON.stringify(
			{
				'translation-revision-date': '2026-09-09 00:00:00+0000',
				generator: 'tools/sync-wordpress-strings.mjs',
				domain: 'messages',
				locale_data: {
					messages: {
						'': {
							domain: 'messages',
							lang: wpLocale,
							'plural-forms': 'nplurals=2; plural=(n != 1);',
						},
						...Object.fromEntries(script.map((entry) => [entry.msgid, [entry.translation]])),
					},
				},
			},
			null,
			1
		)}\n`
	);
}

if (missing.length) {
	console.error('Strings with no translation:');

	for (const line of [...new Set(missing)].sort()) {
		console.error(`  ${line}`);
	}

	process.exit(1);
}

console.log(`Strings:  ${entries.length} in the plugin`);
console.log(`Shared:   ${pickerKeys.size} picker strings taken from site.php`);
console.log(`Locales:  ${Object.values(LOCALES).join(', ')}`);

if (diverged.length) {
	console.log('Its own:  picker strings the plugin words differently, translated in wordpress.php');

	for (const line of diverged) {
		console.log(`          ${line}`);
	}
}

console.log(
	written.length ? written.map((file) => `Wrote     ${file}`).join('\n') : 'Everything was already in step.'
);
