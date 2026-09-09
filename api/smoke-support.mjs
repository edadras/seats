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
