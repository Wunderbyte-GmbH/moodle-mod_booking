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

namespace mod_booking\local\certificate_conditions\conditions;

use context;
use context_module;
use context_system;
use html_writer;
use mod_booking\event\bookingoption_completed;
use mod_booking\local\certificate_conditions\certificate_conditions_interface;
use mod_booking\local\certificate_conditions\conditions_info;
use MoodleQuickForm;
use stdClass;

/**
 * Certificate condition combining other saved conditions with AND / OR.
 *
 * The building blocks ("leaves") are other records of booking_cert_cond. They are arranged in groups:
 * within a group the leaves are combined with the group operator, the groups are combined with the
 * top level operator. With "uniqueoptions" a completed booking option counts for one leaf only, so
 * "1 option of instance A" in group 1 and "1 option of instance A" in group 2 need two different options.
 *
 * Example (instances A, B, C; one option in each, then one other option in any of them):
 *   operator AND, groups [AND: A>=1, B>=1, C>=1], [OR: A>=1, B>=1, C>=1], uniqueoptions on.
 *
 * Leaf filters, leaf actions and the active flag of leaves are ignored here; the combination's own
 * filter, action and active flag govern. A combination cannot be a leaf of another combination.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>,
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class combination implements certificate_conditions_interface {
    /** @var string operator AND */
    public const OP_AND = 'AND';

    /** @var string operator OR */
    public const OP_OR = 'OR';

    /** @var int maximum number of groups offered in the form */
    public const MAX_GROUPS = 5;

    /** @var string area of the booking_cert_cond_item rows linking a combination to its leaves */
    public const ITEM_AREA = 'certcondition';

    /** @var int id of the condition record, 0 if unknown */
    public int $id = 0;

    /** @var string operator between the groups */
    public string $operator = self::OP_AND;

    /** @var bool a completed option counts for one leaf only */
    public bool $uniqueoptions = true;

    /** @var array list of ['operator' => string, 'conditionids' => int[]] */
    public array $groups = [];

    /**
     * Add logic fields to the dynamic form.
     *
     * @param MoodleQuickForm $mform
     * @param array|null $ajaxformdata
     * @return void
     */
    public function add_logic_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        $contextid = (int)($ajaxformdata['contextid'] ?? 0);
        if (empty($contextid) && !empty($ajaxformdata['cmid'])) {
            $modulecontext = context_module::instance((int)$ajaxformdata['cmid'], IGNORE_MISSING);
            $contextid = $modulecontext ? $modulecontext->id : 0;
        }
        $excludeid = (int)($ajaxformdata['id'] ?? 0);

        $operators = [
            self::OP_AND => get_string('condition_combination_and', 'mod_booking'),
            self::OP_OR => get_string('condition_combination_or', 'mod_booking'),
        ];
        $groupoperators = ['0' => get_string('condition_combination_groupnotused', 'mod_booking')] + $operators;

        $mform->addElement('static', 'condition_combination_hint', '', get_string('condition_combination_hint', 'mod_booking'));

        $mform->addElement(
            'select',
            'condition_combination_operator',
            get_string('condition_combination_operator', 'mod_booking'),
            $operators
        );
        $mform->setType('condition_combination_operator', PARAM_ALPHA);
        $mform->setDefault('condition_combination_operator', self::OP_AND);

        $mform->addElement(
            'advcheckbox',
            'condition_combination_uniqueoptions',
            get_string('condition_combination_uniqueoptions', 'mod_booking')
        );
        $mform->setDefault('condition_combination_uniqueoptions', 1);
        $mform->addHelpButton('condition_combination_uniqueoptions', 'condition_combination_uniqueoptions', 'mod_booking');

        $eligible = self::get_eligible_leaf_conditions($contextid, $excludeid);
        if (empty($eligible)) {
            $mform->addElement(
                'static',
                'condition_combination_noleaves',
                '',
                html_writer::div(get_string('condition_combination_noleaves', 'mod_booking'), 'alert alert-warning')
            );
        }

        for ($i = 1; $i <= self::MAX_GROUPS; $i++) {
            $header = "condition_combination_group_{$i}_header";
            $operator = "condition_combination_group_{$i}_operator";
            $conditionids = "condition_combination_group_{$i}_conditionids";
            $previousoperator = 'condition_combination_group_' . ($i - 1) . '_operator';

            $mform->addElement(
                'static',
                $header,
                '',
                html_writer::tag('strong', get_string('condition_combination_group', 'mod_booking', $i))
            );
            $mform->addElement(
                'select',
                $operator,
                get_string('condition_combination_groupoperator', 'mod_booking'),
                $groupoperators
            );
            $mform->setType($operator, PARAM_ALPHANUM);
            $mform->setDefault($operator, $i === 1 ? self::OP_AND : '0');

            $mform->addElement(
                'autocomplete',
                $conditionids,
                get_string('condition_combination_conditionids', 'mod_booking'),
                $eligible,
                [
                    'multiple' => true,
                    'noselectionstring' => get_string('choose...', 'mod_booking'),
                ]
            );
            $mform->setType($conditionids, PARAM_INT);
            $mform->hideIf($conditionids, $operator, 'eq', '0');

            if ($i > 1) {
                // A group is only shown once the previous one is in use.
                $mform->hideIf($header, $previousoperator, 'eq', '0');
                $mform->hideIf($operator, $previousoperator, 'eq', '0');
                $mform->hideIf($conditionids, $previousoperator, 'eq', '0');
            }
        }
    }

    /**
     * Return logic label.
     *
     * @param bool $localized
     * @return string
     */
    public function get_name_of_logic(bool $localized = true): string {
        return $localized ? get_string('condition_combination', 'mod_booking') : 'condition_combination';
    }

    /**
     * Persist logic configuration into JSON payload.
     *
     * @param stdClass $data
     * @return void
     */
    public function save_condition(stdClass &$data): void {
        $jsonobject = new stdClass();
        $jsonobject->conditionname = 'combination';
        $jsonobject->operator = self::normalize_operator((string)($data->condition_combination_operator ?? self::OP_AND));
        $jsonobject->uniqueoptions = empty($data->condition_combination_uniqueoptions) ? 0 : 1;
        $jsonobject->groups = self::groups_from_formdata($data);
        $data->conditionjson = json_encode($jsonobject);
    }

    /**
     * Write one lookup row per leaf (area "certcondition") so that leaves can find the combinations using them.
     *
     * @param int $conditionid
     * @param stdClass $data
     * @return void
     */
    public function save_items(int $conditionid, stdClass $data): void {
        global $DB;

        $groups = self::groups_from_formdata($data);
        if (empty($groups) && !empty($data->conditionjson)) {
            $jsonobject = json_decode($data->conditionjson);
            $groups = self::groups_from_json($jsonobject);
        }

        $DB->delete_records(
            'booking_cert_cond_item',
            ['conditionid' => $conditionid, 'component' => 'mod_booking', 'area' => self::ITEM_AREA]
        );

        $sortorder = 0;
        foreach (self::leaf_ids_of_groups($groups) as $leafid) {
            $record = new stdClass();
            $record->conditionid = $conditionid;
            $record->itemid = $leafid;
            $record->component = 'mod_booking';
            $record->area = self::ITEM_AREA;
            $record->configjson = json_encode([]);
            $record->sortorder = $sortorder++;
            $DB->insert_record('booking_cert_cond_item', $record);
        }
    }

    /**
     * Set default form values from existing record.
     *
     * @param stdClass $data
     * @param stdClass $record
     * @return void
     */
    public function set_defaults(stdClass &$data, stdClass $record) {
        $this->set_logicdata($record);

        $data->condition_combination_operator = $this->operator;
        $data->condition_combination_uniqueoptions = $this->uniqueoptions ? 1 : 0;

        for ($i = 1; $i <= self::MAX_GROUPS; $i++) {
            $group = $this->groups[$i - 1] ?? null;
            $data->{"condition_combination_group_{$i}_operator"} = $group ? $group['operator'] : '0';
            $data->{"condition_combination_group_{$i}_conditionids"} = $group ? $group['conditionids'] : [];
        }
    }

    /**
     * Set internal logic data from condition record.
     *
     * @param stdClass $record
     * @return void
     */
    public function set_logicdata(stdClass $record): void {
        $this->id = (int)($record->id ?? 0);
        $this->set_conditiondata_from_json((string)($record->logicjson ?? ''));
    }

    /**
     * Set internal logic data from JSON payload.
     *
     * @param string $json
     * @return void
     */
    public function set_conditiondata_from_json(string $json): void {
        $this->operator = self::OP_AND;
        $this->uniqueoptions = true;
        $this->groups = [];

        $jsonobject = json_decode($json);
        if (!$jsonobject) {
            return;
        }
        $this->operator = self::normalize_operator((string)($jsonobject->operator ?? self::OP_AND));
        $this->uniqueoptions = !isset($jsonobject->uniqueoptions) || !empty($jsonobject->uniqueoptions);
        $this->groups = self::groups_from_json($jsonobject);
    }

    /**
     * Apply logic constraints to SQL builder context.
     *
     * @param stdClass $sql
     * @param array $params
     * @return void
     */
    public function execute(stdClass &$sql, array &$params): void {
    }

    /**
     * Returns true when an option of one of the leaves was completed and the combination is fulfilled for the user.
     *
     * @param stdClass $context
     * @return bool
     */
    public function evaluate(stdClass $context): bool {
        $event = $context->event ?? null;
        if (!($event instanceof bookingoption_completed)) {
            return false;
        }
        $userid = (int)($context->userid ?? $event->relateduserid ?? $event->userid ?? 0);
        if (empty($userid)) {
            return false;
        }

        $leaves = $this->load_leaves();
        if (empty($leaves)) {
            return false;
        }

        // Only the completion of an option observed by one of the leaves may trigger the combination.
        if (!self::any_leaf_targets_option($leaves, (int)($context->optionid ?? 0))) {
            return false;
        }

        return $this->is_fulfilled_for_user($userid, [], $leaves);
    }

    /**
     * Returns true when one of the leaves observes the option.
     *
     * @param int $optionid
     * @return bool
     */
    public function targets_option(int $optionid): bool {
        return self::any_leaf_targets_option($this->load_leaves(), $optionid);
    }

    /**
     * Returns true when one of the given leaves observes the option.
     *
     * @param array $leaves
     * @param int $optionid
     * @return bool
     */
    private static function any_leaf_targets_option(array $leaves, int $optionid): bool {
        if (empty($optionid)) {
            return false;
        }
        foreach ($leaves as $leaf) {
            if ($leaf->targets_option($optionid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true when the groups of this combination are fulfilled for the user.
     *
     * @param int $userid
     * @param int[] $excludeoptionids options that must not be counted (treated as already consumed)
     * @param array|null $leaves loaded leaves (id => condition), loaded when null
     * @return bool
     */
    public function is_fulfilled_for_user(int $userid, array $excludeoptionids = [], ?array $leaves = null): bool {
        $leaves = $leaves ?? $this->load_leaves();
        if (empty($leaves) || empty($this->groups)) {
            return false;
        }

        $overlap = self::build_overlap_map($leaves);
        $consumed = array_map('intval', $excludeoptionids);

        foreach ($this->groups as $group) {
            $snapshot = $consumed;
            $fulfilled = $this->evaluate_group($group, $userid, $leaves, $overlap, $consumed);
            if (!$fulfilled) {
                // A failed group must not leave consumed options behind.
                $consumed = $snapshot;
            }
            if ($this->operator === self::OP_AND && !$fulfilled) {
                return false;
            }
            if ($this->operator === self::OP_OR && $fulfilled) {
                return true;
            }
        }

        // AND: every group passed. OR: no group passed.
        return $this->operator === self::OP_AND;
    }

    /**
     * Evaluate one group of leaves.
     *
     * @param array $group ['operator' => string, 'conditionids' => int[]]
     * @param int $userid
     * @param array $leaves id => condition
     * @param array $overlap optionid => number of leaves observing it
     * @param array $consumed consumed option ids, extended on success
     * @return bool
     */
    private function evaluate_group(array $group, int $userid, array $leaves, array $overlap, array &$consumed): bool {
        $conditionids = $group['conditionids'] ?? [];
        if (empty($conditionids)) {
            return false;
        }

        if (($group['operator'] ?? self::OP_AND) === self::OP_OR) {
            foreach ($conditionids as $leafid) {
                $candidate = $consumed;
                if ($this->evaluate_leaf((int)$leafid, $userid, $leaves, $overlap, $candidate)) {
                    $consumed = $candidate;
                    return true;
                }
            }
            return false;
        }

        foreach ($conditionids as $leafid) {
            if (!$this->evaluate_leaf((int)$leafid, $userid, $leaves, $overlap, $consumed)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Evaluate one leaf and consume the options it used.
     *
     * @param int $leafid
     * @param int $userid
     * @param array $leaves
     * @param array $overlap
     * @param array $consumed
     * @return bool
     */
    private function evaluate_leaf(int $leafid, int $userid, array $leaves, array $overlap, array &$consumed): bool {
        if (!isset($leaves[$leafid])) {
            return false;
        }
        /** @var certificate_conditions_interface $leaf */
        $leaf = $leaves[$leafid];

        $targeted = $leaf->get_targeted_optionids();
        if (empty($targeted)) {
            return false;
        }
        $requiredcount = min(max(1, $leaf->get_requiredcount()), count($targeted));

        $completed = $leaf->get_completed_optionids_for_user($userid, $this->uniqueoptions ? $consumed : []);
        if (count($completed) < $requiredcount) {
            return false;
        }

        if ($this->uniqueoptions) {
            // Prefer options that no other leaf observes, so that shared options stay available for later leaves.
            usort($completed, fn($a, $b) => [$overlap[$a] ?? 0, $a] <=> [$overlap[$b] ?? 0, $b]);
            $consumed = array_merge($consumed, array_slice($completed, 0, $requiredcount));
        }
        return true;
    }

    /**
     * Load the leaves of this combination as condition objects keyed by condition id.
     *
     * Missing records and combinations are skipped (a combination cannot be a leaf).
     *
     * @return array id => certificate_conditions_interface
     */
    private function load_leaves(): array {
        global $DB;

        $leafids = self::leaf_ids_of_groups($this->groups);
        if (empty($leafids)) {
            return [];
        }

        $records = $DB->get_records_list('booking_cert_cond', 'id', $leafids);
        $leaves = [];
        foreach ($leafids as $leafid) {
            $record = $records[$leafid] ?? null;
            if (!$record) {
                debugging("Certificate condition combination {$this->id} references missing condition {$leafid}.");
                continue;
            }
            $jsonobject = json_decode((string)$record->logicjson);
            $conditionname = (string)($jsonobject->conditionname ?? '');
            if ($conditionname === '' || $conditionname === 'combination') {
                debugging("Certificate condition combination {$this->id} cannot use condition {$leafid} as building block.");
                continue;
            }
            $leaf = conditions_info::get_condition($conditionname);
            if (!$leaf) {
                continue;
            }
            $leaf->set_logicdata($record);
            $leaves[$leafid] = $leaf;
        }
        return $leaves;
    }

    /**
     * Return the union of the leaves' option ids.
     *
     * @return int[]
     */
    public function get_targeted_optionids(): array {
        return self::union_of_targeted_optionids($this->load_leaves());
    }

    /**
     * A combination has no own required count.
     *
     * @return int
     */
    public function get_requiredcount(): int {
        return 1;
    }

    /**
     * Return the union of the completed options of all leaves.
     *
     * @param int $userid
     * @param int[] $excludeoptionids
     * @return int[]
     */
    public function get_completed_optionids_for_user(int $userid, array $excludeoptionids = []): array {
        $completed = [];
        foreach ($this->load_leaves() as $leaf) {
            $completed = array_merge($completed, $leaf->get_completed_optionids_for_user($userid, $excludeoptionids));
        }
        $completed = array_values(array_unique(array_map('intval', $completed)));
        sort($completed);
        return $completed;
    }

    /**
     * Return a summary like "(A ≥ 1 AND B ≥ 1) AND (A ≥ 1 OR B ≥ 1) [each option counts once]".
     *
     * @param stdClass $record
     * @return string
     */
    public function get_description(stdClass $record): string {
        global $DB;

        $this->set_logicdata($record);
        $leafids = self::leaf_ids_of_groups($this->groups);
        $leafrecords = empty($leafids) ? [] : $DB->get_records_list('booking_cert_cond', 'id', $leafids, '', 'id,name,logicjson');

        $operatorstrings = [
            self::OP_AND => get_string('condition_combination_and', 'mod_booking'),
            self::OP_OR => get_string('condition_combination_or', 'mod_booking'),
        ];

        $groupstrings = [];
        foreach ($this->groups as $group) {
            $leafstrings = [];
            foreach ($group['conditionids'] as $leafid) {
                $leafrecord = $leafrecords[$leafid] ?? null;
                $name = $leafrecord ? format_string($leafrecord->name) : '#' . $leafid;
                $jsonobject = $leafrecord ? json_decode((string)$leafrecord->logicjson) : null;
                $requiredcount = max(1, (int)($jsonobject->requiredcount ?? 1));
                $leafstrings[] = $name . ' ≥ ' . $requiredcount;
            }
            $groupstrings[] = '(' . implode(' ' . $operatorstrings[$group['operator']] . ' ', $leafstrings) . ')';
        }

        $description = implode(' ' . $operatorstrings[$this->operator] . ' ', $groupstrings);
        if ($this->uniqueoptions) {
            $description .= ' [' . get_string('condition_combination_uniqueoptionsshort', 'mod_booking') . ']';
        }
        return $description;
    }

    /**
     * Validate logic-specific form data.
     *
     * @param array $data
     * @return array
     */
    public function validate(array $data): array {
        global $DB;
        $errors = [];

        $contextid = (int)($data['contextid'] ?? 0);
        if (empty($contextid)) {
            $contextid = (int)context_system::instance()->id;
        }
        $id = (int)($data['id'] ?? 0);
        if (!empty($id)) {
            // The context of a saved combination cannot change; its building blocks are bound to it.
            $storedcontextid = (int)$DB->get_field('booking_cert_cond', 'contextid', ['id' => $id], IGNORE_MISSING);
            if (!empty($storedcontextid) && $storedcontextid !== $contextid) {
                $errors['condition_combination_group_1_operator'] =
                    get_string('error:condition_combination_context', 'mod_booking');
                return $errors;
            }
        }

        $eligible = self::get_eligible_leaf_conditions($contextid, $id);
        $groups = self::groups_from_formdata((object)$data);

        $usedgroups = 0;
        for ($i = 1; $i <= self::MAX_GROUPS; $i++) {
            $operator = (string)($data["condition_combination_group_{$i}_operator"] ?? '0');
            if ($operator === '0') {
                continue;
            }
            $usedgroups++;
            $conditionids = (array)($data["condition_combination_group_{$i}_conditionids"] ?? []);
            $conditionids = array_filter(array_map('intval', $conditionids));
            if (empty($conditionids)) {
                $errors["condition_combination_group_{$i}_conditionids"] = get_string('error:entervalue', 'mod_booking');
                continue;
            }
            foreach ($conditionids as $conditionid) {
                if (!isset($eligible[$conditionid])) {
                    $errors["condition_combination_group_{$i}_conditionids"] =
                        get_string('error:condition_combination_invalidleaf', 'mod_booking');
                    break;
                }
            }
        }

        if ($usedgroups === 0 || empty($groups)) {
            $errors['condition_combination_group_1_operator'] = get_string('error:condition_combination_nogroup', 'mod_booking');
        }

        return $errors;
    }

    /**
     * Return the groups of this combination after set_logicdata().
     *
     * @return array list of ['operator' => string, 'conditionids' => int[]]
     */
    public function get_groups(): array {
        return $this->groups;
    }

    /**
     * Return the conditions that may be used as building blocks, keyed by id.
     *
     * Only conditions saved in the same context as the combination are eligible: global combinations use
     * global conditions, a combination of a booking instance uses the conditions of that instance.
     *
     * @param int $contextid context of the combination (0 = system)
     * @param int $excludeid id of the combination being edited
     * @return array id => "name (type)"
     */
    public static function get_eligible_leaf_conditions(int $contextid, int $excludeid = 0): array {
        global $DB;

        if (empty($contextid)) {
            $contextid = (int)context_system::instance()->id;
        }

        $records = $DB->get_records(
            'booking_cert_cond',
            ['contextid' => $contextid],
            'name ASC',
            'id,name,contextid,logicjson'
        );
        $eligible = [];
        foreach ($records as $record) {
            if ((int)$record->id === $excludeid) {
                continue;
            }
            $jsonobject = json_decode((string)$record->logicjson);
            $conditionname = (string)($jsonobject->conditionname ?? ($jsonobject->logicname ?? ''));
            if ($conditionname === '' || $conditionname === 'combination') {
                continue;
            }
            $condition = conditions_info::get_condition($conditionname);
            $typename = $condition ? $condition->get_name_of_logic() : $conditionname;
            $eligible[(int)$record->id] = format_string($record->name) . ' (' . $typename . ')';
        }
        return $eligible;
    }

    /**
     * Return the combinations using the given condition as building block.
     *
     * @param int $leafid
     * @return array id => name
     */
    public static function get_combinations_using(int $leafid): array {
        global $DB;

        $items = $DB->get_records(
            'booking_cert_cond_item',
            ['component' => 'mod_booking', 'area' => self::ITEM_AREA, 'itemid' => $leafid],
            '',
            'id,conditionid'
        );
        if (empty($items)) {
            return [];
        }
        $records = $DB->get_records_list(
            'booking_cert_cond',
            'id',
            array_unique(array_column($items, 'conditionid')),
            'name ASC',
            'id,name'
        );
        $combinations = [];
        foreach ($records as $record) {
            $combinations[(int)$record->id] = (string)$record->name;
        }
        return $combinations;
    }

    /**
     * Remove a deleted condition from every combination using it and drop the lookup rows.
     *
     * @param int $leafid
     * @return void
     */
    public static function remove_leaf_from_combinations(int $leafid): void {
        global $DB;

        foreach (array_keys(self::get_combinations_using($leafid)) as $combinationid) {
            $record = $DB->get_record('booking_cert_cond', ['id' => $combinationid]);
            if (!$record) {
                continue;
            }
            $jsonobject = json_decode((string)$record->logicjson);
            if (!$jsonobject || ($jsonobject->conditionname ?? '') !== 'combination') {
                continue;
            }
            $groups = [];
            foreach (self::groups_from_json($jsonobject) as $group) {
                $group['conditionids'] = array_values(array_filter(
                    $group['conditionids'],
                    fn($id) => (int)$id !== $leafid
                ));
                if (!empty($group['conditionids'])) {
                    $groups[] = $group;
                }
            }
            $jsonobject->groups = $groups;
            $record->logicjson = json_encode($jsonobject);
            $record->timemodified = time();
            $DB->update_record('booking_cert_cond', $record);
        }

        $DB->delete_records(
            'booking_cert_cond_item',
            ['component' => 'mod_booking', 'area' => self::ITEM_AREA, 'itemid' => $leafid]
        );
    }

    /**
     * Build the groups from the form slots.
     *
     * @param stdClass $data
     * @return array
     */
    private static function groups_from_formdata(stdClass $data): array {
        $groups = [];
        for ($i = 1; $i <= self::MAX_GROUPS; $i++) {
            $operator = (string)($data->{"condition_combination_group_{$i}_operator"} ?? '0');
            if ($operator !== self::OP_AND && $operator !== self::OP_OR) {
                continue;
            }
            $conditionids = (array)($data->{"condition_combination_group_{$i}_conditionids"} ?? []);
            $conditionids = array_values(array_unique(array_filter(array_map('intval', $conditionids))));
            if (empty($conditionids)) {
                continue;
            }
            $groups[] = ['operator' => $operator, 'conditionids' => $conditionids];
        }
        return $groups;
    }

    /**
     * Build the groups from a decoded logicjson object.
     *
     * @param mixed $jsonobject
     * @return array
     */
    private static function groups_from_json($jsonobject): array {
        $groups = [];
        foreach ((array)($jsonobject->groups ?? []) as $group) {
            $group = (object)$group;
            $conditionids = array_map('intval', (array)($group->conditionids ?? []));
            $conditionids = array_values(array_unique(array_filter($conditionids)));
            if (empty($conditionids)) {
                continue;
            }
            $groups[] = [
                'operator' => self::normalize_operator((string)($group->operator ?? self::OP_AND)),
                'conditionids' => $conditionids,
            ];
        }
        return $groups;
    }

    /**
     * Return the distinct leaf ids of the groups in order of appearance.
     *
     * @param array $groups
     * @return int[]
     */
    private static function leaf_ids_of_groups(array $groups): array {
        $leafids = [];
        foreach ($groups as $group) {
            foreach ($group['conditionids'] as $leafid) {
                $leafids[(int)$leafid] = (int)$leafid;
            }
        }
        return array_values($leafids);
    }

    /**
     * Return the union of the option ids of the given leaves.
     *
     * @param array $leaves
     * @return int[]
     */
    private static function union_of_targeted_optionids(array $leaves): array {
        $optionids = [];
        foreach ($leaves as $leaf) {
            foreach ($leaf->get_targeted_optionids() as $optionid) {
                $optionids[(int)$optionid] = (int)$optionid;
            }
        }
        return array_values($optionids);
    }

    /**
     * Return how many leaves observe each option.
     *
     * @param array $leaves
     * @return array optionid => count
     */
    private static function build_overlap_map(array $leaves): array {
        $overlap = [];
        foreach ($leaves as $leaf) {
            foreach ($leaf->get_targeted_optionids() as $optionid) {
                $overlap[(int)$optionid] = ($overlap[(int)$optionid] ?? 0) + 1;
            }
        }
        return $overlap;
    }

    /**
     * Normalize an operator string to AND or OR.
     *
     * @param string $operator
     * @return string
     */
    private static function normalize_operator(string $operator): string {
        return strtoupper($operator) === self::OP_OR ? self::OP_OR : self::OP_AND;
    }
}
