# Mindful Import

## What it is

Mindful Import is a read-only WordPress plugin that exposes what a Mindful CMS import needs and what core
WordPress REST does not return. That covers post meta on every post type, term meta, every user, an allowlisted
set of options, redirect rules, rendered content and a change log for incremental (delta) imports. Every request
authenticates with a single key sent on the `X-Mindful-Import-Key` header. The key does not go in `Authorization`
because many hosts and proxies strip that header before it reaches PHP. The plugin never writes content: any
non-GET request that carries the key gets a 403.

## Install

1. Download `mindful-import-<version>.zip` from the GitHub Releases page. In WordPress, go to
   **Plugins → Add New → Upload Plugin**. You can also copy the `plugin/` folder to
   `wp-content/plugins/mindful-import/`.
2. Activate **Mindful Import**.
3. Go to **Settings → Mindful Import** and click **Generate key**. The key is shown once, in the admin notice.
   Only a salted hash is stored, so copy the key before you leave the page.
4. Send Parameter1 the key and the discovery URL, `https://<site>/wp-json/mindful-import/v1/discovery`.

Generating a new key revokes the old one straight away.

## What it exposes

### Plugin routes (`/wp-json/mindful-import/v1/…`, GET, key required)

| Route | Query params | Response |
|---|---|---|
| `discovery` | none | `{plugin:{version,hook_api}, wordpress, post_types:[{name,label,public,show_in_rest,rest_base,hierarchical,taxonomies,count}], taxonomies, plugins:[{file,name,version}], theme, settings, changes:{head_id}, …}`: everything needed to plan a run |
| `options` | `names` (comma list, narrows the result) | `{options:[{key,value}], denied_count}` |
| `settings` | none | Plugin settings without secrets: `{post_types, taxonomies, options_allowlist, options_prefixes, retention_days, render_enabled, key_created_at, version, …}` |
| `users` | `page` (1), `per_page` (100, max 500) | `[{id,login,email,display_name,nicename,url,registered,roles,first_name,last_name,nickname,description,avatar_url,meta}]`. Includes users with no posts, never the plugin's own `mindful-import` account. Sends `X-WP-Total` / `X-WP-TotalPages` |
| `changes` | `after` (id, 0), `limit` (100, max 500), `object_type` (`post`/`attachment`/`term`/`user`), `since` (ISO 8601) | `{changes:[{id,object_type,object_id,post_type,taxonomy,action,old_status,new_status,changed_at,user_id}], head_id, has_more, next_after}` |
| `changes/latest` | none | `{head_id, latest, …}` |
| `redirects` | `provider` (`redirection`, `safe-redirect-manager`, `yoast-premium`) | `{providers:[…], redirects:[{source,target,code,enabled,regex,provider,…}], count}` |

### Fields added to core routes (`/wp-json/wp/v2/…`)

These fields are registered only on key-authenticated requests. Anonymous responses are byte-for-byte what core returns (no `post_meta_fields` key at all).

- `post_meta_fields` on posts, pages, every public CPT and attachments. All post meta is flattened into the
  shape base-imports already reads: a single-value array becomes a scalar, a multi-value meta stays an array,
  and `.` in a key becomes `__`.
- `term_meta_fields` on every public taxonomy (same shape).
- `mindful_rendered_content` on non-attachment post types when the request adds `?mindful_render=1`. This is
  `the_content` with shortcodes and blocks rendered. It can be turned off in settings.
- Public post types and taxonomies registered without `show_in_rest` are put into REST (`rest_base` = name),
  so a CPT the theme hid from REST can still be imported. This happens only on key-authenticated requests:
  anonymous visitors still get a 404 for such a type, and the admin (including the classic-versus-block
  editor choice, which core derives from `show_in_rest`) sees the type exactly as the site registered it.
  Non-public types stay out.
- The import user can read drafts, scheduled and private content (`?status=draft&context=edit`). Two core limits
  to know: WordPress protects the Privacy Policy page (and the front/posts pages) behind `manage_options`, so a
  draft of those is not returned even though `X-WP-Total` counts it; and `wp/v2/users?context=edit` returns only
  users the caller may *edit* (just the import user itself), so read users from `mindful-import/v1/users`, which
  returns everyone with emails, roles and meta.

### Read-only guarantee

Three layers, all applied to key-authenticated requests:

- **The key only works on REST.** It is honoured on requests core serves through the REST API and nowhere
  else: not on admin screens, `admin-ajax.php`, `admin-post.php`, `wp-login.php`, cron or XML-RPC, whatever
  the query string says. A key sent there is ignored and the request stays anonymous.
- **Writes are refused.** Any request that is not GET/HEAD/OPTIONS, after core's `_method` and
  `X-HTTP-Method-Override` overrides are applied, is rejected with **403**. That includes core routes such as
  `POST /wp/v2/posts` and `DELETE /wp/v2/posts/<id>`.
