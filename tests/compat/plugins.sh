#!/usr/bin/env bash
# Installs the latest release of each free plugin, activates it alone (with WooCommerce),
# and runs check-plugin.php for its module. Runs INSIDE the wp-env tests-cli container:
#   npx wp-env run tests-cli bash wp-content/plugins/wp4odoo/tests/compat/plugins.sh
set -uo pipefail
cd /var/www/html
DIR=wp-content/plugins/wp4odoo/tests/compat
fail=0
total=0

# wp-env installs WooCommerce as "woocommerce.latest-stable"; several plugins (e.g. WC Vendors)
# check for the standard path woocommerce/woocommerce.php, so use the regular copy.
wp plugin deactivate woocommerce.latest-stable >/dev/null 2>&1 || true
wp plugin install woocommerce --activate >/dev/null 2>&1 || wp plugin activate woocommerce >/dev/null 2>&1 || true

while read -r mod slug; do
	[[ -z "$mod" || "$mod" == \#* ]] && continue
	total=$((total + 1))
	if [ "$slug" != "woocommerce" ]; then
		wp plugin is-installed "$slug" 2>/dev/null || wp plugin install "$slug" >/dev/null 2>&1
		# An activation failure is not fatal here: check-plugin.php then reports the
		# module as not detected, which is a failure unless listed in known-issues.php.
		act=$(wp plugin activate "$slug" 2>&1) || echo "note: $slug could not be activated: $(echo "$act" | tail -1)"
		ver=$(wp plugin get "$slug" --field=version 2>/dev/null)
	else
		ver=$(wp plugin get woocommerce --field=version 2>/dev/null)
	fi
	out=$(wp eval-file "$DIR/check-plugin.php" "$mod" 2>&1 | grep -E '^\[(PASS|FAIL|KNOWN)\]|Fatal error' | head -3)
	[ -z "$out" ] && out="[FAIL] $mod — no result (fatal error while loading the module?)"
	echo "$out  <- $slug $ver"
	if echo "$out" | grep -q '^\[FAIL\]\|Fatal error'; then
		fail=$((fail + 1))
		echo "  diagnostics: active plugins = $(wp plugin list --status=active --field=name 2>/dev/null | tr '\n' ' ')"
	fi
	[ "$slug" != "woocommerce" ] && wp plugin deactivate "$slug" >/dev/null 2>&1
done < "$DIR/plugins.txt"

echo
echo "$((total - fail))/$total modules verified against the latest plugin releases."
exit $((fail > 0 ? 1 : 0))
