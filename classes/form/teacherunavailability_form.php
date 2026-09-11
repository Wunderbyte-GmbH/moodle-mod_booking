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
 * Moodle form for teacher slot unavailability entries.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use context;
use context_module;
use core_form\dynamic_form;
use html_writer;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\singleton_service;
use moodle_url;
use stdClass;

/**
 * Form to mark teacher availability/unavailability on existing slots.
 */
class teacherunavailability_form extends dynamic_form {
    /** @var string mark mode: picked slots are unavailable */
    private const MODE_UNAVAILABILITY = 'unavailability';

    /** @var string mark mode: picked slots are available */
    private const MODE_AVAILABILITY = 'availability';

    /** @var string scope for system-wide (stored as optionid=0) */
    private const SCOPE_SYSTEM = 'system';

    /** @var string scope for all slot options in this booking instance */
    private const SCOPE_INSTANCE = 'instance';

    /** @var string scope for one slot option only */
    private const SCOPE_OPTION = 'option';

    /** @var string view mode calendar */
    private const VIEW_CALENDAR = 'calendar';

    /** @var string view mode list */
    private const VIEW_LIST = 'list';

    /** @var string list checkbox prefix */
    private const SLOT_CHECKBOX_PREFIX = 'slot_selection_cb_';

    /**
     * Dynamic submission context.
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $cmid = (int)($this->get_formdata()['id'] ?? 0);
        return context_module::instance($cmid);
    }

    /**
     * Dynamic permission check.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        global $USER, $CFG;

        require_once($CFG->dirroot . '/mod/booking/locallib.php');

        $formdata = $this->get_formdata();
        $cmid = (int)($formdata['id'] ?? 0);
        $currentoptionid = (int)($formdata['optionid'] ?? 0);
        $teacherid = (int)($formdata['teacherid'] ?? 0);
        if ($teacherid <= 0) {
            $teacherid = (int)$USER->id;
        }

        $context = context_module::instance($cmid);
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $currentoptionid);
        $isteacherofoption = booking_check_if_teacher($bookingoption->settings);
        $canmanageunavailability = is_siteadmin()
            || has_capability('mod/booking:manageslotunavailability', $context)
            || has_capability('mod/booking:updatebooking', $context);

        if (!$canmanageunavailability && !$isteacherofoption) {
            require_capability('mod/booking:manageslotunavailability', $context);
        }

        if (!$canmanageunavailability && $teacherid !== (int)$USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', null, get_string('slot_error_editownonly', 'mod_booking'));
        }
    }

    /**
     * Preload form defaults.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        global $USER;

        $formdata = $this->get_formdata();
        $cmid = (int)($formdata['id'] ?? 0);
        $currentoptionid = (int)($formdata['optionid'] ?? 0);
        $teacherid = (int)($formdata['teacherid'] ?? 0);
        if ($teacherid <= 0) {
            $teacherid = (int)$USER->id;
        }

        $date = (int)($formdata['date'] ?? 0);
        if ($date <= 0) {
            $date = time();
        }

        $scope = $this->normalize_scope((string)($formdata['scope'] ?? self::SCOPE_SYSTEM));
        $markmode = $this->normalize_markmode((string)($formdata['markmode'] ?? self::MODE_UNAVAILABILITY));
        $viewmode = $this->normalize_viewmode((string)($formdata['viewmode'] ?? self::VIEW_CALENDAR));

        $bookingid = $this->get_bookingid_for_option($currentoptionid);
        $slotoptions = $this->get_slot_option_records($bookingid);

        $scopeoptionid = (int)($formdata['scopeoptionid'] ?? 0);
        if ($scopeoptionid <= 0 || empty($slotoptions[$scopeoptionid])) {
            $scopeoptionid = $currentoptionid;
        }

        $effectivedata = [
            'id' => $cmid,
            'optionid' => $currentoptionid,
            'teacherid' => $teacherid,
            'date' => $date,
            'scope' => $scope,
            'scopeoptionid' => $scopeoptionid,
            'markmode' => $markmode,
            'viewmode' => $viewmode,
        ];

        $entries = $this->get_slot_entries($effectivedata);
        $selectedkeys = [];

        if ($this->has_submitted_selection($formdata)) {
            $selectedkeys = $this->extract_selected_slot_keys((array)$formdata, $entries);
        } else {
            $unavailablekeyset = $this->get_unavailable_key_set($entries, $teacherid, $scope, $scopeoptionid);
            if ($markmode === self::MODE_AVAILABILITY) {
                foreach ($entries as $entry) {
                    if (empty($unavailablekeyset[$entry['key']])) {
                        $selectedkeys[] = $entry['key'];
                    }
                }
            } else {
                $selectedkeys = array_keys($unavailablekeyset);
            }
        }

        $selectedset = array_fill_keys($selectedkeys, true);

        $data = new stdClass();
        $data->id = $cmid;
        $data->optionid = $currentoptionid;
        $data->teacherid = $teacherid;
        $data->date = $date;
        $data->scope = $scope;
        $data->scopeoptionid = $scopeoptionid;
        $data->markmode = $markmode;
        $data->viewmode = $viewmode;
        $data->slot_calendar_data = json_encode($entries);
        $data->slot_selection = implode(',', array_keys($selectedset));

        foreach ($entries as $index => $entry) {
            $fieldname = self::SLOT_CHECKBOX_PREFIX . $index;
            $data->{$fieldname} = !empty($selectedset[$entry['key']]) ? 1 : 0;
        }

        $this->set_data($data);
    }

    /**
     * Process dynamic submission.
     *
     * @return stdClass
     */
    public function process_dynamic_submission(): stdClass {
        global $DB;

        $data = $this->get_data();
        if (empty($data)) {
            return (object)[
                'saved' => false,
            ];
        }

        $submitted = (array)$data;

        $cmid = (int)($submitted['id'] ?? 0);
        $currentoptionid = (int)($submitted['optionid'] ?? 0);
        $teacherid = (int)($submitted['teacherid'] ?? 0);
        $date = (int)($submitted['date'] ?? 0);

        $scope = $this->normalize_scope((string)($submitted['scope'] ?? self::SCOPE_SYSTEM));
        $markmode = $this->normalize_markmode((string)($submitted['markmode'] ?? self::MODE_UNAVAILABILITY));
        $viewmode = $this->normalize_viewmode((string)($submitted['viewmode'] ?? self::VIEW_CALENDAR));

        $bookingid = $this->get_bookingid_for_option($currentoptionid);
        $slotoptions = $this->get_slot_option_records($bookingid);

        $scopeoptionid = (int)($submitted['scopeoptionid'] ?? 0);
        if ($scopeoptionid <= 0 || empty($slotoptions[$scopeoptionid])) {
            $scopeoptionid = $currentoptionid;
        }

        $effectivedata = [
            'id' => $cmid,
            'optionid' => $currentoptionid,
            'teacherid' => $teacherid,
            'date' => $date,
            'scope' => $scope,
            'scopeoptionid' => $scopeoptionid,
            'markmode' => $markmode,
            'viewmode' => $viewmode,
        ];

        $entries = $this->get_slot_entries($effectivedata);
        $allkeyset = [];
        foreach ($entries as $entry) {
            $allkeyset[$entry['key']] = true;
        }

        $selectedkeys = $this->extract_selected_slot_keys($submitted, $entries);
        $selectedset = array_fill_keys($selectedkeys, true);

        $unavailablekeys = [];
        if ($markmode === self::MODE_AVAILABILITY) {
            foreach (array_keys($allkeyset) as $key) {
                if (empty($selectedset[$key])) {
                    $unavailablekeys[] = $key;
                }
            }
        } else {
            foreach (array_keys($selectedset) as $key) {
                if (!empty($allkeyset[$key])) {
                    $unavailablekeys[] = $key;
                }
            }
        }

        $targetoptionids = $this->get_scope_target_optionids($scope, $currentoptionid, $scopeoptionid);

        $transaction = $DB->start_delegated_transaction();

        if ($scope === self::SCOPE_SYSTEM) {
            $DB->delete_records('booking_teacher_unavailability', [
                'teacherid' => $teacherid,
                'optionid' => 0,
            ]);
        } else if (count($targetoptionids) === 1) {
            $DB->delete_records('booking_teacher_unavailability', [
                'teacherid' => $teacherid,
                'optionid' => reset($targetoptionids),
            ]);
        } else if (!empty($targetoptionids)) {
            [$insql, $params] = $DB->get_in_or_equal($targetoptionids, SQL_PARAMS_NAMED, 'opt');
            $params['teacherid'] = $teacherid;
            $DB->delete_records_select('booking_teacher_unavailability', 'teacherid = :teacherid AND optionid ' . $insql, $params);
        }

        $now = time();
        $insertedkeys = [];
        foreach (array_values(array_unique($unavailablekeys)) as $key) {
            if (empty($allkeyset[$key])) {
                continue;
            }

            $parts = explode(':', (string)$key);
            if (count($parts) !== 3) {
                continue;
            }

            $storedoptionid = $scope === self::SCOPE_SYSTEM ? 0 : (int)$parts[0];
            $from = (int)$parts[1];
            $until = (int)$parts[2];
            if ($from <= 0 || $until <= $from) {
                continue;
            }

            $insertkey = $storedoptionid . ':' . $from . ':' . $until;
            if (!empty($insertedkeys[$insertkey])) {
                continue;
            }
            $insertedkeys[$insertkey] = true;

            $DB->insert_record('booking_teacher_unavailability', (object)[
                'optionid' => $storedoptionid,
                'teacherid' => $teacherid,
                'unavailable_from' => $from,
                'unavailable_until' => $until,
                'reason' => '',
                'timecreated' => $now,
            ]);
        }

        $transaction->allow_commit();

        return (object)[
            'id' => $cmid,
            'optionid' => $currentoptionid,
            'scopeoptionid' => $scopeoptionid,
            'teacherid' => $teacherid,
            'date' => $date,
            'scope' => $scope,
            'markmode' => $markmode,
            'viewmode' => $viewmode,
            'saved' => true,
        ];
    }