- **Only content routes are reachable.** GET is allowed on the REST index, `mindful-import/v1/*`, every
  REST-visible post type and taxonomy, and `wp/v2/{users,comments,types,taxonomies,statuses,search}`. Anything
  else answers **403** (`mindful_import_route_denied`), which keeps the oembed proxy, the block renderer, the
  block and pattern directories, plugins, themes, settings, site health and batch out of reach even though
  they answer GET. Companions extend the list with `mindful_import_allowed_routes`.

The role itself holds only `read`. Core REST needs edit-level capabilities to return `context=edit` and
non-published statuses, so those are granted to the import user for the duration of a GET REST request
only, through `user_has_cap`, and are never stored. Outside such a request the account is a subscriber.

### Change log

A custom table records `create`, `update`, `publish`, `unpublish`, `trash`, `untrash` and `delete` for posts
(every post type), attachments, terms and users. Each entry carries the old and new status. Ids only ever
increase, so an importer stores `head_id` (or `next_after`) and later asks for `changes?after=<id>`. Changes
made by the import user itself are not logged, and neither is that account's own creation or update. A daily
WP-Cron job deletes entries older than `retention_days` (default 90, minimum 7).

## Configuration (Settings → Mindful Import)

| Setting | Default | Effect |
|---|---|---|
| Key | none | Generate (or rotate) the import key. Shows when the key was created |
| Post types | all public enabled | Untick a type to keep it out of forced REST exposure (which only key requests get) and the meta fields |
| Taxonomies | all public enabled | Same as post types, for taxonomies |
| Options allowlist | site basics (`blogname`, `home`, `timezone_string`, `permalink_structure`, …) | Exact option names returned by `options` |
| Option prefixes | `wpseo_`, `aioseo_` | Every option starting with a prefix is returned, unless its name hits the deny-list |
| Retention days | 90 | Change-log retention (minimum 7 is enforced) |
| Render content | on | Allows `?mindful_render=1` |

## Security notes

- **Key hashing.** Only `sha256(salt + key)` and the salt are stored, and comparison uses `hash_equals`. The
  plaintext appears once, at generation. Neither `settings` nor `discovery` ever returns the hash or the salt.
- **Dedicated role and user.** Key requests run as a dedicated `mindful-import` user with the `mindful_import`
  role, which holds `read` and nothing else. The user's password is 64 random characters generated at
  creation and never shown to anyone, not even Parameter1. The account cannot log in interactively and
  cannot hold application passwords. The only secret that leaves the site is the import key.
- **Why the request-time grant includes `edit_*` capabilities.** The plugin's own routes need no
  capabilities at all. The edit capabilities exist only for core `wp/v2` routes, because WordPress has no
  "read unpublished content" capability and uses edit permissions as the proxy for it:
  - `edit_posts` / `edit_pages`: listing `?status=draft`, `pending`, `future` or `trash`
    (`sanitize_post_statuses`), and `context=edit`, which returns the raw body and registered `meta`.
  - `edit_others_posts`, `edit_published_posts`, `edit_private_posts` and the page equivalents: reading a
    single unpublished item. Core maps `read_post` on a draft to `edit_post`, which needs `edit_others_posts`
    for items the import user did not write.
  - `manage_categories`: `context=edit` on terms. `list_users`: `/wp/v2/users?context=edit`. Media reads,
    including `context=edit`, need only `edit_posts`; `upload_files` is checked for uploads and edits alone,
    so it is never granted.
  - `read`, `read_private_posts`, `read_private_pages` are the only purely read capabilities and cover
    published and private content only.
  Rather than store these on the role, where any code path that runs as the account could use them, the
  plugin grants them through `user_has_cap` only while a GET/HEAD/OPTIONS REST request is being served, and
  adds the equivalents for every REST-visible type and taxonomy with its own `capability_type`. The user
  never gets `manage_options`, `edit_theme_options`, `upload_files`, `delete_*`, `publish_*` or plugin and
  theme capabilities, whatever a post type maps its own capabilities to. This supersedes the 2026-10-13
  decision to keep the capabilities on the role: that rested on the key being ignored on `admin-ajax`,
  which was not the case until the request-context check below was tightened.
- **Options deny-list.** Options matched by a prefix are dropped (and counted in `denied_count`) when their
  name contains `key`, `secret`, `token`, `password`, `passwd`, `salt`, `smtp`, `auth`, `private` or `license`.
  Exact allowlist entries are an explicit admin choice and bypass the deny-list.
- **User meta filtering.** Session tokens, capabilities, user level, admin UI preferences and screen options
  are never returned, nor is any key named like a credential: `pass`/`password`, `token`, `secret`, `apikey`
  and friends as whole name parts, and `key` as the last part (`api_key`). Matching is by whole token, so
  `focus_keyword`, `passions` or `key_skills` come through.
- **403 on writes and on non-content routes.** See the read-only guarantee above.
- **Uninstall** removes the option, the change-log table, the role and the import user.

