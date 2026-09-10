/**
 * The bits every browser smoke needs before it can start.
 *
 * Mostly one thing: *which* event to drive. The demo deliberately contains two kinds of room — a
 * theatre with named chairs and a warehouse sold by the head — because half the events on this
 * platform are the second kind and a demo of only the first hides every way that path differs.
 * That means "the first event in the list" is no longer a safe thing for a test to grab, and a
 * check that silently drove the wrong room would fail with a timeout thirty seconds later and tell
 * you nothing about why.
 */

const login = async (base, device) => {
	const response = await fetch(base + '/v1/auth/login', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
		body: JSON.stringify({ email: 'owner@northgate.test', password: 'password', device_name: device }),
	});

	const body = await response.json();

	if (!body.token) {
		throw new Error('Could not sign in: ' + JSON.stringify(body));
	}

	return body.token;
};

/**
 * The seeded event that has chairs in it.
 *
 * Asked of availability rather than of the seat count, because standing places count towards a
 * seat total too — "1364 seats" is what the warehouse reports, and it has none.
 */
export async function seatedEvent(base, device = 'smoke') {
	const token = await login(base, device);

	const events = await (await fetch(base + '/v1/events', {
		headers: { Accept: 'application/json', Authorization: 'Bearer ' + token },
	})).json();

	for (const event of events.data || []) {
		const availability = await (await fetch(
			`${base}/v1/embed/events/${encodeURIComponent(event.public_id)}/availability`,
			{ headers: { Accept: 'application/json' } }
		)).json();

		if ((availability.seats || []).length) {
			return { ...event, token };
		}
	}

	throw new Error('No seeded event has any seats. Re-seed before running the smokes.');
}

/**
 * Open a section, the way a buyer opens one.
 *
 * A hall is offered as sections first and the chairs are inside one. Clicking the middle of the
 * plan and hoping a section is under the pointer is how a check breaks the day somebody moves a
 * block: the section list is a row of real buttons with real labels, so this presses one.
 *
 * Returns false where the room has no sections at all — a warehouse sold by the head — which is a
 * legitimate shape, not a failure.
 */
export async function openASection(page) {
	/*
	 * Wait for the picker to have drawn *something* first.
	 *
	 * The stage appears before the side list is filled in, so counting section buttons the
	 * instant the stage exists finds none, decides the room has no sections, and leaves a caller
	 * looking for seats inside a section nobody opened. Either shape settles this wait: a hall
	 * with sections lists them, a warehouse sold by the head lists its places.
	 */
	await page.waitForSelector('.seatmap-widget__blocks, .seatmap-widget__list', { timeout: 15000 });

	const sections = page.locator('.seatmap-widget__block:not([disabled])');

	if (0 === await sections.count()) {
		return false;
	}

	await sections.first().click();
	await page.waitForSelector('.seatmap-widget__list');

	return true;
}

/**
 * Where the picker drew the nth seat that can still be bought.
 *
 * Asked of the widget rather than guessed from the DOM, because the seats are drawn on a canvas
 * and there is nothing in the DOM to ask.
 */
export async function seatPoint(page, index) {
	/*
	 * Put the plan in the middle of the window before measuring anything.
	 *
	 * The coordinates below are viewport coordinates, and a page that happens to be scrolled — the
	 * picker scrolls itself into view on some routes — can leave a seat sitting behind the site's
	 * own sticky header. The click then lands on the header, nothing is selected, and the failure
	 * arrives much later as "the reserve button never became enabled".
	 */
	await page.evaluate(() => {
		document.querySelector('.seatmap-widget').scrollIntoView({ block: 'center' });
	});
	await page.waitForTimeout(150);

	const point = await page.evaluate((i) => {
		const widget = document.querySelector('.seatmap-widget').seatmapWidget;
		const seats = widget.seats.filter((seat) =>
			seat.floorKey === widget.floorKey && widget.inOpenBlock(seat) && 'available' === seat.state);

		if (!seats[i]) {
			return { error: `${seats.length} seats are open; asked for #${i}.` };
		}

		const rect = widget.canvas.getBoundingClientRect();
		const scale = widget.baseScale * widget.view.scale;

		return {
			x: rect.left + seats[i].x * scale + widget.view.x,
			y: rect.top + seats[i].y * scale + widget.view.y,
		};
	}, index);

	if (point.error) {
		throw new Error('No seat to click: ' + point.error);
	}

	return point;
}
