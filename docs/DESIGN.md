# What "professional" means on the buyer's side

This is the standard the hosted site is held to. It exists because "make it look better" is not a
brief anybody can check their work against, and because the failures that separate a competent
ticket page from a professional one are specific, repeatable, and mostly not about taste.

Every rule here was written after looking at a rendered page and measuring it, not from a list of
best practices. Where a rule has a number in it, the number came from that measurement.

---

## 1. The most valuable space must sell something

A ticket shop's first screen is the most valuable space it owns. Spending it on the venue's name is
spending it on a word the masthead already says, forty pixels above.

**The rule.** Above the fold, on any page a buyer can arrive at cold, there must be at least one of:
a show, a date, a price, or a way in. A band of colour with a name on it is none of those.

**The corollary that is easy to miss.** A hero exists to give a *photograph* room. With no
photograph there is nothing to give room to, and a fixed tall band becomes 480 pixels of gradient
that a buyer has to scroll past before the shop begins. So a hero with no artwork sizes itself to
its own content; only one carrying a picture holds the band.

## 2. Empty space is composed, never left over

There is a difference between space that was placed and space that is simply what was left when the
content ran out. Readers cannot name the difference and always feel it.

**The rules.**

- A grid must not reserve a track it has nothing to put in. `auto-fill` reserves; `auto-fit`
  collapses. Two cards in a three-track grid is a hole, and it is the single most common way a
  listing page looks unfinished on the day a venue has only two nights on sale.
- Two adjacent spacings are one spacing. A section that ends with 72px of padding followed by a
  footer that begins with 80px of margin is 152px of nothing that neither author intended.
- A single item must not be stretched to the full measure just because it can be. One card at the
  width of three is not a feature, it is a card that lost its proportions.

## 3. One thing wins in every composition

Where three elements overlap — a date badge, a category chip and the artwork's own lettering —
the page must say which one matters. Usually it is the date: it is the fact a buyer is scanning for.

**The rule.** When elements share a box, rank them explicitly: one at full strength, the rest
quieter. A fallback tile's lettering is a texture, not a headline, and it must never be set larger
than the title of the thing it stands for.

## 4. Measure is per content type, not per page

Prose wants 60–75 characters. A seating plan wants as much width as the room can give it: the plan
*is* the product on that page, and shrinking it to the width of a paragraph makes a buyer pan around
a diagram that would have fitted.

**The rule.** Three measures, chosen by what is in them — reading (`--shell-narrow`), listing
(`--shell`), and working (wider than either, for the seat map). Never one measure because it was
already defined.

## 5. The scale has no holes in it

A type scale that jumps 38px → 19px with nothing between forces every intermediate heading to pick
a side, and the page loses its hierarchy at exactly the level where a buyer is scanning.

**The rule.** Every step a page actually uses exists as a step. Body copy gets `text-wrap: pretty`
so a paragraph never ends on one orphaned word; headings get `balance`.

## 6. Nothing is only decorative

- Every interactive element has a visible `:focus-visible` state. A shop that can be used with a
  mouse and not with a keyboard is a shop with a door some people cannot open.
- Every transition is off under `prefers-reduced-motion: reduce`. Motion is a preference somebody
  has already expressed to their operating system; asking again on the page is not respect.
- Text over an image always has a scrim. An organiser will upload a photograph that is bright where
  the title sits, and the title has to survive it.
- Contrast is checked, not eyeballed: 4.5:1 for body text, 3:1 for large text, in **both** themes.
  `a11y_check` measures this on every run and is the arbiter.

## 7. The phone is the primary device, not the fallback

Most tickets are bought on a phone and every one of them is *used* on one, in a queue, at a door.

**The rules.** A side gutter at every width. Touch targets of at least 44px on a coarse pointer.
Fields at 16px or larger, or Safari zooms the page and stays zoomed. Sticky bars padded with
`env(safe-area-inset-bottom)`. Heroes sized in `dvh` rather than `vh`, because `100vh` is the height
with the browser's toolbars *hidden*.

## 8. It has to survive the organiser

Everything on these pages is somebody's content, and a design that only works with the content it
was designed against is a design that breaks on its first real customer.

**The rule.** Every component is checked against: no artwork, a very long name, a name in Persian
or Arabic, one item, thirty items, and a sold-out state. A page that needs a photograph to look
finished will not look finished, because on the first afternoon there is never a photograph.

## 9. A canvas still has to answer the pointer

A seat map is drawn on a `<canvas>`, and a canvas has no `:hover`. So the whole vocabulary the rest
of the page gets for free — a cursor change, a lift, a tint — is absent exactly where the buyer is
making the decision the page exists for, unless it is drawn deliberately.

**The rule.** Whatever is under the pointer is drawn differently from its neighbours, and the
difference is visible at the size a seat actually is. Measured: a seat in the stalls is **9.3 CSS
pixels across at 390px wide**, drawn about eight pixels from the next one. A one-pixel border and a
five per cent tint are not feedback at that size.

**What it is here.** An available seat under the pointer takes the ink-coloured ring at twice the
normal weight, grows by a pixel, and gains a soft halo of its own colour just beyond its edge, drawn
before the seat so the seat stays crisp. At 9.3 pixels the ring is what carries it; the halo is what
keeps the ring from closing up against the neighbour eight pixels away, which is what would make a
dense row read as a smudge rather than as one chair. Only available seats answer: a pointer moving
across a sold block should not suggest that any of it can be had.

**The corollary.** A hover is not a selection and must not be drawn like one. It is redrawn in the
2D renderer only; in 3D the pointer already moves a camera, and a room where everything glows as the
eye passes is a room nobody can read.

---

## How this is enforced

| | |
| --- | --- |
| Contrast, focus order, keyboard reach | `api/a11y_check.mjs`, both themes, every run |
| That a page still renders and sells | `api/site_smoke.mjs` and the other 67 browser checks |
| That the six languages still fit | `tools/i18n-check.mjs`, and the RTL pass in `languages_smoke` |
| Everything above that a machine cannot judge | Look at the rendered page. Screenshot it at 1440 and at 390. Measure the gaps before claiming they are right. |

The last row is the one that matters, and it is the one that gets skipped. Reading the stylesheet is
not looking at the page.
