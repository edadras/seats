#!/usr/bin/env bash
#
# Build the door scanner and install it into the API's public directory, so one deployment serves
# both the panel and the app the volunteers open at the gate.
#
# Serving it from the API is not a convenience: the app guesses its API address from the address it
# was loaded from, which makes the common install a matter of opening /checkin and typing a pairing
# code — no address to get wrong at a door, in the dark, five minutes before the house opens.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
target="${1:-$here/../api/public/checkin}"

if ! command -v flutter >/dev/null 2>&1; then
    echo "flutter is not on PATH. Install Flutter 3.38 or newer, then run this again." >&2
    exit 1
fi

cd "$here"

flutter pub get
flutter analyze
flutter test

# --base-href must match where the app is served. The router serves it at /checkin, and the built
# index.html hard-codes this value, so building for a different path means changing both.
#
# --no-web-resources-cdn is not optional here. Without it the engine fetches CanvasKit from
# gstatic.com on every cold start, so the scanner does not open at all on a venue's locked-down
# guest wifi — which is most venues, and always the one night it matters.
flutter build web --release --base-href=/checkin/ --no-web-resources-cdn

# The symbol maps are only useful to whoever owns the private symbol files, and they are large.
find build/web -name '*.symbols' -delete

mkdir -p "$target"
# Mirror rather than copy: a stale main.dart.js left behind by an older build is a scanner that
# fails on exactly the machines that cached it.
rm -rf "${target:?}"/*
cp -R build/web/. "$target/"

echo "Installed $(du -sh "$target" | cut -f1) into $target"
