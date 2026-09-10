#!/usr/bin/env bash
#
# One seat picker, and one room, however many consumers.
#
# The WordPress plugin ships them in its own assets directory, the hosted sites serve them from the
# API's public directory, and the designer loads the 3D engine from its own. None can reference a
# file outside its own deliverable, so each gets a copy — and CI runs this and fails on a diff,
# which is what stops them from quietly forking.
#
# The 3D engine is shared for a stronger reason than convenience: the room an organiser sets up in
# the designer has to be the room a buyer is shown, down to the last centimetre of rake, and two
# implementations of the same projection would agree until the day they did not.
#
# Edit shared/, never a copy.

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
SRC="$ROOT/shared/seat-picker"
HALL="$ROOT/shared/hall-3d"

BANNER='/* Generated from shared/ — edit the original, then run tools/sync-seat-picker.sh. */'

copy() {
	local from="$1" to="$2"

	mkdir -p "$( dirname "$to" )"
	{ echo "$BANNER"; cat "$from"; } > "$to"
}

copy "$SRC/widget.js"  "$ROOT/wordpress-plugin/seatmap-connect/assets/js/widget.js"
copy "$SRC/widget.css" "$ROOT/wordpress-plugin/seatmap-connect/assets/css/widget.css"
copy "$SRC/widget.js"  "$ROOT/api/public/site/js/widget.js"
copy "$SRC/widget.css" "$ROOT/api/public/site/css/widget.css"

copy "$HALL/hall3d.js" "$ROOT/wordpress-plugin/seatmap-connect/assets/js/hall3d.js"
copy "$HALL/hall3d.js" "$ROOT/api/public/site/js/hall3d.js"
copy "$HALL/hall3d.js" "$ROOT/api/public/editor/js/hall3d.js"

echo "Seat picker and hall renderer synced to every consumer."
