/**
 * Every form in the panel, read the way somebody meets it for the first time.
 *
 * This is not about whether a form works — the other checks do that — but about whether its shape
 * says what to do. The event form once asked twenty-eight questions in one column, in no order,
 * with nothing marking which five of them it could not do without, and *Hours before* sitting in
 * plain view under a refund policy of never. Nobody reported that as a bug, because every field
 * did what it said; it was simply unusable without being told how.
 *
 * So the rules are structural, and a browser is the only place they can be checked — the shape of a
 * form is a fact about the rendered page, not about the source that printed it:
 *
 *   **Every control is labelled.** A box with a placeholder and no label is a guess.
 *   **A long form is divided into named parts.** Over a dozen questions in one run is a wall.
 *   **Every part is named**, and a closed one says what it is holding.
 *   **A rule that hides a field names a control that exists.** A typo in `data-when` hides a field
 *   for ever, in silence, and nothing else would ever catch it.
 *   **Nothing that has to be answered is hidden.** The browser refuses to submit while a required
 *   field is empty; a required field nobody can see is a refusal with nothing on screen to act on.
 *   **A field that has to be answered is marked as such**, before it is answered.
 *
 *   php artisan migrate:fresh --seed --force
 *   php artisan serve --port=8123 &
 *   node forms_smoke.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.SEATMAP_URL || 'http://127.0.0.1:8123';
const SHOTS = process.env.SEATMAP_SHOTS || '/tmp/form-shots';

/** Past this many questions in one run, a form needs parts. */
const WALL = 12;

