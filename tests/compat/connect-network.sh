#!/usr/bin/env bash
# Attach the Odoo containers to the wp-env network so the tests-cli container
# reaches them as http://odoo19:8069 and http://odoo20:8069 (works on Linux and macOS).
set -euo pipefail
VERSIONS=${ODOO_VERSIONS:-"17 18 19 20"}
cli=$(docker ps --format '{{.Names}}' | grep -m1 'tests-cli')
net=$(docker inspect "$cli" --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' | awk '{print $1}')
for v in $VERSIONS; do
	ctr=$(docker ps --format '{{.Names}}' | grep -m1 "wp4odoo-compat-odoo$v-1")
	docker network connect --alias "odoo$v" "$net" "$ctr" 2>/dev/null || true
done
echo "Odoo containers attached to $net"
