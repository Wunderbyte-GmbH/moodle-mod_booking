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
 * Repeatable form to manage a dynamicformat booking custom field's option hierarchy.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      David Ala
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use moodleform;
use mod_booking\customfield\hierarchy_manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

/**
 * Manages the parent/child option list of a single dynamicformat booking custom field.
 *
 * The picked field id is carried in customdata['fieldid']; the field's existing rows
 * (each ['id','label','parentid']) are carried in customdata['rows'].
 */
class customfield_options_form extends moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;

        $fieldid = (int) ($this->_customdata['fieldid'] ?? 0);
        $loadedrows = array_values($this->_customdata['rows'] ?? []);
        $nextid = (int) ($this->_customdata['nextid'] ?? 1);
        $fieldoptions = $this->_customdata['fieldoptions'] ?? hierarchy_manager::get_manageable_fields();

        // Field picker with a no-submit "switch" button that reloads without saving.
        $mform->addElement(
            'select',
            'fieldid',
            get_string('selectcustomfield', 'mod_booking'),
            ['' => get_string('choosedots')] + $fieldoptions
        );
        $mform->setType('fieldid', PARAM_INT);
        if (!empty($fieldid)) {
            $mform->setDefault('fieldid', $fieldid);
        }
        $mform->registerNoSubmitButton('switchfield');
        $mform->addElement('submit', 'switchfield', get_string('loadfield', 'mod_booking'));

        // Nothing to manage until a field has been chosen.
        if (empty($fieldid)) {
            return;
        }

        $mform->addElement('header', 'optionshdr', get_string('optionshdr', 'mod_booking'));
        $mform->setExpanded('optionshdr', true);

        // Resolve every row that will be rendered (loaded + submitted + the spare add row),
        // allocating a stable id to any row that does not have one yet so it can immediately
        // be chosen as a parent.
        $baserepeats = max(count($loadedrows) + 1, 1);
        $resolved = $this->resolve_rows($loadedrows, $nextid, $baserepeats);

        // Parent dropdown: top level + every labelled top level row. Only one level of
        // hierarchy is allowed, so rows that are themselves children may not be parents.
        $parentoptions = [0 => get_string('optiontoplevel', 'mod_booking')];
        foreach ($resolved as $row) {
            if (trim($row['label']) !== '' && $row['parentid'] === 0) {
                $parentoptions[$row['id']] = $row['label'];
            }
        }

        $repeatarray = [
            $mform->createElement('text', 'optionlabel', get_string('optionlabel', 'mod_booking')),
            $mform->createElement('select', 'parent', get_string('optionparent', 'mod_booking'), $parentoptions),
            $mform->createElement('advcheckbox', 'optiondelete', get_string('optiondelete', 'mod_booking')),
            $mform->createElement('hidden', 'optionid', 0),
        ];
        $repeatoptions = [
            'optionlabel' => ['type' => PARAM_TEXT],
            'parent' => ['type' => PARAM_INT],
            'optiondelete' => ['type' => PARAM_BOOL],
            'optionid' => ['type' => PARAM_INT],
        ];

        $this->repeat_elements(
            $repeatarray,
            $baserepeats,
            $repeatoptions,
            'option_repeats',
            'option_add',
            1,
            get_string('addoption', 'mod_booking'),
            true
        );

        // Prefill defaults (used for new rows; existing rows keep their submitted values).
        foreach ($resolved as $i => $row) {
            $mform->setDefault("optionlabel[$i]", $row['label']);
            $mform->setDefault("parent[$i]", $row['parentid']);
            $mform->setDefault("optionid[$i]", $row['id']);
        }

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Resolves the rows that will be rendered, assigning ids to rows that lack one.
     *
     * Mirrors how repeat_elements() derives its row count, then merges submitted values
     * (when the form was posted) over the loaded rows and allocates ids from $nextid.
     *
     * @param array $loadedrows stored rows (each ['id','label','parentid'])
     * @param int $nextid first free id
     * @param int $baserepeats base repeat count passed to repeat_elements()
     * @return array indexed by row position, each ['id','label','parentid']
     */
    private function resolve_rows(array $loadedrows, int $nextid, int $baserepeats): array {
        $repeats = optional_param('option_repeats', $baserepeats, PARAM_INT);
        if (optional_param('option_add', '', PARAM_TEXT) !== '') {
            $repeats += 1;
        }

        $submittedlabels = optional_param_array('optionlabel', [], PARAM_TEXT);
        $submittedids = optional_param_array('optionid', [], PARAM_INT);
        $submittedparents = optional_param_array('parent', [], PARAM_INT);
        $posted = !empty($submittedlabels) || optional_param('option_repeats', 0, PARAM_INT) > 0;

        $alloc = $nextid;
        $resolved = [];
        for ($i = 0; $i < $repeats; $i++) {
            if ($posted) {
                $id = (int) ($submittedids[$i] ?? 0);
                $label = (string) ($submittedlabels[$i] ?? '');
                $parentid = (int) ($submittedparents[$i] ?? 0);
            } else {
                $id = (int) ($loadedrows[$i]['id'] ?? 0);
                $label = (string) ($loadedrows[$i]['label'] ?? '');
                $parentid = (int) ($loadedrows[$i]['parentid'] ?? 0);
            }
            if ($id < 1) {
                $id = $alloc++;
            } else if ($id >= $alloc) {
                $alloc = $id + 1;
            }
            $resolved[$i] = ['id' => $id, 'label' => $label, 'parentid' => $parentid];
        }

        return $resolved;
    }

    /**
     * Server side validation, including the single level hierarchy check.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (empty($data['fieldid'])) {
            $errors['fieldid'] = get_string('error_nofieldselected', 'mod_booking');
            return $errors;
        }

        $rows = self::extract_rows($data);
        foreach (hierarchy_manager::validate_rows($rows) as $i => $message) {
            $errors["optionlabel[$i]"] = $message;
        }

        return $errors;
    }

    /**
     * Turns the submitted indexed arrays into a list of option rows.
     *
     * @param array|\stdClass $data
     * @return array list of ['id','label','parentid','delete']
     */
    public static function extract_rows($data): array {
        $data = (array) $data;
        $labels = $data['optionlabel'] ?? [];
        $ids = $data['optionid'] ?? [];
        $parents = $data['parent'] ?? [];
        $deletes = $data['optiondelete'] ?? [];

        $rows = [];
        foreach ($labels as $i => $label) {
            $rows[$i] = [
                'label' => (string) $label,
                'id' => (int) ($ids[$i] ?? 0),
                'parentid' => (int) ($parents[$i] ?? 0),
                'delete' => !empty($deletes[$i]),
            ];
        }

        return $rows;
    }
}
