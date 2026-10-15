#!/usr/bin/env bash
# End-to-end checks against the seeded harness (dev/seed.sh first). Needs curl + jq on the host.
# Re-registers (rotating the key) at the end and writes the new key to dev/.key.
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
expect_status "secret_internal: refused even with key (allowlist 403 precedes core 404)" GET "$BASE/wp-json/wp/v2/secret_internal" 403 "$H"
expect_status "secret_internal: 404 without key" GET "$BASE/wp-json/wp/v2/secret_internal" 404
expect_jq "discovery: Redirection listed in plugins" "$API/discovery" '[.plugins[] | select(.name|test("Redirection"))] | length > 0' "$H" $((1-REDIR))
expect_jq "discovery: changes.head_id is a number" "$API/discovery" '.changes.head_id | type=="number"' "$H"

echo "== core routes: meta fields"
expect_status "news: 200 with key" GET "$WPV2/news" 200 "$H"
expect_jq "news: post_meta_fields.mi_source" "$WPV2/news" '.[0].post_meta_fields.mi_source == "wire"' "$H"
expect_status "news: 404 without key (forced REST is key-only)" GET "$WPV2/news" 404
expect_status "news_topic: 404 without key" GET "$WPV2/news_topic" 404
if [ "$(wpv eval 'echo get_post_type_object("news")->show_in_rest ? "yes" : "no";')" = "no" ]; then pass "news: show_in_rest stays false outside a key request"
else fail "news: show_in_rest stays false outside a key request"; fi
P="$WPV2/posts?search=Published"
expect_jq "posts: no post_meta_fields key at all without key" "$P" '.[0] | has("post_meta_fields") | not'
expect_jq "posts: dotted key flattened to mi__dotted" "$P" '.[0].post_meta_fields.mi__dotted == "dotval"' "$H"
expect_jq "posts: _yoast_wpseo_title" "$P" '.[0].post_meta_fields._yoast_wpseo_title == "Custom SEO Title"' "$H"
expect_jq "posts: mi_multi has 2 values" "$P" '.[0].post_meta_fields.mi_multi | length == 2' "$H"
expect_status "posts: drafts 200 (context=edit)" GET "$WPV2/posts?status=draft&context=edit" 200 "$H"
expect_jq "posts: drafts listed" "$WPV2/posts?status=draft&context=edit" 'length >= 1' "$H"
expect_jq "posts: scheduled listed" "$WPV2/posts?status=future&context=edit" '[.[] | select(.title.rendered|test("Scheduled"))] | length >= 1' "$H"
expect_status "posts: drafts 200 via ?rest_route= (context=edit)" GET "$BASE/?rest_route=/wp/v2/posts&status=draft&context=edit" 200 "$H"
expect_status "news: context=edit 200 (own capability_type granted per request)" GET "$WPV2/news?context=edit" 200 "$H"
expect_jq "posts: _embed author resolves (wp/v2/users stays reachable)" "$P&_embed=author" '.[0]._embedded.author[0].id | type=="number"' "$H"

PUB=$(get "$P" "$H" | jq -r '.[0].id // empty')
echo "== read-only (published post id: ${PUB:-?})"
expect_status "POST posts with key: 403" POST "$WPV2/posts" 403 "$H" -H 'Content-Type: application/json' --data '{"title":"x"}'
expect_status "DELETE post with key: 403" DELETE "$WPV2/posts/${PUB:-1}" 403 "$H"
expect_status "GET with _method=POST: 403" GET "$WPV2/posts?_method=POST" 403 "$H"
expect_status "GET with X-HTTP-Method-Override: POST: 403" GET "$WPV2/posts" 403 "$H" -H 'X-HTTP-Method-Override: POST'
expect_status "POST disguised as GET gets no edit caps (context=edit): 403" POST "$WPV2/posts?_method=GET&context=edit" 403 "$H"
# Third-party plugins add their own caps to every role (Wordfence: wfls_*, wf2fa_*), so assert shape, not equality.
ROLE_CAPS=$(wpv cap list mindful_import --format=json)
if printf '%s' "$ROLE_CAPS" | jq -e '([.[].name] | index("read") != null) and ([.[].name | select(test("^(edit_|delete_|publish_|upload_|create_|manage_|list_users|install_|update_|activate_|switch_|unfiltered_|import|export)"))] | length == 0)' >/dev/null 2>&1; then pass "role mindful_import stores read and no write-shaped caps"
else fail "role mindful_import stores read and no write-shaped caps" "$ROLE_CAPS"; fi