## Extending per site

The plugin is built for sites where we have no administrative access, so it needs nothing installed beside
it and we never rely on adding code to a customer site. Changes for a single site follow a two-tier rule:

1. **Mapping in base-imports first.** If the data is already in `post_meta_fields`, `term_meta_fields`,
   `users[].meta` or `options`, map it in the importer. Nothing on the site changes.
2. **Plugin configuration second.** Use an option prefix or allowlist entry, or a post type or taxonomy toggle.

The hooks below exist for the rare site where someone with access wants to adjust what the plugin exposes,
from an mu-plugin or the theme. None of them can authenticate a request: only the key does that.
`plugin/includes/hooks.php` has full docs and examples (hook API version 1):

| Hook | Type | Signature |
|---|---|---|
| `mindful_import_post_fields` | filter | `( array $fields, WP_Post $post ): array` |
| `mindful_import_term_fields` | filter | `( array $fields, WP_Term $term ): array` |
| `mindful_import_user_fields` | filter | `( array $fields, WP_User $user ): array` |
| `mindful_import_options_allowlist` | filter | `( array $names, array $prefixes ): array`. Return names, or `[ $names, $prefixes ]` to change both |
| `mindful_import_change_entry` | filter | `( ?array $entry ): ?array`. Return null to skip the entry |
| `mindful_import_discovery` | filter | `( array $discovery ): array` |
| `mindful_import_allowed_routes` | filter | `( string[] $routes ): string[]`. Route prefixes an import request may reach |
| `mindful_import_register_resource` | action | `( string $namespace )`. Call `\Mindful_Import\REST::add_route( $path, $args )` to inherit key auth |

## Updates

The plugin updates itself from this repository's GitHub Releases through the bundled
[plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) **v5.6** (MIT,
`plugin/vendor/plugin-update-checker/`). It reads the releases of `parameter1/wordpress-plugin-mindful-import`
and installs the attached `mindful-import-<version>.zip`. This needs the repository to be **public**, because
the checker sends no token. Updates then show up like any other plugin update, and the WordPress
**Enable auto-updates** link on the Plugins screen works with them.

Release flow:

1. Bump `Version:` in the header and `MINDFUL_IMPORT_VERSION` in `plugin/mindful-import.php`.
2. Commit, then tag `vX.Y.Z` and push the tag.
3. `.github/workflows/release.yml` checks that the tag matches both version strings, runs
   `scripts/build-zip.sh` and publishes a release with the zip attached.

## Development

Requires Docker, `curl` and `jq`.

> **Do not run `wp plugin install … --force` against the harness.** `plugin/` is bind-mounted into the
> container as the plugin folder, so a forced reinstall deletes your working copy on the host. To check the
> zip, unzip it and diff it against `plugin/` instead (`scripts/build-zip.sh` then `diff -rq`).

```sh
docker compose up -d          # WordPress on http://localhost:8089 + MySQL 8 (project: mindful-import-dev)
dev/seed.sh                   # install, activate, seed fixtures + live-like plugins, write a key to dev/.key (admin / admin)
dev/smoke.sh                  # end-to-end assertions; prints "SMOKE: N passed, M failed"; rotates the key
scripts/build-zip.sh          # dist/mindful-import-<version>.zip
docker compose --profile tools run --rm cli plugin list     # wp-cli
docker run --rm -v "$PWD/plugin:/p" php:7.4-cli sh -c 'for f in $(find /p -name "*.php" -not -path "*/vendor/*"); do php -l "$f"; done'
docker compose down -v        # wipe everything
```

The seed also installs the neighbours a real site has, so every assertion runs alongside them: Wordfence,
Yoast SEO, All in One SEO, Advanced Custom Fields, WooCommerce, Elementor, Classic Editor, WP Super Cache,
Limit Login Attempts Reloaded and Disable REST API, plus Redirection. Override the list with
`SEED_PLUGINS="wordfence wordpress-seo" dev/seed.sh` (an empty value installs none). The smoke suite warns
rather than fails when one is missing, and it adds plugin-specific checks (Yoast and AIOSEO options through
the default prefixes, a WooCommerce product through forced REST, `wc/v3` refused by the route allowlist,
Disable REST API blocking anonymous calls while key calls still pass). Plugin notices go to
`wp-content/debug.log` inside the container, never into responses. Expect each request and each wp-cli call to
take a few seconds with this set loaded.

`dev/test-cpt.php` is mounted as a must-use plugin on the dev site only. It registers a `news` CPT (with its
own `capability_type`) and a `news_topic` taxonomy that are deliberately not in REST, a `[mi_test]` shortcode,
a non-public `secret_internal` type and an `mi_whoami` ajax action. It is a single-file bind mount, so after
editing it with a tool that replaces the file, run `docker compose up -d --force-recreate wordpress` or the
container keeps the old inode and every request fails with a missing-include warning.

## License

MIT. See [LICENSE](LICENSE).
