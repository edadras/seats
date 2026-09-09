/**
 * Every string the seat picker asks for must exist wherever the picker is booted.
 *
 * The picker takes its whole vocabulary from whoever starts it — a hosted site, the WordPress
 * plugin, the plugin's preview page — because it is one shared implementation and must not learn
 * which host it is inside. The cost of that is three lists that can drift apart, and a missing key
 * does not throw: it renders as `undefined`, or as nothing at all, in front of a buyer.
 *
 *   node tools/picker-strings-check.mjs
 */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const root = new URL( '..', import.meta.url ).pathname;
const widget = readFileSync( root + 'shared/seat-picker/widget.js', 'utf8' );

// What the picker actually reads. Both spellings appear because half of it runs inside callbacks.
const asked = [ ...widget.matchAll( /(?:this|self)\.i18n\.([A-Za-z0-9_]+)/g ) ]
	.map( ( match ) => match[ 1 ] );

const keys = [ ...new Set( asked ) ].sort();

const site = JSON.parse( execFileSync( 'php', [
	'-r',
	'$c = require $argv[1]; echo json_encode($c["picker"], JSON_UNESCAPED_UNICODE);',
	root + 'api/lang/en/site.php',
], { encoding: 'utf8' } ) );

const plugin = readFileSync(
	root + 'wordpress-plugin/seatmap-connect/includes/class-seatmap-widget.php', 'utf8' );
const preview = readFileSync( root + 'wordpress-plugin/tools/preview.html', 'utf8' );

const hosts = [
	{ name: 'api/lang/en/site.php (picker)', has: ( key ) => Object.hasOwn( site, key ) },
	{ name: 'the WordPress plugin', has: ( key ) => new RegExp( `'${ key }'\\s*=>` ).test( plugin ) },
	{ name: "the plugin's preview page", has: ( key ) => new RegExp( `\\b${ key }\\s*:` ).test( preview ) },
];

let missing = 0;

for ( const host of hosts ) {
	const gaps = keys.filter( ( key ) => ! host.has( key ) );

	if ( gaps.length ) {
		missing += gaps.length;
		console.log( `  MISSING in ${ host.name }: ${ gaps.join( ', ' ) }` );
	} else {
		console.log( `  ok   ${ host.name } — all ${ keys.length } strings` );
	}
}

console.log( missing ? `\n${ missing } MISSING STRING(S)` : '\nEVERY PICKER STRING IS PROVIDED' );
process.exit( missing ? 1 : 0 );
