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

## Amendment, 2026-09: the panel finishes what it started

The catalogues above were built out screen by screen, and for several increments the panel was
honestly half-translated: everything added after the i18n work — modules, team, pricing, seat
prices, themes, reports, messaging, signup — read `App.t()`, while everything written before it —
the shell and its navigation, events, venues, seat maps, connections, the whole designer, the
website editor and the ticket desk — still held English inline. A Persian organiser got a Persian
sidebar over an English designer.

That is now closed. `api/lang/*/panel.php` is the largest catalogue on the platform (514 keys) and
`api/public/editor/js` holds no user-facing word of its own. Three things came out of doing it that
are worth writing down, because each is a rule for the next surface.

**A word that describes the machine is not translated.** The shortcut sheet keeps `Shift`, `Enter`,
`Ctrl/⌘`, `Delete` and the arrows in Latin: those letters are printed on the reader's keyboard
whatever language they read in, and translating them names a key that does not exist. What each
combination *does* is translated, and so are the three entries that are actions rather than keys —
Click, drag, Double-click. The same reasoning keeps theme names (Aurora, Noir) out of the
catalogue and puts their descriptions in it.

**Content created on the customer's behalf is content, and is written in their language.** A new
account is provisioned with a home page, an event page and a visiting page. Those were English
strings in `SiteProvisioner`; they are now `site.seed.*`, resolved in the *site's* locale rather
than the locale of whoever happened to click Create. An organiser whose site is Persian should not
have to translate three pages before going live. The same applies to the two menus a site is given:
their names are resolved from the menu key at render time, so a site created in one language does
not keep those names in another.

**Two checks are needed, not one.** `tools/i18n-check.mjs` proves the six locales are level with
each other; it is blind to whether the panel reads them at all, because `t()` never throws — a
mistyped key renders its own last segment and nothing goes red. So `tools/panel-strings-check.mjs`
now asserts the other direction in CI: every `panel.…` key the JavaScript looks up exists in the
catalogue, and every key in the catalogue is looked up by something. Keys assembled at run time
(`'panel.tools.' + tool.key`) count as a prefix, which is deliberately loose — the alternative is a
second copy of the tool list living in a lint. Beyond that, `api/locale_smoke.mjs` drives the real
panel in Persian and German and asserts both that the translations appear and that a list of
formerly hard-coded English strings does not.

## Amendment, 2026-09: the console too, and a way to choose

Two things were left over from the amendment above, and both are now done.

**The console is no longer English.** The reasoning for leaving it English was that a handful of
operators read it and they can read English. That holds only while the platform is run by one
office: support staff, resellers and the on-call operator of a self-hosted deployment are not
required to be English speakers, and a screen that can suspend an organiser's account is a poor
place to guess. `api/lang/*/console.php` is the catalogue, and `tools/panel-strings-check.mjs`
now checks the panel and the console as two separate surfaces — a `console.…` key looked up by
panel.js is a failure, which is the mistake worth catching between two applications that share a
directory.

It is delivered differently, and deliberately: the console page renders its own catalogue into the
document rather than fetching `/v1/i18n`. That endpoint is public and serves every panel visitor,
and there is no reason for an organiser's browser to download the words of a screen they may not
open. Because the page is rendered by the server, choosing a language is a reload with `?lang=`,
which `LocaleResolver` already understood and already remembers for the session.

Two things stay in English on that screen, for the same reason the keyboard keys did:

- **the audit actions** (`console.signed_in`, `tenant.suspended`) — stable identifiers in a log read
  back years later, and a log whose entries change wording is a log of nothing;
- **plan and theme keys** — `pro` is what a subscription points at.

**There is now a way to choose a language.** `I18n.choose` had existed since the i18n work and
nothing called it: the panel's language came from the account and the `Accept-Language` header, and
a person who wanted a different one had no way to say so. Both the panel and the console now carry
a menu in the sidebar footer, and the console carries a second one on its sign-in card — somebody
who cannot read the sign-in screen cannot reach the switch on the other side of it. Each locale is
listed in its own language, never in the reader's: somebody looking for Persian is looking for
فارسی, and "Persian" is exactly the word they cannot read.

The two applications share the choice through `localStorage`, because they are read by the same
person in the same browser. The console cannot see localStorage from the server, so its first load
in a session redirects once with `?lang=`; the session remembers it and no further redirect
happens.

One bug came out of this that is worth recording, because it is invisible in English. Persian and
Arabic render digits in their own shapes, and a middle dot between two of them reads as another
digit: "۲۰ · سالن‌ها" is easily read as "۲۰۰". The list separator is therefore a translated string
rather than a literal in the code — `،` in Persian and Arabic, ` · ` elsewhere. Punctuation is part
of a language, not part of a layout.

## Amendment, 2026-09: the door

The consequences above said the check-in app "carries its own catalogue for the same six locales,
because a door in Tehran is where a missing translation costs the most and where nobody can fix
it". That was the intent and not the fact: the scanner was English, in one language, everywhere.
It is six languages now, and the sentence describes something that exists.

