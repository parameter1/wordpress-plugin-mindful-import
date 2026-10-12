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
| `settings` | none | Plugin settings without secrets: `{post_types, taxonomies, options_allowlist, options_prefixes, retention_days, render_enabled, key_created_at, last_seen, version, …}` |
| `users` | `page` (1), `per_page` (100, max 500) | `[{id,login,email,display_name,nicename,url,registered,roles,first_name,last_name,nickname,description,avatar_url,meta}]`. Includes users with no posts. Sends `X-WP-Total` / `X-WP-TotalPages` |
| `changes` | `after` (id, 0), `limit` (100, max 500), `object_type` (`post`/`attachment`/`term`/`user`), `since` (ISO 8601) | `{changes:[{id,object_type,object_id,post_type,taxonomy,action,old_status,new_status,changed_at,user_id}], head_id, has_more, next_after}` |
| `changes/latest` | none | `{head_id, latest, …}` |
| `redirects` | `provider` (`redirection`, `safe-redirect-manager`, `yoast-premium`) | `{providers:[…], redirects:[{source,target,code,enabled,regex,provider,…}], count}` |

### Fields added to core routes (`/wp-json/wp/v2/…`)

These fields appear only on key-authenticated requests. Anonymous responses are unchanged.

- `post_meta_fields` on posts, pages, every public CPT and attachments. All post meta is flattened into the
  shape base-imports already reads: a single-value array becomes a scalar, a multi-value meta stays an array,
  and `.` in a key becomes `__`.
- `term_meta_fields` on every public taxonomy (same shape).
- `mindful_rendered_content` on non-attachment post types when the request adds `?mindful_render=1`. This is
  `the_content` with shortcodes and blocks rendered. It can be turned off in settings.
- Public post types and taxonomies registered without `show_in_rest` are put into REST (`rest_base` = name),
  so a CPT the theme hid from REST can still be imported. Non-public types stay out.
- The import user can read drafts, scheduled and private content (`?status=draft&context=edit`).

### Read-only guarantee

Any request authenticated by the key that is not GET/HEAD/OPTIONS is rejected with **403**. That includes
core routes such as `POST /wp/v2/posts` and `DELETE /wp/v2/posts/<id>`. The dedicated role has edit
capabilities only so that core REST will return `context=edit` and non-published statuses. The 403 gate is
what enforces read-only.

### Change log

A custom table records `create`, `update`, `publish`, `unpublish`, `trash`, `untrash` and `delete` for posts
(every post type), attachments, terms and users. Each entry carries the old and new status. Ids only ever
increase, so an importer stores `head_id` (or `next_after`) and later asks for `changes?after=<id>`. Changes
made by the import user itself are not logged. A daily WP-Cron job deletes entries older than
`retention_days` (default 90, minimum 7).

## Configuration (Settings → Mindful Import)

| Setting | Default | Effect |
|---|---|---|
| Key | none | Generate (or rotate) the import key. Shows when the key was created and when and from where it was last used |
| Post types | all public enabled | Untick a type to keep it out of forced REST exposure and the meta fields |
| Taxonomies | all public enabled | Same as post types, for taxonomies |
| Options allowlist | site basics (`blogname`, `home`, `timezone_string`, `permalink_structure`, …) | Exact option names returned by `options` |
| Option prefixes | `wpseo_`, `aioseo_` | Every option starting with a prefix is returned, unless its name hits the deny-list |
| Retention days | 90 | Change-log retention (minimum 7 is enforced) |
| Render content | on | Allows `?mindful_render=1` |

## Security notes

- **Key hashing.** Only `sha256(salt + key)` and the salt are stored, and comparison uses `hash_equals`. The
  plaintext appears once, at generation. Neither `settings` nor `discovery` ever returns the hash or the salt.
- **Dedicated role and user.** Key requests run as a dedicated `mindful-import` user with the `mindful_import`
  role. The role has read and edit capabilities plus `list_users`, and no `manage_options`, `delete_*`,
  `publish_*` or plugin and theme capabilities.
- **Options deny-list.** Options matched by a prefix are dropped (and counted in `denied_count`) when their
  name contains `key`, `secret`, `token`, `password`, `passwd`, `salt`, `smtp`, `auth`, `private` or `license`.
  Exact allowlist entries are an explicit admin choice and bypass the deny-list.
- **User meta filtering.** Passwords, session tokens, capabilities, user level, any key, token or secret
  values, and admin UI preferences are never returned.
- **403 on writes.** See the read-only guarantee above.
- **Uninstall** removes the option, the change-log table, the role and the import user.

## Extending per site

Changes for a single site follow a three-tier rule:

1. **Mapping in base-imports first.** If the data is already in `post_meta_fields`, `term_meta_fields`,
   `users[].meta` or `options`, map it in the importer. Nothing on the site changes.
2. **Plugin configuration second.** Use an option prefix or allowlist entry, or a post type or taxonomy toggle.
3. **Companion plugin last.** Use one only for data that has to be computed in PHP, such as ACF/Pods values
   that need their API or a custom table. Write a small plugin with `Requires Plugins: mindful-import` that
   hooks the filters below. [`examples/companion/mindful-import-example.php`](examples/companion/mindful-import-example.php)
   is a working template. Guard it with `function_exists( 'mindful_import' )` and check
   `mindful_import()['hook_api']`.

Hooks (hook API version 1; `plugin/includes/hooks.php` has full docs and examples):

| Hook | Type | Signature |
|---|---|---|
| `mindful_import_post_fields` | filter | `( array $fields, WP_Post $post ): array` |
| `mindful_import_term_fields` | filter | `( array $fields, WP_Term $term ): array` |
| `mindful_import_user_fields` | filter | `( array $fields, WP_User $user ): array` |
| `mindful_import_options_allowlist` | filter | `( array $names, array $prefixes ): array`. Return names, or `[ $names, $prefixes ]` to change both |
| `mindful_import_change_entry` | filter | `( ?array $entry ): ?array`. Return null to skip the entry |
| `mindful_import_authenticate` | filter | `( ?int $user_id, ?WP_REST_Request $request ): ?int` |
| `mindful_import_discovery` | filter | `( array $discovery ): array` |
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
dev/seed.sh                   # install, activate, seed fixtures, write a key to dev/.key (admin / admin)
dev/smoke.sh                  # end-to-end assertions; prints "SMOKE: N passed, M failed"; rotates the key
scripts/build-zip.sh          # dist/mindful-import-<version>.zip
docker compose --profile tools run --rm cli plugin list     # wp-cli
docker run --rm -v "$PWD/plugin:/p" php:7.4-cli sh -c 'for f in $(find /p -name "*.php" -not -path "*/vendor/*"); do php -l "$f"; done'
docker compose down -v        # wipe everything
```

`dev/test-cpt.php` is mounted as a must-use plugin on the dev site only. It registers a `news` CPT and a
`news_topic` taxonomy that are deliberately not in REST, a `[mi_test]` shortcode and a non-public
`secret_internal` type. `examples/companion` is mounted but inactive. Run `SEED_COMPANION=1 dev/seed.sh` to
activate it.

## License

MIT. See [LICENSE](LICENSE).