let failures = 0;
const check = ( label, ok, detail = '' ) => {
	console.log( `  ${ ok ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' — ' + detail : '' }` );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
} );
const page = await browser.newPage( { viewport: { width: 1500, height: 1000 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'console', ( m ) => { if ( 'error' === m.type() ) errors.push( m.text() ); } );
// Nothing on these screens may ask through the browser's own grey box.
const prompts = [];
page.on( 'dialog', async ( d ) => { prompts.push( d.type() ); await d.dismiss(); } );

/*
 * The rules, run inside the page against one root — a dialog, or a whole screen.
 *
 * In the page rather than over the wire because every one of them is about what is *rendered*:
 * which label belongs to which control, what is hidden, what a part is called.
 */
const READ = ( root ) => {
	const text = ( node ) => ( node ? node.textContent.trim().replace( /\s+/g, ' ' ) : '' );
	const controls = [ ...root.querySelectorAll( 'input, select, textarea' ) ]
		.filter( ( c ) => 'hidden' !== c.type );

	const labelFor = ( control ) => {
		const byId = control.id && root.querySelector( `label[for="${ CSS.escape( control.id ) }"]` );

		return byId || control.closest( 'label' ) || null;
	};

	const parts = [ ...root.querySelectorAll( '.form-group' ) ];

	return {
		controls: controls.length,
		unlabelled: controls
			.filter( ( c ) => ! labelFor( c ) && ! c.getAttribute( 'aria-label' ) )
			.map( ( c ) => c.name || c.id || c.type ),
		parts: parts.map( ( part ) => ( {
			title: text( part.querySelector( '.form-group__title' ) ),
			state: text( part.querySelector( '.form-group__state' ) ),
			open: part.open,
		} ) ),
		// A rule pointing at nothing: the field it guards is hidden for ever and nobody is told.
		danglingRules: [ ...root.querySelectorAll( '[data-when]' ) ]
			.map( ( node ) => String( node.dataset.when || '' ).split( '=' )[ 0 ] )
			.filter( ( name, index, all ) => all.indexOf( name ) === index )
			.filter( ( name ) => {
				const scope = root.closest( 'form' ) || root;

				return ! scope.querySelector( `[name="${ name }"]` ) &&
					! scope.querySelector( `#${ CSS.escape( name ) }` ) &&
					! document.querySelector( `[name="${ name }"]` ) &&
					! document.getElementById( name );
			} ),
		// Required and out of sight: the browser will refuse to send the form and point at nothing.
		hiddenRequired: controls
			.filter( ( c ) => c.required && c.closest( '[data-when]' ) && c.closest( '[data-when]' ).hidden )
			.map( ( c ) => c.name || c.id ),
		unmarkedRequired: controls
			.filter( ( c ) => c.required )
			.filter( ( c ) => {
				const label = labelFor( c );

				return label && ! label.querySelector( '.req' );
			} )
			.map( ( c ) => c.name || c.id ),
		required: controls.filter( ( c ) => c.required ).length,
	};
};

console.log( 'Panel: sign in' );
await page.goto( BASE, { waitUntil: 'networkidle' } );
await page.fill( 'input[name=email]', 'owner@northgate.test' );
await page.fill( 'input[name=password]', 'password' );
await page.click( '#login button[type=submit]' );
await page.waitForSelector( '.sidebar' );

const views = await page.locator( 'nav button[data-view]' ).evaluateAll( ( buttons ) =>
	buttons.map( ( button ) => button.dataset.view ) );

check( 'the panel offers its screens', views.length >= 20, `${ views.length } screens` );

/*
 * Every screen, and the first form each one offers.
 *
 * The first button in a page's header is the action that screen was built for — *New event*,
 * *Issue one*, *Add a scanner*. Where it opens a dialog, that dialog is the form somebody meets.
 */
console.log( '\nEvery form the panel opens' );

const walls = [];
const unlabelled = [];
const dangling = [];
const hiddenRequired = [];
const unmarked = [];
const unnamedParts = [];
let formsSeen = 0;

for ( const view of views ) {
	await page.click( `nav button[data-view=${ view }]` );

	try {
		await page.waitForFunction( () => {
			const heading = document.querySelector( '.page-head h1' );

			return heading && heading.textContent.trim().length > 0;
		}, null, { timeout: 8000 } );
	} catch ( error ) {
		check( `${ view } drew something`, false );
		continue;
	}

	await page.waitForTimeout( 300 );

	// The screen's own body is a form too, on the screens that are settings rather than lists.
	const screen = await page.evaluate( READ, await page.$( '.page-body' ) );

	if ( screen.controls ) {
		formsSeen++;
		if ( screen.unlabelled.length ) unlabelled.push( `${ view }: ${ screen.unlabelled.join( ', ' ) }` );
		if ( screen.danglingRules.length ) dangling.push( `${ view }: ${ screen.danglingRules.join( ', ' ) }` );
		if ( screen.hiddenRequired.length ) hiddenRequired.push( `${ view }: ${ screen.hiddenRequired.join( ', ' ) }` );
		if ( screen.unmarkedRequired.length ) unmarked.push( `${ view }: ${ screen.unmarkedRequired.join( ', ' ) }` );
	}

	const opener = page.locator( '.page-head button' ).first();

	if ( ! ( await opener.count() ) ) {
		continue;
	}

	try {
		await opener.click( { timeout: 3000 } );
		await page.waitForSelector( '.modal', { timeout: 2500 } );
	} catch ( error ) {
		continue; // Not a form: an export, a download, a screen of its own.
	}

	await page.waitForTimeout( 300 );

	const form = await page.evaluate( READ, await page.$( '.modal' ) );
	const title = await page.locator( '.modal h2' ).innerText();

	formsSeen++;

	if ( form.unlabelled.length ) unlabelled.push( `${ title }: ${ form.unlabelled.join( ', ' ) }` );
	if ( form.danglingRules.length ) dangling.push( `${ title }: ${ form.danglingRules.join( ', ' ) }` );
	if ( form.hiddenRequired.length ) hiddenRequired.push( `${ title }: ${ form.hiddenRequired.join( ', ' ) }` );
	if ( form.unmarkedRequired.length ) unmarked.push( `${ title }: ${ form.unmarkedRequired.join( ', ' ) }` );

	if ( form.controls > WALL && ! form.parts.length ) {
		walls.push( `${ title }: ${ form.controls } questions in one run` );
	}

	form.parts.forEach( function ( part ) {
		if ( ! part.title ) {
			unnamedParts.push( title );
		}
	} );

	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 200 );
}

check( 'forms were found and read', formsSeen >= 20, `${ formsSeen } forms` );
check( 'every control is labelled', 0 === unlabelled.length, unlabelled.join( ' | ' ) );
check( 'no form is a wall of questions', 0 === walls.length, walls.join( ' | ' ) );
check( 'every part of a form is named', 0 === unnamedParts.length, unnamedParts.join( ', ' ) );
check( 'every rule that hides a field names a real control', 0 === dangling.length, dangling.join( ' | ' ) );
check( 'nothing that must be answered is out of sight', 0 === hiddenRequired.length, hiddenRequired.join( ' | ' ) );
check( 'every field that must be answered is marked', 0 === unmarked.length, unmarked.join( ' | ' ) );
check( 'nothing asks through the browser’s own grey box', 0 === prompts.length, prompts.join( ', ' ) );

/*
 * The form that made the case for all of this.
 *
 * Twenty-eight questions, five of them needed to get a night on sale and no way to tell which.
 * It is five named parts now, and the four that are policies rather than facts are closed with a
 * line saying what they hold.
 */
console.log( '\nThe event form: five decisions rather than twenty-eight boxes' );

await page.click( 'nav button[data-view=events]' );
await page.waitForSelector( '.page-head button' );
await page.click( '.page-head button' );
await page.waitForSelector( '.modal' );
await page.waitForTimeout( 400 );

const event = await page.evaluate( READ, await page.$( '.modal' ) );

check( 'it is in named parts', 5 === event.parts.length,
	event.parts.map( ( part ) => part.title ).join( ' | ' ) );
check( 'the part that has to be answered is the one that is open',
	event.parts[ 0 ].open && event.parts.slice( 1 ).every( ( part ) => ! part.open ) );
check( 'and the closed ones say what they hold',
	event.parts.slice( 1 ).every( ( part ) => part.state.length > 0 ),
	event.parts[ 3 ].state );

/*
 * What is actually on screen.
 *
 * `offsetParent` is not enough: a browser hides the contents of a closed `<details>` with
 * `content-visibility`, which leaves the layout boxes in place — so every question in every closed
 * part counts as visible unless the parts themselves are asked.
 */
const asked = await page.evaluate( () => {
	const onScreen = ( control ) => {
		if ( 'hidden' === control.type || null === control.offsetParent ) {
			return false;
		}

		for ( var node = control.parentElement; node; node = node.parentElement ) {
			if ( node.hidden || ( 'DETAILS' === node.tagName && ! node.open ) ) {
				return false;
			}
		}

		return true;
	};

	return [ ...document.querySelectorAll( '.modal input, .modal select, .modal textarea' ) ]
		.filter( onScreen ).length;
} );

check( 'what it asks for at first is a night, not a policy', asked <= 12, `${ asked } questions on screen` );

/* A field whose answer cannot matter yet is not shown at all. */
const state = () => page.evaluate( () => [ ...document.querySelectorAll( '.modal [data-when]' ) ]
	.map( ( node ) => node.dataset.when + ( node.hidden ? '' : ' SHOWN' ) )
	.filter( ( entry ) => / SHOWN$/.test( entry ) ) );

check( 'nothing conditional is shown before it applies', 0 === ( await state() ).length,
	( await state() ).join( ', ' ) );

await page.locator( '.modal .form-group' ).nth( 3 ).locator( 'summary' ).click();
await page.waitForTimeout( 200 );
await page.selectOption( '.modal [name=refunds]', 'until' );
await page.waitForTimeout( 250 );

check( 'offering refunds brings out the terms of them',
	( await state() ).some( ( entry ) => entry.startsWith( 'refunds=until ' ) ),
	( await state() ).join( ', ' ) );

await page.selectOption( '.modal [name=refunds]', 'never' );
await page.waitForTimeout( 250 );

check( 'and taking the offer away puts them back', 0 === ( await state() ).length );

await page.screenshot( { path: `${ SHOTS }/event-form.png` } );
await page.keyboard.press( 'Escape' );

/*
 * A module that is switched off asks for nothing. Ten payment gateways, each wanting an API key and
 * a merchant id for something that is not running, reads as a job somebody has to do.
 */
console.log( '\nA switch and the questions that belong to it' );

await page.click( 'nav button[data-view=modules]' );
await page.waitForTimeout( 900 );

const modules = await page.evaluate( () => [ ...document.querySelectorAll( '[data-settings]' ) ]
	.map( ( form ) => {
		const card = form.closest( '[data-module]' );

		return {
			key: card.dataset.module,
			on: card.querySelector( '[data-toggle]' ).checked,
			asking: ! form.hidden,
		};
	} ) );

check( 'the modules screen has settings to show', modules.length >= 5, `${ modules.length } modules` );
check( 'a module that is on asks for what it needs',
	modules.filter( ( module ) => module.on ).every( ( module ) => module.asking ),
	modules.filter( ( module ) => module.on ).map( ( module ) => module.key ).join( ', ' ) );
check( 'a module that is off asks for nothing',
	modules.filter( ( module ) => ! module.on ).every( ( module ) => ! module.asking ),
	modules.filter( ( module ) => ! module.on && module.asking ).map( ( module ) => module.key ).join( ', ' ) || 'none asking' );

/*
 * An empty screen that says how to fill it.
 *
 * An empty list is the first thing most people see on most screens, and each of these used to be a
 * sentence and a full stop — *No discount codes yet. Make one and it works on your own site
 * straight away.* Made where? Worse, several pointed at another screen in words and offered no way
 * of getting there. So every empty state now carries the way out of itself: the screen's own action,
 * pressed from here, or the screen where the thing is actually made — or, where an empty list is
 * simply the right answer, it says so in the markup rather than by leaving a gap.
 */
console.log( '\nEvery empty screen says how to fill it' );

const deadEnds = [];
const brokenWays = [];
let emptySeen = 0;
let leadless = [];

for ( const view of views ) {
	await page.click( `nav button[data-view=${ view }]` );

	try {
		await page.waitForFunction( () => {
			const heading = document.querySelector( '.page-head h1' );

			return heading && heading.textContent.trim().length > 0;
		}, null, { timeout: 8000 } );
	} catch ( error ) {
		continue;
	}

	await page.waitForTimeout( 300 );

	const screen = await page.evaluate( () => {
		const text = ( node ) => ( node ? node.textContent.trim().replace( /\s+/g, ' ' ) : '' );

		return {
			lead: text( document.querySelector( '.page-head__desc' ) ),
			empties: [ ...document.querySelectorAll( '.page-body .empty' ) ].map( ( empty ) => ( {
				title: text( empty.querySelector( '.empty__title' ) ),
				waiting: empty.hasAttribute( 'data-waiting' ),
				does: ( empty.querySelector( '[data-does]' ) || {} ).dataset?.does || '',
				goes: ( empty.querySelector( '[data-goes]' ) || {} ).dataset?.goes || '',
				// A button pointing at an id that is not on the screen would press nothing at all.
				reaches: ( () => {
					const press = empty.querySelector( '[data-does]' );

					return ! press || !! document.getElementById( press.dataset.does );
				} )(),
				label: text( empty.querySelector( '.empty__action' ) ),
			} ) ),
		};
	} );

	if ( ! screen.lead ) {
		leadless.push( view );
	}

	screen.empties.forEach( function ( empty ) {
		emptySeen++;

		if ( ! empty.waiting && ! empty.does && ! empty.goes ) {
			deadEnds.push( `${ view }: “${ empty.title }”` );
		}

		if ( ! empty.reaches ) {
			brokenWays.push( `${ view }: “${ empty.title }” presses ${ empty.does }, which is not on the screen` );
		}

		if ( ( empty.does || empty.goes ) && ! empty.label ) {
			deadEnds.push( `${ view }: “${ empty.title }” offers an unnamed button` );
		}
	} );
}

check( 'empty screens were found and read', emptySeen >= 10, `${ emptySeen } of them` );
check( 'none of them is a dead end', 0 === deadEnds.length, deadEnds.join( ' | ' ) );
check( 'and every way out reaches something', 0 === brokenWays.length, brokenWays.join( ' | ' ) );
check( 'every screen says what it is', 0 === leadless.length, leadless.join( ', ' ) );

/* And the way out works — pressed, on a screen that really is empty. */
await page.click( 'nav button[data-view=discounts]' );
await page.waitForSelector( '.page-body .empty [data-does]', { timeout: 8000 } );
await page.click( '.page-body .empty [data-does]' );
await page.waitForTimeout( 700 );

check( 'pressing the way out of an empty screen opens the thing it names',
	1 === await page.locator( '.modal' ).count(),
	await page.locator( '.modal h2' ).innerText().catch( () => 'nothing opened' ) );

await page.keyboard.press( 'Escape' );
await page.waitForTimeout( 300 );

/*
 * And a screen that sends you elsewhere actually takes you there. Season tickets is the specimen:
 * a season needs a run of at least two nights, a run is made on an event, and this screen used to
 * say so in a sentence and leave you to find the way.
 */
await page.click( 'nav button[data-view=seasons]' );
await page.waitForSelector( '.page-body .empty [data-goes]', { timeout: 8000 } );
await page.click( '.page-body .empty [data-goes]' );
await page.waitForTimeout( 900 );

check( 'and one that points at another screen opens it',
	'Events' === await page.locator( '.page-head h1' ).innerText(),
	await page.locator( '.page-head h1' ).innerText() );

/*
 * Leaving a half-finished job is the panel's own question.
 *
 * Two screens guarded unsaved work with `window.confirm` — the browser's grey box, whose buttons
 * are in the browser's language and which a browser may suppress outright, guarding nothing.
 */
console.log( '\nLeaving without saving is asked, not assumed' );

const putToTheBrowser = prompts.length;

await page.click( 'nav button[data-view=themes]' );
await page.waitForTimeout( 900 );

/* Made from the empty state's own way out, which checks both things at once. */
if ( await page.locator( '.page-body .empty [data-does]' ).count() ) {
	await page.click( '.page-body .empty [data-does]' );
	await page.waitForSelector( '.modal', { timeout: 8000 } );
	await page.fill( '.modal input[name=name]', 'A theme to leave' );
	await page.click( '.modal button[type=submit]' );
	await page.waitForTimeout( 1500 );
}

// Making one opens its editor; an account that already had themes is opened from the gallery.
if ( ! ( await page.locator( '#theme-back' ).count() ) ) {
	await page.waitForSelector( '[data-edit]', { timeout: 10000 } );
	await page.locator( '[data-edit]' ).first().click();
}

await page.waitForSelector( '#theme-back', { timeout: 10000 } );
await page.waitForTimeout( 500 );

const token = page.locator( '[data-token]' ).first();

check( 'the theme editor offers something to change', ( await token.count() ) > 0 );

await token.fill( '#123456' );
await page.waitForTimeout( 300 );
await page.click( '#theme-back' );
await page.waitForTimeout( 700 );

check( 'leaving unsaved work asks first', 1 === await page.locator( '.modal' ).count(),
	await page.locator( '.modal h2' ).innerText().catch( () => 'nothing was asked' ) );
check( 'and the browser is never asked to put the question',
	putToTheBrowser === prompts.length );

await page.keyboard.press( 'Escape' );
await page.waitForTimeout( 400 );

check( 'saying nothing keeps the work on screen', 1 === await page.locator( '#theme-back' ).count() );

/*
 * One night, seven screens.
 *
 * The door list, the tickets, the questions a checkout asks, the entry windows, the waiting list,
 * the rehearsal and the counter all ask which night somebody is working on, and every one of them
 * used to keep its own answer — so a clerk who chose Saturday on one screen was shown Friday on the
 * next, from the same picker in the same place, with nothing to say the question had been asked
 * again.
 */
console.log( '\nOne night, however many screens' );

const PICKERS = {
	doorlist: '#door-event',
	questions: '#q-event',
	entryslots: '#es-event',
	waitlist: '#wait-event',
	tickets: '#ticket-event',
	rehearsal: '#reh-event',
	counter: '#counter-event',
};

await page.click( 'nav button[data-view=doorlist]' );
await page.waitForTimeout( 900 );

const nights = await page.locator( '#door-event option' ).evaluateAll( ( options ) =>
	options.map( ( option ) => option.value ) );

check( 'there is more than one night to be on', nights.length > 1, `${ nights.length } nights` );

const chosen = nights[ 1 ] || nights[ 0 ];

await page.selectOption( '#door-event', chosen );
await page.waitForTimeout( 900 );

const following = [];
const wandering = [];

for ( const [ view, selector ] of Object.entries( PICKERS ) ) {
	await page.click( `nav button[data-view=${ view }]` );
	await page.waitForTimeout( 900 );

	if ( ! ( await page.locator( selector ).count() ) ) {
		continue;
	}

	( ( await page.locator( selector ).inputValue() ) === chosen ? following : wandering ).push( view );
}

check( 'every screen that asks which night is on the same one',
	0 === wandering.length && following.length >= 5,
	wandering.length ? `still on another night: ${ wandering.join( ', ' ) }` : following.join( ', ' ) );

await page.reload( { waitUntil: 'networkidle' } );
await page.waitForTimeout( 1200 );
await page.click( 'nav button[data-view=questions]' );
await page.waitForTimeout( 1200 );

check( 'and it is still the same one tomorrow',
	chosen === await page.locator( '#q-event' ).inputValue() );

console.log( '\nConsole errors: ' + ( errors.length ? errors.join( ' | ' ) : 'none' ) );
if ( errors.length ) failures++;

await browser.close();

console.log( failures === 0 ? '\nEVERY FORM SAYS WHAT IT WANTS' : `\n${ failures } CHECK(S) FAILED` );
process.exit( failures === 0 ? 0 : 1 );
