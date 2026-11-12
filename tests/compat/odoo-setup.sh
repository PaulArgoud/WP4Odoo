#!/usr/bin/env bash
# Initialise the Odoo test databases (Community apps the modules need) and
# generate an API key per version in .keys/ (git-ignored).
set -euo pipefail
cd "$(dirname "$0")"

COMPOSE=(docker compose -f docker-compose.odoo.yml)
VERSIONS=${ODOO_VERSIONS:-"19 20"}
APPS="contacts,crm,sale_management,account,stock,purchase,mail,project,hr,hr_holidays,hr_recruitment,hr_timesheet,mass_mailing,calendar,event,mrp,survey,point_of_sale,pos_restaurant,delivery,loyalty,sale_loyalty,account_payment,stock_delivery"

mkdir -p .keys
"${COMPOSE[@]}" up -d --wait pg

for v in $VERSIONS; do
	echo "::group::Initialise Odoo $v"
	"${COMPOSE[@]}" run --rm -T --no-deps --entrypoint odoo "odoo$v" \
		-d "db$v" -i "$APPS" --without-demo=all --stop-after-init \
		--db_host=pg --db_user=odoo --db_password=odoo
	# `rpc` scope is required by Odoo 20 for external API keys.
	key=$("${COMPOSE[@]}" run --rm -T --no-deps --entrypoint odoo "odoo$v" \
		shell -d "db$v" --db_host=pg --db_user=odoo --db_password=odoo --no-http <<'PY' | sed -n 's/^KEY=//p'
import datetime
user = env['res.users'].browse(2)
key = env['res.users.apikeys'].with_user(user)._generate('rpc', 'wp4odoo-compat', datetime.datetime.now() + datetime.timedelta(days=1))
env.cr.commit()
print("KEY=" + key)
PY
)
	[ -n "$key" ] || { echo "Could not generate an API key for Odoo $v" >&2; exit 1; }
	printf '%s' "$key" > ".keys/odoo$v.key"
	echo "::endgroup::"
done

# shellcheck disable=SC2086
"${COMPOSE[@]}" up -d --wait $(for v in $VERSIONS; do printf 'odoo%s ' "$v"; done) || true

for v in $VERSIONS; do
	port=$([ "$v" = 19 ] && echo 8069 || echo 8070)
	for _ in $(seq 1 60); do
		curl -fsS "http://localhost:$port/web/login" -o /dev/null && break
		sleep 2
	done
done
echo "Odoo ready: $VERSIONS"
