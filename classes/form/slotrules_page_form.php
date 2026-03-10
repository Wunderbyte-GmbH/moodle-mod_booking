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
 * Slot rules page form.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use mod_booking\price as booking_price;
use mod_booking\local\slotbooking\slot_rule_manager;

/**
 * Form to create/update slot rules.
 */
class slotrules_page_form extends \moodleform {
    /**
     * Definition.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;

        $cmid = (int)($this->_customdata['cmid'] ?? 0);
        $optionid = (int)($this->_customdata['optionid'] ?? 0);
        $ruleid = (int)($this->_customdata['ruleid'] ?? 0);

        $mform->addElement('hidden', 'id', $cmid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'optionid', $optionid);
        $mform->setType('optionid', PARAM_INT);

        $mform->addElement('hidden', 'ruleid', $ruleid);
        $mform->setType('ruleid', PARAM_INT);

        $mform->addElement('header', 'slotruleheader', get_string('slot_rule_editor_formheader', 'mod_booking'));

        $ruletypeoptions = [
            slot_rule_manager::RULETYPE_CLOSED => get_string('slot_rule_type_closed', 'mod_booking'),
            slot_rule_manager::RULETYPE_PRICE => get_string('slot_rule_type_price', 'mod_booking'),
        ];
        $mform->addElement('select', 'ruletype', get_string('slot_rule_type', 'mod_booking'), $ruletypeoptions);
        $mform->setType('ruletype', PARAM_ALPHAEXT);

        $mform->addElement('text', 'priority', get_string('slot_rule_priority', 'mod_booking'));
        $mform->setType('priority', PARAM_INT);
        $mform->setDefault('priority', 100);

        $mform->addElement('advcheckbox', 'useactiverange', get_string('slot_rule_useactiverange', 'mod_booking'));
        $mform->setType('useactiverange', PARAM_INT);

        $mform->addElement('date_time_selector', 'activefrom', get_string('slot_rule_activefrom', 'mod_booking'));
        $mform->setType('activefrom', PARAM_INT);
        $mform->disabledIf('activefrom', 'useactiverange', 'neq', 1);

        $mform->addElement('date_time_selector', 'activeuntil', get_string('slot_rule_activeuntil', 'mod_booking'));
        $mform->setType('activeuntil', PARAM_INT);
        $mform->disabledIf('activeuntil', 'useactiverange', 'neq', 1);

        $mform->addElement('advcheckbox', 'weekday_1', get_string('slot_day_mon', 'mod_booking'));
        $mform->addElement('advcheckbox', 'weekday_2', get_string('slot_day_tue', 'mod_booking'));
        $mform->addElement('advcheckbox', 'weekday_3', get_string('slot_day_wed', 'mod_booking'));
        $mform->addElement('advcheckbox', 'weekday_4', get_string('slot_day_thu', 'mod_booking'));
        $mform->addElement('advcheckbox', 'weekday_5', get_string('slot_day_fri', 'mod_booking'));
        $mform->addElement('advcheckbox', 'weekday_6', get_string('slot_day_sat', 'mod_booking'));
        $mform->addElement('advcheckbox', 'weekday_7', get_string('slot_day_sun', 'mod_booking'));
        for ($i = 1; $i <= 7; $i++) {
            $mform->setType('weekday_' . $i, PARAM_INT);
        }

        $mform->addElement('text', 'timerangestart', get_string('slot_rule_timerangestart', 'mod_booking'));
        $mform->setType('timerangestart', PARAM_TEXT);
        $mform->setDefault('timerangestart', '');

        $mform->addElement('text', 'timerangeend', get_string('slot_rule_timerangeend', 'mod_booking'));
        $mform->setType('timerangeend', PARAM_TEXT);
        $mform->setDefault('timerangeend', '');

        $mform->addElement('header', 'slotrulepriceheader', get_string('slot_rule_priceheader', 'mod_booking'));
        $mform->hideIf('slotrulepriceheader', 'ruletype', 'neq', slot_rule_manager::RULETYPE_PRICE);

        $pricecategoryoptions = self::get_pricecategory_options($optionid);
        $mform->addElement(
            'select',
            'pricecategoryidentifier',
            get_string('slot_rule_pricecategoryidentifier', 'mod_booking'),
            $pricecategoryoptions
        );
        $mform->setType('pricecategoryidentifier', PARAM_ALPHANUMEXT);
        $mform->setDefault('pricecategoryidentifier', 'default');
        $mform->hideIf('pricecategoryidentifier', 'ruletype', 'neq', slot_rule_manager::RULETYPE_PRICE);

        $pricemodes = [
            slot_rule_manager::PRICEMODE_ABSOLUTE => get_string('slot_rule_pricemode_absolute', 'mod_booking'),
            slot_rule_manager::PRICEMODE_DELTA => get_string('slot_rule_pricemode_delta', 'mod_booking'),
            slot_rule_manager::PRICEMODE_FACTOR => get_string('slot_rule_pricemode_factor', 'mod_booking'),
        ];
        $mform->addElement('select', 'pricemode', get_string('slot_rule_pricemode', 'mod_booking'), $pricemodes);
        $mform->setType('pricemode', PARAM_ALPHAEXT);
        $mform->hideIf('pricemode', 'ruletype', 'neq', slot_rule_manager::RULETYPE_PRICE);

        $mform->addElement('float', 'pricevalue', get_string('slot_rule_pricevalue', 'mod_booking'));
        $mform->setType('pricevalue', PARAM_FLOAT);
        $mform->hideIf('pricevalue', 'ruletype', 'neq', slot_rule_manager::RULETYPE_PRICE);

        $mform->addElement('text', 'pricecurrency', get_string('slot_rule_pricecurrency', 'mod_booking'));
        $mform->setType('pricecurrency', PARAM_TEXT);
        $mform->hideIf('pricecurrency', 'ruletype', 'neq', slot_rule_manager::RULETYPE_PRICE);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $timerangestart = trim((string)($data['timerangestart'] ?? ''));
        $timerangeend = trim((string)($data['timerangeend'] ?? ''));

        if ($timerangestart !== '' && !preg_match('/^\d{2}:\d{2}$/', $timerangestart)) {
            $errors['timerangestart'] = get_string('slot_error_timeformat', 'mod_booking');
        }

        if ($timerangeend !== '' && !preg_match('/^\d{2}:\d{2}$/', $timerangeend)) {
            $errors['timerangeend'] = get_string('slot_error_timeformat', 'mod_booking');
        }

        if (!empty($data['useactiverange']) && !empty($data['activefrom']) && !empty($data['activeuntil'])) {
            if ((int)$data['activeuntil'] <= (int)$data['activefrom']) {
                $errors['activeuntil'] = get_string('slot_rule_error_activerange', 'mod_booking');
            }
        }

        if (($data['ruletype'] ?? '') === slot_rule_manager::RULETYPE_PRICE) {
            if (($data['pricecategoryidentifier'] ?? '') === '') {
                $errors['pricecategoryidentifier'] = get_string('required');
            } else {
                $pricecategoryoptions = self::get_pricecategory_options((int)($data['optionid'] ?? 0));
                if (!array_key_exists((string)$data['pricecategoryidentifier'], $pricecategoryoptions)) {
                    $errors['pricecategoryidentifier'] = get_string('invaliddata', 'error');
                }
            }
        }

        return $errors;
    }

    /**
     * Return selectable active price categories.
     *
     * @param int $optionid
     * @return array
     */
    private static function get_pricecategory_options(int $optionid): array {
        $pricehandler = new booking_price('option', $optionid);
        $options = [];

        foreach ((array)$pricehandler->pricecategories as $pricecategory) {
            $identifier = (string)($pricecategory->identifier ?? '');
            if ($identifier === '') {
                continue;
            }

            $name = trim((string)($pricecategory->name ?? ''));
            $options[$identifier] = $name !== '' ? $name . ' (' . $identifier . ')' : $identifier;
        }

        if (empty($options)) {
            $options['default'] = 'default';
        }

        return $options;
    }
}
