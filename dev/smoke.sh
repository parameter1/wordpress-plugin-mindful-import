#!/usr/bin/env bash
# End-to-end checks against the seeded harness (dev/seed.sh first). Needs curl + jq on the host.
# Rotates the key at the end and writes the new one to dev/.key.
set -uo pipefail
cd "$(dirname "$0")/.."

BASE=${BASE:-http://localhost:8089}
API="$BASE/wp-json/mindful-import/v1"
WPV2="$BASE/wp-json/wp/v2"
[ -s dev/.key ] || { echo "dev/.key missing; run dev/seed.sh" >&2; exit 2; }
KEY=$(tr -d '[:space:]' < dev/.key)
H="X-Mindful-Import-Key: $KEY"
BAD="X-Mindful-Import-Key: not-the-key-0000000000000000"

PASS=0; FAIL=0; WARNS=0
pass() { PASS=$((PASS+1)); printf 'PASS  %s\n' "$1"; }
fail() { FAIL=$((FAIL+1)); printf 'FAIL  %s%s\n' "$1" "${2:+  ($2)}"; }
warn() { WARNS=$((WARNS+1)); printf 'WARN  %s%s\n' "$1" "${2:+  ($2)}"; }

wp() { docker compose --profile tools run --rm -T --quiet-pull cli "$@" 2> >(grep -v -E "^ Container |^$" >&2); }
wpv() { wp "$@" 2>/dev/null | tr -d '\r' | tail -n1 | tr -d '[:space:]'; }

# get url [header] -> body on stdout
get() { if [ -n "${2:-}" ]; then curl -s -H "$2" "$1"; else curl -s "$1"; fi; }

# expect_status name method url status [header] [extra curl args...]
expect_status() {
  local name=$1 method=$2 url=$3 want=$4 hdr=${5:-}; shift 5 2>/dev/null || shift $#
  local args=(-s -o /dev/null -w '%{http_code}' -X "$method")
  [ -n "$hdr" ] && args+=(-H "$hdr")
  local got; got=$(curl "${args[@]}" "$@" "$url")
  if [ "$got" = "$want" ]; then pass "$name"; else fail "$name" "HTTP $got, want $want"; fi
}

# expect_jq name url jq_expr [header] [soft]   soft=1 turns a failure into a WARN
expect_jq() {
  local name=$1 url=$2 expr=$3 hdr=${4:-} soft=${5:-0} body
  body=$(get "$url" "$hdr")
  if printf '%s' "$body" | jq -e "$expr" >/dev/null 2>&1; then pass "$name"
  elif [ "$soft" = 1 ]; then warn "$name" "$(printf '%s' "$body" | head -c 200)"
  else fail "$name" "$(printf '%s' "$body" | head -c 200)"; fi
}

# Change-log list responses: accept a bare array or {changes|entries|items: [...]}.
ENTRIES='(if type=="array" then . else (.changes // .entries // .items // []) end)'

echo "== auth on every v1 route"
for r in discovery options settings users changes changes/latest redirects; do
  expect_status "$r: 401 without key" GET "$API/$r" 401
  expect_status "$r: 200 with key" GET "$API/$r" 200 "$H"
  expect_status "$r: 401 with wrong key" GET "$API/$r" 401 "$BAD"
done

REDIR=0
wp plugin is-active redirection >/dev/null 2>&1 && REDIR=1

echo "== discovery"
expect_jq "discovery: plugin.version" "$API/discovery" '.plugin.version | type=="string" and length>0' "$H"
expect_jq "discovery: news forced show_in_rest" "$API/discovery" '[.post_types[] | select(.name=="news") | .show_in_rest] == [true]' "$H"
expect_jq "discovery: secret_internal absent" "$API/discovery" '[.post_types[] | select(.name=="secret_internal")] | length == 0' "$H"
expect_status "secret_internal: no REST route even with key" GET "$BASE/wp-json/wp/v2/secret_internal" 404 "$H"
expect_jq "discovery: Redirection listed in plugins" "$API/discovery" '[.plugins[] | select(.name|test("Redirection"))] | length > 0' "$H" $((1-REDIR))
expect_jq "discovery: changes.head_id is a number" "$API/discovery" '.changes.head_id | type=="number"' "$H"

echo "== core routes: meta fields"
expect_status "news: 200 with key" GET "$WPV2/news" 200 "$H"
expect_jq "news: post_meta_fields.mi_source" "$WPV2/news" '.[0].post_meta_fields.mi_source == "wire"' "$H"
expect_status "news: 200 without key" GET "$WPV2/news" 200
expect_jq "posts: anonymous response has no post_meta_fields key" "$WPV2/posts?per_page=1" '(.[0] | has("post_meta_fields")) | not'
expect_jq "news: no post_meta_fields without key" "$WPV2/news" '.[0] | (has("post_meta_fields") | not) or .post_meta_fields == null'
P="$WPV2/posts?search=Published"
expect_jq "posts: dotted key flattened to mi__dotted" "$P" '.[0].post_meta_fields.mi__dotted == "dotval"' "$H"
expect_jq "posts: _yoast_wpseo_title" "$P" '.[0].post_meta_fields._yoast_wpseo_title == "Custom SEO Title"' "$H"
expect_jq "posts: mi_multi has 2 values" "$P" '.[0].post_meta_fields.mi_multi | length == 2' "$H"
expect_status "posts: drafts 200 (context=edit)" GET "$WPV2/posts?status=draft&context=edit" 200 "$H"
expect_jq "posts: drafts listed" "$WPV2/posts?status=draft&context=edit" 'length >= 1' "$H"
expect_jq "posts: scheduled listed" "$WPV2/posts?status=future&context=edit" '[.[] | select(.title.rendered|test("Scheduled"))] | length >= 1' "$H"

PUB=$(get "$P" "$H" | jq -r '.[0].id // empty')
echo "== read-only (published post id: ${PUB:-?})"
expect_status "POST posts with key: 403" POST "$WPV2/posts" 403 "$H" -H 'Content-Type: application/json' --data '{"title":"x"}'
expect_status "DELETE post with key: 403" DELETE "$WPV2/posts/${PUB:-1}" 403 "$H"

echo "== rendering"
expect_jq "posts: mindful_rendered_content runs shortcodes" "$P&mindful_render=1" \
  '.[0].mindful_rendered_content | (if type=="object" then (.rendered // "") else . end) | test("rendered-ok")' "$H"

echo "== term meta"
expect_jq "categories: term_meta_fields.mi_color" "$WPV2/categories?search=Industry" '.[0].term_meta_fields.mi_color == "blue"' "$H"
expect_status "news_topic: 200 with key" GET "$WPV2/news_topic" 200 "$H"
expect_jq "news_topic: term_meta_fields.mi_order" "$WPV2/news_topic" '.[0].term_meta_fields.mi_order == "3"' "$H"

echo "== users"
expect_jq "users: subscriber with no posts included" "$API/users" '[.[] | select(.login=="nobody")] | length == 1' "$H"
expect_jq "users: no pass/session/capabilities keys" "$API/users" '[.. | objects | keys[]] | map(select(test("pass|session|capabilit"))) | length == 0' "$H"
expect_jq "users: jane meta.twitter" "$API/users" '[.[] | select(.login=="jane") | .meta.twitter] == ["janedoe"]' "$H"
if curl -s -D - -o /dev/null -H "$H" "$API/users" | grep -qi '^x-wp-total:'; then pass "users: X-WP-Total header"; else fail "users: X-WP-Total header"; fi

echo "== options"
expect_jq "options: blogname" "$API/options" '[.options[] | select(.key=="blogname") | .value] == ["Mindful Import Dev"]' "$H"
expect_jq "options: auth_key denied" "$API/options?names=auth_key" '[.options[] | select(.key=="auth_key")] | length == 0' "$H"
expect_jq "options: denied_count is a number" "$API/options?names=auth_key" '.denied_count | type=="number"' "$H"

echo "== change log"
HEAD=$(get "$API/changes/latest" "$H" | jq -r '.head_id // empty')
if [ -z "$HEAD" ] || [ -z "$PUB" ]; then
  fail "changes: prerequisites" "head='$HEAD' published id='$PUB'"
else
  wp post update "$PUB" --post_status=draft >/dev/null 2>&1
  wp post update "$PUB" --post_status=publish >/dev/null 2>&1
  TMP=$(wpv post create --post_title="Smoke temp $(date +%s)" --post_status=draft --porcelain)
  wp post delete "$TMP" >/dev/null 2>&1
  wp post delete "$TMP" --force >/dev/null 2>&1
  wp user update nobody --display_name="Nobody $(date +%s)" >/dev/null 2>&1

  BODY=$(get "$API/changes?after=$HEAD&limit=500" "$H")
  # Ordered subsequence: extra entries (update, meta, other objects) may sit between.
  ORDER='["unpublish","publish","create","trash","delete"] as $want
    | reduce ('"$ENTRIES"'[] | .action) as $a (0; if . < ($want|length) and $a == $want[.] then .+1 else . end)
    | . == ($want|length)'
  if printf '%s' "$BODY" | jq -e "$ORDER" >/dev/null 2>&1; then pass "changes: unpublish, publish, create, trash, delete in order"
  else fail "changes: unpublish, publish, create, trash, delete in order" "$(printf '%s' "$BODY" | jq -c "[$ENTRIES[] | .action]" 2>/dev/null | head -c 300)"; fi

  MAXID=$(printf '%s' "$BODY" | jq -r "[$ENTRIES[] | .id] | max // empty")
  LATEST=$(get "$API/changes/latest" "$H" | jq -r '.head_id // empty')
  if [ -n "$MAXID" ] && [ "$MAXID" = "$LATEST" ]; then pass "changes/latest head_id == max id ($LATEST)"
  else fail "changes/latest head_id == max id" "latest=$LATEST max=$MAXID"; fi

  expect_jq "changes: object_type=user returns only users" "$API/changes?object_type=user" "$ENTRIES | all(.object_type==\"user\")" "$H"
  expect_jq "changes: user update was logged" "$API/changes?object_type=user&after=$HEAD" "$ENTRIES | length >= 1" "$H" 1
fi

echo "== redirects"
expect_jq "redirects: providers has redirection" "$API/redirects" '.providers | index("redirection") != null' "$H" $((1-REDIR))
expect_jq "redirects: /old-path/ is 301" "$API/redirects" '[.redirects[] | select(.source=="/old-path/") | (.code|tonumber)] == [301]' "$H" $((1-REDIR))

echo "== settings"
expect_jq "settings: key_created_at is a number" "$API/settings" '.key_created_at | type=="number"' "$H"
expect_jq "settings: no key_hash/key_salt" "$API/settings" '[.. | objects | keys[]] | map(select(.=="key_hash" or .=="key_salt")) | length == 0' "$H"

echo "== key rotation"
NEWKEY=$(wpv eval 'echo Mindful_Import\Auth::generate_key();')
if [ -z "$NEWKEY" ]; then
  fail "rotation: generate_key returned a key"
else
  expect_status "rotation: old key 401" GET "$API/discovery" 401 "$H"
  expect_status "rotation: new key 200" GET "$API/discovery" 200 "X-Mindful-Import-Key: $NEWKEY"
  printf '%s\n' "$NEWKEY" > dev/.key
fi

echo
echo "SMOKE: $PASS passed, $FAIL failed${WARNS:+, $WARNS warnings}"
exit $(( FAIL > 125 ? 125 : FAIL ))
