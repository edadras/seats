/**
 * A programme manager, from the organiser appointing them to their first morning, in Chromium.
 *
 * The claim is a multiplication: what this person may *do* is a role, and what they may do it *to*
 * is a list of nights, and neither half is any use without the other. So the checks that matter are
 * the two ends of it — that a manager really can run their own concert, and that the night next to
 * it does not exist for them.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node managers_smoke.mjs
 */
import { chromium } from 'playwright';
import { openHall, chooseSeats, startSale } from './counter-hall.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/manager-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );

const page = await ( await browser.newContext( { viewport: { width: 1500, height: 1000 } } ) ).newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'The organiser appoints somebody to run a concert' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=managers]' );
await page.waitForSelector( '#mgr-add', { timeout: 20000 } );

const promoter = `rosa+${ Date.now() }@promoter.test`;

await page.click( '#mgr-add' );
await page.waitForSelector( '#mgr-name' );
await page.fill( '#mgr-name', 'Rosa Iqbal' );
await page.fill( '#mgr-email', promoter );

const nights = await page.locator( '#mgr-events label' ).allInnerTexts();

check( 'every night is offered', nights.length >= 2, nights.map( ( n ) => n.split( '\n' )[ 0 ] ).join( ' | ' ) );

/*
 * The seated night, so the checks below can sell a chair on a plan — and, because the account has
 * two published nights, the other one is the thing this manager must not be able to reach.
 */
await page.locator( '#mgr-events label', { hasText: 'Opening night' } )
	.first().locator( 'input' ).check();
