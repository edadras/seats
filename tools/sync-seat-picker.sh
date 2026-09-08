#!/usr/bin/env bash
#
# One seat picker, two consumers.
#
# The WordPress plugin ships it in its own assets directory and the hosted sites serve it from the
# API's public directory. Neither can reference a file outside its own deliverable, so both get a
# copy — and CI runs this and fails on a diff, which is what stops the two from quietly forking.
#
# Edit shared/seat-picker/, never a copy.

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
SRC="$ROOT/shared/seat-picker"

BANNER='/* Generated from shared/seat-picker — edit that, then run tools/sync-seat-picker.sh. */'

copy() {
	local from="$1" to="$2"

	mkdir -p "$( dirname "$to" )"
	{ echo "$BANNER"; cat "$from"; } > "$to"
}

copy "$SRC/widget.js"  "$ROOT/wordpress-plugin/seatmap-connect/assets/js/widget.js"
copy "$SRC/widget.css" "$ROOT/wordpress-plugin/seatmap-connect/assets/css/widget.css"
copy "$SRC/widget.js"  "$ROOT/api/public/site/js/widget.js"
copy "$SRC/widget.css" "$ROOT/api/public/site/css/widget.css"

echo "Seat picker synced to both consumers."
