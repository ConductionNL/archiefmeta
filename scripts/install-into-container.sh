#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Conduction B.V.
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Copies the built app into a running Nextcloud container and enables it.
# Usage: scripts/install-into-container.sh <container> [custom_apps dir]
set -euo pipefail

container="${1:?container name}"
apps_dir="${2:-/var/www/html/custom_apps}"
root="$(cd "$(dirname "$0")/.." && pwd)"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

test -f "$root/js/archiefmeta-sidebar.mjs" || { echo "Run npm ci && npm run build first" >&2; exit 1; }

mkdir -p "$stage/archiefmeta"
cp -r "$root/appinfo" "$root/lib" "$root/l10n" "$root/js" "$root/LICENSE" "$stage/archiefmeta/"
[ -d "$root/img" ] && cp -r "$root/img" "$stage/archiefmeta/"

docker exec "$container" rm -rf "$apps_dir/archiefmeta"
docker cp "$stage/archiefmeta" "$container:$apps_dir/archiefmeta"
docker exec "$container" chown -R www-data:www-data "$apps_dir/archiefmeta"
docker exec -u www-data "$container" php occ app:enable archiefmeta