echo "== route allowlist"
expect_status "oembed proxy with key: 403" GET "$BASE/wp-json/oembed/1.0/proxy?url=https://example.com/" 403 "$H"
expect_status "block-renderer core/rss with key: 403" GET "$WPV2/block-renderer/core/rss?context=edit" 403 "$H"
expect_status "wp/v2/settings with key: 403" GET "$WPV2/settings" 403 "$H"
expect_status "wp/v2/plugins with key: 403" GET "$WPV2/plugins" 403 "$H"
expect_jq "denied route reports mindful_import_route_denied" "$WPV2/settings" '.code == "mindful_import_route_denied"' "$H"
expect_status "REST index with key: 200" GET "$BASE/wp-json/" 200 "$H"
expect_status "wp/v2/media with key: 200" GET "$WPV2/media" 200 "$H"

echo "== key ignored outside REST"
AJAX=$(curl -s -X POST -H "$H" "$BASE/wp-admin/admin-ajax.php?rest_route=/wp/v2/posts&action=mi_whoami")
if printf '%s' "$AJAX" | jq -e '.user == 0 and .edit_posts == false' >/dev/null 2>&1; then pass "admin-ajax with key + rest_route runs anonymously"
else fail "admin-ajax with key + rest_route runs anonymously" "$(printf '%s' "$AJAX" | head -c 200)"; fi

echo "== rendering"
expect_jq "posts: mindful_rendered_content runs shortcodes" "$P&mindful_render=1" \
  '.[0].mindful_rendered_content | (if type=="object" then (.rendered // "") else . end) | test("rendered-ok")' "$H"

echo "== term meta"
expect_jq "categories: term_meta_fields.mi_color" "$WPV2/categories?search=Industry" '.[0].term_meta_fields.mi_color == "blue"' "$H"
expect_jq "categories: empty term meta is {} not []" "$WPV2/categories?slug=uncategorized" '.[0].term_meta_fields | type == "object" and length == 0' "$H"
expect_jq "posts: empty post meta is {} not []" "$WPV2/posts?status=draft&context=edit&search=Draft" '.[0].post_meta_fields | type == "object"' "$H"
expect_status "news_topic: 200 with key" GET "$WPV2/news_topic" 200 "$H"
expect_jq "news_topic: term_meta_fields.mi_order" "$WPV2/news_topic" '.[0].term_meta_fields.mi_order == "3"' "$H"

echo "== users"
expect_jq "users: subscriber with no posts included" "$API/users" '[.[] | select(.login=="nobody")] | length == 1' "$H"
expect_jq "users: the plugin's own account is excluded" "$API/users?per_page=500" '[.[] | select(.login=="mindful-import")] | length == 0' "$H"
expect_jq "users: meta is always an object" "$API/users" '[.[] | .meta | type] | unique == ["object"]' "$H"
expect_jq "users: no pass/session/capabilities keys" "$API/users" '[.. | objects | keys[]] | map(select(test("pass|session|capabilit"))) | length == 0' "$H"
expect_jq "users: jane meta.twitter" "$API/users" '[.[] | select(.login=="jane") | .meta.twitter] == ["janedoe"]' "$H"
wp user meta update jane focus_keyword robotics >/dev/null 2>&1
wp user meta update jane api_key sk_test_123 >/dev/null 2>&1
expect_jq "users: focus_keyword kept (token match, not substring)" "$API/users" '[.[] | select(.login=="jane") | .meta.focus_keyword] == ["robotics"]' "$H"
expect_jq "users: api_key dropped" "$API/users" '[.[] | select(.login=="jane") | .meta | has("api_key")] == [false]' "$H"
expect_jq "users: wpseo_keyword_analysis_disable kept" "$API/users" '[.[] | select(.login=="jane") | .meta.wpseo_keyword_analysis_disable] == ["on"]' "$H"
expect_jq "users: joomlapass dropped (run-together password hash)" "$API/users" '[.[] | select(.login=="jane") | .meta | has("joomlapass")] == [false]' "$H"
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
  # The service account's own creation was logged by earlier versions; the upgrade purges it and new
  # changes to it are never recorded.
  IMPORT_ID=$(get "$API/settings" "$H" | jq -r '.import_user_id')
  wp option patch delete mindful_import installed_version >/dev/null 2>&1
  wp user update mindful-import --display_name="Mindful Import" >/dev/null 2>&1   # loads the plugin (upgrade runs), then edits the account
  expect_jq "changes: no entries about the plugin's own account (id $IMPORT_ID)" "$API/changes?object_type=user&limit=500" "$ENTRIES | map(select(.object_id == $IMPORT_ID)) | length == 0" "$H"