    /**
     * Dynamic form page URL.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/teacherunavailability.php');
    }

    /**
     * Form definition.
     *
     * @return void
     */
    public function definition(): void {
        global $USER;

        $mform = $this->_form;
        $formdata = $this->get_formdata();

        $cmid = (int)($formdata['id'] ?? 0);
        $currentoptionid = (int)($formdata['optionid'] ?? 0);
        $teacherid = (int)($formdata['teacherid'] ?? 0);
        if ($teacherid <= 0) {
            $teacherid = (int)$USER->id;
        }

        $date = (int)($formdata['date'] ?? 0);
        if ($date <= 0) {
            $date = time();
        }

        $scope = $this->normalize_scope((string)($formdata['scope'] ?? self::SCOPE_SYSTEM));
        $markmode = $this->normalize_markmode((string)($formdata['markmode'] ?? self::MODE_UNAVAILABILITY));
        $viewmode = $this->normalize_viewmode((string)($formdata['viewmode'] ?? self::VIEW_CALENDAR));

        $bookingid = $this->get_bookingid_for_option($currentoptionid);
        $slotoptions = $this->get_slot_option_records($bookingid);

        $scopeoptionid = (int)($formdata['scopeoptionid'] ?? 0);
        if ($scopeoptionid <= 0 || empty($slotoptions[$scopeoptionid])) {
            $scopeoptionid = $currentoptionid;
        }

        $mform->addElement('hidden', 'id', $cmid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'optionid', $currentoptionid);
        $mform->setType('optionid', PARAM_INT);

        $mform->addElement('hidden', 'teacherid', $teacherid);
        $mform->setType('teacherid', PARAM_INT);

        $mform->addElement('hidden', 'date', $date);
        $mform->setType('date', PARAM_INT);

        $scopeoptions = [
            self::SCOPE_SYSTEM => get_string('slot_unavailability_scope_system', 'mod_booking'),
            self::SCOPE_INSTANCE => get_string('slot_unavailability_scope_instance', 'mod_booking'),
            self::SCOPE_OPTION => get_string('slot_unavailability_scope_option', 'mod_booking'),
        ];
        $mform->addElement('select', 'scope', get_string('slot_unavailability_scope', 'mod_booking'), $scopeoptions);
        $mform->setType('scope', PARAM_ALPHAEXT);
        $mform->setDefault('scope', $scope);

        $scopeoptionlabels = [];
        foreach ($slotoptions as $optionid => $option) {
            $scopeoptionlabels[$optionid] = $option['name'];
        }

        if (empty($scopeoptionlabels)) {
            $scopeoptionlabels[$currentoptionid] = get_string('slot_unavailability_scope_currentfallback', 'mod_booking');
        }

        $mform->addElement(
            'autocomplete',
            'scopeoptionid',
            get_string('slot_unavailability_scope_targetoption', 'mod_booking'),
            $scopeoptionlabels,
            ['tags' => false, 'multiple' => false]
        );
        $mform->setType('scopeoptionid', PARAM_INT);
        $mform->setDefault('scopeoptionid', $scopeoptionid);

        $modemenu = [
            self::MODE_UNAVAILABILITY => get_string('slot_unavailability_mode_unavailability', 'mod_booking'),
            self::MODE_AVAILABILITY => get_string('slot_unavailability_mode_availability', 'mod_booking'),
        ];
        $mform->addElement('select', 'markmode', get_string('slot_unavailability_mode', 'mod_booking'), $modemenu);
        $mform->setType('markmode', PARAM_ALPHAEXT);
        $mform->setDefault('markmode', $markmode);

        $viewmenu = [
            self::VIEW_CALENDAR => get_string('slot_booking_view_calendar', 'mod_booking'),
            self::VIEW_LIST => get_string('slot_booking_view_list', 'mod_booking'),
        ];
        $mform->addElement('select', 'viewmode', get_string('slot_unavailability_viewmode', 'mod_booking'), $viewmenu);
        $mform->setType('viewmode', PARAM_ALPHAEXT);
        $mform->setDefault('viewmode', $viewmode);

        $mform->addElement(
            'static',
            'slot_unavailability_helptext',
            '',
            get_string('slot_unavailability_helptext', 'mod_booking')
        );

        $effectivedata = [
            'id' => $cmid,
            'optionid' => $currentoptionid,
            'teacherid' => $teacherid,
            'date' => $date,
            'scope' => $scope,
            'scopeoptionid' => $scopeoptionid,
            'markmode' => $markmode,
            'viewmode' => $viewmode,
        ];

        $entries = $this->get_slot_entries($effectivedata);

        $mform->addElement('hidden', 'slot_calendar_data', json_encode($entries));
        $mform->setType('slot_calendar_data', PARAM_RAW_TRIMMED);

        $mform->addElement('hidden', 'slot_selection', '');
        $mform->setType('slot_selection', PARAM_RAW_TRIMMED);

        if (empty($entries)) {
            $mform->addElement('static', 'slot_selection_info', '', get_string('slot_unavailability_no_slots', 'mod_booking'));
        } else if ($viewmode === self::VIEW_CALENDAR) {
            $calendarcontainer = html_writer::div('', 'booking-slot-calendar-picker', [
                'data-region' => 'slot-calendar-picker',
            ]);
            $mform->addElement('static', 'slot_calendar_ui', get_string('slot_selection', 'mod_booking'), $calendarcontainer);
        } else {
            foreach ($entries as $index => $entry) {
                $fieldname = self::SLOT_CHECKBOX_PREFIX . $index;
                $label = $entry['daylabel'] . ' · ' . $entry['timelabel'];
                $mform->addElement('advcheckbox', $fieldname, '', $label, [
                    'data-slot-selection-checkbox' => '1',
                ]);
                $mform->setType($fieldname, PARAM_INT);
            }
        }

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Validation.
     *
     * @param array $data Form data
     * @param array $files Files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = [];

        $scope = $this->normalize_scope((string)($data['scope'] ?? self::SCOPE_SYSTEM));
        if ($scope === self::SCOPE_OPTION && empty($data['scopeoptionid'])) {
            $errors['scopeoptionid'] = get_string('required');
        }

        return $errors;
    }

