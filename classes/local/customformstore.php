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
 * The cartstore class handles the in and out of the cache.
 *
 * @package mod_booking
 * @author Georg Maißer
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use cache;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class cartstore
 *
 * @author Georg Maißer
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class customformstore {

    /** @var int */
    protected $userid = 0;

    /** @var int */
    protected $itemid = 0;

    /** @var object */
    protected $cache = null;

    /** @var string */
    protected $cachekey = '';

    /**
     * Cartstore constructor.
     * @param int $userid
     * @param int $itemid
     * @return void
     */
    public function __construct(int $userid, int $itemid) {
        $this->userid = $userid;
        $this->itemid = $itemid;
        $this->cache = cache::make('mod_booking', 'customformuserdata');
        $this->cachekey = $userid . "_" . $itemid . '_customform';
    }
    /**
     * Validates each submission entry.
     * @return object
     */
    public function get_customform_data() {
        return $this->cache->get($this->cachekey);
    }

    /**
     * Validates each submission entry.
     * @param object $data
     * @return void
     */
    public function set_customform_data($data) {
        $this->cache->set($this->cachekey, $data);
    }

    /**
     * Validates each submission entry.
     * @return void
     */
    public function delete_customform_data() {
        $this->cache->delete($this->cachekey);
    }

    /**
     * Server-side form validation.
     * @param object $customform
     * @param array $data
     * @return array $errors
     */
    public function validation($customform, $data): array {
        $errors = [];
        foreach ($customform as $key => $formelement) {
            if (!empty($formelement->notempty)) {
                $identifier = 'customform_' . $formelement->formtype . "_" . $key;

                if (empty($data[$identifier])) {
                    $errors[$identifier] = get_string('error:mustnotbeempty', 'mod_booking');
                }
            }
        }
        return $errors;
    }

    /**
     * Validates each submission entry.
     * @param object $customform
     * @param object $customformuserdata
     * @return object
     */
    public static function validation_data($customform, $customformuserdata) {
        foreach ($customform as $key => &$customitem) {
            if (!empty($customitem->notempty)) {
                if (empty($customformuserdata)) {
                    $customitem->error = true;
                } else {
                    $found = false;
                    foreach ($customformuserdata as $keyformitem => $customformitem) {
                        if ($keyformitem == 'customform_' . $customitem->formtype . '_' . $key) {
                            $found = true;
                            if (str_contains($customitem->formtype, 'select')) {
                                $customitem->selectedvalue = $customformitem;
                                $selecttype = gettype($customitem->selectedvalue);
                                if ($selecttype != 'string') {
                                    $customitem->error = true;
                                } else {
                                    $customitem->error = false;
                                }
                            } else {
                                $customitem->value = $customformitem;
                                if (empty($customformitem)) {
                                    $customitem->error = true;
                                } else {
                                    $customitem->error = false;
                                }
                            }
                        }
                    }
                    if (!$found) {
                        $customitem->error = true;
                    }
                }
            }
        }
        return $customform;
    }
}
