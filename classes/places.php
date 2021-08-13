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
namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

/**
 * Util class.
 *
 * @package mod_booking
 * @copyright 2018 Andraž Prinčič, David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class places {

    public $maxanswers = 0;

    public $available = 0;

    public $maxoverbooking = 0;

    public $overbookingavailable = 0;

    public function __construct($maxanswers, $available, $maxoverbooking, $overbookingavailable) {
        $this->maxanswers = $maxanswers;
        $this->available = $available;
        $this->maxoverbooking = $maxoverbooking;
        $this->overbookingavailable = $overbookingavailable;
    }
}
