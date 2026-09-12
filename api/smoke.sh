#!/usr/bin/env bash
#
# Every browser check, in one go.
#
# These exist one file at a time and were run one file at a time, which is how a frozen list of
# fourteen screen names survived the panel growing to twenty-four: nothing ran them all, so nothing
# noticed. This runs them all.
#
# Two things have to happen between checks, and both were learned the hard way:
#
#   - the database is re-seeded, because most of these edit what they find;
#   - the rate limiter is emptied, because a dozen checks each signing in as the same owner will
#     trip `throttle:20,1` on /v1/auth/login and every one after that fails with a timeout that
#     says nothing about why.
#
# Needs a server on 8123 and, for the accessibility check, the plugin preview on 8200:
#
#   php artisan serve --port=8123 &
#   (cd ../wordpress-plugin && python3 -m http.server 8200) &
#   ./smoke.sh [name ...]
#
set -uo pipefail

cd "$(dirname "$0")"

ALL=(
	editor_smoke picker_smoke site_smoke embed_smoke locale_smoke console_smoke
	pricing_smoke ticket_types_smoke discount_smoke counter_smoke customers_smoke
	door_smoke entry_smoke together_smoke cancel_smoke translations_smoke
	refund_smoke settlement_smoke wallet_smoke presale_smoke addons_smoke voucher_smoke season_smoke basket_smoke queue_smoke quota_smoke pace_smoke till_smoke limits_smoke messaging_smoke segments_smoke languages_smoke consent_smoke resale_smoke renewal_smoke import_smoke tiers_smoke promoters_smoke chargeback_smoke printing_smoke plans_smoke productions_smoke hall3d_smoke agents_smoke managers_smoke invite_smoke reports_smoke waitlist_smoke billing_smoke rehearsal_smoke leaving_smoke sso_smoke loyalty_smoke modules_smoke webhooks_smoke sender_smoke measurement_smoke memberships_smoke views_smoke webapp_smoke scanners_smoke bank_smoke firstrun_smoke ticket_design_smoke
	themes_smoke signup_smoke a11y_check
)

CHECKS=("${@:-}")
[ -z "${CHECKS[0]}" ] && CHECKS=("${ALL[@]}")

passed=()
failed=()

for check in "${CHECKS[@]}"; do
	printf '\n\033[1m── %s\033[0m\n' "$check"

	php artisan migrate:fresh --seed --force >/dev/null 2>&1
	php artisan cache:clear >/dev/null 2>&1
	redis-cli flushall >/dev/null 2>&1

	if SEATMAP_SHOTS="${SEATMAP_SHOTS:-/tmp/smoke-shots}/$check" \
		timeout 400 node "$check.mjs" 2>&1 | tail -n 20; then
		passed+=("$check")
	else
		failed+=("$check")
	fi
done

printf '\n%s\n' "$(printf '─%.0s' {1..68})"
printf '%d passed, %d failed\n' "${#passed[@]}" "${#failed[@]}"

if [ "${#failed[@]}" -gt 0 ]; then
	printf 'failed: %s\n' "${failed[*]}"

	exit 1
fi
