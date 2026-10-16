# Internal interfaces (fixed; every file builds against these)

Namespace `Mindful_Import`, one class per file, static methods, no autoloader (explicit requires in
`mindful-import.php`). All classes are loaded before `plugins_loaded`; each class's `init()` registers its hooks.

- `Plugin::init()` (in `class-plugin.php`): calls `Settings::init()`, `Auth::init()`,
  `Post_Types::init()`, `Fields::init()`, `Changelog::init()`, `Redirects::init()`, `REST::init()`, `Updater::init()`,
  `Registration::init()`.
  `Plugin::activate()` → `Settings::ensure_defaults()`, `Auth::ensure_role_and_user()`, `Changelog::install_table()`,
  `Changelog::schedule_retention()`, then `Registration::register()` inline (result recorded in settings, return
  value ignored). `Plugin::maybe_upgrade()` additionally calls `Registration::schedule_now()` when
  `registration_status !== 'registered'` (never registers inline: it can run on any front-end request).
  `Plugin::deactivate()` → `Changelog::unschedule_retention()`, `Registration::unschedule()`.
- `Settings::get( string $key, $default = null )` reads from the single option `mindful_import` (array).
  Keys: `key_hash`, `key_salt`, `key_created_at`, `registration_status` (string: `''` | `pending` | `registered` |
  `failed`), `registered_at` (int, 0), `registration_error` (string, `''`; short and sanitised, never contains the
  key), `registration_attempts` (int, 0; failed attempts since the last success), `registration_last_attempt_at`
  (int, 0), `post_types` (array name=>bool, missing = enabled),
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
  `Auth::generate_key(): string` (stores hash, returns plaintext once; only `Registration::register()` calls it).
  `Auth::verify_key( string $plain ): bool`.
- `Registration` (in `class-registration.php`), cron hook `Registration::CRON_HOOK` = `mindful_import_register`.
  Constant `MINDFUL_IMPORT_REGISTRATION_URL` (default `https://apis.mindfulcms.com/import/wordpress/register`, defined only if
  wp-config.php has not defined it).
  `Registration::register(): true|WP_Error` — validates the URL, snapshots `key_hash`/`key_salt`/`key_created_at`,
  calls `Auth::generate_key()` (the new key is live during the request), POSTs `{"rest_url": rest_url(), "key": <plain>}`
  as JSON (no redirects, TLS verified, 15 s). A `rest_url()` with no host fails before any key change. 2xx → `registered`, attempts reset, retry cleared. Anything else →
  snapshot restored, `failed`, attempts + 1, `schedule_retry()`. Holds an option lock
  (`mindful_import_registration_lock`) so two attempts cannot interleave.
  `Registration::is_url_allowed( string $url, ?string $environment_type = null ): bool` — host required; `https`, or
  `http` when the environment type (default `wp_get_environment_type()`) is `local` or `development`.
  `Registration::schedule_retry()` (5 min, 1 h, 6 h, 24 h by failed attempts; none after 5),
  `Registration::schedule_now()` (single event at `time()` when none is pending; sets `pending` when status is `''`),
  `Registration::unschedule()`, `Registration::run_scheduled()` (cron callback; no-op when already registered).
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
  `mindful_import_change_entry(?array $entry)`, `mindful_import_discovery(array)`,
  `mindful_import_allowed_routes(string[] $routes)`;
  action `mindful_import_register_resource(string $namespace)`.
