#!/usr/bin/env node
/**
 * Fails the build when a locale has fallen behind (ADR-0005 §2).
 *
 * The rule this enforces is the one every project with several languages breaks: strings that were
 * there when the languages were added get translated, and every string added afterwards is written
 * inline in English, because nothing stops it. This stops it.
 *
 * It checks four things, and the last two matter more than they look:
 *
 *   1. every key in the reference locale exists in every other locale;
 *   2. no locale carries a key the reference no longer has — a rename that left a stale
 *      translation behind, which reads as correct and is not;
 *   3. every placeholder in a key appears in every translation of that key. A translation that
 *      silently drops `:max` tells a buyer they can select up to seats;
 *   4. placeholder *shape* does not change between locales — `%d` in one and `:count` in another
 *      means one of them renders literally.
 *
 * Run: node tools/i18n-check.mjs
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const langDir = path.join(root, 'api', 'lang');
const REFERENCE = 'en';

/** Locales the platform claims to speak, read from the one place that decides (Locales::ALL). */
function supportedLocales() {
    const source = fs.readFileSync(
        path.join(root, 'api', 'app', 'Support', 'Locale', 'Locales.php'),
        'utf8'
    );

    const block = source.match(/public const ALL = \[([\s\S]*?)\n    \];/);

    if (!block) {
        throw new Error('Could not find Locales::ALL — has the registry moved?');
    }

    return [...block[1].matchAll(/^\s*'([a-z]{2})'\s*=>/gm)].map((m) => m[1]);
}

/**
 * Read a PHP catalogue as JSON.
 *
 * Shelling out to PHP rather than parsing the array here: these files are PHP, and a second
 * half-parser would disagree with the real one on exactly the day it mattered.
 */
function readCatalogue(file) {
    const json = execFileSync(
        'php',
        ['-r', 'echo json_encode(require $argv[1], JSON_UNESCAPED_UNICODE);', file],
        { encoding: 'utf8' }
    );

    return JSON.parse(json);
}

/** Flatten to `a.b.c` keys, so a nested catalogue compares like a flat one. */
function flatten(value, prefix = '', out = {}) {
    for (const [key, entry] of Object.entries(value)) {
        const full = prefix ? `${prefix}.${key}` : key;

        if (entry && typeof entry === 'object' && !Array.isArray(entry)) {
            flatten(entry, full, out);
        } else {
            out[full] = entry;
        }
    }

    return out;
}

/**
 * Every substitution a string performs, as a sorted list.
 *
 * Both shapes are recognised: Laravel's `:name`, and the printf shapes the seat picker uses because
 * it learned them from WordPress. `%%` is an escaped percent and is not a placeholder.
 */
function placeholders(text) {
    if (typeof text !== 'string') {
        return [];
    }

    const found = [
        ...text.replace(/%%/g, '').matchAll(/%(\d+\$)?[sd]/g),
        ...text.matchAll(/(?<![\w:]):[a-zA-Z][a-zA-Z0-9_]*/g),
    ].map((m) => m[0]);

    return [...new Set(found)].sort();
}

const locales = supportedLocales();
const namespaces = fs
    .readdirSync(path.join(langDir, REFERENCE))
    .filter((f) => f.endsWith('.php'))
    .map((f) => f.replace(/\.php$/, ''));

const problems = [];
let drafts = 0;
let checked = 0;

for (const locale of locales) {
    if (!fs.existsSync(path.join(langDir, locale))) {
        problems.push(`${locale}: no catalogue directory at all (api/lang/${locale})`);
        continue;
    }
}

for (const namespace of namespaces) {
    const referencePath = path.join(langDir, REFERENCE, `${namespace}.php`);
    const reference = flatten(readCatalogue(referencePath));

    for (const locale of locales) {
        if (locale === REFERENCE) continue;

        const file = path.join(langDir, locale, `${namespace}.php`);

        if (!fs.existsSync(file)) {
            problems.push(`${locale}/${namespace}.php is missing entirely`);
            continue;
        }

        const translated = flatten(readCatalogue(file));

        for (const [key, english] of Object.entries(reference)) {
            checked++;

            if (!(key in translated)) {
                problems.push(`${locale}/${namespace}: missing key "${key}"`);
                continue;
            }

            const value = translated[key];

            // A draft is an honest state, not a failure: it passes here and the panel counts it.
            if (value && typeof value === 'object' && value._draft) {
                drafts++;
                continue;
            }

            if (typeof value !== 'string' || '' === value.trim()) {
                problems.push(`${locale}/${namespace}: "${key}" is empty`);
                continue;
            }

            const expected = placeholders(english);
            const actual = placeholders(value);

            if (expected.join('|') !== actual.join('|')) {
                problems.push(
                    `${locale}/${namespace}: "${key}" has placeholders [${actual.join(', ')}] ` +
                        `but ${REFERENCE} has [${expected.join(', ')}]`
                );
            }
        }

        for (const key of Object.keys(translated)) {
            if (!(key in reference)) {
                problems.push(
                    `${locale}/${namespace}: "${key}" is not in ${REFERENCE} — a rename left it behind`
                );
            }
        }
    }
}

const width = 68;
console.log(`Locales:    ${locales.join(', ')}`);
console.log(`Namespaces: ${namespaces.join(', ')}`);
console.log(`Compared:   ${checked} key/locale pairs`);

if (drafts) {
    console.log(`Drafts:     ${drafts} entries marked for review`);
}

console.log('-'.repeat(width));

if (problems.length) {
    for (const problem of problems) {
        console.error(`  ${problem}`);
    }

    console.error('-'.repeat(width));
    console.error(`${problems.length} PROBLEM(S). No locale may fall behind ${REFERENCE}.`);
    process.exit(1);
}

console.log('EVERY LOCALE IS COMPLETE');