    /**
     * Returns form data for ajax and direct-render usage.
     *
     * @return array
     */
    private function get_formdata(): array {
        if (!empty($this->_ajaxformdata)) {
            return (array)$this->_ajaxformdata;
        }

        if (!empty($this->_customdata)) {
            return (array)$this->_customdata;
        }

        return [];
    }

    /**
     * Normalize scope value.
     *
     * @param string $scope
     * @return string
     */
    private function normalize_scope(string $scope): string {
        if (in_array($scope, [self::SCOPE_SYSTEM, self::SCOPE_INSTANCE, self::SCOPE_OPTION], true)) {
            return $scope;
        }

        return self::SCOPE_SYSTEM;
    }

    /**
     * Normalize mark mode value.
     *
     * @param string $markmode
     * @return string
     */
    private function normalize_markmode(string $markmode): string {
        if (in_array($markmode, [self::MODE_UNAVAILABILITY, self::MODE_AVAILABILITY], true)) {
            return $markmode;
        }

        return self::MODE_UNAVAILABILITY;
    }

    /**
     * Normalize view mode value.
     *
     * @param string $viewmode
     * @return string
     */
    private function normalize_viewmode(string $viewmode): string {
        if (in_array($viewmode, [self::VIEW_CALENDAR, self::VIEW_LIST], true)) {
            return $viewmode;
        }

        return self::VIEW_CALENDAR;
    }

