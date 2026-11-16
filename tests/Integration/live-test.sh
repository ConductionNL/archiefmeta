#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Conduction B.V.
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Integration test against a running Nextcloud with S3 primary storage (Object Lock)
# and an S3 external mount. It proves that:
#   1. values from S3 Object Lock, object metadata and object tags appear with their source;
#   2. WebDAV PROPPATCH on nc:metadata-archiefmeta-* is refused, for the owner and for a share recipient;
#   3. a regular user can read the values through the app API but has no way to write them.
#
# Evidence (requests, status codes, bodies) is written to $OUT.
# Defaults match the vng-ol test environment; override with environment variables.
# Credentials have no default: set ADMIN_PASS, TEST_PASS, S3_KEY and S3_SECRET.
set -uo pipefail

NC_URL="${NC_URL:-http://127.0.0.1:8310}"
NC_CONTAINER="${NC_CONTAINER:-vng-ol-nextcloud}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:?set ADMIN_PASS}"
TEST_USER="${TEST_USER:-archieftest}"
TEST_PASS="${TEST_PASS:?set TEST_PASS}"
MC_IMAGE="${MC_IMAGE:-bitnamilegacy/minio-client:2025.4.16}"
MC_NETWORK="${MC_NETWORK:-vng-ol-net}"
S3_ENDPOINT="${S3_ENDPOINT:-http://vng-ol-minio:9000}"
S3_KEY="${S3_KEY:?set S3_KEY}"
S3_SECRET="${S3_SECRET:?set S3_SECRET}"
PRIMARY_BUCKET="${PRIMARY_BUCKET:-vng-ol-primary}"
EXTERNAL_BUCKET="${EXTERNAL_BUCKET:-vng-ol-external}"
EXTERNAL_MOUNT="${EXTERNAL_MOUNT:-ext-external}"
OUT="${OUT:-$(cd "$(dirname "$0")/../.." && pwd)/build/integration}"

RUN_ID="$(date +%s)"
DIR="archiefmeta-it-$RUN_ID"
DAV="$NC_URL/remote.php/dav/files"
NS='xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns"'

mkdir -p "$OUT"
EVIDENCE="$OUT/evidence.md"
: > "$EVIDENCE"
pass=0
fail=0

log() { printf '%s\n' "$*" | tee -a "$EVIDENCE"; }
check() {
	local name="$1" ok="$2"
	if [ "$ok" = "1" ]; then pass=$((pass + 1)); log "PASS: $name"; else fail=$((fail + 1)); log "FAIL: $name"; fi
}
occ() { docker exec -u www-data "$NC_CONTAINER" php occ "$@"; }
mc() { docker run --rm --network "$MC_NETWORK" --entrypoint sh "$MC_IMAGE" -c "mc alias set s3 $S3_ENDPOINT $S3_KEY $S3_SECRET >/dev/null && $1"; }
# request <user:pass> <method> <url> [body] -> sets STATUS and BODY
request() {
	local auth="$1" method="$2" url="$3" body="${4:-}" tmp
	tmp="$(mktemp)"
	if [ -n "$body" ]; then
		STATUS="$(curl -s -o "$tmp" -w '%{http_code}' -u "$auth" -X "$method" -H 'OCS-APIRequest: true' -H 'Content-Type: application/xml' --data "$body" "$url")"
	else
		STATUS="$(curl -s -o "$tmp" -w '%{http_code}' -u "$auth" -X "$method" -H 'OCS-APIRequest: true' "$url")"
	fi
	BODY="$(cat "$tmp")"
	rm -f "$tmp"
}
fileid() { request "$1" PROPFIND "$2" "<?xml version=\"1.0\"?><d:propfind $NS><d:prop><oc:fileid/></d:prop></d:propfind>"; sed -n 's:.*<oc\:fileid>\([0-9]*\)</oc\:fileid>.*:\1:p' <<<"$BODY"; }
show_json() { occ archiefmeta:show --json "$1"; }
field() { python3 -c 'import json,sys; d=json.load(sys.stdin)["fields"]; f=d.get(sys.argv[1]) or {}; print(json.dumps(f.get(sys.argv[2])) if f else "null")' "$1" "$2"; }

