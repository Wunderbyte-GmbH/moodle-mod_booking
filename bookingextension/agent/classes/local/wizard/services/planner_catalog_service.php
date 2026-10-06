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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use core_text;
use bookingextension_agent\local\wizard\contracts\skill_family_contract;
use bookingextension_agent\local\wizard\skill_contract_validator;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Planner skill-catalog shaping: slim / sanitize / render / compact / filter.
 *
 * Extracted verbatim from the orchestrator (orchestrator split, planner-catalog seam).
 * Pure catalog transformations; the only collaborator is assistant_state_guidance_service
 * (string-list normalisation for trigger examples). The orchestrator keeps thin delegating
 * methods for the externally-called entries so call sites are unchanged. Behaviour-preserving.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class planner_catalog_service {
    /** @var int Characters of the description a selector card carries. */
    private const CARD_DESCRIPTION_CAP = 240;

    /** @var int Up to here a description is kept whole rather than losing its last sentence. */
    private const CARD_DESCRIPTION_TOLERANCE = 288;

    /**
     * Discovery meta-skills (registry introspection + RAG fallback). They are only meaningful while
     * the catalog is a SEMANTIC SUBSET (embed_topk): there they let the planner widen beyond the
     * top-k. Once the FULL/static catalog is present (slim_all / slim_family) they would only imply
     * non-existent "hidden" skills, so they are excluded from the full slim catalog.
     */
    public const DISCOVERY_META_SKILLS = ['wizard.list_skills', 'wizard.search_skills'];

    /** @var assistant_state_guidance_service */
    private assistant_state_guidance_service $assistantsummariesvc;

    /**
     * Constructor.
     *
     * @param assistant_state_guidance_service $assistantsummariesvc
     */
    public function __construct(assistant_state_guidance_service $assistantsummariesvc) {
        $this->assistantsummariesvc = $assistantsummariesvc;
    }

    /**
     * Reduce skill catalog entries to planner-facing routing metadata only.
     *
     * @param array $skillcatalog
     * @return array
     */
    public function slim_prompt_catalog_for_planner(array $skillcatalog): array {
        $slimcatalog = [];

        foreach ($skillcatalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $skillname = (string)($entry['skill'] ?? '');
            if ($skillname === '') {
                continue;
            }

            $newentry = [
                'skill' => $skillname,
                'readonly' => (bool)($entry['readonly'] ?? false),
                'intent' => (string)($entry['intent'] ?? ''),
                'minimal_input' => (array)($entry['minimal_input'] ?? []),
                'required_input' => (array)($entry['required_input'] ?? []),
                'accepts_empty_input' => (bool)($entry['accepts_empty_input'] ?? true),
                'required_groups' => array_values((array)($entry['required_groups'] ?? [])),
                'example_input' => $this->compact_catalog_example_input((array)($entry['example_input'] ?? [])),
                'description' => $this->compact_catalog_description((string)($entry['description'] ?? '')),
                'is' => trim((string)($entry['is'] ?? '')),
                'not' => trim((string)($entry['not'] ?? '')),
                'when' => trim((string)($entry['when'] ?? '')),
                'message_triggers' => $this->compact_catalog_message_triggers((array)($entry['message_triggers'] ?? [])),
            ];

            if (empty($newentry['example_input']) || $newentry['minimal_input'] == $newentry['example_input']) {
                unset($newentry['example_input']);
            }

            $slimcatalog[] = $newentry;
        }

        return $slimcatalog;
    }

    /**
     * Drop the discovery meta-skills ({@see self::DISCOVERY_META_SKILLS}) from a catalog.
     *
     * Used for the full/static catalog (slim_all / slim_family) and for the full skill listing that
     * wizard.list_skills produces: once the planner already sees every skill, advertising "list/search
     * skills" only implies more hidden skills that do not exist.
     *
     * @param mixed[] $catalog
     * @return array[]
     */
    public function exclude_discovery_meta_skills(array $catalog): array {
        return array_values(array_filter(
            $catalog,
            static function ($entry): bool {
                if (!is_array($entry)) {
                    return false;
                }
                return !in_array((string)($entry['skill'] ?? ''), self::DISCOVERY_META_SKILLS, true);
            }
        ));
    }

    /**
     * Keep only planner-relevant fields before runtime catalog prompt injection.
     *
     * @param array[] $catalog
     * @return array[]
     */
    public function sanitize_runtime_catalog_for_prompt(array $catalog): array {
        $sanitized = [];
        $livecontracts = $this->live_contract_lookup();

        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $skill = trim((string)($entry['skill'] ?? ''));
            if ($skill === '') {
                continue;
            }

            // Card metadata (minimal_input/example_input/intent/readonly/description/message_triggers)
            // is sourced LIVE from the skill registry, never from the embeddings-catalog row, which is
            // only a frozen snapshot from the last rebuild. The row identifies WHICH skill matched; the
            // planner-facing contract must reflect the CURRENT skill schema (e.g. a newly added cross-
            // context targeting field). Without this, a skill change that does not alter the embedding
            // anchor text (description/utterances) is invisible to the catalog until a manual rebuild.
            $live = $livecontracts[$skill] ?? null;
            if ($live !== null) {
                $minimalinput = (array)($live['minimal_input'] ?? []);
                $exampleinputraw = (array)($live['example_input'] ?? []);
                $triggerraw = (array)($live['message_triggers'] ?? []);
                $intent = trim((string)($live['intent'] ?? ''));
                $readonly = !empty($live['readonly']);
                $description = (string)($live['description'] ?? '');
                $is = (string)($live['is'] ?? '');
                $not = (string)($live['not'] ?? '');
                $when = (string)($live['when'] ?? '');
            } else {
                // A row whose skill is no longer registered has no live metadata (the CSV stores none),
                // so emit a minimal entry rather than fabricating fields.
                $minimalinput = [];
                $exampleinputraw = [];
                $triggerraw = [];
                $intent = '';
                $readonly = false;
                $description = '';
                $is = '';
                $not = '';
                $when = '';
            }

            $row = [
                'skill' => $skill,
                'readonly' => $readonly,
                'intent' => $intent,
                'minimal_input' => $minimalinput,
                'required_input' => (array)($live['required_input'] ?? ($entry['required_input'] ?? [])),
                'accepts_empty_input' => (bool)($live['accepts_empty_input'] ?? ($entry['accepts_empty_input'] ?? true)),
                'required_groups' => array_values((array)($live['required_groups'] ?? ($entry['required_groups'] ?? []))),
                'description' => $this->compact_catalog_description($description),
                'is' => trim($is),
                'not' => trim($not),
                'when' => trim($when),
                'message_triggers' => $this->compact_catalog_message_triggers($triggerraw),
            ];

            $exampleinput = $this->compact_catalog_example_input($exampleinputraw);
            if (!empty($exampleinput) && $exampleinput !== $minimalinput) {
                $row['example_input'] = $exampleinput;
            }

            $sanitized[] = $row;
        }

        return $sanitized;
    }

    /**
     * Live skill-name → prompt-contract lookup from the registry.
     *
     * The planner catalog's card metadata must reflect the current skill schema, not a frozen
     * embeddings-catalog snapshot, so {@see self::sanitize_runtime_catalog_for_prompt()} re-joins it
     * live by skill name. Returns an empty map when the registry is unavailable (defensive).
     *
     * @return array
     */
    private function live_contract_lookup(): array {
        try {
            $registry = skill_registry_factory::get_default();
        } catch (\Throwable $e) {
            return [];
        }

        $lookup = [];
        foreach ($registry->get_all_prompt_contracts() as $contract) {
            if (!is_array($contract)) {
                continue;
            }
            $skill = trim((string)($contract['skill'] ?? ''));
            if ($skill !== '') {
                $lookup[$skill] = $contract;
            }
        }

        return $lookup;
    }

    /**
     * Decode JSON array/object payload safely.
     *
     * @param string $json
     * @return array
     */
    public function decode_catalog_json_array(string $json): array {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Render the skill catalog as compact plain text instead of JSON.
     *
     * Each skill gets a heading line plus WHEN / REQUIRED / OPTIONAL / TRIGGERS lines.
     * This is ~75% more token-efficient than JSON and easier for the LLM to scan.
     *
     * @param array $catalog
     * @return string
     */
    public function render_catalog_as_text(array $catalog): string {
        $blocks = [];

        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $skillname = trim((string)($entry['skill'] ?? ''));
            if ($skillname === '') {
                continue;
            }

            $readonly = !empty($entry['readonly']) && (string)($entry['readonly']) !== '0';
            $mutability = $readonly ? 'readonly' : 'mutating';
            $lines = [];
            $lines[] = "## {$skillname} [{$mutability}]";

            // The description arrives already shortened sentence-aware by compact_catalog_description().
            // A second, hard cut here used to slice it mid-word at 160 characters, which is what the
            // selector actually received — and it threw away exactly the sentences ten skill classes place
            // between 160 and 240 on purpose to separate themselves from a sibling (#471, #472, #473, #2423).
            // Re-compacting is idempotent for an already compacted string and keeps a raw entry safe.
            // See planner_catalog_truncation_test::test_rendered_card_keeps_the_compacted_description.
            $description = $this->compact_catalog_description((string)($entry['description'] ?? ''));
            if ($description !== '') {
                $lines[] = $description;
            }

            // IS: / NOT: the sibling discrimination (#2453). It used to live inside the description and
            // was therefore embedded as anchor #0, where a negation does not survive the vector: the
            // sentence "not X" sits next to "X" and names the competitor, attracting the very queries it
            // was written to repel. Here it reaches the selector — which DOES read negation — without
            // touching retrieval. Both lines also travel in the slim catalogue: there every card is in the
            // prompt at once, so confusability is highest and nothing pre-filters.
            $is = trim((string)($entry['is'] ?? ''));
            if ($is !== '') {
                $lines[] = 'IS: ' . $is;
            }
            $not = trim((string)($entry['not'] ?? ''));
            if ($not !== '') {
                $lines[] = 'NOT: ' . $not;
            }

            // WHEN: the skill's declared when-text; without one, the first message trigger's description (#2546).
            $triggers = (array)($entry['message_triggers'] ?? []);
            $when = trim((string)($entry['when'] ?? ''));
            if ($when === '') {
                $firsttrigger = !empty($triggers) && is_array($triggers[0]) ? (array)$triggers[0] : [];
                $when = trim(preg_replace('/\s+/', ' ', (string)($firsttrigger['description'] ?? '')) ?? '');
            }
            if ($when !== '') {
                $lines[] = 'WHEN: ' . core_text::substr($when, 0, 180);
            }

            // REQUIRED: only what the SCHEMA requires. Until run 15 this line printed minimal_input — the fields
            // worth showing — so optional fields read as mandatory and the selector asked the user for them
            // instead of routing (rule "missing required input -> clarification"). See
            // catalog_required_reflects_schema_test.
            // "Requires nothing" must be SAID, not expressed by an absent line. Decision rule 3 of the
            // selector prompt ("missing required input -> clarification") is stated in every prompt, so the
            // model read the silence as ignorance and invented a mandatory field (run 17, DMD-4: it called
            // assignmentid mandatory although the schema marks it optional).
            // The REQUIRED line has three truthful forms, and every skill should produce one of them:
            // schema flags      -> "REQUIRED: a, b"
            // gate alternatives -> "REQUIRED: one of a | b"   (required_groups)
            // nothing at all    -> "REQUIRED: none"           (only when the gate really accepts {})
            // Before 2026-09-20 the line came from the flags alone. That first made it LIE for the sixteen
            // skills that gate their input in check_structure() ("none" although {} is rejected, which tipped
            // EU-2 in run 19), and once the lie was removed it made the line fall SILENT for them — which cost
            // GOD-1 and GOD-2 in run 21, because the model reads a missing line as ignorance, not as freedom.
            $required = array_filter(array_map('strval', (array)($entry['required_input'] ?? [])));
            $groups = [];
            foreach ((array)($entry['required_groups'] ?? []) as $group) {
                $fields = array_values(array_filter(array_map('strval', (array)$group)));
                if (count($fields) > 1) {
                    $groups[] = 'one of ' . implode(' | ', $fields);
                }
            }
            if (empty($required) && empty($groups) && !empty($entry['accepts_empty_input'])) {
                $lines[] = 'REQUIRED: none';
            }
            if (!empty($required) || !empty($groups)) {
                $lines[] = 'REQUIRED: ' . implode(', ', array_merge(array_values($required), $groups));
            }

            // OPTIONAL parameters are deliberately NOT listed in the selection catalog: selection must
            // not construct parameters (the selector picks exactly one skill and omits input), so optional
            // field names carry no routing value and are pure token noise across all skills every turn.
            // The full parameter schema (incl. optional fields, types, descriptions) is provided separately
            // to the constructor as JSON for the single selected skill (see PHASE_PARAMETER_CONSTRUCTION).

            // TRIGGERS: trigger IDs as readable keywords (strip namespace prefix for brevity).
            $triggerids = [];
            foreach ($triggers as $trigger) {
                if (!is_array($trigger)) {
                    continue;
                }
                $id = trim((string)($trigger['id'] ?? ''));
                if ($id !== '') {
                    // Strip module prefix for brevity.
                    // (e.g. "mod_booking.create_option_canonical_fallback" → "create_option_canonical_fallback").
                    $shortid = (string)preg_replace('/^[a-z_]+\./', '', $id);
                    $triggerids[] = $shortid;
                }
            }
            if (!empty($triggerids)) {
                $lines[] = 'TRIGGERS: ' . implode(' | ', array_slice($triggerids, 0, 5));
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Compact the skill catalog description to a shorter length.
     *
     * @param string $description The raw description.
     * @return string The compacted description.
     */
    public function compact_catalog_description(string $description): string {
        $normalized = trim(preg_replace('/\s+/', ' ', $description) ?? $description);
        if ($normalized === '') {
            return '';
        }

        if (core_text::strlen($normalized) <= self::CARD_DESCRIPTION_CAP) {
            return $normalized;
        }

        // A description a few characters over the cap loses its whole last sentence, and that sentence
        // is often the one somebody wrote to separate the skill from a sibling: a survey on 2026-09-22
        // found 58 of 83 descriptions over the cap, among them list_option_fields (270, losing "Not the
        // built-in option properties"), create_option_field (278) and remember (377). Some miss by very
        // little - 249 characters costing 51, 277 costing 57, 290 costing 60.
        //
        // Dropping a sentence because a text is nine characters too long is a cliff, not a budget. Within
        // a fifth over the cap the description stays whole; beyond that the cut still applies, because a
        // card of 841 characters has a different problem and slim_all has to carry every card at once.
        // Measured cost of the tolerance across the whole catalogue: about 930 characters.
        if (core_text::strlen($normalized) <= self::CARD_DESCRIPTION_TOLERANCE) {
            return $normalized;
        }

        // Sentence-aware truncation (C1, thread 589): a hard character cap can slice inside a
        // sentence and invert the card's meaning — the live course.create_course card was cut
        // right after "asks which category to use unless", reading as an instruction to ASK
        // instead of ACT. Cut at the LAST sentence boundary within the cap instead.
        $window = core_text::substr($normalized, 0, self::CARD_DESCRIPTION_CAP);
        if (preg_match('/^(.*[.!?]["\'\)\]]*)(?:\s|$)/us', $window, $matches)) {
            return rtrim($matches[1]);
        }

        // No sentence boundary within the window: fall back to a word boundary + ellipsis so the
        // card at least never breaks a word.
        $wordsafe = preg_replace('/\s+\S*$/u', '', core_text::substr($normalized, 0, 237));
        return rtrim(($wordsafe ?? '') !== '' ? $wordsafe : core_text::substr($normalized, 0, 237)) . '…';
    }

    /**
     * Keep example_input as a compact property-name list for routing hints.
     *
     * This preserves only explicitly declared example fields while avoiding
     * token-heavy concrete sample payloads.
     *
     * @param array $exampleinput
     * @return string[]
     */
    public function compact_catalog_example_input(array $exampleinput): array {
        $keys = [];

        foreach (array_keys($exampleinput) as $key) {
            $name = trim((string)$key);
            if ($name !== '') {
                $keys[] = $name;
            }
        }

        $keys = array_values(array_unique($keys));
        if (empty($keys)) {
            return [];
        }

        // Keep enough fields so slotbooking/selflearning skill variants do not
        // lose critical execution hints (e.g. slot_day_* or duration fields).
        return array_slice($keys, 0, 12);
    }

    /**
     * Drop verbose trigger examples and keep compact id + short description only.
     *
     * @param array $triggers
     * @return array[]
     */
    public function compact_catalog_message_triggers(array $triggers): array {
        $compact = [];

        foreach ($triggers as $trigger) {
            if (!is_array($trigger)) {
                continue;
            }

            $id = trim((string)($trigger['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $description = trim((string)($trigger['description'] ?? ''));
            $description = trim(preg_replace('/\s+/', ' ', $description) ?? $description);

            $row = ['id' => $id];
            if ($description !== '') {
                $row['description'] = core_text::substr($description, 0, 320);
            }

            $examples = (array)($trigger['examples'] ?? []);
            if (!empty($examples)) {
                $row['examples'] = $this->assistantsummariesvc->normalize_nonempty_string_list($examples, 2, 160);
                if (empty($row['examples'])) {
                    unset($row['examples']);
                }
            }

            $compact[] = $row;
        }

        return $compact;
    }

    /**
     * Keep only catalog entries whose skill family is in selected discovery families.
     *
     * @param array[] $catalog
     * @param string[] $selectedfamilies
     * @return array[]
     */
    public function filter_catalog_by_selected_families(array $catalog, array $selectedfamilies): array {
        if (empty($catalog) || empty($selectedfamilies)) {
            return $catalog;
        }

        $allow = [];
        foreach ($selectedfamilies as $family) {
            $normalized = skill_family_contract::normalize_family((string)$family);
            if ($normalized !== skill_family_contract::DEFAULT_FAMILY) {
                $allow[$normalized] = true;
            }
        }

        if (empty($allow)) {
            return [];
        }

        $filtered = [];
        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $skillname = trim((string)($entry['skill'] ?? ''));
            if ($skillname === '') {
                continue;
            }

            $family = skill_family_contract::from_skill_name($skillname);
            if (!isset($allow[$family])) {
                continue;
            }

            $filtered[] = $entry;
        }

        return array_values($filtered);
    }

    /**
     * Whether the active skill catalog is static across turns (no embeddings / slim_all family).
     *
     * @param string $catalogselectionmode the resolved catalog selection mode
     * @return bool
     */
    public function catalog_mode_is_static(string $catalogselectionmode): bool {
        return str_starts_with($catalogselectionmode, 'slim');
    }

    /**
     * Partition prompt contracts by their executability verdicts into the selectable
     * catalog and the UNAVAILABLE (PRO-locked) catalog; every other denied skill is
     * dropped entirely.
     *
     * The catalog gate consumes the SAME evaluator verdicts the executor backstop
     * re-derives later, so the two gates cannot disagree (issue #2223: an inactive
     * skill was selectable, was actively offered, and only failed at governance):
     *  - allow                → selectable,
     *  - deny requires_pro    → UNAVAILABLE with the Get-Pro lock notice (upsell),
     *  - every other deny     → hidden (inactive, missing capability, not registered,
     *                           runtime disabled, invalid context) — the planner must
     *                           never see, offer or plan a skill the user cannot run.
     * A contract without a verdict is treated as denied (fail closed).
     *
     * @param array[] $contracts prompt contracts (all registered skills)
     * @param array $verdicts skillname-indexed results of skill_executability_evaluator::evaluate_all_skills()
     * @return array{0: array[], 1: array[]} [selectable, locked]
     */
    public function partition_prompt_contracts_by_executability(array $contracts, array $verdicts): array {
        $selectable = [];
        $locked = [];
        $upgradeurl = trim((string)get_string('aitrial_pro_license_url', 'bookingextension_agent'));
        // Prepended (not appended): the catalog renderer truncates descriptions,
        // and the lock notice must survive that.
        $lockednote = '[Locked: requires the Wunderbyte PRO license or subscription'
            . ($upgradeurl !== '' ? ' — ' . $upgradeurl : '')
            . '] ';

        foreach ($contracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            $skillname = trim((string)($contract['skill'] ?? ''));
            $verdict = (array)($verdicts[$skillname] ?? []);
            if ((string)($verdict['executable_state'] ?? 'deny') === 'allow') {
                $selectable[] = $contract;
                continue;
            }

            if ((string)($verdict['deny_reason'] ?? '') === skill_contract_validator::DENY_REQUIRES_PRO) {
                $contract['description'] = trim($lockednote . trim((string)($contract['description'] ?? '')));
                $locked[] = $contract;
            }
        }

        return [$selectable, $locked];
    }

    /**
     * Drop catalog rows whose skill is not in the allowed skill-name set.
     *
     * Availability filter for catalog sources that do not originate from the partitioned
     * contract list — embeddings top-k retrieval rows and cached discovery catalogs. The
     * embeddings index is deliberately availability-agnostic (it is global, while
     * executability is per user/context), so retrieval output must be intersected with
     * the selectable set before it reaches the planner; a stale index row for a removed
     * or disabled skill must never resurface as a selectable catalog entry.
     *
     * @param array[] $rows catalog/retrieval rows carrying a 'skill' key
     * @param array $allowedskillnames skill names allowed to stay (values or keys of the set)
     * @return array[]
     */
    public function filter_catalog_rows_to_skills(array $rows, array $allowedskillnames): array {
        $allowed = [];
        foreach ($allowedskillnames as $key => $value) {
            $name = is_string($value) ? $value : (string)$key;
            $name = trim($name);
            if ($name !== '') {
                $allowed[$name] = true;
            }
        }

        $filtered = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!isset($allowed[trim((string)($row['skill'] ?? ''))])) {
                continue;
            }
            $filtered[] = $row;
        }

        return array_values($filtered);
    }

    /**
     * Resolve a deterministic namespace hint from prompt contracts.
     *
     * @param array[] $promptcontracts
     * @return string
     */
    public function resolve_namespace_hint_from_prompt_contracts(array $promptcontracts): string {
        $counts = [];
        foreach ($promptcontracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            $namespace = trim((string)($contract['namespace'] ?? ''));
            if ($namespace === '') {
                continue;
            }

            $counts[$namespace] = (int)($counts[$namespace] ?? 0) + 1;
        }

        if (empty($counts)) {
            return '';
        }

        arsort($counts, SORT_NUMERIC);
        return (string)array_key_first($counts);
    }
}