await page.screenshot( { path: `${ SHOTS }/01-appointing.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.kbd-list code', { timeout: 20000 } );

const handed = await page.locator( '.kbd-list code' ).allInnerTexts();
const password = handed[ 1 ];

check( 'the sign-in is handed over once, in full', !! password && password.length > 8, handed[ 0 ] );

await page.screenshot( { path: `${ SHOTS }/02-sign-in.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForTimeout( 800 );

const listed = await page.locator( '#main' ).innerText();

check( 'and the organiser can see who runs what', listed.includes( 'Rosa Iqbal' ),
	listed.replace( /\n/g, ' | ' ).slice( 0, 140 ) );

await page.screenshot( { path: `${ SHOTS }/03-managers.png` } );

console.log( 'And the manager runs their concert' );
const theirs = await ( await browser.newContext( { viewport: { width: 1500, height: 1000 } } ) ).newPage();
const theirErrors = [];
theirs.on( 'pageerror', ( e ) => theirErrors.push( e.message ) );
theirs.on( 'console', ( m ) => { if ( 'error' === m.type() ) theirErrors.push( m.text() ); } );

await theirs.goto( BASE, { waitUntil: 'networkidle' } );
await theirs.fill( 'input[name=email]', promoter );
await theirs.fill( 'input[name=password]', password );
await theirs.click( '#login button[type=submit]' );
await theirs.waitForSelector( '.sidebar' );
await theirs.waitForTimeout( 1200 );

const theirNav = await theirs.locator( 'nav button' ).evaluateAll(
	( buttons ) => buttons.map( ( button ) => button.dataset.view )
);

check( 'they get the screens a concert needs',
	[ 'events', 'counter', 'orders', 'tickets', 'doorlist', 'maps' ]
		.every( ( view ) => theirNav.includes( view ) ),
	theirNav.join( ', ' ) );

check( 'and none of the account’s own',
	! [ 'customers', 'team', 'managers', 'vouchers', 'sites', 'settlement', 'audit', 'agents', 'reports' ]
		.some( ( view ) => theirNav.includes( view ) ),
	theirNav.join( ', ' ) );

await theirs.click( 'nav button[data-view=events]' );
await theirs.waitForSelector( '.table', { timeout: 20000 } );
await theirs.waitForTimeout( 600 );

const programme = await theirs.locator( '#main tbody tr' ).count();

// The whole point: the account has two published nights and they were given one.
check( 'the programme they see is the one they run', 1 === programme, `${ programme } night(s)` );

await theirs.screenshot( { path: `${ SHOTS }/04-their-programme.png` } );

console.log( 'Their own window, their own hall' );
await theirs.click( 'nav button[data-view=counter]' );
await openHall( theirs );

const offered = await theirs.locator( '#counter-event option' ).allInnerTexts();

check( 'the counter offers only their night', 1 === offered.length, offered.join( ', ' ) );

await chooseSeats( theirs, 1 );
await startSale( theirs );
await theirs.fill( '#c-name', 'A guest of the promoter' );
await theirs.selectOption( '#c-payment', 'comp' );
await theirs.waitForTimeout( 500 );
await theirs.screenshot( { path: `${ SHOTS }/05-issuing.png` } );
await theirs.click( '.modal button[type=submit]' );
await theirs.waitForSelector( '.modal', { state: 'detached', timeout: 20000 } );

const sold = await theirs.locator( '.toast' ).innerText().catch( () => '' );

check( 'they can give a ticket away on their own night', /bo-/.test( sold ), sold );

console.log( 'And the night next door does not exist for them' );

/*
 * Asked of the API directly rather than through the screen, because the screen never offers it —
 * which is the courtesy, not the control. The control is the refusal, and a refusal that is only a
 * hidden button is not one.
 */
const reached = await theirs.evaluate( async () => {
	const api = document.getElementById( 'app' ).dataset.api;
	const token = window.sessionStorage.getItem( 'seatmap_token' );
	const headers = { Authorization: 'Bearer ' + token, Accept: 'application/json' };

	const mine = await fetch( api + '/events', { headers } ).then( ( r ) => r.json() );

	// Every published night in the account, read as the owner would — except this caller is not the
	// owner, so what comes back is only theirs. The other one is found the long way: by id, from a
	// list this caller cannot see, which is exactly what an attacker would have.
	return { visible: ( mine.data || [] ).length };
} );

check( 'their own list is one night long', 1 === reached.visible, String( reached.visible ) );

const strangerId = await page.evaluate( async () => {
	const api = document.getElementById( 'app' ).dataset.api;
	const token = window.sessionStorage.getItem( 'seatmap_token' );

	const all = await fetch( api + '/events?per_page=100', {
		headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
	} ).then( ( r ) => r.json() );

	return ( all.data || [] ).map( ( event ) => event.id );
} );

const theirOwn = await theirs.evaluate( async () => {
	const api = document.getElementById( 'app' ).dataset.api;
	const token = window.sessionStorage.getItem( 'seatmap_token' );

	const mine = await fetch( api + '/events', {
		headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
	} ).then( ( r ) => r.json() );

	return ( mine.data || [] ).map( ( event ) => event.id );
} );

const stranger = strangerId.filter( ( id ) => ! theirOwn.includes( id ) )[ 0 ];

const refused = await theirs.evaluate( async ( id ) => {
	const api = document.getElementById( 'app' ).dataset.api;
	const token = window.sessionStorage.getItem( 'seatmap_token' );
	const headers = { Authorization: 'Bearer ' + token, Accept: 'application/json' };

	const statuses = {};

	for ( const path of [ '', '/stats', '/counter', '/door-list', '/settlement' ] ) {
		statuses[ path || 'event' ] = ( await fetch( api + '/events/' + id + path, { headers } ) ).status;
	}

	return statuses;
}, stranger );

check( 'and every way into it answers "cannot be found"',
	Object.values( refused ).every( ( status ) => 404 === status ),
	JSON.stringify( refused ) );

// The 404s above are deliberate, and a browser logs each of them as a console error.
const noise = ( message ) => /status of 404/.test( message ) || /status of 403/.test( message );

check( 'no console errors',
	0 === errors.filter( ( m ) => ! noise( m ) ).length &&
	0 === theirErrors.filter( ( m ) => ! noise( m ) ).length,
	errors.concat( theirErrors ).filter( ( m ) => ! noise( m ) ).join( ' / ' ) );

await browser.close();

console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
