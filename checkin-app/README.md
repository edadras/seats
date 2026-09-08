# Seatmap check-in

The scanner staff use at the door. A Flutter web app: a volunteer opens a URL on whatever phone
they brought, types a pairing code once, and starts scanning.

Web rather than a store app on purpose. Door staff are casual, often volunteers, and often holding
a phone nobody has admin rights to. "Open this link" is a thing you can say to a stranger five
minutes before the house opens. "Install this from the App Store, then find the invite email" is
not.

## What it does

- **Pairs once.** A single-use pairing code from the panel is exchanged for a device token scoped
  to named events. The code is spent on use and expires on its own.
- **Scans with the camera.** `mobile_scanner` with a software decoder, so it works on the browsers
  that have no native `BarcodeDetector` — which is most iPhones.
- **Types a code by hand.** For the ticket that is crumpled, or the phone with a cracked screen.
- **Says yes or no in one glance.** Green admits, red refuses, and an already-used ticket says who
  admitted it and when — the argument at the door is settled by the screen, not by the volunteer.
- **Keeps working with no signal.** A refused scan and a failed scan are different things: a
  refusal is shown, a network failure is queued. The queue survives a reload and is sent in one
  batch when the connection comes back, deduplicated by a client-side scan id so a retry cannot
  admit anyone twice.
- **Shows the live count.** Sold, checked in, and how many are still outside.

## Running it

```bash
flutter pub get
flutter test
flutter run -d chrome
```

Point it at a Seatmap instance on the pairing screen. In production the app is served *by* that
instance, so the address is filled in from where the page was loaded and staff never type it.

## Building for the platform

```bash
./build.sh              # installs into ../api/public/checkin
./build.sh /some/path   # or somewhere else
```

The script runs `flutter analyze` and the tests before it builds; a scanner is not the place to
find out a build was broken. Output is served at `/checkin` on every host the platform answers to.
It is a build artefact, so it is not committed.

## Two things that must not come from a CDN

A default Flutter web build fetches CanvasKit from `gstatic.com`, its font from
`fonts.gstatic.com`, and — through `mobile_scanner` — its barcode decoder and that decoder's
`.wasm` from `jsdelivr.net`. Four network dependencies before a single ticket can be read, on the
one network you do not control.

So: `--no-web-resources-cdn` in `build.sh` keeps CanvasKit local, Roboto is bundled in `fonts/`
and named in the theme, and `zxing-wasm` is vendored in `web/zxing/` and wired up in
`web/index.html` before Flutter starts. What remains is one origin: the Seatmap instance the door
is already talking to.

## Tests

`flutter test` covers what a wrong answer would cost: that every result the API can return has a
headline and admits or refuses the right way, that an unknown result refuses rather than admits,
that a refusal is never mistaken for being offline, and that the offline queue survives a restart,
deduplicates, and clears only what it actually sent.
