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
namespace mod_booking\signinsheet;

require_once($CFG->libdir . '/tcpdf/tcpdf.php');

/**
 * Extend the TCPDF class in order to add custom page break
 * @author David Bogner
 *
 */
class signin_pdf extends \TCPDF {

    /**
     *
     * @param $h (float) Cell height. Default value: 0.
     * @return boolean
     */
    public function go_to_newline($h) {
        return $this->checkPageBreak($h, '', true);
    }
}