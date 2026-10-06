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

namespace mod_booking\local\wizard\booking\support;

use context_module;
use core_text;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\local\templaterule;
use moodle_url;
use stdClass;

/**
 * Support service for AI booking-rules tasks.
 *
 * Uses the same rules handler pipeline as the dynamic AJAX form
 * (rules_info::set_data_for_form + rules_info::save_booking_rule).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_rules_agent_service {
    /** Cap for the rules offered when a lookup misses; the current and the site-wide rules are never dropped by it. */
    public const MAX_RULE_CANDIDATES = 50;

    /**
     * Resolve module context id from booking cmid.
     *
     * @param int $cmid
     * @return int
     */
    public function get_module_contextid(int $cmid): int {
        $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
        return (int)context_module::instance((int)$cm->id)->id;
    }

    /**
     * Return edit page link for the current booking module.
     *
     * @param int $cmid
     * @return string
     */
    public function build_rules_link(int $cmid): string {
        if ($cmid <= 0) {
            // Site-level rules overview: edit_rules.php resolves to the system context without a cmid.
            return (new moodle_url('/mod/booking/edit_rules.php'))->out(false);
        }
        return (new moodle_url('/mod/booking/edit_rules.php', ['cmid' => $cmid]))->out(false);
    }

    /**
     * List available rule templates.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list_templates(): array {
        $raw = templaterule::get_template_rules();
        $items = [];

        foreach ($raw as $id => $name) {
            $templateid = (int)$id;
            if ($templateid === 0) {
                continue;
            }
            $items[] = [
                'templateid' => $templateid,
                'name' => trim((string)$name),
                'source' => $templateid < 0 ? 'builtin' : 'saved',
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string)$a['name'], (string)$b['name']);
        });

        return $items;
    }

    /**
     * Resolve a template by id or by query.
     *
     * @param int $templateid
     * @param string $templatequery
     * @return array<string,mixed>
     */
    public function resolve_template(int $templateid = 0, string $templatequery = ''): array {
        $templates = $this->list_templates();
        if (empty($templates)) {
            return ['status' => 'error', 'message' => get_string('agent_booking_rules_no_templates_available', 'mod_booking')];
        }

        if ($templateid !== 0) {
            foreach ($templates as $template) {
                if ((int)$template['templateid'] === $templateid) {
                    return ['status' => 'ok', 'template' => $template];
                }
            }
            return ['status' => 'error', 'message' => get_string('agent_booking_rules_templateid_not_found', 'mod_booking')];
        }

        $query = trim($templatequery);
        if ($query === '') {
            return ['status' => 'error', 'message' => get_string('agent_booking_rules_provide_templateid_or_query', 'mod_booking')];
        }
        // A query that carries nothing but an id is the id the planner copied from the candidate
        // list ("-12", "templateid=-12"): resolve it as such, a name lookup can never match it (#2402).
        if (preg_match('/^(?:templateid\s*=\s*)?(-?\d+)$/i', $query, $matches)) {
            return $this->resolve_template((int)$matches[1], '');
        }

        $exact = [];
        $contains = [];
        $needle = core_text::strtolower($query);
        $normalizedneedle = $this->normalize_template_lookup_token($query);
        $compactneedle = str_replace(' ', '', $normalizedneedle);
        foreach ($templates as $template) {
            $name = trim((string)($template['name'] ?? ''));
            $hay = core_text::strtolower($name);
            $normalizedhay = $this->normalize_template_lookup_token($name);
            $compacthay = str_replace(' ', '', $normalizedhay);

            $isexact = ($hay === $needle)
                || ($normalizedhay !== '' && $normalizedhay === $normalizedneedle)
                || ($compacthay !== '' && $compacthay === $compactneedle);
            if ($isexact) {
                $exact[] = $template;
            } else if (
                ($hay !== '' && strpos($hay, $needle) !== false)
                || ($normalizedhay !== '' && $normalizedneedle !== '' && strpos($normalizedhay, $normalizedneedle) !== false)
                || ($compacthay !== '' && $compactneedle !== '' && strpos($compacthay, $compactneedle) !== false)
            ) {
                $contains[] = $template;
            }
        }

        // Wave 32: only the template's own name resolves. A FRAGMENT of a name is the model's word, not a choice:
        // "Bestätigung" hit only "Template - Bestätigung Wartelistenplatz" and CRT-1 (L42sol, thread 12933) staged
        // the WAITING-LIST template for "a confirmation after every booking"; "reminder" hit the per-session and the
        // teacher reminder and hid "Notification n days before start" (CRT-2, threads 12196/12619: L40 staged the
        // TEACHER template, L41 asked). The same holds for the fuzzy similarity pick. Fragment hits and the best
        // fuzzy score are listed FIRST, every other template follows, each with what the rule does - the model
        // chooses by the rule's attributes, the code never by the word.
        if (count($exact) === 1) {
            return ['status' => 'ok', 'template' => $exact[0]];
        }
        $hits = !empty($exact) ? $exact : $contains;
        if (empty($hits)) {
            $scored = [];
            foreach ($templates as $template) {
                $name = trim((string)($template['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $scored[] = [
                    'template' => $template,
                    'score' => $this->score_template_similarity($query, $name),
                ];
            }
            usort($scored, static function (array $a, array $b): int {
                return ($b['score'] <=> $a['score']);
            });
            $topscore = (float)($scored[0]['score'] ?? 0.0);
            $secondscore = (float)($scored[1]['score'] ?? 0.0);
            if ($topscore >= 0.62 && ($topscore - $secondscore) >= 0.08) {
                $hits = [(array)$scored[0]['template']];
            }
        }
        $hitids = array_map(static fn(array $item): int => (int)($item['templateid'] ?? 0), $hits);
        $candidates = $this->template_candidates($hitids);

        if (!empty($candidates)) {
            return [
                'status' => 'ambiguity',
                // Review w32s-b1: one localized text for every non-exact case. "Multiple templates match" was wrong
                // for a single fragment hit, and the English miss text named the schema field templateid (HARD RULE
                // 2026-09-14, point 3); the name hits are marked structurally (namematch) in the candidates.
                'message' => get_string('agent_booking_rules_choose_template', 'mod_booking'),
                'candidates' => $candidates,
            ];
        }

        return ['status' => 'error', 'message' => 'No matching template found.'];
    }

    /**
     * Every template as a choice: the given ids first, then the rest, each with what the rule does (wave 32).
     *
     * "Choices, not an error" (George, 2026-09-24): a template the resolver cannot pin is chosen by the model from
     * the list, and the model matches by the rule's attributes (trigger, recipients, date field, days) - facts of the
     * template record, never words of the request. The templates are few (built-ins plus saved templates), the cap of
     * the rule candidates (50) is only a safety net.
     *
     * @param array $firstids Template ids to list first (e.g. the ones whose name matched a query).
     * @param int $limit Safety cap.
     * @return array templateid, name and the attributes of template_attributes(), one row per template.
     */
    public function template_candidates(array $firstids = [], int $limit = self::MAX_RULE_CANDIDATES): array {
        $first = [];
        $rest = [];
        $firstids = array_map('intval', $firstids);
        foreach ($this->list_templates() as $template) {
            $templateid = (int)($template['templateid'] ?? 0);
            if ($templateid >= 0) {
                // Saved rules marked as template: both rule skills accept only the built-ins (negative ids), so
                // offering one would be a choice that fails on confirm. They were hidden before by the cap of 12
                // (strcmp sorts them after "Template - ..."), now they are left out explicitly.
                continue;
            }
            $candidate = array_merge(
                ['templateid' => $templateid, 'name' => (string)($template['name'] ?? '')],
                $this->template_attributes($templateid)
            );
            $position = array_search($templateid, $firstids, true);
            if ($position !== false) {
                $candidate['namematch'] = 1;
                $first[$position] = $candidate;
            } else {
                $rest[] = $candidate;
            }
        }
        ksort($first);
        return array_slice(array_merge(array_values($first), $rest), 0, max(1, $limit));
    }

    /**
     * What a template does, read from its record: rule type, trigger event, recipients, date field and days.
     *
     * Built-in templates (negative ids) come from their template class, saved templates (positive ids) from
     * booking_rules. Values are the stored identifiers (e.g. rule_daysbefore, select_teacher_in_bo,
     * bookingoption_cancelled) - structure, no wording. Unknown parts are left out.
     *
     * @param int $templateid Template id.
     * @return array<string,int|string>
     */
    public function template_attributes(int $templateid): array {
        global $DB;
        $record = null;
        try {
            if ($templateid < 0) {
                $record = templaterule::get_template_record_by_id($templateid);
            } else if ($templateid > 0) {
                $record = $DB->get_record(
                    'booking_rules',
                    ['id' => $templateid],
                    'id, rulename, rulejson, eventname',
                    IGNORE_MISSING
                );
            }
        } catch (\Throwable $e) {
            $record = null;
        }
        if (!is_object($record)) {
            return [];
        }
        $json = json_decode((string)($record->rulejson ?? '{}'));
        $attributes = [];
        $ruletype = (string)($record->rulename ?? '');
        if ($ruletype !== '') {
            $attributes['ruletype'] = $ruletype;
        }
        $eventname = (string)($record->eventname ?? '');
        if ($eventname !== '') {
            // The event class without its namespace: \mod_booking\event\bookingoption_cancelled -> bookingoption_cancelled.
            $parts = explode('\\', trim($eventname, '\\'));
            $attributes['event'] = (string)end($parts);
        }
        if (is_object($json)) {
            if (!empty($json->conditionname)) {
                $attributes['recipients'] = (string)$json->conditionname;
            }
            if (isset($json->ruledata->datefield) && (string)$json->ruledata->datefield !== '') {
                $attributes['datefield'] = (string)$json->ruledata->datefield;
            }
            if (isset($json->ruledata->days) && $json->ruledata->days !== '') {
                $attributes['days'] = (int)$json->ruledata->days;
            }
        }
        return $attributes;
    }

    /**
     * Normalize a template lookup token for robust text matching.
     *
     * Converts to lowercase, replaces non-letter/digit separators with spaces,
     * then collapses repeated whitespace.
     *
     * @param string $value
     * @return string
     */
    private function normalize_template_lookup_token(string $value): string {
        $value = core_text::strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', (string)$value);
        return trim((string)$value);
    }

    /**
     * Compute a generic similarity score between lookup query and template name.
     *
     * Score blends token overlap and normalized string similarity and returns
     * a value in range [0,1].
     *
     * @param string $query
     * @param string $name
     * @return float
     */
    private function score_template_similarity(string $query, string $name): float {
        $normalizedquery = $this->normalize_template_lookup_token($query);
        $normalizedname = $this->normalize_template_lookup_token($name);
        if ($normalizedquery === '' || $normalizedname === '') {
            return 0.0;
        }

        $querytokens = array_values(array_filter(explode(' ', $normalizedquery), static function (string $token): bool {
            return $token !== '';
        }));
        $nametokens = array_values(array_filter(explode(' ', $normalizedname), static function (string $token): bool {
            return $token !== '';
        }));

        $queryset = array_fill_keys($querytokens, true);
        $nameset = array_fill_keys($nametokens, true);
        $intersectioncount = count(array_intersect_key($queryset, $nameset));
        $unioncount = count($queryset + $nameset);
        $tokenscore = $unioncount > 0 ? ($intersectioncount / $unioncount) : 0.0;

        similar_text(
            str_replace(' ', '', $normalizedquery),
            str_replace(' ', '', $normalizedname),
            $percent
        );
        $stringscore = max(0.0, min(1.0, ((float)$percent / 100.0)));

        // Weighted blend: string similarity captures close wording variants,
        // token overlap keeps ranking anchored in shared intent terms.
        return (0.65 * $stringscore) + (0.35 * $tokenscore);
    }

    /**
     * List rules visible in the given module context.
     *
     * By default all rules are returned. Pass $activeonly = true to restrict
     * to rules with isactive = 1 (e.g. to avoid showing disabled rules).
     * Each entry contains localized names and a direct edit link.
     *
     * @param int  $contextid Module context id.
     * @param bool $activeonly When true only active rules are included.
     * @return array<int,array<string,mixed>>
     */
    public function list_rules_for_context(int $contextid, bool $activeonly = false): array {
        $records = booking_rules::get_list_of_saved_rules_by_context($contextid);
        $items = [];

        foreach ($records as $record) {
            if (!($record instanceof stdClass)) {
                continue;
            }
            if ($activeonly && (int)($record->isactive ?? 0) !== 1) {
                continue;
            }
            $items[] = $this->normalize_rule_record($record, $contextid);
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string)$a['name'], (string)$b['name']);
        });

        return $items;
    }

    /**
     * Resolve target rule by id or query in the given module context.
     *
     * @param int    $contextid Module context id.
     * @param int    $ruleid
     * @param string $rulequery
     * @return array<string,mixed>
     */
    public function resolve_rule(int $contextid, int $ruleid = 0, string $rulequery = ''): array {
        $rules = $this->list_rules_for_context($contextid);
        if (empty($rules)) {
            return ['status' => 'error', 'message' => get_string('agent_booking_rules_no_rules_in_context', 'mod_booking')];
        }

        if ($ruleid > 0) {
            foreach ($rules as $rule) {
                if ((int)$rule['id'] === $ruleid) {
                    return ['status' => 'ok', 'rule' => $rule];
                }
            }
            // A missing id may really mean a rule NAMED like the number (numeric names are
            // legal): offer that reading as a question - the engine never guesses.
            foreach ($rules as $rule) {
                if (trim((string)($rule['name'] ?? '')) === (string)$ruleid) {
                    return [
                        'status' => 'ambiguity',
                        'message' => 'Es gibt keine Regel mit der ID ' . $ruleid . ', aber eine Regel mit dem '
                            . 'NAMEN "' . $ruleid . '" (id=' . (int)$rule['id'] . '). Meinten Sie diese Regel? '
                            . 'Bitte bestätigen Sie den Namen oder geben Sie eine andere ID an.',
                    ];
                }
            }
            return $this->rule_not_found(
                $contextid,
                get_string('agent_booking_rules_ruleid_not_found_in_context', 'mod_booking')
            );
        }

        $query = trim($rulequery);
        if ($query === '') {
            return $this->rule_not_found($contextid, get_string('agent_booking_rules_provide_ruleid_or_query', 'mod_booking'));
        }

        if (ctype_digit($query)) {
            return $this->resolve_rule($contextid, (int)$query, '');
        }

        $needle = core_text::strtolower($query);
        $exact = [];
        $contains = [];

        foreach ($rules as $rule) {
            $name = core_text::strtolower(trim((string)($rule['name'] ?? '')));
            if ($name === $needle) {
                $exact[] = $rule;
            } else if ($name !== '' && strpos($name, $needle) !== false) {
                $contains[] = $rule;
            }
        }

        $candidates = !empty($exact) ? $exact : $contains;
        if (count($candidates) === 1) {
            return ['status' => 'ok', 'rule' => $candidates[0]];
        }
        if (count($candidates) > 1) {
            return [
                'status' => 'ambiguity',
                'message' => get_string('agent_booking_rules_multiple_rules_match', 'mod_booking'),
                'candidates' => array_values(array_map(static function (array $item): array {
                    return [
                        'id' => (int)($item['id'] ?? 0),
                        'name' => (string)($item['name'] ?? ''),
                    ];
                }, $candidates)),
            ];
        }

        return $this->rule_not_found($contextid, get_string('agent_booking_rules_no_matching_rule', 'mod_booking'));
    }

    /**
     * A rule lookup that found nothing is not the end: the rules that exist are offered as a choice.
     *
     * The code never translates or guesses a name ("Erinnerung" is not "Email reminder 2 days before course start");
     * the model may match, but only when it is shown what exists. Thread 11412 (baseline run 38) ended in "no matching
     * rule" although the context held exactly one fitting rule (George, 2026-09-24: a choice instead of an error).
     *
     * @param int $contextid
     * @param string $message
     * @return array<string,mixed> status notfound with the candidates of {@see rule_candidates_for_context()}.
     */
    private function rule_not_found(int $contextid, string $message): array {
        $candidates = $this->rule_candidates_for_context($contextid);
        if (!empty($candidates)) {
            $message .= ' ' . get_string('agent_booking_rules_choose_from_candidates', 'mod_booking');
        }
        return [
            'status' => 'notfound',
            'message' => $message,
            'candidates' => array_values(array_map(static function (array $item): array {
                return [
                    'id' => (int)($item['id'] ?? 0),
                    'name' => (string)($item['name'] ?? ''),
                    'scope' => (string)($item['context_scope'] ?? ''),
                    'isactive' => (int)($item['isactive'] ?? 0),
                    'days' => $item['days'] ?? null,
                ];
            }, $candidates)),
        ];
    }

    /**
     * The rules a model may choose from when a lookup misses: the current context first, the site-wide rules always,
     * rules of any other context on the path until the cap is reached (George, 2026-09-24). Booking rules live in
     * module or system contexts only ({@see booking_rules::get_list_of_saved_rules()}), so the cap has nothing to drop
     * today; special rules of another activity are looked for from that activity and are never on the path.
     *
     * @param int $contextid Context the request operates in.
     * @param int $limit Cap; the current and the site-wide rules are never dropped by it.
     * @return array<int,array<string,mixed>> Normalised rule records, active rules first within each group.
     */
    public function rule_candidates_for_context(int $contextid, int $limit = self::MAX_RULE_CANDIDATES): array {
        $groups = ['current' => [], 'system' => [], 'other' => []];
        foreach ($this->list_rules_for_context($contextid) as $rule) {
            $scope = (string)($rule['context_scope'] ?? 'other');
            $groups[array_key_exists($scope, $groups) ? $scope : 'other'][] = $rule;
        }
        foreach ($groups as &$group) {
            usort($group, static function (array $a, array $b): int {
                return [(int)$b['isactive'], (string)$a['name']] <=> [(int)$a['isactive'], (string)$b['name']];
            });
        }
        unset($group);
        $candidates = array_merge($groups['current'], $groups['system']);
        foreach ($groups['other'] as $rule) {
            if (count($candidates) >= $limit) {
                break;
            }
            $candidates[] = $rule;
        }
        return $candidates;
    }

    /**
     * Create a new rule from a template via the existing rules handler pipeline.
     *
     * @param int $contextid
     * @param int $templateid
     * @param array $overrides
     * @return array<string,mixed>
     */
    public function create_rule_from_template(int $contextid, int $templateid, array $overrides = []): array {
        global $DB;

        if ($templateid >= 0) {
            return [
            'status' => 'error',
            'message' => get_string('agent_booking_rules_only_predefined_templates_supported', 'mod_booking'),
            ];
        }

        $templaterecord = templaterule::get_template_record_by_id($templateid);
        if (empty($templaterecord) || empty($templaterecord->rulejson)) {
            return ['status' => 'error', 'message' => get_string('agent_booking_rules_template_load_failed', 'mod_booking')];
        }

        $seed = (object)[
            'id' => $templateid,
            'contextid' => $contextid,
            'btn_bookingruletemplates' => 1,
            'bookingruletemplate' => $templateid,
        ];
        $data = rules_info::set_data_for_form($seed);
        if (!($data instanceof stdClass)) {
            return [
                'status' => 'error',
                'message' => get_string('agent_booking_rules_template_data_prepare_failed', 'mod_booking'),
            ];
        }

        $data->id = 0;
        $data->contextid = $contextid;
        $data->bookingruletemplate = $templateid;
        $data->btn_bookingruletemplates = 1;
        $this->apply_handler_defaults_from_record($data, $templaterecord);

        if (isset($overrides['rulename']) && trim((string)$overrides['rulename']) !== '') {
            $data->rule_name = trim((string)$overrides['rulename']);
        }
        // A new rule is active unless the caller says otherwise: the schema promises
        // "isactive (default true)" and the rule form defaults to active. Template records carry
        // no flag, so the handler defaults would otherwise persist 0 (F34, #2241).
        $data->ruleisactive = array_key_exists('isactive', $overrides) && empty($overrides['isactive']) ? 0 : 1;
        $this->apply_days_override($data, $overrides);

        $newruleid = rules_info::save_booking_rule($data);

        if ($newruleid <= 0) {
            return ['status' => 'error', 'message' => get_string('agent_booking_rules_saved_rule_not_determined', 'mod_booking')];
        }

        $saved = $DB->get_record('booking_rules', ['id' => $newruleid], '*', IGNORE_MISSING);
        if (!$saved) {
            return ['status' => 'error', 'message' => get_string('agent_booking_rules_created_rule_load_failed', 'mod_booking')];
        }

        return ['status' => 'ok', 'rule' => $this->normalize_rule_record($saved)];
    }

    /**
     * Update a context-local rule; optionally reapply another template first.
     *
     * @param int $contextid
     * @param int $ruleid
     * @param int $templateid
     * @param array $overrides
     * @return array<string,mixed>
     */
    public function update_rule_from_template(
        int $contextid,
        int $ruleid,
        int $templateid = 0,
        array $overrides = []
    ): array {
        global $DB;

        $record = $DB->get_record('booking_rules', ['id' => $ruleid], '*', IGNORE_MISSING);
        if (!$record) {
            return ['status' => 'error', 'message' => get_string('agent_booking_rules_rule_not_found', 'mod_booking')];
        }

        if ((int)$record->contextid !== $contextid) {
            return [
                'status' => 'error',
                'message' => get_string('agent_booking_rules_only_current_context_editable', 'mod_booking'),
            ];
        }

        $seed = (object)[
            'id' => $ruleid,
            'contextid' => $contextid,
        ];
        $data = rules_info::set_data_for_form($seed);
        if (!($data instanceof stdClass)) {
            return [
                'status' => 'error',
                'message' => get_string('agent_booking_rules_rule_data_prepare_failed', 'mod_booking'),
            ];
        }

        if ($templateid !== 0) {
            if ($templateid >= 0) {
                return [
                    'status' => 'error',
                    'message' => get_string('agent_booking_rules_only_predefined_templates_allowed', 'mod_booking'),
                ];
            }
            $templaterecord = templaterule::get_template_record_by_id($templateid);
            if (empty($templaterecord) || empty($templaterecord->rulejson)) {
                return ['status' => 'error', 'message' => get_string('agent_booking_rules_template_load_failed', 'mod_booking')];
            }

            $templatedataseed = (object)[
                'id' => $templateid,
                'contextid' => $contextid,
                'btn_bookingruletemplates' => 1,
                'bookingruletemplate' => $templateid,
            ];
            $templatedata = rules_info::set_data_for_form($templatedataseed);
            if ($templatedata instanceof stdClass) {
                $data = $templatedata;
                $this->apply_handler_defaults_from_record($data, $templaterecord);
            }
        }

        $existingname = $this->extract_rule_name_from_record($record);

        $data->id = $ruleid;
        $data->contextid = $contextid;
        if (isset($overrides['rulename']) && trim((string)$overrides['rulename']) !== '') {
            $data->rule_name = trim((string)$overrides['rulename']);
        } else if (empty($data->rule_name) && $existingname !== '') {
            $data->rule_name = $existingname;
        }

        if (array_key_exists('isactive', $overrides)) {
            $data->ruleisactive = !empty($overrides['isactive']) ? 1 : 0;
        } else {
            $data->ruleisactive = (int)$record->isactive;
        }

        $this->apply_handler_defaults_from_record($data, $record);
        $this->apply_days_override($data, $overrides);
        $this->apply_mail_text_override($data, $overrides);

        rules_info::save_booking_rule($data);

        $saved = $DB->get_record('booking_rules', ['id' => $ruleid], '*', IGNORE_MISSING);
        if (!$saved) {
            return ['status' => 'error', 'message' => get_string('agent_booking_rules_updated_rule_load_failed', 'mod_booking')];
        }

        return ['status' => 'ok', 'rule' => $this->normalize_rule_record($saved)];
    }

    /**
     * List all ACTIVE rules visible in the given module context.
     *
     * Only rules with isactive = 1 are included. Each entry contains localized
     * names and a direct edit link for the booking rules page.
     *
     * @param int $contextid Module context id.
     * @return array<int,array<string,mixed>>
     */
    public function list_active_rules_for_context(int $contextid): array {
        $records = booking_rules::get_list_of_saved_rules_by_context($contextid);
        $items = [];

        foreach ($records as $record) {
            if (!($record instanceof stdClass)) {
                continue;
            }
            if ((int)($record->isactive ?? 0) !== 1) {
                continue;
            }
            $items[] = $this->normalize_rule_record($record, $contextid);
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string)$a['name'], (string)$b['name']);
        });

        return $items;
    }

    /**
     * Normalize DB rule record for task output.
     *
     * When $cmid > 0 the result includes an editlink pointing to
     * edit_rules.php for the correct context:
     *  - system rules  → edit_rules.php (no cmid)
     *  - current module rules → edit_rules.php?cmid=$cmid
     *  - rules from another module → edit_rules.php?cmid=<that module's cmid>
     *
     * @param stdClass $record
     * @param int      $currentcontextid Optional – current module context id.
     * @return array<string,mixed>
     */
    /**
     * Rule handler type ("rule_daysbefore", "rule_react_on_event") of a template or saved rule.
     *
     * @param int $id Negative id = built-in template, positive id = booking_rules record.
     * @return string Empty when unknown.
     */
    public function rule_type_of(int $id): string {
        global $DB;
        if ($id < 0) {
            $record = templaterule::get_template_record_by_id($id);
            return (string)($record->rulename ?? '');
        }
        if ($id > 0) {
            return (string)($DB->get_field('booking_rules', 'rulename', ['id' => $id], IGNORE_MISSING) ?: '');
        }
        return '';
    }

    /**
     * Whether a rule type carries the "days before/after a date" model.
     *
     * @param string $ruletype
     * @return bool
     */
    public function rule_type_has_days(string $ruletype): bool {
        return $ruletype === 'rule_daysbefore';
    }

    /**
     * The mail subject and text a saved rule sends, when its action carries them (wave 32, URT-3 / R5).
     *
     * Read from the rule's own actiondata: an action with a subject and a template (send_mail, send_mail_interval)
     * has a mail text; any other action (e.g. confirm_bookinganswer) has none and returns null.
     *
     * @param int $ruleid booking_rules id.
     * @return array{subject:string,body:string}|null
     */
    public function rule_mail_text(int $ruleid): ?array {
        global $DB;
        if ($ruleid <= 0) {
            return null;
        }
        $rulejson = $DB->get_field('booking_rules', 'rulejson', ['id' => $ruleid], IGNORE_MISSING);
        $json = json_decode((string)($rulejson ?: '{}'));
        if (!is_object($json) || !isset($json->actiondata) || !is_object($json->actiondata)) {
            return null;
        }
        if (!property_exists($json->actiondata, 'subject') || !property_exists($json->actiondata, 'template')) {
            return null;
        }
        return [
            'subject' => (string)($json->actiondata->subject ?? ''),
            'body' => (string)($json->actiondata->template ?? ''),
        ];
    }

    /**
     * Apply a new mail subject and/or text to the form data of a mail-sending rule (wave 32).
     *
     * The form fields are named by the action handler ("action_<actiontype>_subject", "action_<actiontype>_template"
     * with text and format), so the override follows the handler the rule already uses and never changes it. Fields
     * the handler does not have are left alone.
     *
     * @param stdClass $data Rule form data after set_data_for_form().
     * @param array $overrides Overrides; keys mailsubject and mailbody.
     * @return void
     */
    private function apply_mail_text_override(stdClass $data, array $overrides): void {
        $actiontype = (string)($data->bookingruleactiontype ?? '');
        if ($actiontype === '') {
            return;
        }
        $subjectkey = 'action_' . $actiontype . '_subject';
        $bodykey = 'action_' . $actiontype . '_template';
        $subject = trim((string)($overrides['mailsubject'] ?? ''));
        $body = (string)($overrides['mailbody'] ?? '');
        if ($subject !== '' && property_exists($data, $subjectkey)) {
            $data->{$subjectkey} = $subject;
        }
        if (trim($body) !== '' && property_exists($data, $bodykey)) {
            $template = is_array($data->{$bodykey}) ? $data->{$bodykey} : [];
            $template['text'] = $body;
            $template['format'] = $template['format'] ?? FORMAT_HTML;
            $data->{$bodykey} = $template;
        }
    }

    /**
     * Apply the days override to the form data of a days-before rule (#2403).
     *
     * @param stdClass $data
     * @param array $overrides
     * @return void
     */
    private function apply_days_override(stdClass $data, array $overrides): void {
        if (!array_key_exists('days', $overrides) || $overrides['days'] === null || $overrides['days'] === '') {
            return;
        }
        if (!$this->rule_type_has_days((string)($data->bookingruletype ?? ''))) {
            return;
        }
        $data->rule_daysbefore_days = (int)$overrides['days'];
    }

    /**
     * Normalize a booking_rules record into the array the skills report (names, components, flags, days).
     *
     * @param stdClass $record
     * @param int $currentcontextid
     * @return array
     */
    private function normalize_rule_record(stdClass $record, int $currentcontextid = 0): array {
        $json = json_decode((string)($record->rulejson ?? '{}'));
        $name = '';
        if (!empty($json) && is_object($json) && !empty($json->name)) {
            $name = trim((string)$json->name);
        }

        $rulename      = (string)($record->rulename ?? '');
        $conditionname = is_object($json) ? (string)($json->conditionname ?? '') : '';
        $actionname    = is_object($json) ? (string)($json->actionname ?? '') : '';

        $rulecomponent      = (is_object($json) && isset($json->ruledata->component))
            ? (string)$json->ruledata->component : 'booking';
        $conditioncomponent = (is_object($json) && isset($json->conditioncomponent))
            ? (string)$json->conditioncomponent : 'booking';
        $actioncomponent    = (is_object($json) && isset($json->actiondata->component))
            ? (string)$json->actiondata->component : 'booking';

        $lrulename      = str_replace('_', '', $rulename);
        $lconditionname = str_replace('_', '', $conditionname);
        $lactionname    = str_replace('_', '', $actionname);

        $sm = get_string_manager();
        $localizedrulename = ($lrulename !== '' && $sm->string_exists($lrulename, $rulecomponent))
            ? get_string($lrulename, $rulecomponent) : $rulename;
        $localizedconditionname = ($lconditionname !== '' && $sm->string_exists($lconditionname, $conditioncomponent))
            ? get_string($lconditionname, $conditioncomponent) : $conditionname;
        $localizedactionname = ($lactionname !== '' && $sm->string_exists($lactionname, $actioncomponent))
            ? get_string($lactionname, $actioncomponent) : $actionname;

        // Build edit link directly from the rule's own contextid.
        $rulectxid    = (int)($record->contextid ?? 0);
        $contextscope = 'unknown';
        $editlink     = '';

        if ($rulectxid > 0) {
            $contextscope = ($currentcontextid > 0 && $rulectxid === $currentcontextid)
                ? 'current'
                : ($rulectxid === 1 ? 'system' : 'other');
            $editlink = (new moodle_url('/mod/booking/edit_rules.php', ['contextid' => $rulectxid]))->out(false);
        }

        return [
            'id'                     => (int)($record->id ?? 0),
            'contextid'              => $rulectxid,
            'context_scope'          => $contextscope,
            'name'                   => $name,
            'rulename'               => $rulename,
            'localizedrulename'      => $localizedrulename,
            'eventname'              => (string)($record->eventname ?? ''),
            'conditionname'          => $conditionname,
            'localizedconditionname' => $localizedconditionname,
            'actionname'             => $actionname,
            'localizedactionname'    => $localizedactionname,
            'isactive'               => (int)($record->isactive ?? 0),
            'days'                   => (is_object($json) && isset($json->ruledata->days)) ? (int)$json->ruledata->days : null,
            // Days after the option end in which a "react on event" rule still applies; null = no limit.
            'aftercompletion'        => ($rulename === 'rule_react_on_event' && !empty($json->ruledata->aftercompletion))
                ? (int)$json->ruledata->aftercompletion : null,
            'editlink'               => $editlink,
        ];
    }

    /**
     * Ensure rule/action/condition handler type fields are present.
     *
     * @param stdClass $data
     * @param stdClass $record
     * @return void
     */
    private function apply_handler_defaults_from_record(stdClass $data, stdClass $record): void {
        $json = json_decode((string)($record->rulejson ?? '{}'));

        if (empty($data->bookingruletype) && !empty($record->rulename)) {
            $data->bookingruletype = (string)$record->rulename;
        }
        if (empty($data->bookingruleconditiontype) && !empty($json->conditionname)) {
            $data->bookingruleconditiontype = (string)$json->conditionname;
        }
        if (empty($data->bookingruleactiontype) && !empty($json->actionname)) {
            $data->bookingruleactiontype = (string)$json->actionname;
        }
    }

    /**
     * Extract display name from a rule record.
     *
     * @param stdClass $record
     * @return string
     */
    private function extract_rule_name_from_record(stdClass $record): string {
        $json = json_decode((string)($record->rulejson ?? '{}'));
        if (is_object($json) && !empty($json->name)) {
            return trim((string)$json->name);
        }
        return '';
    }
}
