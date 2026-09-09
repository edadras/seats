# ADR-0007: Announcements to buyers, and notices to the organiser

- Status: Accepted
- Date: 2026-09-09

## Context

Messaging already told a buyer what they were entitled to be told: their order was confirmed, their
order was cancelled, their event is tomorrow. Two things were missing, and organisers asked for
both in the same breath.

They need to be able to *say something* — the doors have moved, the show is postponed, thank you
for coming — to the people who bought, by email or SMS, without exporting a list into somebody
else's mailing tool.

And they need the platform to tell *them* things: a refund happened, a channel refused a message,
an announcement finished, a night sold out. Those currently exist only as audit rows, which is to
say they exist for somebody who already suspects something and goes looking.

## Decision

### 1. An announcement is an instruction; its messages are ordinary deliveries

`announcements` holds what was said, to whom, on which channels. Every message it sends is a row in
`message_deliveries` with `kind = announcement` and an `announcement_id`. There is no recipients
table.

This is the decision that shapes everything else. "Did they get it" is already answered in one
place, already retried by `messages:retry`, and already on a screen. A second table of who was
written to would be a second copy of every buyer's address, a second answer to the same question,
and a second thing to keep in step with the first.

### 2. The audience is resolved once, at send time

Sending goes on for as long as it goes on, and orders keep arriving while it does. Re-resolving
"everybody who bought" for each batch would send some people two copies and miss others entirely,
depending on where the sort order moved them. So the queue is written in full the moment the
organiser presses send, deduplicated by address — a buyer with four orders gets one email — and
only from orders where money actually arrived.

### 3. The first batch is sent in the request; the rest is scheduled

A small announcement is finished by the time the screen comes back, which is what somebody sending
to forty people expects. A large one is finished by `messages:announce`. Both call the same method:
two ways of sending one announcement is how two of them start disagreeing about who has been
written to.

### 4. Sending is its own permission

`messages.send`, not `account.manage`. Writing to every customer is a different act from
configuring the platform, and a manager who runs the programme should be able to say "tonight is
moved" without also being able to change who is in the account. The box office, who may refund an
order, may not write to everybody who bought.

### 5. A notification is a kind and its facts, never a sentence

`notifications` stores a kind, a level and the values that go in it. The sentence is composed when
it is read, in the reader's language, from a catalogue this platform owns (ADR-0005). A notice
raised while one colleague was reading Persian must not reach the German one in Persian.

### 6. Who may see a notice is decided when it is read

Each kind names the permission that governs the thing it is about: a refund by `orders.view`, a
failed message by `account.manage`, a verified domain by `sites.view`. That is checked on the way
out rather than baked in when the row is written, because roles change — somebody promoted on
Tuesday should see Monday's refunds, and somebody who loses the box office should stop seeing them.

Read marks are per person: one colleague reading a notice does not read it for everybody.

### 7. `danger` also goes out by email

A message a buyer did not receive is not a thing to find out next time somebody opens the panel. A
`danger` notification is emailed to the people holding its permission, through the same dispatcher
and the same channels as everything else, as the `system.notice` kind — capped at five addresses,
because an alarm that goes to forty people is an alarm nobody owns.

## Consequences

- **An announcement cannot be unsent.** It is queued in full at the moment it is sent, and the
  screen says how many messages that is before the button is pressed. There is no scheduled send
  and no cancel; both are additions, and neither is pretended at.
- **"Sold out" is noticed hourly, not instantly.** The honest answer to whether an event is full
  is `AvailabilityService`, which walks the house; walking a twenty-thousand-seat hall on every
  confirmed order would make selling slower for everyone to deliver a notice an hour earlier. A
  cheaper second implementation of "sold out" is the alternative, and two implementations is how a
  hall gets declared full while seats are still on sale.
- **A refusal is reported once per channel per hour.** A channel that is refusing is refusing
  everything it is handed.
- **Announcements are plain text.** They are rendered as text by every channel, an SMS has no
  subject line, and a rich-text editor here would mean an HTML sanitiser for content that goes to
  thousands of strangers' inboxes.
