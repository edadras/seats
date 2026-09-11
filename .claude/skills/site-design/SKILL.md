---
name: site-design
description: The standard the buyer-facing pages of this platform are held to. Use when changing anything a ticket buyer sees — the hosted site, its blocks and stylesheet, the seat picker, the checkout — or when asked to make a page look better, more professional, or more finished.
---

# Designing the buyer's side of this platform

`docs/DESIGN.md` is the full standard and the reasoning behind each rule. This is the working
version: what to do, and the order to do it in.

## Do not start from the stylesheet

Reading CSS tells you what was intended. It never tells you what a buyer sees, and every defect
found in this repository so far was invisible in the source and obvious in a screenshot.

**Start every change like this:**

```bash
php artisan migrate:fresh --seed --force && php artisan serve --port=8123 &
# then screenshot http://northgate.localhost:8123 at 1440×1000 and at 390×844, full page
```

Then *measure*. Read the numbers out of the DOM rather than judging by eye — `getBoundingClientRect`
on the grid, the hero, the gap above the footer, and `getComputedStyle` on anything you suspect.
Claims like "there is too much space" are not actionable; "152px between the last card and the
footer, from a 72px section padding plus an 80px footer margin" is.

Scroll before you conclude. A full-page screenshot renders `position: sticky` at its unscrolled
position, so a sticky element that is completely broken looks identical to one that works.

## The rules, shortest form

1. **The first screen must sell something** — a show, a date, a price or a way in. Never the venue's
   name, which the masthead already says.
2. **A height is for a photograph.** No photograph, no height.
3. **`auto-fit`, never `auto-fill`**, and never a hard column count that outlives the item count.
   Two cards in a three-track grid is the hole every venue's programme has in its quiet month.
4. **Two adjacent spacings are one spacing.**
5. **One element wins per composition.** A fallback tile's lettering is texture, never a headline.
6. **Measure follows content**: prose narrow, listings at the shell, a seat plan wider than both —
   the plan is the product on that page.
7. **Focus-visible on everything; all motion off under `prefers-reduced-motion`.**
8. **It has to survive the organiser**: no artwork, a very long name, a Persian or Arabic name, one
   item, thirty items, sold out. There is never a photograph on the first afternoon.

## Things specific to this codebase

- The seat picker lives in `shared/seat-picker/`. **Run `tools/sync-seat-picker.sh` after touching
  it** — it is served to the hosted site, to somebody else's WordPress, and to the panel.
- Because of that, the picker must never assume what is above it. Anything sticky inside it reads
  `--seatmap-sticky-top`, and the host sets it.
- Six locales, two of them right-to-left. Use logical properties (`inset-block-start`,
  `margin-inline`), never `left`/`right`.
- Themes (`api/public/site/css/themes/*.css`) may override the tokens, so never hard-code a colour
  that a theme is supposed to own.

## Before saying it is done

```bash
cd api
./smoke.sh a11y_check site_smoke picker_smoke embed_smoke   # then the full ./smoke.sh
php artisan test
```

`a11y_check` measures contrast in both themes and is the arbiter — not your judgement of whether
something "looks readable". Then screenshot again at both widths and compare against the before
shots. If you cannot show what changed, you do not know that anything did.
