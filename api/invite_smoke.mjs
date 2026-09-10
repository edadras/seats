/**
 * An invitation, from the organiser's screen to the colleague's first morning, in Chromium.
 *
 * The half that was missing: issuing an invitation minted a token and copied a link, and nothing
 * served that link or redeemed that token. So the check that matters here is the last one — the
 * person who followed the link is signed in, at the right account, with the role they were given
 * and nothing beside it.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node invite_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/invite-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );

const page = await ( await browser.newContext( { viewport: { width: 1400, height: 1000 } } ) ).newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'The organiser invites somebody' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=team]' );
await page.waitForSelector( '#team-invite', { timeout: 20000 } );

const colleague = `rosa+${ Date.now() }@northgate.test`;

/*
 * The token itself never reaches the screen — it goes to the clipboard and nowhere else — so it is
 * read back from the API with the organiser's own token, which is what an email would carry.
 */
const bearer = await page.evaluate( () => window.sessionStorage.getItem( 'seatmap_token' ) );

const invited = await fetch( `${ BASE }/v1/team/invitations`, {
	method: 'POST',
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: `Bearer ${ bearer }`,
	},
	body: JSON.stringify( { email: colleague, role: 'box_office' } ),
} ).then( ( response ) => response.json() );

check( 'the invitation carries its token exactly once', !! invited.token && invited.token.length > 20 );

await page.click( 'nav button[data-view=team]' );
await page.waitForTimeout( 800 );

const listed = await page.locator( '#main' ).innerText();

check( 'and the organiser can see who is still waiting', listed.includes( colleague ), colleague );

await page.screenshot( { path: `${ SHOTS }/01-invited.png` } );

console.log( 'The colleague follows the link' );
const theirs = await ( await browser.newContext( { viewport: { width: 1400, height: 1000 } } ) ).newPage();
const theirErrors = [];
theirs.on( 'pageerror', ( e ) => theirErrors.push( e.message ) );
theirs.on( 'console', ( m ) => { if ( 'error' === m.type() ) theirErrors.push( m.text() ); } );

await theirs.goto( `${ BASE }/invite/${ invited.token }`, { waitUntil: 'networkidle' } );
await theirs.waitForSelector( '#join', { timeout: 20000 } );

const offered = await theirs.locator( '#join' ).innerText();

// The screen knows whose account it is and as what before it asks for a password.
check( 'the screen says who invited them, and as what',
	/Northgate/.test( offered ) && /[Bb]ox office/.test( offered ), offered.replace( /\n/g, ' | ' ) );

check( 'and the address is shown rather than asked for',
	colleague === await theirs.locator( '#join-email' ).inputValue() );

await theirs.screenshot( { path: `${ SHOTS }/02-join.png` } );

await theirs.fill( '#join-name', 'Rosa Iqbal' );
await theirs.fill( '#join-password', 'a-long-enough-one' );
await theirs.click( '#join button[type=submit]' );
await theirs.waitForSelector( '.sidebar', { timeout: 20000 } );
await theirs.waitForTimeout( 1000 );

check( 'they land in the account they were invited to',
	/Northgate/.test( await theirs.locator( '.account__name' ).innerText() ),
	await theirs.locator( '.account__name' ).innerText() );

check( 'as the role they were given',
	/[Bb]ox office/.test( await theirs.locator( '.account__meta' ).innerText() ),
	await theirs.locator( '.account__meta' ).innerText() );

const theirNav = await theirs.locator( 'nav button' ).evaluateAll(
	( buttons ) => buttons.map( ( button ) => button.dataset.view )
);

check( 'with the box office’s screens and not the account’s',
	theirNav.includes( 'orders' ) && theirNav.includes( 'counter' ) &&
	! theirNav.includes( 'team' ) && ! theirNav.includes( 'audit' ),
	theirNav.join( ', ' ) );

// The link is spent, and the address bar no longer carries it.
check( 'and the link is out of the address bar', ! /\/invite\//.test( theirs.url() ), theirs.url() );

await theirs.screenshot( { path: `${ SHOTS }/03-joined.png` } );

console.log( 'And the link is worth one membership' );
const again = await ( await browser.newContext() ).newPage();
await again.goto( `${ BASE }/invite/${ invited.token }`, { waitUntil: 'networkidle' } );
await again.waitForSelector( '#login', { timeout: 20000 } );

const told = await again.locator( '.toast' ).innerText().catch( () => '' );

check( 'a second use is turned away with a reason',
	/already|taken up/i.test( told ), told );

console.log( 'And the organiser sees it was taken up' );
await page.click( 'nav button[data-view=team]' );
await page.waitForTimeout( 1000 );

const after = await page.locator( '#main' ).innerText();

/*
 * Scoped to the rows that carry a cancel button, because the address is on the screen either way
 * now — as a member. The waiting list is the part that should have let go of it.
 */
const stillWaiting = await page.locator( 'tr', { hasText: colleague } )
	.filter( { has: page.locator( '[data-revoke]' ) } ).count();

check( 'the invitation is off the waiting list', 0 === stillWaiting, `${ stillWaiting } row(s)` );
check( 'and Rosa is in the account', /Rosa Iqbal/.test( after ) );

await page.screenshot( { path: `${ SHOTS }/04-joined-team.png` } );

// The second visit above is deliberately refused, and a browser logs that 409 as a console error.
const noise = ( message ) => /status of 409/.test( message );

check( 'no console errors',
	0 === errors.filter( ( m ) => ! noise( m ) ).length &&
	0 === theirErrors.filter( ( m ) => ! noise( m ) ).length,
	errors.concat( theirErrors ).filter( ( m ) => ! noise( m ) ).join( ' / ' ) );

await browser.close();

console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