fi

echo "== redirects"
expect_jq "redirects: providers has redirection" "$API/redirects" '.providers | index("redirection") != null' "$H" $((1-REDIR))
expect_jq "redirects: /old-path/ is 301" "$API/redirects" '[.redirects[] | select(.source=="/old-path/") | (.code|tonumber)] == [301]' "$H" $((1-REDIR))

echo "== settings"
expect_jq "settings: key_created_at is a number" "$API/settings" '.key_created_at | type=="number"' "$H"
expect_jq "settings: no key_hash/key_salt" "$API/settings" '[.. | objects | keys[]] | map(select(.=="key_hash" or .=="key_salt")) | length == 0' "$H"
expect_jq "settings: no last_seen" "$API/settings" 'has("last_seen") | not' "$H"
# Plant the pre-release key and forget the installed version; the next load runs Plugin::maybe_upgrade().
wp option patch insert mindful_import last_seen stale >/dev/null 2>&1
wp option patch delete mindful_import installed_version >/dev/null 2>&1
if [ "$(wpv eval 'echo array_key_exists("last_seen", (array) get_option("mindful_import")) ? "present" : "gone";')" = "gone" ]; then pass "option: stale last_seen key removed by upgrade"
else fail "option: stale last_seen key removed by upgrade"; fi

echo "== import registration"
# Last registration the mock receiver (dev/test-cpt.php) accepted, as JSON.
last_reg() { wp eval 'echo wp_json_encode( mi_dev_last_registration() );' 2>/dev/null | tr -d '\r' | tail -n1; }
reg_setting() { wpv eval "echo Mindful_Import\\Settings::get( '$1' );"; }
if [ "$(reg_setting registration_status)" = "registered" ]; then pass "registration: status is registered"
else fail "registration: status is registered" "$(reg_setting registration_status)"; fi
LAST=$(last_reg)
if printf '%s' "$LAST" | jq -e '.rest_url | type=="string" and startswith("http://localhost")' >/dev/null 2>&1; then pass "registration: receiver got rest_url starting with http://localhost"
else fail "registration: receiver got rest_url starting with http://localhost" "$(printf '%s' "$LAST" | jq -c '.rest_url' 2>/dev/null)"; fi
if printf '%s' "$LAST" | jq -e '.user_agent | test("^Mindful-Import/")' >/dev/null 2>&1; then pass "registration: user agent is Mindful-Import/<version>"
else fail "registration: user agent is Mindful-Import/<version>"; fi
REGKEY=$(printf '%s' "$LAST" | jq -r '.key // empty' 2>/dev/null)
if [ -n "$REGKEY" ]; then expect_status "registration: receiver's key gets 200 on discovery" GET "$API/discovery" 200 "X-Mindful-Import-Key: $REGKEY"
else fail "registration: receiver's key gets 200 on discovery" "receiver has no key"; fi
expect_jq "registration: settings.registration_status == registered" "$API/settings" '.registration_status == "registered"' "$H"
expect_jq "registration: settings.registered_at is a positive number" "$API/settings" '.registered_at | type=="number" and . > 0' "$H"
expect_jq "registration: settings still has no key_hash/key_salt" "$API/settings" '[.. | objects | keys[]] | map(select(.=="key_hash" or .=="key_salt")) | length == 0' "$H"
if wpv eval 'echo wp_json_encode( get_option( "mindful_import" ) );' | grep -q -F -e "$KEY"; then fail "registration: plaintext key is not in the option"
else pass "registration: plaintext key is not in the option"; fi

