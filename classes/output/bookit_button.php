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
 * This file contains the definition for the renderable classes for column 'price'.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use mod_booking\singleton_service;
use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying bookit button.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookit_button implements renderable, templatable {
    /** @var array $data */
    public $data = [];

    /**
     * @param array $data
     */
    /**
     * Constructor.
     *
     * @param array $data
     *
     */
    public function __construct(array $data = []) {

        global $USER;

        if (class_exists('local_shopping_cart\shopping_cart')) {
            $data['shoppingcartisavailable'] = true;
        }

        if (empty($data['main']['label'])) {
            $data['main']['label'] = get_string('booknow', 'mod_booking');
        }

        if (empty($data['main']['class'])) {
            $data['main']['class'] = 'btn btn-primary';
        }

        if (empty($data['area'])) {
            $data['area'] = 'option';
        }

        if (empty($data['userid'])) {
            $data['userid'] = $USER->id;
        }

        if (empty($data['componentname'])) {
            $data['componentname'] = 'mod_booking';
        }

        $user = singleton_service::get_instance_of_user($data['userid']);
        $pricecategoryidentifier = singleton_service::get_pricecategory_for_user($user) ?? '';

        if (empty($data['pricecategoryidentifier'])) {
            $data['pricecategoryidentifier'] = $pricecategoryidentifier;
        }

        $this->data = $data;
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        return $this->data;
    }
}
