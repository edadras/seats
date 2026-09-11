/**
 * Driving the hall at the counter, for the checks that sell something.
 *
 * The box office runs the buyer's own seat picker now — one plan, one implementation, both sides of
 * the glass — so every check that used to click a grid of numbered buttons clicks chairs on a plan
 * instead. That is five scripts doing the same four steps, so the four steps live here.
 *
 * The seats are taken from the list under the plan rather than from the canvas: a canvas has no
 * elements to click, and the list is the same selection by another route — which is exactly why it
 * is there.
 */

/** Open a night at the counter and wait for its hall to arrive. */
export async function openHall( page, label ) {
	await page.waitForSelector( '#counter-event', { timeout: 20000 } );

	if ( label ) {
		await page.selectOption( '#counter-event', { label } );
	}

	await page.waitForSelector( '#counter-picker .seatmap-widget__canvas', { timeout: 20000 } );
	await page.waitForTimeout( 1200 );
}

/**
 * Choose `quantity` free chairs, entering a block first where the hall has blocks.
 *
 * Returns how many were actually taken, so a caller can check rather than assume.
 */
export async function chooseSeats( page, quantity = 1 ) {
	var blocks = page.locator( '#counter-picker .seatmap-widget__block:not([disabled])' );

	if ( await blocks.count() ) {
		await blocks.first().click();

		/*
		 * Waited for rather than slept through: entering a block re-renders the list under the plan,
		 * and a fixed pause is a guess that is too long on one machine and too short on the next.
		 *
		 * `attached`, not visible: the chairs are inside a disclosure that starts closed, so they
		 * are in the page before anybody can see them — which is the next step's problem, not this
		 * one's.
		 */
		await page.waitForSelector( '#counter-picker .seatmap-widget__seat', {
			state: 'attached',
			timeout: 20000,
		} );
		await page.waitForTimeout( 300 );
	}

	/*
	 * Free, and not already chosen.
	 *
	 * A chosen seat stays enabled — it has to, or a buyer could not change their mind — so clicking
	 * "the first free one" twice picks a chair and then puts it back.
	 */
	const seats = page.locator(
		'#counter-picker .seatmap-widget__seat:not([disabled]):not(.is-selected)'
	);

	/*
	 * The chairs are folded into a disclosure: the plan is where they are picked by hand, and the
	 * list is the way in for a keyboard — and for a script. Opened by asking whether the first seat
	 * can actually be seen rather than by reading the `open` attribute, because "in the DOM" and
	 * "on the screen" are not the same question and only one of them can be clicked.
	 */
	if ( await seats.count() && ! await seats.first().isVisible() ) {
		await page.locator( '#counter-picker .seatmap-widget__list > summary' ).click();
		await page.waitForTimeout( 400 );
	}

	const wanted = Math.min( quantity, await seats.count() );
	let taken = 0;

	for ( let i = 0; i < wanted; i++ ) {
		// Always the first still-unchosen one: each click takes a chair out of that set.
		await seats.first().click();
		await page.waitForTimeout( 300 );
		taken = await page.locator( '#counter-picker .seatmap-widget__seat.is-selected' ).count();
	}

	return taken;
}

/** Press the button that hands the chosen seats to the sale dialog. */
export async function startSale( page ) {
	await page.locator( '#counter-picker .seatmap-widget__submit' ).click();
	await page.waitForSelector( '.modal', { timeout: 20000 } );
}
