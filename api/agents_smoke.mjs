/**
 * A bureau selling somebody else's tickets, driven in Chromium.
 *
 * `SalesAgentTest` holds up the rules. What a browser adds is the arrangement as both sides live
 * it: an organiser takes an agent on, hands them one night and a float, and watches the account;
 * the agent signs in to a panel that offers them that night and no other, sells until their credit
 * runs out, and is stopped at the counter rather than at the end of a transaction.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node agents_smoke.mjs
 */
import { chromium } from 'playwright';
import { seatedEvent } from './smoke-support.mjs';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/agents-shots';

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const night = await seatedEvent( BASE, 'agents-smoke' );

const api = ( method, path, body ) => fetch( BASE + path, {
	method,
	headers: {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		Authorization: 'Bearer ' + night.token,
	},
	body: body ? JSON.stringify( body ) : undefined,
} ).then( async ( response ) => ( { status: response.status, body: await response.json().catch( () => ( {} ) ) } ) );

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const context = await browser.newContext( { viewport: { width: 1500, height: 1000 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );

console.log( 'The organiser takes an agent on' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );
await page.click( 'nav button[data-view=agents]' );
await page.waitForSelector( '#agent-add', { timeout: 20000 } );
await page.click( '#agent-add' );
await page.waitForSelector( '#ag-name' );
await page.fill( '#ag-name', 'Bureau 12' );
await page.fill( '#ag-code', 'bureau-12' );
await page.fill( '#ag-email', 'bureau12@example.test' );
await page.fill( '#ag-rate', '10' );
await page.fill( '#ag-limit', '0' );
await page.screenshot( { path: `${ SHOTS }/01-new-agent.png` } );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '#agent-events', { timeout: 20000 } );

check( 'the agent exists and their account is empty',
	/0[.,]00/.test( await page.locator( '.stat-strip' ).innerText() ),
	( await page.locator( '.stat-strip' ).innerText() ).replace( /\n/g, ' | ' ).slice( 0, 120 ) );

// And a way in for them, created from the screen the organiser is already on: the password exists
// once, which is what the modal that follows says.
await page.click( '#agent-signin' );
await page.waitForSelector( '#ag-login-email' );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.kbd-list code', { timeout: 20000 } );

const shown = await page.locator( '.kbd-list code' ).allInnerTexts();
const password = shown[ 1 ];

check( 'the sign-in is handed over once, in full', !! password && password.length > 8,
	shown[ 0 ] );

await page.screenshot( { path: `${ SHOTS }/02-sign-in.png` } );
await page.locator( '.modal [data-close]' ).first().click();
await page.waitForTimeout( 600 );

const agents = ( await api( 'GET', '/v1/sales-agents' ) ).body.data || [];
const bureau = agents.filter( ( row ) => 'bureau-12' === row.code )[ 0 ];

check( 'and the agent now has somebody to be', !! bureau.user_id );

console.log( 'One night, and a float' );
await page.click( '#agent-events' );
await page.waitForSelector( '#ag-events' );
const seated = page.locator( '#ag-events .perms__row', { hasText: 'Opening night' } ).first();

await seated.locator( 'input' ).check();
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.modal', { state: 'detached', timeout: 20000 } );
await page.waitForSelector( '#agent-credit', { timeout: 20000 } );
await page.waitForTimeout( 1200 );

const allowed = await page.locator( '#main' ).innerText();

check( 'the screen says what they may sell', /Opening night/.test( allowed ),
	allowed.split( '\n' ).filter( ( line ) => /night/i.test( line ) )[ 0 ] || '' );

await page.click( '#agent-credit' );
await page.waitForSelector( '#ag-amount' );
await page.selectOption( '#ag-kind', 'topup' );
await page.fill( '#ag-amount', '50' );
await page.fill( '#ag-reference', 'BANK-1' );
await page.click( '.modal button[type=submit]' );
await page.waitForSelector( '.modal', { state: 'detached', timeout: 20000 } );
await page.waitForSelector( '#agent-credit', { timeout: 20000 } );
await page.waitForTimeout( 1200 );

const funded = await page.locator( '.stat-strip' ).innerText();

check( 'the float shows in the account', /50[.,]00/.test( funded ),
	funded.replace( /\n/g, ' | ' ).slice( 0, 140 ) );

await page.screenshot( { path: `${ SHOTS }/03-account.png` } );

console.log( 'And the agent sells, until they cannot' );
const theirs = await ( await browser.newContext( { viewport: { width: 1500, height: 1000 } } ) ).newPage();
const theirErrors = [];

theirs.on( 'pageerror', ( e ) => theirErrors.push( e.message ) );
theirs.on( 'console', ( m ) => { if ( 'error' === m.type() ) theirErrors.push( m.text() ); } );

await theirs.goto( BASE, { waitUntil: 'networkidle' } );
await theirs.fill( 'input[name=email]', 'bureau12@example.test' );
await theirs.fill( 'input[name=password]', password );
await theirs.click( '#login button[type=submit]' );
await theirs.waitForSelector( '.sidebar' );

/*
 * What an outside agency is shown on their first morning.
 *
 * The nav is the whole list of doors somebody believes they have, so a row they cannot open is a
 * refusal they meet by clicking. The organiser's screens — the customer directory, the vouchers,
 * the seat map designer, the agency list itself — are not hidden *instead* of being refused: the
 * server refuses them too, and this only stops the panel from offering them.
 */
const theirNav = await theirs.locator( 'nav button' ).evaluateAll(
	( buttons ) => buttons.map( ( button ) => button.dataset.view )
);

check( 'the agency is offered the counter and their own book',
	theirNav.includes( 'counter' ) && theirNav.includes( 'orders' ) && theirNav.includes( 'events' ),
	theirNav.join( ', ' ) );

/*
 * The seat map and the venue stay: an agency cannot sell a seat it cannot see on a plan, and
 * `maps.view` is reading one rather than drawing it. What goes is the organiser's own business —
 * their audience, their money, their account, and the agency list this agency is *on*.
 */
check( 'and not one screen of the organiser\u2019s own',
	! [ 'customers', 'vouchers', 'agents', 'team', 'audit', 'overview', 'settlement', 'waitlist',
		'discounts', 'messaging', 'reports', 'promoters', 'baskets', 'sites', 'modules', 'connections' ]
		.some( ( view ) => theirNav.includes( view ) ),
	theirNav.join( ', ' ) );

// And the screen the panel chooses for them is one of theirs, not the overview they never see.
const landed = await theirs.locator( 'nav button.is-active' ).first().getAttribute( 'data-view' );

check( 'so the screen they land on is one they may use',
	'overview' !== landed && theirNav.includes( landed ), landed );

await theirs.screenshot( { path: `${ SHOTS }/04-their-whole-panel.png` } );

await theirs.click( 'nav button[data-view=counter]' );
await theirs.waitForSelector( '#counter-event', { timeout: 20000 } );
await theirs.waitForTimeout( 1200 );

const offered = await theirs.locator( '#counter-event option' ).allInnerTexts();

// The seeded account has two published events; this agent was given one of them.
check( 'the counter offers only the night they were given', 1 === offered.length, offered.join( ', ' ) );

const strip = await theirs.locator( '.stat-strip' ).innerText();

check( 'and shows what they have left to sell against', /50[.,]00/.test( strip ),
	strip.replace( /\n/g, ' | ' ).slice( 0, 140 ) );

await theirs.screenshot( { path: `${ SHOTS }/05-agent-counter.png` } );

await theirs.waitForSelector( '.counter__blocks', { timeout: 20000 } );
await theirs.locator( '.counter__block:not([disabled])' ).first().click();
await theirs.waitForSelector( '.counter__seat' );
await theirs.locator( '.counter__seat:not([disabled])' ).first().click();
await theirs.waitForTimeout( 600 );

// A sale in progress, as the agency sees it: the chair taken, the total beside it, and the credit
// they are selling against at the top of their own screen.
await theirs.screenshot( { path: `${ SHOTS }/06-agent-selling.png` } );

await theirs.click( '#counter-sell' );
await theirs.waitForSelector( '.modal' );
await theirs.fill( '#c-name', 'Walk-up buyer' );

// And the money asked for: a seat chosen, the money asked for, and the credit they are
// selling against still on the screen behind the dialog. After the dialog's own fade, or the
// picture is of a half-painted one.
await theirs.waitForTimeout( 700 );
await theirs.screenshot( { path: `${ SHOTS }/07-agent-taking-money.png` } );

await theirs.click( '.modal button[type=submit]' );
await theirs.waitForTimeout( 3000 );

const sold = await theirs.locator( '.toast' ).innerText().catch( () => '' );

check( 'a sale inside their credit goes through', /bo-/.test( sold ), sold );

const after = await theirs.locator( '.stat-strip' ).innerText();

check( 'and the strip says so without being reloaded by hand',
	! /50[.,]00/.test( after.split( '\n' ).slice( 3, 6 ).join( ' ' ) ),
	after.replace( /\n/g, ' | ' ).slice( 0, 160 ) );

// Now spend the rest of it. Each seat is 25.00 and the float was 60.00, so the third is refused.
for ( let round = 0; round < 8; round++ ) {
	// Every sale returns the counter to the blocks, the way it does for anybody: the hall is
	// re-read because seats have just been taken out of it.
	await theirs.waitForSelector( '.counter__blocks', { timeout: 20000 } );
	await theirs.locator( '.counter__block:not([disabled])' ).first().click();
	await theirs.waitForSelector( '.counter__seat', { timeout: 20000 } );
	await theirs.locator( '.counter__seat:not([disabled])' ).first().click();
	await theirs.waitForTimeout( 300 );
	await theirs.click( '#counter-sell' );
	await theirs.waitForSelector( '.modal' );
	await theirs.fill( '#c-name', 'Walk-up buyer' );
	await theirs.click( '.modal button[type=submit]' );
	await theirs.waitForTimeout( 2500 );

	if ( /more than|credit|left to sell/i.test( await theirs.locator( '.toast' ).innerText().catch( () => '' ) ) ) {
		break;
	}
}

const refused = await theirs.locator( '.toast' ).innerText().catch( () => '' );

check( 'and the counter stops them when the credit runs out',
	/more than|credit|left to sell/i.test( refused ), refused );

await theirs.screenshot( { path: `${ SHOTS }/08-refused.png` } );

console.log( 'And reads their own account' );

// The refusal above leaves the sell dialog open, which is the right behaviour — the sale did not
// happen and the seats are still chosen — so it is dismissed the way a person would.
await theirs.keyboard.press( 'Escape' );
await theirs.waitForSelector( '.modal', { state: 'detached', timeout: 10000 } );

await theirs.click( 'nav button[data-view=myagency]' );
await theirs.waitForSelector( '#mine-period', { timeout: 20000 } );
await theirs.waitForTimeout( 1200 );

// The refusal was about the counter, and walking away from the counter ends it.
check( 'a message from the last screen does not follow them to this one',
	0 === await theirs.locator( '.toast' ).count() );

const mine = await theirs.locator( '#main' ).innerText();

check( 'the agency has an account screen of its own',
	/bureau-12/.test( mine ), mine.split( '\n' ).slice( 0, 3 ).join( ' | ' ) );

check( 'with the statement for the period, and the ledger under it',
	/Sold in this period/i.test( mine ) && /Paid in/i.test( mine ),
	mine.replace( /\n/g, ' | ' ).slice( 0, 200 ) );

await theirs.screenshot( { path: `${ SHOTS }/09-agent-statement.png`, fullPage: true } );

// The organiser's own screens stay the organiser's: the agency's list is not on their nav, and
// asking for it by name is still refused.
const reachedOthers = await theirs.evaluate( () => fetch(
	document.getElementById( 'app' ).dataset.api + '/sales-agents',
	{ headers: { Authorization: 'Bearer ' + window.sessionStorage.getItem( 'seatmap_token' ) } }
).then( ( response ) => response.status ) );

check( 'and no route from it to anybody else’s', 403 === reachedOthers, String( reachedOthers ) );

const account = ( await api( 'GET', `/v1/sales-agents/${ bureau.id }` ) ).body;

check( 'the account counts what they sold from the tickets themselves',
	account.account.sold > 0 && account.account.commission > 0 &&
	account.account.balance === 5000 - account.account.sold + account.account.commission,
	JSON.stringify( account.account ) );

const statement = ( await api( 'GET', `/v1/sales-agents/${ bureau.id }/statement` ) ).body;

check( 'and the statement says what is due for the period',
	statement.period.due === statement.period.sold - statement.period.commission,
	`${ statement.period.sold } − ${ statement.period.commission } = ${ statement.period.due }` );

/*
 * The refusal above is a 409, and a browser logs every 409 as a console error.
 *
 * That one is the point of the check that produced it, so it is not counted here — everything else
 * is, including any other status the agent's screen might have provoked.
 */
const noise = ( message ) => /status of 409/.test( message ) || /status of 403/.test( message );

check( 'no console errors',
	0 === errors.filter( ( message ) => ! noise( message ) ).length &&
	0 === theirErrors.filter( ( message ) => ! noise( message ) ).length,
	errors.concat( theirErrors ).filter( ( message ) => ! noise( message ) ).join( ' / ' ) );

await browser.close();
console.log( failures ? `\n${ failures } FAILED` : '\nall good' );
process.exit( failures ? 1 : 0 );
