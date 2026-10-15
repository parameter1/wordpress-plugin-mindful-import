#!/usr/bin/env bash
# Seeds the local harness (docker compose up -d first). Idempotent: safe to re-run.
# Writes a fresh import key to dev/.key on every run (the previous key stops working).
set -euo pipefail
cd "$(dirname "$0")/.."

wp() { docker compose --profile tools run --rm -T --quiet-pull cli "$@" 2> >(grep -v -E "^ Container |^$" >&2); }
# Single trimmed value (last line; WP_DEBUG notices can precede it).
wpv() { wp "$@" | tr -d '\r' | tail -n1 | tr -d '[:space:]'; }
warn() { printf 'WARN: %s\n' "$*" >&2; }
log() { printf '==> %s\n' "$*"; }

log "Waiting for WordPress files and database"
for i in $(seq 1 60); do
  # `wp db check` uses the mariadb client, which fails TLS against MySQL 8; `core is-installed`
  # exits 0 (installed) or 1 (not yet) once the DB is reachable, and 2+ on a connection error.
  if wp core version >/dev/null 2>&1; then
    rc=0; wp core is-installed >/dev/null 2>&1 || rc=$?
    [ "$rc" -le 1 ] && break
  fi
  [ "$i" = 60 ] && { echo "WordPress never came up (is 'docker compose up -d' running?)" >&2; exit 1; }
  sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
  log "Installing WordPress"
  wp core install --url=http://localhost:8089 --title="Mindful Import Dev" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
fi

wp rewrite structure '/%postname%/' --hard
wp plugin activate mindful-import

if wp plugin is-installed redirection >/dev/null 2>&1 || wp plugin install redirection >/dev/null; then
  wp plugin activate redirection || warn "could not activate Redirection"
  wp redirection database install >/dev/null 2>&1 || true
else
  warn "Redirection install failed (offline?); redirect checks will be warn-only"
fi

# ---------- users ----------
ensure_user() { # login email role first last description
  local id
  id=$(wpv user get "$1" --field=ID 2>/dev/null || true)
  if [ -z "$id" ]; then
    id=$(wpv user create "$1" "$2" --role="$3" --first_name="$4" --last_name="$5" --description="$6" --porcelain)
  fi
  echo "$id"
}
log "Users"
JANE=$(ensure_user jane jane@example.com author Jane Doe "Jane covers robotics and automation.")
ensure_user nobody nobody@example.com subscriber No Body "Subscriber with no posts." >/dev/null
wp user meta update jane twitter janedoe >/dev/null
# A legitimate Yoast profile key the old substring deny-list dropped, and the Joomla password hash that
# FG Joomla to WordPress leaves on every migrated user, which must never come through.
wp user meta update jane wpseo_keyword_analysis_disable on >/dev/null
wp user meta update jane joomlapass 'not-a-real-hash' >/dev/null

# ---------- terms ----------
ensure_term() { # taxonomy name
  local id
  id=$(wpv term list "$1" --name="$2" --hide_empty=0 --field=term_id)
  [ -z "$id" ] && id=$(wpv term create "$1" "$2" --porcelain)
  echo "$id"
}
log "Terms"
INDUSTRY=$(ensure_term category Industry)
wp term meta update "$INDUSTRY" mi_color blue >/dev/null
ROBOTICS=$(ensure_term news_topic Robotics)
wp term meta update "$ROBOTICS" mi_order 3 >/dev/null

# ---------- posts ----------
find_post() { # post_type title
  wpv post list --post_type="$1" --title="$2" --post_status=any --field=ID --posts_per_page=1
}
ensure_post() { # post_type title status [extra wp post create args...]
  local type=$1 title=$2 status=$3 id; shift 3
  id=$(find_post "$type" "$title")
  [ -z "$id" ] && id=$(wpv post create --post_type="$type" --post_title="$title" --post_status="$status" "$@" --porcelain)
  echo "$id"
}
log "Posts"
PUB=$(ensure_post post "Published Post" publish --post_author="$JANE" --post_category="$INDUSTRY" \
  --post_content='<p>Body before.</p> [mi_test] <p>Body after.</p>')
wp post meta update "$PUB" _yoast_wpseo_title "Custom SEO Title" >/dev/null
wp post meta update "$PUB" mi.dotted dotval >/dev/null
wp post meta delete "$PUB" mi_multi >/dev/null 2>&1 || true
wp post meta add "$PUB" mi_multi first >/dev/null
wp post meta add "$PUB" mi_multi second >/dev/null

ensure_post post "Draft Post" draft --post_author="$JANE" --post_content='Draft body.' >/dev/null

FUTURE_DATE=$(date -v+7d '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -d '+7 days' '+%Y-%m-%d %H:%M:%S')
ensure_post post "Scheduled Post" future --post_author="$JANE" --post_date="$FUTURE_DATE" \
  --post_content='Scheduled body.' >/dev/null

for title in "News One" "News Two"; do
  NID=$(ensure_post news "$title" publish --post_author="$JANE" --post_content="$title body.")
  wp post term set "$NID" news_topic "$ROBOTICS" --by=id >/dev/null
  wp post meta update "$NID" mi_source wire >/dev/null
done

# ---------- media ----------
log "Media"
MEDIA=$(wpv post list --post_type=attachment --title="Mindful Pixel" --post_status=inherit --field=ID --posts_per_page=1)
if [ -z "$MEDIA" ]; then
  wp media import /fixtures/pixel.png --title="Mindful Pixel" --alt="One pixel" \
    --post_id="$PUB" --featured_image --porcelain >/dev/null
fi

# ---------- redirects ----------
log "Redirects"
if wp plugin is-active redirection >/dev/null 2>&1; then
  wp eval '
    if ( ! class_exists( "Red_Item" ) ) { echo "no-api"; return; }
    global $wpdb;
    $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}redirection_items WHERE url = %s", "/old-path/" ) );
    if ( $exists ) { echo "exists"; return; }
    $r = Red_Item::create( array(
      "url" => "/old-path/", "match_type" => "url", "action_type" => "url", "action_code" => 301,
      "action_data" => array( "url" => "/published-post/" ), "group_id" => 1, "regex" => false,
    ) );
    echo is_wp_error( $r ) ? "error: " . $r->get_error_message() : "created";
  ' | tail -n1 | sed 's/^/    redirect: /'
else
  warn "Redirection not active; skipped /old-path/ rule"
fi

# ---------- key ----------
log "Generating import key"
KEY=$(wpv eval 'echo Mindful_Import\Auth::generate_key();')
[ -n "$KEY" ] || { echo "key generation returned nothing" >&2; exit 1; }
printf '%s\n' "$KEY" > dev/.key
chmod 600 dev/.key

echo
echo "Discovery: http://localhost:8089/wp-json/mindful-import/v1/discovery"
echo "Key file:  $(pwd)/dev/.key   (header: X-Mindful-Import-Key)"
echo "Admin:     http://localhost:8089/wp-admin/  admin / admin"
