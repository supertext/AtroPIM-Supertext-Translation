#!/usr/bin/env bash
# CI: starts the demo image twice against MySQL with the stand-in API, checks the demo accounts
# (created once, never duplicated, passwords never logged), translates the sample products and
# checks the Commando Center parts: the ping endpoint and the reports (sent to the stand-in, never
# to the live center: SUPERTEXT_REPORT_URL).
# Needs: docker image supertext-atropim-demo, MySQL at $MYSQL_URL (mysql client on the host, or
# MYSQL_CMD), the stand-in on 127.0.0.1:8765.
set -euo pipefail
# No pipes into grep -q: with pipefail they can fail on SIGPIPE. Capture the output, then grep a here-string.
PORT=8080
DB=atropim_ci
export DEMO_ADMIN_EMAIL=ci-admin@example.com DEMO_ADMIN_PASSWORD="Ci-$(openssl rand -hex 8)-1"
export DEMO_EDITOR_EMAIL=ci-editor@example.com DEMO_EDITOR_PASSWORD="Ci-$(openssl rand -hex 8)-2"
MYSQL_CMD="${MYSQL_CMD:-mysql -h127.0.0.1 -uroot -proot}"
sql() { $MYSQL_CMD --default-character-set=utf8mb4 "$DB" -N -s -e "$1" 2>/dev/null; }
console() { docker exec demo demo-console "$@"; }

start() {
	docker rm -f demo >/dev/null 2>&1 || true
	docker run -d --name demo --network host -e PORT=$PORT -e MYSQL_URL="$MYSQL_URL" -e ATROPIM_DB_NAME=$DB \
		-e DEMO_SITE_URL=http://127.0.0.1:$PORT -e DEMO_ADMIN_EMAIL -e DEMO_ADMIN_PASSWORD -e DEMO_EDITOR_EMAIL -e DEMO_EDITOR_PASSWORD \
		-e SUPERTEXT_API_KEY=anything -e SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/ -e SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report -v atropim-ci-data:/data supertext-atropim-demo >/dev/null
	for _ in $(seq 200); do curl -sf -o /dev/null http://127.0.0.1:$PORT/ && break; sleep 3; done
	curl -sf -o /dev/null http://127.0.0.1:$PORT/
	docker logs demo 2>&1 | grep '\[demo\]' || true
}

docker volume rm -f atropim-ci-data >/dev/null 2>&1 || true
start
start   # second start: nothing duplicated or changed
logs=$(docker logs demo 2>&1)
grep -qF 'DEMO_EDITOR: account exists, left unchanged' <<< "$logs"
if grep -qF -e "$DEMO_ADMIN_PASSWORD" -e "$DEMO_EDITOR_PASSWORD" <<< "$logs"; then echo "A password appeared in the log"; exit 1; fi

test "$(sql "select group_concat(user_name order by user_name) from user where user_name like 'ci-%' and deleted = 0")" = "ci-admin@example.com,ci-editor@example.com"
test "$(sql "select is_admin from user where user_name = 'ci-admin@example.com'")" = 1
test "$(sql "select r.name from user u join role_user ru on ru.user_id = u.id join role r on r.id = ru.role_id where u.user_name = 'ci-editor@example.com'")" = "Product editor (Supertext demo)"
test "$(sql "select count(*) from product where number in ('praline-box-16', 'dark-chocolate-72') and deleted = 0")" = 2
test "$(sql "select count(*) from action where type = 'supertextTranslate' and deleted = 0")" = 1

