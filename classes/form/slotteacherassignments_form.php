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
 * Dynamic form for slot booking student-teacher assignments.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use context;
use context_module;
use context_system;
use core_form\dynamic_form;
use html_writer;
use mod_booking\singleton_service;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Student-teacher assignment form for slot booking options.
 */
class slotteacherassignments_form extends dynamic_form {
    /** @var int[] */
    private array $studentids = [];

    /** @var int[] */
    private array $teacherids = [];

    /**
     * Get context for dynamic submission.
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $formdata = $this->get_formdata();
        $cmid = (int)($formdata['id'] ?? 0);
        if ($cmid <= 0) {
            return context_system::instance();
        }

        return context_module::instance($cmid);
    }

    /**
     * Permission check for dynamic submission.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        $context = $this->get_slot_context();
        if ($context === null) {
            throw new moodle_exception('invalidcoursemodule');
        }

        $option = $this->get_option_settings();
        $isteacherofoption = !empty($option) ? booking_check_if_teacher($option) : false;
        $canmanage = is_siteadmin()
            || has_capability('mod/booking:manageslotunavailability', $context)
            || has_capability('mod/booking:updatebooking', $context);

        if (!$canmanage && !$isteacherofoption) {
            require_capability('mod/booking:manageslotunavailability', $context);
        }
    }

    /**
     * Set defaults for submitted form.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $formdata = $this->get_formdata();
        $optionid = (int)($formdata['optionid'] ?? 0);
        $cmid = (int)($formdata['id'] ?? 0);

        $data = new stdClass();
        $data->id = $cmid;
        $data->optionid = $optionid;

        [$teacheroptions, $studentids] = $this->get_teacher_and_student_ids();
        $assigned = $this->get_assigned_by_student(array_keys($teacheroptions));

        foreach ($studentids as $studentid) {
            foreach (array_keys($teacheroptions) as $teacherid) {
                $field = self::field_name($studentid, (int)$teacherid);
                $data->{$field} = !empty($assigned[$studentid][(int)$teacherid]) ? 1 : 0;
            }
        }

        $this->set_data($data);
    }

    /**
     * Persist assignments.
     *
     * @return stdClass
     */
    public function process_dynamic_submission(): stdClass {
        global $DB;

        $data = $this->get_data();
        if (empty($data)) {
            return (object)['saved' => 0];
        }

        [$teacheroptions, $studentids] = $this->get_teacher_and_student_ids();
        $teacherset = array_fill_keys(array_map('intval', array_keys($teacheroptions)), true);
        $studentset = array_fill_keys(array_map('intval', $studentids), true);

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $optionid = (int)($data->optionid ?? 0);

        $DB->delete_records('booking_slot_student_teacher', ['optionid' => $optionid]);

        foreach ((array)$data as $key => $value) {
            if (strpos((string)$key, 'teacher_') !== 0 || empty($value)) {
                continue;
            }

            if (!preg_match('/^teacher_(\d+)_(\d+)$/', (string)$key, $matches)) {
                continue;
            }

            $studentid = (int)$matches[1];
            $teacherid = (int)$matches[2];

            if (empty($studentset[$studentid]) || empty($teacherset[$teacherid])) {
                continue;
            }

            $DB->insert_record('booking_slot_student_teacher', (object)[
                'optionid' => $optionid,
                'userid' => $studentid,
                'teacherid' => $teacherid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $transaction->allow_commit();

        return (object)[
            'saved' => 1,
            'message' => get_string('slot_student_teacher_assignments_saved', 'mod_booking'),
        ];
    }

    /**
     * Define mform elements.
     *
     * @return void
     */
    public function definition(): void {
        global $CFG;

        require_once($CFG->dirroot . '/user/lib.php');

        $mform = $this->_form;
        $formdata = $this->get_formdata();
        $cmid = (int)($formdata['id'] ?? 0);
        $optionid = (int)($formdata['optionid'] ?? 0);

        $mform->addElement('hidden', 'id', $cmid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'optionid', $optionid);
        $mform->setType('optionid', PARAM_INT);

        [$teacheroptions, $studentids] = $this->get_teacher_and_student_ids();

        if (empty($teacheroptions)) {
            $mform->addElement('static', 'slotta_no_teachers', '',
                get_string('slot_student_teacher_assignments_no_teachers', 'mod_booking'));
            return;
        }

        if (empty($studentids)) {
            $mform->addElement('static', 'slotta_no_students', '',
                get_string('slot_student_teacher_assignments_no_students', 'mod_booking'));
            return;
        }

        $students = user_get_users_by_id($studentids);
        usort($students, static function (stdClass $a, stdClass $b): int {
            return strcasecmp(fullname($a), fullname($b));
        });

        foreach ($students as $student) {
            $studentid = (int)$student->id;
            $profileurl = new moodle_url('/user/profile.php', ['id' => $studentid]);
            $studentlabel = html_writer::link($profileurl, fullname($student));
            if (!empty($student->email)) {
                $studentlabel .= html_writer::empty_tag('br');
                $studentlabel .= html_writer::span(s($student->email), 'text-muted small');
            }

            $teachergroup = [];
            foreach ($teacheroptions as $teacherid => $teachername) {
                $field = self::field_name($studentid, (int)$teacherid);
                $teachergroup[] = $mform->createElement('advcheckbox', $field, '', $teachername);
                $mform->setType($field, PARAM_INT);
            }

            $mform->addGroup($teachergroup, 'teachersgroup_' . $studentid, $studentlabel, '', false);
        }

        $this->add_action_buttons(false, get_string('savechanges'));
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        return [];
    }

    /**
     * URL for dynamic form submission.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/slotteacherassignments.php');
    }

    /**
     * Resolve current option settings.
     *
     * @return ?\mod_booking\booking_option_settings
     */
    private function get_option_settings(): ?\mod_booking\booking_option_settings {
        $formdata = $this->get_formdata();
        $cmid = (int)($formdata['id'] ?? 0);
        $optionid = (int)($formdata['optionid'] ?? 0);
        if ($cmid <= 0 || $optionid <= 0) {
            return null;
        }

        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        return $bookingoption->settings ?? null;
    }

    /**
     * Resolve module context.
     *
     * @return ?context_module
     */
    private function get_slot_context(): ?context_module {
        $formdata = $this->get_formdata();
        $cmid = (int)($formdata['id'] ?? 0);
        if ($cmid <= 0) {
            return null;
        }

        return context_module::instance($cmid);
    }

    /**
     * Returns teacher options and student ids.
     *
     * @return array{0: array<int,string>, 1: array<int>}
     */
    private function get_teacher_and_student_ids(): array {
        global $CFG;

        require_once($CFG->dirroot . '/user/lib.php');

        $option = $this->get_option_settings();
        $context = $this->get_slot_context();
        if (empty($option) || empty($context)) {
            return [[], []];
        }

        $teacherpool = [];
        $slotconfig = $option->slotconfig ?? null;
        if (!empty($slotconfig) && !empty($slotconfig->teacher_pool)) {
            $teacherpool = json_decode((string)$slotconfig->teacher_pool, true);
            if (!is_array($teacherpool)) {
                $teacherpool = [];
            }
        }

        $teacherpool = array_values(array_unique(array_filter(array_map('intval', $teacherpool), static function (int $teacherid): bool {
            return $teacherid > 0;
        })));

        $teachers = !empty($teacherpool) ? user_get_users_by_id($teacherpool) : [];
        $teacheroptions = [];
        foreach ($teacherpool as $teacherid) {
            if (empty($teachers[$teacherid])) {
                continue;
            }
            $teacheroptions[$teacherid] = fullname($teachers[$teacherid]);
        }

        $students = get_enrolled_users(
            $context,
            'mod/booking:choose',
            0,
            'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.idnumber, u.email'
        );

        $studentids = array_values(array_map(static function (stdClass $student): int {
            return (int)$student->id;
        }, $students));

        return [$teacheroptions, $studentids];
    }

    /**
     * Existing assignment map.
     *
     * @param int[] $allowedteacherids
     * @return array<int, array<int, bool>>
     */
    private function get_assigned_by_student(array $allowedteacherids): array {
        global $DB;

        $formdata = $this->get_formdata();
        $optionid = (int)($formdata['optionid'] ?? 0);
        if ($optionid <= 0) {
            return [];
        }

        $allowedset = array_fill_keys(array_map('intval', $allowedteacherids), true);
        $records = $DB->get_records('booking_slot_student_teacher', ['optionid' => $optionid], '', 'id, userid, teacherid');

        $assigned = [];
        foreach ($records as $record) {
            $studentid = (int)$record->userid;
            $teacherid = (int)$record->teacherid;
            if (empty($allowedset[$teacherid])) {
                continue;
            }
            if (empty($assigned[$studentid])) {
                $assigned[$studentid] = [];
            }
            $assigned[$studentid][$teacherid] = true;
        }

        return $assigned;
    }

    /**
     * Checkbox field name.
     *
     * @param int $studentid
     * @param int $teacherid
     * @return string
     */
    private static function field_name(int $studentid, int $teacherid): string {
        return 'teacher_' . $studentid . '_' . $teacherid;
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
}
