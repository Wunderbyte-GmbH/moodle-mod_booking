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
 * Price categories form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace mod_booking\form;

use cache_helper;
use context;
use context_system;
use core_form\dynamic_form;
use mod_booking\local\pricecategories_handler;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Add price categories form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pricecategories_form extends dynamic_form {
    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;
        $pchandler = new pricecategories_handler();
        $pricecategories = $pchandler->get_pricecategories();

        $repeats = max(count($pricecategories), 1);

        $repeatarray = [];

        $repeatarray[] = $mform->createElement('hidden', 'pricecategoryid', 0);
        $repeatarray[] = $mform->createElement('hidden', 'pricecategoryordernum', 0);
        $repeatarray[] = $mform->createElement('text', 'pricecategoryidentifier', get_string('pricecategoryidentifier', 'booking'));
        $repeatarray[] = $mform->createElement('text', 'pricecategoryname', get_string('pricecategoryname', 'booking'));
        $repeatarray[] = $mform->createElement('float', 'defaultvalue', get_string('defaultvalue', 'booking'));
        $repeatarray[] = $mform->createElement('text', 'pricecatsortorder', get_string('pricecatsortorder', 'mod_booking'));
        $repeatarray[] = $mform->createElement(
            'advcheckbox',
            'disablepricecategory',
            get_string('disablepricecategory', 'booking')
        );

        $repeateloptions = [
            'pricecategoryid' => [
                'type' => PARAM_INT,
            ],
            'pricecategoryordernum' => [
                'type' => PARAM_INT,
            ],
            'pricecategoryidentifier' => [
                'helpbutton' => ['pricecategoryidentifier', 'booking'],
                'disabledif' => ['disablepricecategory', 'checked'],
                'type' => PARAM_TEXT,
            ],
            'pricecategoryname' => [
                'helpbutton' => ['pricecategoryname', 'booking'],
                'disabledif' => ['disablepricecategory', 'checked'],
                'type' => PARAM_RAW,
            ],
            'defaultvalue' => [
                'helpbutton' => ['defaultvalue', 'booking'],
                'disabledif' => ['disablepricecategory', 'checked'],
                'type' => PARAM_FLOAT,
            ],
            'pricecatsortorder' => [
                'helpbutton' => ['pricecatsortorder', 'mod_booking'],
                'disabledif' => ['disablepricecategory', 'checked'],
                'type' => PARAM_INT,
            ],
            'disablepricecategory' => [
                'helpbutton' => ['disablepricecategory', 'booking'],
                'type' => PARAM_BOOL,
            ],
        ];

        $this->repeat_elements(
            $repeatarray,
            $repeats,
            $repeateloptions,
            'pricecategories_repeats',
            'addfields',
            1,
            get_string('addpricecategory', 'booking')
        );
        $mform->freeze('pricecategoryidentifier[0]'); // Makes it uneditable (disabled).
        $mform->freeze('disablepricecategory[0]'); // Makes it uneditable (disabled).

        $this->add_action_buttons(true);
    }

    /**
     * Form validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     *
     */
    public function validation($data, $files) {

        global $DB;

        $errors = [];

        // Validate price categories.
        for ($i = 0; $i < $data['pricecategories_repeats']; $i++) {
            // The price category identifier is not allowed to be empty.
            if (empty($data["pricecategoryidentifier"][$i])) {
                $errors["pricecategoryidentifier[$i]"] = get_string('erroremptypricecategoryidentifier', 'mod_booking');
            } else if ($i != 0 && $data["pricecategoryidentifier"][$i] == 'default') {
                $errors["pricecategoryidentifier[$i]"] = get_string('errorpricecategoryidentifierdefaultnotallowed', 'mod_booking');
            } else if ($i == 0 && $data["pricecategoryidentifier"][$i] != 'default') {
                $errors["pricecategoryidentifier[$i]"] = get_string('errorpricecategoryidentifiermustbedefault', 'mod_booking');
            }
            if (empty($data["pricecategoryname"][$i])) {
                $errors["pricecategoryname[$i]"] = get_string('erroremptypricecategoryname', 'mod_booking');
            }
            // The price category name is not allowed to be empty.
            if (empty($data["pricecategoryname"][$i])) {
                $errors["pricecategoryname[$i]"] = get_string('erroremptypricecategoryname', 'mod_booking');
            }
            // Not more than 2 decimals are allowed for the default price.
            if (!empty($data["defaultvalue"][$i]) && is_float($data["defaultvalue"][$i])) {
                $numberofdecimals = strlen(substr(strrchr($data["defaultvalue"][$i], "."), 1));
                if ($numberofdecimals > 2) {
                    $errors["defaultvalue[$i]"] = get_string('errortoomanydecimals', 'mod_booking');
                }
            }
            // Sort order value is not allowed to be empty.
            if (empty($data["pricecatsortorder"][$i])) {
                $errors["pricecatsortorder[$i]"] = get_string('error:entervalue', 'mod_booking');
            }
            // The identifier of a price category needs to be unique.
            if ($this->value_is_duplicated($data["pricecategoryidentifier"], $data["pricecategoryidentifier"][$i])) {
                $errors["pricecategoryidentifier[$i]"] = get_string('errorduplicatepricecategoryidentifier', 'mod_booking');
            }
            // The name of a price category needs to be unique.
            if ($this->value_is_duplicated($data["pricecategoryname"], $data["pricecategoryname"][$i])) {
                $errors["pricecategoryname[$i]"] = get_string('errorduplicatepricecategoryname', 'mod_booking');
            }
            // The pricatsortorder of a price category needs to be unique.
            if ($this->value_is_duplicated($data["pricecatsortorder"], $data["pricecatsortorder"][$i])) {
                $errors["pricecatsortorder[$i]"] = get_string('errorduplicatepricecatsortorder', 'mod_booking');
            }
        }
        return $errors;
    }

    /**
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission(): stdClass {
        global $DB;

        // This is the correct place to save and update pricecategories.
        $data = $this->get_data();

        $handler = new pricecategories_handler();
        $handler->process_pricecategories_form($data);

        return $this->get_data();
    }

    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        global $DB;

        $pchandler = new pricecategories_handler();
        $pricecategories = $pchandler->get_pricecategories();
        $data = [];

        // Set default values for existing categories.
        if (!empty($pricecategories)) {
            $i = 0;
            foreach ($pricecategories as $category) {
                $data['pricecategoryid[' . $i . ']'] = $category->id;
                $data['pricecategoryidentifier[' . $i . ']'] = $category->identifier;
                $data['pricecategoryname[' . $i . ']'] = $category->name;
                $data['defaultvalue[' . $i . ']'] = $category->defaultvalue;
                $data['pricecatsortorder[' . $i . ']'] = $category->pricecatsortorder;
                // We don't need ordernum anymore, so we just store pricecatsortorder there too.
                $data['pricecategoryordernum[' . $i . ']'] = $category->pricecatsortorder;
                $data['disablepricecategory[' . $i . ']'] = $category->disabled;
                $i++;
            }
        }

        $this->set_data($data);
    }

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        return context_system::instance();
    }

    /**
     * Check access for dynamic submission.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('moodle/site:config', context_system::instance());
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/pricecategories.php');
    }

    /**
     * Helper function to check for duplicates.
     * @param array $array
     * @param mixed $value
     * @return bool
     */
    private function value_is_duplicated(array $array, $value): bool {
        // Count all the values in the array.
        $valuecounts = array_count_values($array);
        // Check if the specific value exists more than once.
        return isset($valuecounts[$value]) && $valuecounts[$value] > 1;
    }
}