ADMIN="$ADMIN_USER:$ADMIN_PASS"
TESTER="$TEST_USER:$TEST_PASS"

log "# archiefmeta integration run $RUN_ID"
log "Nextcloud: $NC_URL ($(occ status --output=json | python3 -c 'import json,sys; print(json.load(sys.stdin)["versionstring"])'))"
log ""

# Setup: a regular user and a working folder.
if ! occ user:info "$TEST_USER" >/dev/null 2>&1; then
	docker exec -u www-data -e OC_PASS="$TEST_PASS" "$NC_CONTAINER" php occ user:add --password-from-env "$TEST_USER" >/dev/null
fi
request "$ADMIN" MKCOL "$DAV/$ADMIN_USER/$DIR"

log "## 1. Primary object storage: Object Lock + object tags"
request "$ADMIN" PUT "$DAV/$ADMIN_USER/$DIR/besluit.txt" "Besluit op verzoek, integratietest $RUN_ID"
check "upload to primary storage (HTTP $STATUS)" "$([ "$STATUS" = 201 ] && echo 1)"
PRIMARY_ID="$(fileid "$ADMIN" "$DAV/$ADMIN_USER/$DIR/besluit.txt")"
log "fileid: $PRIMARY_ID, S3 key: $PRIMARY_BUCKET/urn:oid:$PRIMARY_ID"

AFTER_UPLOAD="$(show_json "$PRIMARY_ID")"
check "Object Lock retention read on upload, source s3-object-lock" "$(field bewaartermijn source <<<"$AFTER_UPLOAD" | grep -q s3-object-lock && echo 1)"
check "identificatie falls back to the Nextcloud file ID" "$(field identificatie source <<<"$AFTER_UPLOAD" | grep -q nextcloud-fileid && echo 1)"

mc "mc tag set 's3/$PRIMARY_BUCKET/urn:oid:$PRIMARY_ID' 'mdto-classificatie=Openbaar&mdto-zorgdrager-1=Gemeente Zuiddrecht&mdto-zorgdrager-2=Regionaal Archief Zuiddrecht&mdto-identificatie=ZD-BESLUIT-$RUN_ID&mdto-identificatie-systeem=Zaaksysteem'" >/dev/null
mc "mc legalhold set 's3/$PRIMARY_BUCKET/urn:oid:$PRIMARY_ID'" >/dev/null
occ archiefmeta:refresh "$PRIMARY_ID" | tee -a "$EVIDENCE"
PRIMARY="$(show_json "$PRIMARY_ID")"
log '```json'; log "$PRIMARY"; log '```'
check "classificatie from S3 object tags" "$(field classificatie source <<<"$PRIMARY" | grep -q s3-object-tags && echo 1)"
check "two zorgdragers" "$(field zorgdrager values <<<"$PRIMARY" | grep -q '"Gemeente Zuiddrecht", "Regionaal Archief Zuiddrecht"' && echo 1)"
check "identificatie from the storage layer replaces the file ID" "$(field identificatie values <<<"$PRIMARY" | grep -q "ZD-BESLUIT-$RUN_ID" && echo 1)"
check "legal hold ON reported with retention" "$(field bewaartermijn details <<<"$PRIMARY" | grep -q '"legalHold": "ON"' && echo 1)"
check "every value has a readAt time" "$(python3 -c 'import json,sys; d=json.load(sys.stdin)["fields"]; print(1 if d and all(v.get("readAt") for v in d.values()) else 0)' <<<"$PRIMARY")"
# Lift the legal hold again so the test object only stays for the bucket's default retention.
mc "mc legalhold clear 's3/$PRIMARY_BUCKET/urn:oid:$PRIMARY_ID'" >/dev/null

