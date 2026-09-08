# ADR-0005: Six locales, right-to-left, and no untranslated string

- Status: Accepted
- Date: 2026-09-08

## Context

The platform must work in Persian, English, Arabic, German, French and Italian — across the panel,
the buyer-facing seat picker, hosted event sites, ticket email and SMS, and the door scanner. Two of
those languages are written right to left, and one of them (Persian) is the primary market.

The requirement was put plainly: put translation keys everywhere, in proportion to the languages,
and let nothing be left behind. That last clause is the hard part. Every project that adds
languages late has the same failure: the strings that were already there get translated, and every
string added afterwards is written in English inline, because nothing stops it.

## Decision

### 1. Six locales, one catalogue shape, one source of truth

`fa`, `en`, `ar`, `de`, `fr`, `it`. `en` is the reference catalogue: it defines the set of keys.
Every other locale is checked against it. `fa` and `ar` are right-to-left.

The catalogues are PHP files under `api/lang/<locale>/`, one per surface, and they are the *only*
place a user-visible string lives. This includes API error messages, which until now were English
literals at `ApiException` call sites; the error **code** is now also the translation key, so a new
refusal is translatable by construction rather than by remembering.

The browser is served the same catalogues as JSON from `/v1/i18n/<locale>`, projected from those
files rather than kept as a second copy: two copies of a translation is two translations, and the
one that is wrong is always the one nobody is looking at.

### 2. Nothing may be left behind, and CI is what enforces it

`tools/i18n-check.mjs` fails the build when:

- a key exists in `en` and is missing from any other locale;
- a key exists in another locale and not in `en` (a rename that left a stale translation);
- a placeholder (`:name`, `%d`, `%1$s`) appears in one locale of a key and not another — a
  translation that silently drops `:max` tells a buyer they may select up to seats;
- the *shape* of a placeholder differs between locales — `%d` in one and `:count` in another means
  one of them renders literally.

A rule that is only written down is a rule that is followed until the week before a release. This
one runs on every push.

### 3. Direction is a property of the locale, applied once

`dir="rtl"` and `lang` are set on the document from the resolved locale, and the CSS is written in
logical properties (`margin-inline-start`, not `margin-left`) so one stylesheet serves both
directions. There is no separate RTL stylesheet to fall out of date.

Mirroring is not blanket: text, layout and navigation flip; a seat map does not. Row A stays where
the venue put it, and an arrow that means "next" flips while an arrow that means "north" does not.

### 4. Locale is resolved from the nearest person, not the nearest server

In order: an explicit choice for this session; the signed-in user's preference; for a hosted site,
the site's locale; then the tenant's; then `Accept-Language`; then `en`. A buyer on an organiser's
Persian site gets Persian without asking, and a German-speaking member of that organiser's staff
gets German in the panel at the same moment.

### 5. Numbers, dates and money follow the reader; the amount does not

Formatting — digit shapes, decimal separators, calendars, currency placement — follows the
*reader's* locale. The currency itself follows the *event*. A Persian reader looking at a Berlin
show sees euros written the Persian way, not tomans, and not euros written the German way. Getting
this backwards would misprice a ticket, so it is `Money::format($amount, $currency, $locale)` and
never string concatenation.

Persian and Arabic get their own digit shapes by default, because a Persian ticket printed with
Latin digits reads as a machine's output rather than a document. It is a per-locale setting, not a
per-user one, so a venue's tickets look the same as each other.

The **calendar** follows the same rule and is the sharper case. Persian is rendered in the Persian
calendar — ۷ مهر ۱۴۰۵, not ۲۹ سپتامبر ۲۰۲۶ — because the second is a date an Iranian reader has to
convert before it names a day they could turn up on. Arabic is *not* given the Hijri calendar:
Arabic-speaking countries use the Gregorian calendar for civil dates, and a ticket dated by the
lunar calendar would be the mirror of the same mistake. This is a fact about each language's
readers, recorded per locale, and not a symmetry to be tidied.

None of this is Carbon's `format()` or its `isoFormat()`. The first prints English month names in
every language; the second translates the words and leaves the digits Latin, which produces a date
half in one script and half in another. `App\Support\Locale\Dates` goes through ICU, which does
month names, digit shapes and the calendar in one pass.

### 6. Translation is a data problem, and the panel is where it is solved

Organiser-authored content — page titles, block text, event names, email templates — is translated
per locale in the panel, with the reference locale shown beside the field being written. Untranslated
content falls back to the site's default locale rather than showing a key: a visitor should see a
page in the wrong language before they see `site.page.title`.

## Consequences

- Every string added from here on has to be added to six files. This is friction on purpose: it is
  the friction that keeps the promise.
- The check has a machine-translation escape hatch for *drafts* (`"_draft": true` on a locale
  entry), which the panel shows as unreviewed. Draft entries pass CI; they do not pass silently —
  the panel counts them and says so.
- The check-in app carries its own catalogue for the same six locales, because a door in Tehran is
  where a missing translation costs the most and where nobody can fix it.