check=$(console supertext check)
echo "$check"
grep -qF 'Version reports: on (ping URL http://127.0.0.1:'"$PORT"'/api/supertext/ping), report sent' <<< "$check"
grep -qF 'de_CH → de-CH' <<< "$check"
reports=$(curl -sf http://127.0.0.1:8765/reports)
python3 - "$PORT" "$reports" <<'PY'
import json, sys
reports = json.loads(sys.argv[2])
events = [r['event'] for r in reports]
assert 'installed' in events and events[-1] == 'check', events
r = reports[-1]
assert r['plugin']['name'] == 'AtroPIM-Supertext-Translation', r
assert r['system']['name'] == 'AtroPIM' and r['system'].get('version'), r
assert r['site_url'] == 'http://127.0.0.1:' + sys.argv[1], r
assert r['callback']['url'] == r['site_url'] + '/api/supertext/ping', r
assert r['api_key_check']['status'] == 'ok' and r['api_key_configured'] is True, r
assert 'anything' not in json.dumps(reports), 'the API key must never be reported'
PY

# The language mapping: one combo box per language, preselected with Supertext's code, validated on
# save, and the old text field's lines moved into the combo boxes (as the administrator, REST API).
admin_token=$(curl -sf -H "Authorization-Token: $(printf '%s:%s' "$DEMO_ADMIN_EMAIL" "$DEMO_ADMIN_PASSWORD" | base64 -w0)" http://127.0.0.1:$PORT/api/userSession | python3 -c 'import json,sys; print(json.load(sys.stdin)["user"]["token"])')
admin="Authorization-Token: $(printf '%s:%s' "$DEMO_ADMIN_EMAIL" "$admin_token" | base64 -w0)"
connection=$(sql "select id from connection where type = 'supertext' and deleted = 0")
field() { curl -sf -H "$admin" "http://127.0.0.1:$PORT/api/Connection/$connection" | python3 -c "import json,sys; print(json.load(sys.stdin).get('$1'))"; }
patch() { curl -s -o /dev/null -w '%{http_code}' -H "$admin" -H 'Content-Type: application/json' -X PATCH "http://127.0.0.1:$PORT/api/Connection/$connection" -d "$1"; }
test "$(field supertextCodeDeCh)" = de-CH
test "$(field supertextCodeEnUs)" = en-US
grep -qF '"fr-FR"' <<< "$(curl -sf -H "$admin" http://127.0.0.1:$PORT/api/metadata | python3 -c 'import json,sys; print(json.dumps(json.load(sys.stdin)["entityDefs"]["Connection"]["fields"]["supertextCodeFrCh"]["options"]))')"
labels=$(curl -sf "http://127.0.0.1:$PORT/api/i18n" | python3 -c 'import json,sys; c=json.load(sys.stdin)["Connection"]; print(c["fields"]["supertextCodeFrCh"]); print(c["options"]["supertextCodeFrCh"]["fr-CH"]); print(c["options"]["supertextCodeFrCh"][""])')
echo "$labels"
grep -qF 'Supertext language for' <<< "$labels"
grep -qF '(fr-CH)' <<< "$labels"
grep -qF -- '— choose —' <<< "$labels"
test "$(patch '{"supertextCodeFrCh":"xx-YY"}')" = 400
test "$(patch '{"supertextCodeFrCh":"fr-FR"}')" = 200
grep -qF 'fr_CH → fr-FR' <<< "$(console supertext check)"
test "$(patch '{"supertextCodeFrCh":"fr-CH"}')" = 200
sql "update connection set data = json_remove(json_set(data, '$.supertextLanguageCodes', 'it_CH = it-IT\nxx_YY = xx-YY'), '$.supertextCodeItCh') where id = '$connection'"
migrated=$(field supertextCodeItCh)
kept=$(sql "select json_unquote(json_extract(data, '$.supertextLanguageCodes')) from connection where id = '$connection'")
echo "Migrated: it_CH → $migrated; kept in the old field: $kept"
test "$migrated" = it-IT
test "$kept" = 'xx_YY = xx-YY'
test "$(patch '{"supertextCodeItCh":"it-CH"}')" = 200
grep -qF 'it_CH → it-CH' <<< "$(console supertext check)"
# Translation review per language: off by default, ticked next to the combo box, shown by the check.
[[ "$(field supertextReviewDeCh)" =~ ^(False|None)$ ]]
test "$(field supertextReviewUrl)" = https://localizationreview-production.up.railway.app/v1/
test "$(patch '{"supertextReviewDeCh":true}')" = 200
grep -qF 'de_CH → de-CH · translation review' <<< "$(console supertext check)"
test "$(patch '{"supertextReviewDeCh":false}')" = 200

# The public ping endpoint (no login): the challenge echoed, nothing else.
test "$(curl -s "http://127.0.0.1:$PORT/api/supertext/ping?challenge=ci-challenge-0123456789")" = '{"ok":true,"plugin":"AtroPIM-Supertext-Translation","challenge":"ci-challenge-0123456789"}'
test "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/api/supertext/ping?challenge=short")" = 400
grep -qi '^cache-control: no-store' <<< "$(curl -s -D - -o /dev/null "http://127.0.0.1:$PORT/api/supertext/ping?challenge=ci-challenge-0123456789")"
praline=$(sql "select id from product where number = 'praline-box-16'")
out=$(console supertext translate Product "$praline")
echo "$out"
grep -qF 'de_CH, fr_CH, it_CH: translated (4 fields)' <<< "$out"
test "$(sql "select name_de_ch from product where id = '$praline'")" = "Handgemachte Pralinenschachtel, 16 Stück"
grep -qF '<strong>à la main</strong>' <<< "$(sql "select long_description_fr_ch from product where id = '$praline'")"
test "$(sql "select text_value_it_ch from product_attribute_value where product_id = '$praline'")" = "Nocciola, caramello e un pizzico di sale marino."

# A second run keeps the languages that now have their own text.
grep -qF 'de_CH, fr_CH, it_CH: kept, already translated' <<< "$(console supertext translate Product "$praline")"

# The action, as the editor would run it from the product list (mass action, REST API).
token=$(curl -sf -H "Authorization-Token: $(printf '%s:%s' "$DEMO_EDITOR_EMAIL" "$DEMO_EDITOR_PASSWORD" | base64 -w0)" http://127.0.0.1:$PORT/api/userSession | python3 -c 'import json,sys; print(json.load(sys.stdin)["user"]["token"])')
action=$(sql "select id from action where type = 'supertextTranslate'")
result=$(curl -sf -H "Authorization-Token: $(printf '%s:%s' "$DEMO_EDITOR_EMAIL" "$token" | base64 -w0)" -H 'Content-Type: application/json' \
	-X POST "http://127.0.0.1:$PORT/api/Action/$action/supertextTranslate" -d '{"where":[{"type":"equals","attribute":"number","value":"dark-chocolate-72"}],"massAction":true}')
echo "$result"
grep -qF 'translated (4 fields)' <<< "$result"
test "$(sql "select name_fr_ch from product where number = 'dark-chocolate-72'")" = "Tablette de chocolat noir, 72 % de cacao"

# Export for translation → Import translation, as the editor through the REST API.
auth="Authorization-Token: $(printf '%s:%s' "$DEMO_EDITOR_EMAIL" "$token" | base64 -w0)"
xliff=$(mktemp)
headers=$(curl -sf -H "$auth" -D - -o "$xliff" "http://127.0.0.1:$PORT/api/supertext/export?entity=Product&id=$praline&targets=de_CH&format=xliff&overwrite=1")
grep -qi '^content-type: application/x-xliff+xml' <<< "$headers"
grep -qi "^content-disposition: attachment; filename=\"atropim-product-$praline-de_CH.xlf\"" <<< "$headers"
grep -qF "original=\"Product:$praline:en_US:de_CH\"" "$xliff"
python3 - "$xliff" <<'PY'
import re, sys
path = sys.argv[1]
xliff = open(path, encoding='utf-8').read()
xliff, n = re.subn(r'(<trans-unit id="name"[^>]*>\s*<source>.*?</source>)', r'\1<target>Pralinenschachtel aus der Datei</target>', xliff, flags=re.S)
assert n == 1, 'no name unit'
open(path, 'w', encoding='utf-8').write(xliff)
PY
result=$(curl -sf -H "$auth" -H 'Accept: application/json' -F "file=@$xliff;type=application/x-xliff+xml" -F overwrite=1 "http://127.0.0.1:$PORT/api/supertext/import")
echo "$result"
grep -qF 'Imported: 1' <<< "$result"
test "$(sql "select name_de_ch from product where id = '$praline'")" = "Pralinenschachtel aus der Datei"
test "$(curl -s -o /dev/null -w '%{http_code}' -H "$auth" -F "file=@$xliff" -F token=x -b "auth-token=$token" "http://127.0.0.1:$PORT/api/supertext/import")" = 403
grep -qF 'Translated file (XLIFF or HTML)' <<< "$(curl -sf -H "$auth" "http://127.0.0.1:$PORT/api/supertext/import")"
rm -f "$xliff"
echo "Demo check passed"
