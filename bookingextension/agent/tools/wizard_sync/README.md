# wizard_sync — one-way generator for local_wizard

`bookingextension_agent` is the single source of truth. The standalone
`local_wizard` plugin is a **generated build artifact**: never edit it by hand,
port every change to the agent and regenerate.

## Usage

```bash
python3 tools/wizard_sync/generate_local_wizard.py --target /path/to/local/wizard
```

Options: `--dry-run` (report only), `--force` (discard hand-edits in the target).

The script needs only Python 3 (stdlib), no Moodle bootstrap, and is fully
deterministic: same source tree in, byte-identical artifact out.

**Rebuild semantics:** logically a full rebuild on every run (every source file
is re-transformed; there is no cache that can go stale), physically incremental
at write time: files are hash-compared and only written when they differ
(mtimes of unchanged files survive), files no longer generated are removed via
the manifest (`… 1 stale removed`), foreign files in the target are left alone
and reported. A run without source changes is a guaranteed no-op.

**Exit codes:** `0` OK · `1` verification failed (nothing half-written is
trusted — fix the source) · `2` hand-edited target detected (port the change to
the agent and regenerate, or `--force` to discard it).

**The output is a ready-to-install plugin.** Generate into (or copy to)
`<webroot>/local/wizard` and run the normal Moodle install
(`admin/cli/upgrade.php`). Verified both standalone (Moodle without
mod_booking) and in coexistence next to mod_booking, where local_wizard takes
over the engine and the bundled agent stands down.

## What it does

1. **Token map** (single pass, longest match first): frankenstyle
   `bookingextension_agent` → `local_wizard`, table prefix `bx_agent_` →
   `local_wizard_`, capability/path form `bookingextension/agent` →
   `local/wizard`, hyphen form (CSS/DOM), split savepoint args, webroot path
   `mod/booking/bookingextension/agent` → `local/wizard`, admin parent
   `modbookingfolder` → `localplugins`. File and directory names are mapped
   with the same rules (`lang/en/bookingextension_agent.php` →
   `lang/en/local_wizard.php`). In lang files the plugin-name-derived
   capability keys `$string['agent:…']` become `$string['wizard:…']` (Moodle
   resolves capability display strings under the plugin NAME, not the
   component). Known cosmetic consequence: key renames unsort the generated
   lang files; irrelevant at runtime, but a future artifact CI needs the
   sorting sniff relaxed (or a re-sort step here).
2. **config.php require depth**: the plugin moves from 4 to 2 directory levels
   below the webroot; every `__DIR__ . '/../../ … /config.php'` climb is
   rewritten to the correct depth for its file.
3. **version.php**: the `mod_booking` dependency block is removed — the
   artifact must install without mod_booking.
3b. **db/services.php**: the external service display name becomes
   `'Booking Wizard'` — both engines install side by side and service names
   sit under a unique index (the shortname is component-derived and handled by
   the token map).
4. **Overlays** (`tools/wizard_sync/overlays/…` ships verbatim instead of a
   transformed copy; a path without source counterpart is ADDED to the
   artifact; overlays may name the agent deliberately and are exempt from the
   residual-token check): `db/upgrade.php` (agent upgrade history does not
   apply; documented no-op while pre-production) and `db/install.php` (the
   takeover migration: on installation next to an active agent the wizard
   COPIES the agent's bx_agent_* table rows, plugin settings and role
   capability assignments into its own component — agent originals stay
   untouched, so uninstalling the wizard later reactivates the agent exactly
   where it stood; this functionality exists only in the artifact, never in
   the installed agent).
4b. **Verbatim files** (copied untransformed, exempt from the residual-token
   check): the scaffold's engine-alias-layer templates
   (`classes/local/wizard/services/scaffold/templates/engine_layer/`) — they
   are engine-universal by design and must keep naming both engine components
   in either plugin.
5. **Excluded**: `.git`, `.github` (CI is agent-specific), `.claude`,
   `node_modules`, `tools/`, and `classes/agent.php` (mod_booking subplugin
   registration, load-fatal standalone).
6. **Built-in verification** (non-zero exit on failure): no residual
   `bookingextension`/`bx_agent` token anywhere, install.xml table names
   `local_wizard_*` and ≤ 28 chars, config.php require depths correct.
7. **Manifest** (`.wizard_sync_manifest.json` in the target): SHA-256 per
   generated file. The next run refuses to overwrite hand-edited files
   (exit 2) unless `--force` is given, and deletes files that are no longer
   generated.

## Source-side invariants the generator relies on

- Table name suffix after `bx_agent_` stays ≤ 15 chars (verified: 28-char
  limit after prefix swap).
- Coexistence logic goes through
  `authorization_service::primary_engine_takes_over()` (symmetric via the
  `ENGINE_COMPONENT`/`PRIMARY_ENGINE` constants) — never probe
  `local_wizard` directly, the literal maps onto itself.
- Booking-coupled tests call
  `mod_booking_dependency::require_installed()` first in `setUp()`; test base
  classes from mod_booking only via conditional `class_alias`.
- mod_booking classes are referenced at runtime only (string FQCN +
  `class_exists`), never via `use` + static call (see `wb_license`,
  `aiready`).
- mod_booking FILES are never addressed by a relative path that assumes the
  engine sits inside mod_booking — resolve the directory via
  `core_component::get_component_directory('mod_booking')` (see
  `abstract_agent_testcase`).
- New PHP entry scripts must build their config.php require as
  `__DIR__ . '/../…/config.php'` (the depth fixer only rewrites that shape).
- Anything site-unique a plugin registers (external service names, admin page
  ids, …) must either carry a component token (the map renames it) or get an
  explicit transform here — two engines install side by side.

## The engine alias layer (consumer side)

Skill-providing components (mod_booking, the oneclick extension, every
scaffolded plugin) never import an engine component directly. Each carries an
identical `classes/local/wizard/engine/` directory: `engine_resolver` picks the
active engine (local_wizard when installed and upgraded, the bundled agent
otherwise) and one `class_alias` file per engine contract type binds stable
names in the component's own namespace. The canonical source of that layer is
`classes/local/wizard/services/scaffold/templates/engine_layer/`; mod_booking's
`engine_alias_layer_test` pins all copies byte-identical — do not fork the
pattern. Two hard-won rules encoded there: PHP checks typed signatures WITHOUT
autoloading (hence the resolver's eager preload), and alias files must be
idempotent (re-entrant loads during preload).
