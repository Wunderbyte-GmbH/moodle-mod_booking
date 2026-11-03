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
 * PDF/A sign-in sheet with the footer of the HTML template.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\signinsheet;

use local_wunderbyte_table\local\pdf\pdfa_pdf;

/**
 * PDF/A-2b sign-in sheet (setting local_wunderbyte_table/pdfaenabled) printing the footer of the
 * HTML template on every page.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdfa_signin_pdf extends pdfa_pdf {
    use html_footer_trait;

    /**
     * Page footer: the footer of the HTML template, nothing without it.
     */
    public function footer() {
        if ($this->footerhtml !== '') {
            $this->print_html_footer();
        }
    }
}
