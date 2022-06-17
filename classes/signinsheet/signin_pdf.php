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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/pdflib.php');
/**
 * Extend the TCPDF class in order to add custom page break
 * @author David Bogner
 *
 */
class signin_pdf extends \pdf {

    private $file = false;

    /**
     *
     * @param $h (float) Cell height. Default value: 0.
     * @return boolean
     */
    public function go_to_newline($h) {
        return $this->checkPageBreak($h, '', true);
    }

    /**
     * Page footer
     */
    public function footer() {
        // Position at 15 mm from bottom.
        $this->SetY(-20);

        if ($this->file) {
            $filepath = $this->file->get_filepath() . $this->file->get_filename();
            $imageinfo = $this->file->get_imageinfo();
            $signinsheetlogofooter = $this->file->get_content();
            $filetype = str_replace('image/', '', $this->file->get_mimetype());
            $w = 0;
            $h = 15;

            if ($imageinfo['height'] > 15) {
                $h = 15;
            }

            $this->Image('@' . $signinsheetlogofooter, '', '', $w, $h, $filetype, '', 'T', true,
                    150, 'C', false, false, 1, false, false, false);
        }
    }

    public function setfooterimage($file) {
        $this->file = $file;
    }
}