**The one place a second copy of a translation is correct.** Everywhere else on this platform, two
copies is two translations and the wrong one is the one nobody is looking at. The scanner is the
exception, and the reason is in the sentence above: it has to work with no network, so a catalogue
it fetches is a catalogue it cannot fetch at the moment it is needed. The copy is therefore
*generated* — `tools/sync-checkin-strings.mjs` writes `checkin-app/lib/l10n/strings.g.dart` and the
boot strings inside `web/index.html` from `api/lang/<locale>/checkin.php` — and CI runs the tool
and fails on a diff, the same discipline `tools/sync-seat-picker.sh` applies to the picker. There is
still exactly one file anybody edits.

**A Flutter app is not a web page, so three things had to be built rather than asked for.**

- *The calendar.* `intl` does not do the Persian calendar, so the conversion is written out. It is
  deliberately ICU's arithmetic and not Birashk's 2820-year cycle, which the other implementations
  use: the two disagree about whether 1404 is a leap year, and the disagreement puts Nowruz a day
  out. A scanner that names a different day from the panel that sold the ticket is worse than one
  that shows a Gregorian date. The test reads its expectations out of ICU directly.
- *The digits.* Persian and Arabic count in their own glyphs, and a scanner showing "3 in" on a
  Persian screen reads as somebody else's machine. Numbers and clock times are shaped.
- *The font.* This is the one that would have shipped broken. Roboto has no Arabic script, and the
  engine's answer to a glyph it does not have is to fetch a fallback from `fonts.gstatic.com` —
  which `--no-web-resources-cdn` switches off, and a venue's wifi would block anyway. The first
  Persian build laid out perfectly and rendered every word as an empty box. Vazirmatn (SIL OFL) is
  bundled as a fallback family, and CI asserts it is in the build alongside CanvasKit and the
  barcode decoder, for the same reason all three are asserted.

Two things about the delivery are worth recording:

- The boot screen — the seconds a volunteer stares at on venue wifi before the engine has loaded
  anything — resolves the language itself, in six lines of inline script generated from the same
  catalogue. The app then reaches the same answer independently from the same three signals: the
  URL's `?lang=`, the stored choice, the phone's own languages.
- `web/manifest.json` stays English. It is a static file the web server hands out without PHP, so
  its `name` cannot vary by reader without giving up that property. It is the label under a
  home-screen icon and no working screen depends on it — but it is untranslated, and pretending
  otherwise would be worse than saying so.

## Amendment, 2026-09: the WordPress plugin

The plugin was already written for translation — a text domain, `__()` at every call site, a `.pot`
— and had no translations. It has five now, and they are generated rather than typed.

**Generated, in a project whose convention is that translators edit `.po` files.** That is the one
place this decision costs something, and it is worth being plain about: someone who edits
`wordpress-plugin/seatmap-connect/languages/seatmap-connect-de_DE.po` will lose the edit on the next
sync. The reason to accept that is the seat picker. It is one component, shipped twice — once inside
a hosted site and once inside somebody's WooCommerce shop — and its words live in
`api/lang/<locale>/site.php`. Translating them a second time into a `.po` would be two translations
of one component, and `tools/sync-seat-picker.sh` exists precisely because the two copies of the
picker's *code* had already tried to fork once.

So `tools/sync-wordpress-strings.mjs` extracts every msgid from the plugin, resolves each one, and
writes the `.pot`, the five `.po` files, the five compiled `.mo` files, and the JSON that
`wp_set_script_translations` needs for the block editor. It fails outright on a msgid with no
translation, so a new string cannot reach a shop untranslated, and CI fails on a diff.

Three things it taught, each a rule rather than a detail:

- **A shared key is not a shared string.** The first version matched the picker's strings to
  `site.php` by key, on the reasoning that the plugin builds the same keyed array. That put the
  hosted site's "Reserve these seats" on the plugin's button in five languages — where the English
  deliberately says "Reserve and add to cart", because WooCommerce has a cart and a hosted site does
  not. A translation is now shared only where the English is *identical*; where the two diverge the
  tool says so by name and demands the plugin's own wording. It found exactly one such string, which
  is the point: the rule is cheap and the failure was silent.
- **`.mo` is written here, not by `msgfmt`.** The format is a header and four tables, and requiring
  a gettext toolchain on every contributor's machine to change a word is worse than eighty lines
  that a real WordPress then reads back.
- **A script's translations are not in the `.mo`.** `wp_set_script_translations` wants a JSON file
  named after the md5 of the script's path, and falls back to English in silence when it is absent.
  The block's own panel was the last English thing on the screen, and nothing would have said so.

Verified in a throwaway WordPress 7.1 with WooCommerce, in Persian: the settings screen, the seat
picker on a page, and the block in the editor. `tools/wordpress-check.mjs` still buys a seat end to
end — and was repaired while it was in hand, because it had been waiting for a seat element the
picker no longer shows first (ADR-0003 §5) and had been reporting a working picker as broken.
