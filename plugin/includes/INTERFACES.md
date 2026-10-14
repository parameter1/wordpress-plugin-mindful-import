# Internal interfaces (fixed; every file builds against these)

Namespace `Mindful_Import`, one class per file, static methods, no autoloader (explicit requires in
`mindful-import.php`). All classes are loaded before `plugins_loaded`; each class's `init()` registers its hooks.

- `Plugin::init()` (in `class-plugin.php`): calls `Settings::init()`, `Auth::init()`,
  `Post_Types::init()`, `Fields::init()`, `Changelog::init()`, `Redirects::init()`, `REST::init()`, `Updater::init()`.
  `Plugin::activate()` → `Settings::ensure_defaults()`, `Auth::ensure_role_and_user()`, `Changelog::install_table()`,
  `Changelog::schedule_retention()`. `Plugin::deactivate()` → `Changelog::unschedule_retention()`.
- `Settings::get( string $key, $default = null )` reads from the single option `mindful_import` (array).
  Keys: `key_hash`, `key_salt`, `key_created_at`, `post_types` (array name=>bool, missing = enabled),
  `taxonomies` (same), `options_allowlist` (array of names), `options_prefixes` (array), `retention_days` (int, 90),
  `render_enabled` (bool, true), `installed_version` (string; drives `Plugin::maybe_upgrade()`).
  `Settings::update( array $partial )`, `Settings::delete( string $key )`, `Settings::ensure_defaults()`,
  `Settings::defaults()`.
- `Auth::is_import_request( ?WP_REST_Request $request = null ): bool` — true when the current request carried a
  valid key (memoised per request). The key is honoured only on requests core serves through the REST API.
  `Auth::permission_callback( WP_REST_Request $r )` → true|WP_Error(401).
  `Auth::ensure_role_and_user(): int` returns the import user id; the role holds `read` only, and the edit-level
  reads core REST needs are granted through `user_has_cap` for GET/HEAD/OPTIONS REST requests only.
  `Auth::allowed_routes(): string[]` — route prefixes an import request may reach (`rest_pre_dispatch` refuses the
  rest with 403). `Auth::import_user_id(): int`.
  `Auth::generate_key(): string` (stores hash, returns plaintext once). `Auth::verify_key( string $plain ): bool`.
- `REST::add_route( string $path, array $args )` — registers under `mindful-import/v1` with
  `permission_callback => Auth::permission_callback` injected when absent, `methods` default `GET`.
  Classes call it from the `rest_api_init` action (REST::init hooks `rest_api_init` at priority 5 and fires
  `do_action( 'mindful_import_register_resource', MINDFUL_IMPORT_REST_NAMESPACE )` at priority 20).
- `Changelog::record( string $object_type, int $object_id, string $action, array $extra = array() )`.
  `Changelog::head_id(): int`. `Changelog::install_table()`.
- `Redirects::all(): array{providers: string[], redirects: array<array{source,target,code,enabled,provider}>}`.
- `Fields::flatten_meta( array $meta ): array` — the old base-imports shape (single-value arrays flattened,
  `.` → `__`).
- Hooks (hooks.php documents them; emitted by the owning class):
  filters `mindful_import_post_fields($fields, WP_Post)`, `mindful_import_term_fields($fields, WP_Term)`,
  `mindful_import_user_fields($fields, WP_User)`, `mindful_import_options_allowlist(array $names, array $prefixes)`,
  `mindful_import_change_entry(?array $entry)`, `mindful_import_authenticate(?int $user_id, WP_REST_Request|null)`,
  `mindful_import_discovery(array)`, `mindful_import_allowed_routes(string[] $routes)`;
  action `mindful_import_register_resource(string $namespace)`.