    /**
     * Resolve booking id for option.
     *
     * @param int $optionid
     * @return int
     */
    private function get_bookingid_for_option(int $optionid): int {
        if ($optionid <= 0) {
            return 0;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        return (int)($settings->bookingid ?? 0);
    }

    /**
     * Returns slot-enabled options of a booking instance.
     *
     * @param int $bookingid
     * @return array<int, array{id:int,name:string}>
     */
    private function get_slot_option_records(int $bookingid): array {
        global $DB;

        if ($bookingid <= 0) {
            return [];
        }

        $records = $DB->get_records('booking_options', ['bookingid' => $bookingid], 'text ASC', 'id, text, type');
        if (empty($records)) {
            return [];
        }

        $options = [];
        foreach ($records as $record) {
            $type = (int)($record->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT);
            if ($type !== MOD_BOOKING_OPTIONTYPE_SLOTBOOKING) {
                continue;
            }

            $options[(int)$record->id] = [
                'id' => (int)$record->id,
                'name' => format_string((string)($record->text ?? '')),
            ];
        }

        return $options;
    }

    /**
     * Resolve option ids affected by selected scope.
     *
     * @param string $scope
     * @param int $currentoptionid
     * @param int $scopeoptionid
     * @return int[]
     */
    private function get_scope_target_optionids(string $scope, int $currentoptionid, int $scopeoptionid): array {
        if ($scope === self::SCOPE_SYSTEM) {
            return array_values(array_map('intval', array_keys($this->get_all_slot_option_records())));
        }

        if ($scope === self::SCOPE_OPTION) {
            $target = $scopeoptionid > 0 ? $scopeoptionid : $currentoptionid;
            return $target > 0 ? [$target] : [];
        }

        $bookingid = $this->get_bookingid_for_option($currentoptionid);
        $slotoptions = $this->get_slot_option_records($bookingid);
        if (empty($slotoptions)) {
            return $currentoptionid > 0 ? [$currentoptionid] : [];
        }

        return array_values(array_map('intval', array_keys($slotoptions)));
    }

    /**
     * Returns all slot-enabled options in the system.
     *
     * @return array<int, array{id:int,name:string}>
     */
    private function get_all_slot_option_records(): array {
        global $DB;

        $records = $DB->get_records('booking_options', ['type' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING], 'text ASC', 'id, text');
        if (empty($records)) {
            return [];
        }

        $options = [];
        foreach ($records as $record) {
            $options[(int)$record->id] = [
                'id' => (int)$record->id,
                'name' => format_string((string)($record->text ?? '')),
            ];
        }

        return $options;
    }

    /**
     * Build slot entries for selected scope and date range.
     *
     * @param array $formdata
     * @return array<int, array>
     */
    private function get_slot_entries(array $formdata): array {
        $currentoptionid = (int)($formdata['optionid'] ?? 0);
        $scope = $this->normalize_scope((string)($formdata['scope'] ?? self::SCOPE_SYSTEM));
        $scopeoptionid = (int)($formdata['scopeoptionid'] ?? 0);

        $targetoptionids = $this->get_scope_target_optionids($scope, $currentoptionid, $scopeoptionid);
        if (empty($targetoptionids)) {
            return [];
        }

        $date = (int)($formdata['date'] ?? 0);
        if ($date <= 0) {
            $date = time();
        }

        $viewstart = strtotime('monday this week', $date);
        if ($viewstart === false) {
            $viewstart = strtotime('monday this week');
        }
        $rangefrom = strtotime('-6 weeks', (int)$viewstart);
        $rangeuntil = strtotime('+18 weeks', (int)$viewstart);

        $bookingid = $this->get_bookingid_for_option($currentoptionid);
        $slotoptions = $scope === self::SCOPE_SYSTEM
            ? $this->get_all_slot_option_records()
            : $this->get_slot_option_records($bookingid);
        $showoptionname = count($targetoptionids) > 1;

        $entries = [];
        foreach ($targetoptionids as $targetoptionid) {
            $targetoptionid = (int)$targetoptionid;
            if ($targetoptionid <= 0) {
                continue;
            }

            $slots = slot_availability::get_slots_with_status_for_range($targetoptionid, $rangefrom, $rangeuntil);
            foreach ($slots as $slot) {
                $start = (int)($slot['start'] ?? 0);
                $end = (int)($slot['end'] ?? 0);
                if ($start <= 0 || $end <= $start) {
                    continue;
                }

                $timelabel = userdate($start, get_string('strftimetime', 'langconfig'))
                    . ' - '
                    . userdate($end, get_string('strftimetime', 'langconfig'));
                if ($showoptionname && !empty($slotoptions[$targetoptionid]['name'])) {
                    $timelabel .= ' - ' . $slotoptions[$targetoptionid]['name'];
                }

                $entries[] = [
                    'key' => $targetoptionid . ':' . $start . ':' . $end,
                    'optionid' => $targetoptionid,
                    'start' => $start,
                    'end' => $end,
                    'daylabel' => userdate($start, get_string('strftimedaydate', 'langconfig')),
                    'timelabel' => $timelabel,
                    'bookings' => (int)($slot['bookings'] ?? 0),
                    'capacity' => (int)($slot['capacity'] ?? 1),
                ];
            }
        }

        usort($entries, static function (array $a, array $b): int {
            if ($a['start'] !== $b['start']) {
                return $a['start'] <=> $b['start'];
            }
            if ($a['end'] !== $b['end']) {
                return $a['end'] <=> $b['end'];
            }
            return $a['optionid'] <=> $b['optionid'];
        });

        return $entries;
    }

    /**
     * Check if selection was submitted already.
     *
     * @param array $submitted
     * @return bool
     */
    private function has_submitted_selection(array $submitted): bool {
        if (array_key_exists('slot_selection', $submitted)) {
            return true;
        }

        foreach (array_keys($submitted) as $key) {
            if (strpos((string)$key, self::SLOT_CHECKBOX_PREFIX) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract selected slot keys from hidden value and list checkboxes.
     *
     * @param array $submitted
     * @param array $entries
     * @return string[]
     */
    private function extract_selected_slot_keys(array $submitted, array $entries): array {
        $validkeys = [];
        foreach ($entries as $entry) {
            $validkeys[(string)$entry['key']] = true;
        }

        $selected = [];
        $hascheckboxes = false;
        foreach ($entries as $index => $entry) {
            $fieldname = self::SLOT_CHECKBOX_PREFIX . $index;
            if (!array_key_exists($fieldname, $submitted)) {
                continue;
            }
            $hascheckboxes = true;
            if (!empty($submitted[$fieldname])) {
                $selected[] = (string)$entry['key'];
            }
        }

        if (!$hascheckboxes) {
            $selectionraw = (string)($submitted['slot_selection'] ?? '');
            if ($selectionraw !== '') {
                $selected = array_filter(array_map('trim', explode(',', $selectionraw)), static function (string $key): bool {
                    return $key !== '';
                });
            }
        }

        $normalized = [];
        foreach ($selected as $key) {
            $key = (string)$key;
            if (!empty($validkeys[$key])) {
                $normalized[$key] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * Resolve unavailable key set from DB for current scope.
     *
     * @param array $entries
     * @param int $teacherid
     * @param string $scope
     * @param int $scopeoptionid
     * @return array<string,bool>
     */
    private function get_unavailable_key_set(array $entries, int $teacherid, string $scope, int $scopeoptionid): array {
        global $DB;

        if ($teacherid <= 0 || empty($entries)) {
            return [];
        }

        $records = [];
        if ($scope === self::SCOPE_SYSTEM) {
            $records = $DB->get_records('booking_teacher_unavailability', [
                'teacherid' => $teacherid,
                'optionid' => 0,
            ]);
        } else if ($scope === self::SCOPE_OPTION) {
            $target = $scopeoptionid > 0 ? $scopeoptionid : (int)($entries[0]['optionid'] ?? 0);
            if ($target > 0) {
                $records = $DB->get_records('booking_teacher_unavailability', [
                    'teacherid' => $teacherid,
                    'optionid' => $target,
                ]);
            }
        } else {
            $optionids = array_values(array_unique(array_map(static function (array $entry): int {
                return (int)($entry['optionid'] ?? 0);
            }, $entries)));
            if (!empty($optionids)) {
                [$insql, $params] = $DB->get_in_or_equal($optionids, SQL_PARAMS_NAMED, 'opt');
                $params['teacherid'] = $teacherid;
                $records = $DB->get_records_select(
                    'booking_teacher_unavailability',
                    'teacherid = :teacherid AND optionid ' . $insql,
                    $params
                );
            }
        }

        if (empty($records)) {
            return [];
        }

        $recordsbyoption = [];
        foreach ($records as $record) {
            $optionid = (int)$record->optionid;
            if (empty($recordsbyoption[$optionid])) {
                $recordsbyoption[$optionid] = [];
            }
            $recordsbyoption[$optionid][] = [
                'from' => (int)$record->unavailable_from,
                'until' => (int)$record->unavailable_until,
            ];
        }

        $keyset = [];
        foreach ($entries as $entry) {
            $entryoptionid = (int)$entry['optionid'];
            $entryfrom = (int)$entry['start'];
            $entryuntil = (int)$entry['end'];

            $candidates = $scope === self::SCOPE_SYSTEM
                ? ($recordsbyoption[0] ?? [])
                : ($recordsbyoption[$entryoptionid] ?? []);

            foreach ($candidates as $candidate) {
                if ($candidate['from'] < $entryuntil && $candidate['until'] > $entryfrom) {
                    $keyset[(string)$entry['key']] = true;
                    break;
                }
            }
        }

        return $keyset;
    }
}