log ""
log "## 2. External S3 mount: object written to the bucket with user metadata"
EXT_KEY="$DIR/zaakdossier.txt"
mc "echo 'Zaakdossier $RUN_ID' > /tmp/z.txt && mc cp --attr 'x-amz-meta-mdto-classificatie=Intern;x-amz-meta-mdto-zorgdrager=Gemeente Zuiddrecht;x-amz-meta-mdto-identificatie=ZD-DOSSIER-$RUN_ID' /tmp/z.txt 's3/$EXTERNAL_BUCKET/$EXT_KEY'" >/dev/null
occ files:scan --path="/$ADMIN_USER/files/$EXTERNAL_MOUNT/$DIR" >/dev/null 2>&1
EXT_ID="$(fileid "$ADMIN" "$DAV/$ADMIN_USER/$EXTERNAL_MOUNT/$EXT_KEY")"
occ archiefmeta:refresh "$EXT_ID" | tee -a "$EVIDENCE"
EXTERNAL="$(show_json "$EXT_ID")"
log '```json'; log "$EXTERNAL"; log '```'
check "external mount: classificatie from S3 object metadata" "$(field classificatie source <<<"$EXTERNAL" | grep -q s3-object-metadata && echo 1)"
check "external mount: retention from Object Lock" "$(field bewaartermijn source <<<"$EXTERNAL" | grep -q s3-object-lock && echo 1)"

log ""
log "## 3. Administrator values on a file the storage layer has no values for"
LOCAL_FILE="$DIR/notitie.txt"
request "$ADMIN" PUT "$DAV/$ADMIN_USER/$LOCAL_FILE" "Notitie $RUN_ID"
NOTE_ID="$(fileid "$ADMIN" "$DAV/$ADMIN_USER/$LOCAL_FILE")"
occ archiefmeta:set "$NOTE_ID" --zorgdrager="Gemeente Zuiddrecht" --zorgdrager="Provincie Zuid" --classificatie="Intern" | tee -a "$EVIDENCE"
NOTE="$(show_json "$NOTE_ID")"
check "administrator zorgdrager stored with source administrator" "$(field zorgdrager source <<<"$NOTE" | grep -q administrator && echo 1)"
check "storage layer still wins for bewaartermijn" "$(field bewaartermijn source <<<"$NOTE" | grep -q s3-object-lock && echo 1)"

log ""
log "## 4. WebDAV PROPPATCH is refused"
PATCH_SET="<?xml version=\"1.0\"?><d:propertyupdate $NS><d:set><d:prop><nc:metadata-archiefmeta-classificatie>{\"values\":[\"Geheim\"],\"source\":\"administrator\",\"readAt\":\"2026-01-01T00:00:00+00:00\"}</nc:metadata-archiefmeta-classificatie></d:prop></d:set></d:propertyupdate>"
PATCH_REMOVE="<?xml version=\"1.0\"?><d:propertyupdate $NS><d:remove><d:prop><nc:metadata-archiefmeta-bewaartermijn/></d:prop></d:remove></d:propertyupdate>"

request "$ADMIN" PROPPATCH "$DAV/$ADMIN_USER/$DIR/besluit.txt" "$PATCH_SET"
log "owner (admin) PROPPATCH set classificatie -> HTTP $STATUS"; log '```xml'; log "$BODY"; log '```'
check "owner PROPPATCH set refused" "$([ "$STATUS" != 207 ] && [ "$STATUS" != 200 ] && grep -q 'enough rights' <<<"$BODY" && echo 1)"

request "$ADMIN" PROPPATCH "$DAV/$ADMIN_USER/$DIR/besluit.txt" "$PATCH_REMOVE"
log "owner (admin) PROPPATCH remove bewaartermijn -> HTTP $STATUS"; log '```xml'; log "$BODY"; log '```'
check "owner PROPPATCH remove refused" "$([ "$STATUS" != 207 ] && [ "$STATUS" != 200 ] && grep -q 'enough rights' <<<"$BODY" && echo 1)"

