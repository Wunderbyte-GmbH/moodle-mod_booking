<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrade hook.
 *
 * @package     bookingextension_agent
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade function.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_bookingextension_agent_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026063003) {
        // Record per benchmark run whether family/skill embeddings were live (vs keyword-only routing).
        // New, correctly-prefixed bx_agent_ field, guarded + idempotent.
        $table = new xmldb_table('bx_agent_benchmark_runs');
        $field = new xmldb_field('embeddings_used', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'git_ref');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026063003, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026063008) {
        // Record the embedding model used when embeddings were live (catalog current) for a run.
        $table = new xmldb_table('bx_agent_benchmark_runs');
        $field = new xmldb_field('embeddings_model', XMLDB_TYPE_CHAR, '80', null, null, null, null, 'embeddings_used');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026063008, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026070200) {
        // Retrieval foundation: DB-backed embeddings store. Idempotent create, guarded by table_exists;
        // mirrors db/install.xml exactly.
        $table = new xmldb_table('bx_agent_embeddings');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('area', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('owner', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('refkey', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('refindex', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('endindex', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('title', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('emodel', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
            $table->add_field('edims', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('contenthash', XMLDB_TYPE_CHAR, '40', null, null, null, null);
            $table->add_field('identityhash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $table->add_field('generation', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('embedding', XMLDB_TYPE_BINARY, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('docid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('owneruserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('variant_gen_idx', XMLDB_INDEX_NOTUNIQUE, ['area', 'emodel', 'edims', 'generation']);
            $table->add_index('reuse_idx', XMLDB_INDEX_NOTUNIQUE, ['area', 'emodel', 'edims', 'generation', 'identityhash']);
            $table->add_index('contextid_idx', XMLDB_INDEX_NOTUNIQUE, ['contextid']);
            $dbman->create_table($table);
        }

        $meta = new xmldb_table('bx_agent_embeddings_meta');
        if (!$dbman->table_exists($meta)) {
            $meta->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $meta->add_field('area', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $meta->add_field('emodel', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
            $meta->add_field('edims', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $meta->add_field('committedgeneration', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $meta->add_field('fingerprint', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $meta->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $meta->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $meta->add_index('variant_uix', XMLDB_INDEX_UNIQUE, ['area', 'emodel', 'edims']);
            $dbman->create_table($meta);
        }

        upgrade_plugin_savepoint(true, 2026070200, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026070202) {
        // Site search foundation: per area x variant incremental indexing cursor (runtime state,
        // task-written) + governance scope table (admin-written config, default = everything off).
        // Idempotent create, guarded by table_exists; mirrors db/install.xml exactly.
        // The cursor column is named indexcursor because "cursor" is a MySQL reserved word.
        $state = new xmldb_table('bx_agent_sitesearch_state');
        if (!$dbman->table_exists($state)) {
            $state->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            // Length 100 (not 255): the unique index areakey+emodel+edims must stay under Moodle's
            // 333-char composed-index limit (255 + 128 chars would exceed it).
            $state->add_field('areakey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $state->add_field('emodel', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
            $state->add_field('edims', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $state->add_field('indexcursor', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $state->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $state->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $state->add_index('areavariant_uix', XMLDB_INDEX_UNIQUE, ['areakey', 'emodel', 'edims']);
            $dbman->create_table($state);
        }

        $scope = new xmldb_table('bx_agent_search_scope');
        if (!$dbman->table_exists($scope)) {
            $scope->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $scope->add_field('area', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $scope->add_field('scopetype', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $scope->add_field('scopeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $scope->add_field('enabled', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $scope->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $scope->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $scope->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $scope->add_index('areascope_idx', XMLDB_INDEX_NOTUNIQUE, ['area', 'scopetype', 'scopeid']);
            $dbman->create_table($scope);
        }

        upgrade_plugin_savepoint(true, 2026070202, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026070203) {
        // The skills catalog computes sha256 content hashes (64 chars); the column was sized for
        // the docs sha1 (40) and truncation would break change detection. The column is not part
        // of any index, so widening is safe.
        $table = new xmldb_table('bx_agent_embeddings');
        $field = new xmldb_field('contenthash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'edims');
        if ($dbman->table_exists($table) && $dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026070203, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026070205) {
        // Site-search file indexing (PDF, v1): per area x scope flag — file indexing is governed
        // exactly like enablement itself, so it naturally extends to course/category scoping.
        // Default 0 (off, cost-sensitive); only effective while the row is enabled.
        $table = new xmldb_table('bx_agent_search_scope');
        $field = new xmldb_field('includefiles', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'enabled');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026070205, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026070701) {
        // Shorten table suffixes so the local_wizard sync generator can map tables by pure
        // prefix swap: every suffix after bx_agent_ must stay <= 15 chars, or the generated
        // local_wizard_* name would exceed Moodle's 28-char table name limit. Data-preserving
        // rename (benchmark history matters in dev).
        $renames = [
            'bx_agent_benchmark_runs' => 'bx_agent_bm_runs',
            'bx_agent_benchmark_scenarios' => 'bx_agent_bm_scenarios',
            'bx_agent_benchmark_baselines' => 'bx_agent_bm_baselines',
            'bx_agent_benchmark_metrics' => 'bx_agent_bm_metrics',
            'bx_agent_sitesearch_state' => 'bx_agent_search_state',
        ];
        foreach ($renames as $oldname => $newname) {
            $table = new xmldb_table($oldname);
            if ($dbman->table_exists($table) && !$dbman->table_exists(new xmldb_table($newname))) {
                $dbman->rename_table($table, $newname);
            }
        }

        upgrade_plugin_savepoint(true, 2026070701, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026070703) {
        // XMLDB forbids CHAR NOT NULL columns with '' as default (debugging noise on every
        // structure load, e.g. plugin uninstall). Both columns are always set explicitly on
        // insert, so the default is dropped entirely instead of inventing a meaningful one.
        $table = new xmldb_table('bx_agent_embeddings');
        if ($dbman->table_exists($table)) {
            $owner = new xmldb_field('owner', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'area');
            if ($dbman->field_exists($table, $owner)) {
                $dbman->change_field_default($table, $owner);
            }
            $identityhash = new xmldb_field(
                'identityhash',
                XMLDB_TYPE_CHAR,
                '40',
                null,
                XMLDB_NOTNULL,
                null,
                null,
                'contenthash'
            );
            if ($dbman->field_exists($table, $identityhash)) {
                // The identityhash column is part of the reuse index, and Moodle's DDL refuses to
                // modify a field a key depends on. Drop the index, change the default, restore it.
                $reuseindex = new xmldb_index(
                    'reuse_idx',
                    XMLDB_INDEX_NOTUNIQUE,
                    ['area', 'emodel', 'edims', 'generation', 'identityhash']
                );
                $hadreuseindex = $dbman->index_exists($table, $reuseindex);
                if ($hadreuseindex) {
                    $dbman->drop_index($table, $reuseindex);
                }
                $dbman->change_field_default($table, $identityhash);
                if ($hadreuseindex) {
                    $dbman->add_index($table, $reuseindex);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026070703, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026082000) {
        // Re-seed the planner prompt settings (Wunderbyte-GmbH/Wunderbyte-GmbH#2200): selection and
        // construction were historically seeded from the SAME planner/routing template, which put the
        // routing cascade and planned_steps rules into the constructor prompt (self-contradictory with
        // its constructor-only contract, #2199). Settings seed only once (get_config === false), so
        // code-default changes never reach existing instances without this migration. Only values that
        // exactly match a known historical seed are replaced — admin customizations stay untouched.
        $normalize = static function (string $value): string {
            return trim(str_replace(["\r\n", "\r"], "\n", $value));
        };

        // Historical seed V1: the shared planner template as seeded from 2026-07 on (incl. the
        // thread-542 scope paragraph and the old dual-phase wording in decision rule 4).
        $legacyseedv1 = <<<'PROMPT'
You are an AI agent planner.

ACTION-SPECIFIC GUIDANCE FOR ROUTING:
- Keep instructions compact and action-oriented. Do not over-explain.
- Use this strict decision order (first matching rule wins):
  1) already completed outcome in completed_commands/completed_observations
      -> response_type=sufficient, commands=[].
  2) explicit confirmation of an already pending action
      -> response_type=confirm_pending, commands=[].
  3) missing required input for the selected skill
      -> response_type=clarification, commands=[].
  4) grounded mutating intent
      -> response_type=skill_call (selector) or confirmation_request (constructor), commands non-empty.
  5) grounded read-only intent
      -> response_type=skill_call, commands non-empty.
  6) multi-step request, first turn, no [PENDING PLANNED STEPS] in context
      -> select the first skill + set planned_steps=[{intent of step 2},{intent of step 3},...].
- CONTEXT-AWARE PLANNING: Action skills resolve their own target via their query field (optionquery,
  coursequery, userquery, ...). For "do X for/in <named target>", select the ACTION skill directly and
  pass the named target as its query — do NOT add a preceding search/resolution/lookup step (this
  includes a target that is the current SYSTEM_RUNTIME context; e.g. "create a quiz in this course" ->
  the quiz skill now, NOT course.search_courses first; "book Anna into the First Aid course" ->
  book_users now, NOT a search step first). Use a search/list skill ONLY when the user explicitly wants
  to find or list something, never as a means to an action. A skill that cannot resolve its target will
  ask for clarification itself.
- Use only exact skill names from the SKILL CATALOG. Never invent aliases.
- If a matching skill appears in UNAVAILABLE SKILLS, do NOT execute it and do NOT invent your own wording.
  When its description is prefixed with "[Locked: requires the Wunderbyte PRO license or subscription - <url>]",
  respond (clarification) that this task is only available with a Wunderbyte PRO license or a Wunderbyte
  subscription, and include that exact <url> from the marker as a markdown link labelled Get Pro, i.e.
  [Get Pro](<url>). Never reveal the internal skill name and never tell the user to try again later or
  contact support. If it is unavailable for any other reason (no such marker), just state that it exists
  but is currently not executable.
- Do not emit unavailable skills in commands.
- Never re-emit an already completed action signature (same skill + normalized input intent).
- A completed action does NOT cover a request that adds a NEW scope or target — a named activity,
  course, option or person that the completed input did not contain. That is a NEW action:
  emit the command again including the new scope (thread 542: "search X" completed does not
  answer "search X in activity Y" — search again with the activity).

GROUNDING (prefer skills over free-form answers):
- If a skill in the SKILL CATALOG can fulfil OR answer the request, select it (response_type=skill_call)
  instead of answering from your own knowledge. This explicitly includes questions about your own
  capabilities or which actions exist: prefer the catalog's introspection/listing skill over composing
  such a list yourself (a self-composed list is partial and goes stale).
- Only answer directly (response_type=sufficient) for pure conversation/acknowledgement, or when no
  catalog skill applies.

SKILL CONTRACT FIRST (highest priority):
- Follow skill-level routing hints from the SKILL CATALOG (WHEN, REQUIRED, TRIGGERS).
- Keep global routing generic; do not hardcode special behavior for individual skill names.
PROMPT;

        // Historical seed V2: the same template as seeded before the thread-542 scope paragraph was
        // added (still live on instances seeded before that change, e.g. the local dev VM).
        $legacy542block = "\n- A completed action does NOT cover a request that adds a NEW scope or target — a named activity,\n"
            . "  course, option or person that the completed input did not contain. That is a NEW action:\n"
            . "  emit the command again including the new scope (thread 542: \"search X\" completed does not\n"
            . "  answer \"search X in activity Y\" — search again with the activity).";
        $legacyseedv2 = str_replace($legacy542block, '', $legacyseedv1);

        $legacyseeds = [$normalize($legacyseedv1), $normalize($legacyseedv2)];

        $orchestratorclass = 'bookingextension_agent\local\wizard\orchestrator';
        if (class_exists($orchestratorclass)) {
            $newselectiondefault = $orchestratorclass::get_default_initial_prompt_template_for_action(
                \core_ai\aiactions\summarise_text::class
            );
            $newconstructordefault = $orchestratorclass::get_default_constructor_prompt_template();

            $selection = (string)get_config('bookingextension_agent', 'aiinitialprompt_selection');
            if (in_array($normalize($selection), $legacyseeds, true)) {
                set_config('aiinitialprompt_selection', $newselectiondefault, 'bookingextension_agent');
            }

            $construction = (string)get_config('bookingextension_agent', 'aiinitialprompt_parameter_construction');
            if (in_array($normalize($construction), $legacyseeds, true)) {
                set_config('aiinitialprompt_parameter_construction', $newconstructordefault, 'bookingextension_agent');
            }
        }

        upgrade_plugin_savepoint(true, 2026082000, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026092201) {
        // F72 (baseline runs 25-27): the debug source of a constructor repair call is up to 102 characters
        // ("...|em=cached_applied|tk=1|rq=0|ex=0|rp=1"), the column held 100. The insert threw
        // dml_write_exception and, because nothing caught it, the user's turn died on a debug-log write.
        $table = new xmldb_table('bx_agent_ai_llm_debug');
        $field = new xmldb_field('source', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'contextid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026092201, 'bookingextension', 'agent');
    }

    // Idempotent on every upgrade: a changed default planner prompt reaches the stored config
    // that is actually read, unless an admin has edited it. Without this every wave had to add
    // its superseded seed to the list above by hand, and forgetting looked like the model
    // ignoring a rule it had never been given.
    \bookingextension_agent\local\prompt_seed_sync::apply();

    // Idempotent on every upgrade: archetype edits on existing capabilities never deploy on
    // their own (Moodle applies defaults only at capability creation).
    \bookingextension_agent\local\capability_defaults_sync::apply();

    return true;
}