echo "== import registration: failure keeps the previous key"
wp option update mi_dev_registration_fail 1 >/dev/null 2>&1
RESULT=$(wpv eval '$r = Mindful_Import\Registration::register(); echo is_wp_error( $r ) ? "wp_error" : "ok";')
if [ "$RESULT" = "wp_error" ]; then pass "failure: register() returned a WP_Error"; else fail "failure: register() returned a WP_Error" "$RESULT"; fi
if [ "$(reg_setting registration_status)" = "failed" ]; then pass "failure: status is failed"
else fail "failure: status is failed" "$(reg_setting registration_status)"; fi
ERR=$(wp eval "echo Mindful_Import\\Settings::get( 'registration_error' );" 2>/dev/null | tr -d '\r' | tail -n1)
if [ -n "$ERR" ]; then pass "failure: registration_error set ($ERR)"; else fail "failure: registration_error set"; fi
if printf '%s' "$ERR" | grep -q -F -e "$KEY"; then fail "failure: error message does not contain a key"; else pass "failure: error message does not contain a key"; fi
if [ "$(reg_setting registration_attempts)" -ge 1 ] 2>/dev/null; then pass "failure: attempts counted"
else fail "failure: attempts counted" "$(reg_setting registration_attempts)"; fi
NEXT=$(wpv eval 'echo (int) wp_next_scheduled( Mindful_Import\Registration::CRON_HOOK );')
if [ "${NEXT:-0}" -gt 0 ] 2>/dev/null; then pass "failure: retry scheduled"; else fail "failure: retry scheduled" "next=$NEXT"; fi
expect_status "failure: previous key still 200 on discovery (snapshot restored)" GET "$API/discovery" 200 "$H"
if [ "$(last_reg | jq -r '.key // empty' 2>/dev/null)" = "$KEY" ]; then pass "failure: receiver recorded nothing new"
else fail "failure: receiver recorded nothing new"; fi
wp option delete mi_dev_registration_fail >/dev/null 2>&1

echo "== import registration: https policy"
POLICY=$(wpv eval 'echo (int) Mindful_Import\Registration::is_url_allowed( "http://example.com/x", "production" ), (int) Mindful_Import\Registration::is_url_allowed( "https://example.com/x", "production" ), (int) Mindful_Import\Registration::is_url_allowed( "http://example.com/x", "local" ), (int) Mindful_Import\Registration::is_url_allowed( "ftp://example.com", "local" ), (int) Mindful_Import\Registration::is_url_allowed( "http://example.com/x", "development" ), (int) Mindful_Import\Registration::is_url_allowed( "https:///nohost", "production" );')
if [ "$POLICY" = "011010" ]; then pass "policy: https always; http only on local/development; ftp and hostless refused"
else fail "policy: https always; http only on local/development; ftp and hostless refused" "got $POLICY, want 011010"; fi

echo "== key rotation (re-registration)"
RESULT=$(wpv eval '$r = Mindful_Import\Registration::register(); echo is_wp_error( $r ) ? "wp_error" : "ok";')
NEWKEY=$(last_reg | jq -r '.key // empty' 2>/dev/null)
if [ "$RESULT" != "ok" ] || [ -z "$NEWKEY" ] || [ "$NEWKEY" = "$KEY" ]; then
  fail "rotation: register() succeeded and the receiver got a new key" "result=$RESULT"
else
  pass "rotation: register() succeeded and the receiver got a new key"
  expect_status "rotation: old key 401" GET "$API/discovery" 401 "$H"
  expect_status "rotation: new key 200" GET "$API/discovery" 200 "X-Mindful-Import-Key: $NEWKEY"
  if [ "$(reg_setting registration_status)" = "registered" ] && [ "$(reg_setting registration_attempts)" = "0" ]; then pass "rotation: status registered, attempts reset"
  else fail "rotation: status registered, attempts reset"; fi
  if [ "$(wpv eval 'echo (int) wp_next_scheduled( Mindful_Import\Registration::CRON_HOOK );')" = "0" ]; then pass "rotation: pending retry cleared"
  else fail "rotation: pending retry cleared"; fi
  printf '%s\n' "$NEWKEY" > dev/.key
fi

echo
echo "SMOKE: $PASS passed, $FAIL failed${WARNS:+, $WARNS warnings}"
exit $(( FAIL > 125 ? 125 : FAIL ))