# Share the file with edit rights, so the recipient has write permission on it.
request "$ADMIN" POST "$NC_URL/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json&path=/$DIR/besluit.txt&shareType=0&shareWith=$TEST_USER&permissions=19"
check "file shared with $TEST_USER with edit rights (HTTP $STATUS)" "$([ "$STATUS" = 200 ] && echo 1)"
SHARED_PATH="$DAV/$TEST_USER/besluit.txt"
for _ in 1 2 3 4 5; do
	request "$TESTER" PROPFIND "$SHARED_PATH" "<?xml version=\"1.0\"?><d:propfind $NS><d:prop><oc:fileid/></d:prop></d:propfind>"
	[ "$STATUS" = 207 ] && break
	sleep 1
	SHARED_PATH="$(curl -s -u "$TESTER" -X PROPFIND -H 'Depth: 1' "$DAV/$TEST_USER/" | grep -o "/remote.php/dav/files/$TEST_USER/besluit[^<]*\.txt" | head -1 | sed "s#^#$NC_URL#")"
done

request "$TESTER" PROPPATCH "$SHARED_PATH" "$PATCH_SET"
log "share recipient PROPPATCH set classificatie -> HTTP $STATUS"; log '```xml'; log "$BODY"; log '```'
check "share recipient PROPPATCH refused" "$([ "$STATUS" != 207 ] && [ "$STATUS" != 200 ] && grep -q 'enough rights' <<<"$BODY" && echo 1)"

request "$TESTER" PROPPATCH "$SHARED_PATH" "<?xml version=\"1.0\"?><d:propertyupdate $NS><d:set><d:prop><nc:metadata-archiefmeta-nieuw>x</nc:metadata-archiefmeta-nieuw></d:prop></d:set></d:propertyupdate>"
log "share recipient PROPPATCH on a new archiefmeta key -> HTTP $STATUS"
check "PROPPATCH cannot add a new archiefmeta key" "$([ "$STATUS" != 207 ] && [ "$STATUS" != 200 ] && echo 1)"

AFTER_PATCH="$(show_json "$PRIMARY_ID")"
check "stored values unchanged after all PROPPATCH attempts" "$(python3 -c 'import json,sys; a,b=sys.argv[1:3]; print(1 if json.loads(a)==json.loads(b) else 0)' "$PRIMARY" "$AFTER_PATCH")"

log ""
log "## 5. App API: read for users with access, no write endpoint"
API="$NC_URL/ocs/v2.php/apps/archiefmeta/api/v1/files"
request "$TESTER" GET "$API/$PRIMARY_ID?format=json"
log "share recipient GET -> HTTP $STATUS"; log '```json'; log "$BODY"; log '```'
check "share recipient can read the values with source labels" "$([ "$STATUS" = 200 ] && grep -q 'Storage layer (S3 Object Lock)' <<<"$BODY" && echo 1)"

request "$TESTER" GET "$API/$NOTE_ID?format=json"
log "GET on a file that is not shared with the user -> HTTP $STATUS"
check "file the user cannot access is not found" "$([ "$STATUS" = 404 ] && echo 1)"

for method in POST PUT PATCH DELETE; do
	request "$TESTER" "$method" "$API/$PRIMARY_ID?format=json" '{"fields":{"classificatie":["Geheim"]}}'
	log "share recipient $method -> HTTP $STATUS"
	check "$method on the app API is not possible" "$([ "$STATUS" = 404 ] || [ "$STATUS" = 405 ] && echo 1)"
done

AFTER_API="$(show_json "$PRIMARY_ID")"
check "stored values unchanged after the API attempts" "$(python3 -c 'import json,sys; a,b=sys.argv[1:3]; print(1 if json.loads(a)==json.loads(b) else 0)' "$PRIMARY" "$AFTER_API")"

log ""
log "Demo files: $ADMIN_USER/files/$DIR (fileids $PRIMARY_ID, $NOTE_ID) and $ADMIN_USER/files/$EXTERNAL_MOUNT/$DIR (fileid $EXT_ID)."
log "Note: objects in the locked buckets stay until their retention ends; that is the point of the lock."
log ""
log "Result: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
