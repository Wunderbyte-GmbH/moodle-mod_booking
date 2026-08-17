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

namespace mod_booking\checklist;

use mod_booking\singleton_service;
use local_wunderbyte_table\local\pdf\pdfa_pdf;
use mod_booking\booking_option;
use stdClass;

/**
 * Class checklist_generator
 *
 * @package    mod_booking
 * @copyright  2025 Christian Badusch <christian.badusch@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checklist_generator {
    /**
     * @var \mod_booking\booking_option $bookingoption
     */
    private $bookingoption;

    /**
     * @var string
     */
    private $orientation;

    /**
     * checklist_generator constructor.
     *
     * @param \mod_booking\booking_option $bookingoption
     */
    public function __construct(\mod_booking\booking_option $bookingoption) {
        $this->bookingoption = $bookingoption;
        $this->orientation = 'P'; // Portrait by default.
    }

    /**
     * Prepares and generates a PDF from the checklist HTML.
     *
     * @return void
     */
    public function generate_pdf() {
        // Generate PDF from the (placeholder replaced) HTML.
        $this->download_pdf_from_html($this->render_html());
    }

    /**
     * Returns all the dates in a single string.
     *
     * @return string
     */
    private function get_concatenated_dates(): string {
        $sessions = $this->bookingoption->return_array_of_sessions();
        if (empty($sessions)) {
            return '';
        }
        $dates = array_map(function ($session) {
            return $session['datestring'];
        }, $sessions);
        return implode('<br>', $dates);
    }


    /**
     * Maps placeholder strings to their actual values.
     *
     * @return array
     */
    private function get_placeholder_replacements(): array {
        return [
            '[[booking_id]]' => $this->bookingoption->option->id ?? '',
            '[[booking_text]]' => $this->bookingoption->option->text ?? '',
            '[[max_answers]]' => $this->bookingoption->option->maxanswers ?? '',
            '[[institution]]' => $this->bookingoption->option->institution ?? '',
            '[[location]]' => $this->bookingoption->option->location,
            '[[coursestarttime]]' => userdate($this->bookingoption->option->coursestarttime) ?? '',
            '[[courseendtime]]' => userdate($this->bookingoption->option->courseendtime) ?? '',
            '[[description]]' => format_text($this->bookingoption->option->description, FORMAT_HTML) ?? '',
            '[[address]]' => $this->bookingoption->option->address ?? '',
            '[[teachers]]' => implode(', ', $this->get_teachers_names($this->bookingoption)) ?? '',
            '[[titleprefix]]' => $this->bookingoption->option->titleprefix ?? '',
            '[[dayofweektime]]' => $this->bookingoption->option->dayofweektime ?? '',
            '[[annotation]]' => $this->bookingoption->option->annotation ?? '',
            '[[courseid]]' => $this->bookingoption->option->courseid ?? '',
            '[[course_url]]' => property_exists($this->bookingoption->option, 'courseid') && $this->bookingoption->option->courseid
                ? (new \moodle_url('/course/view.php', ['id' => $this->bookingoption->option->courseid]))->out()
                : '',
            '[[option_times]]' => $this->bookingoption->optiontimes ?? '',
            '[[contact]]' => $this->get_responsible_contact($this->bookingoption) ?? '',
            '[[dates]]' => $this->get_concatenated_dates() ?? '',
            // Add other placeholders here as needed.
        ];
    }

    /**
     * Returns the checklist HTML: configured template (setting checklisthtml) or the
     * default template, with all placeholders replaced.
     *
     * @return string
     */
    public function render_html(): string {
        // Retrieve checklist HTML from configuration.
        $checklisthtml = get_config('booking', 'checklisthtml');
        // Use a default template if not configured.
        if (empty(trim(strip_tags($checklisthtml)))) {
            $checklisthtml = $this->get_default_checklist_html();
        }

        $replacements = $this->get_placeholder_replacements();

        // Replace placeholders in the configured HTML.
        return strtr($checklisthtml, $replacements);
    }

    /**
     * Whether the checklist is generated as PDF/A-2b (setting booking/pdfaenabled).
     *
     * @return bool
     */
    public static function pdfa_enabled(): bool {
        return !empty(get_config('booking', 'pdfaenabled'));
    }

    /**
     * Builds the checklist PDF document from the given HTML.
     *
     * With the setting booking/pdfaenabled the document is PDF/A-2b (see {@see pdfa_pdf}:
     * all fonts embedded, core font names in the template mapped to the embeddable
     * FreeFonts); otherwise it is generated exactly as before.
     *
     * @param string $html
     * @return \pdf the finished document, ready for Output()
     */
    public function create_pdf_from_html(string $html): \pdf {
        if (self::pdfa_enabled()) {
            $pdf = new pdfa_pdf($this->orientation, PDF_UNIT, PDF_PAGE_FORMAT);
        } else {
            $pdf = new checklist_pdf($this->orientation, PDF_UNIT, PDF_PAGE_FORMAT);
        }
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf;
    }

    /**
     * Generates a PDF file from HTML and downloads it.
     *
     * @param string $html
     * @return void
     */
    public function download_pdf_from_html(string $html) {
        $pdf = $this->create_pdf_from_html($html);
        $pdf->Output('checklist.pdf', 'D');
    }

    /**
     * Retrieve teacher names.
     *
     * @param \mod_booking\booking_option $bookingoption
     * @return array
     */
    private function get_teachers_names($bookingoption) {
        if (empty($bookingoption->teachers)) {
            return [];
        }

        return array_map(function ($teacher) {
            return "{$teacher->firstname} {$teacher->lastname}";
        }, $bookingoption->teachers);
    }

    /**
     * Retrieve the contact responsible.
     *
     * @param \mod_booking\booking_option $bookingoption
     * @return string
     */
    private function get_responsible_contact($bookingoption) {
        // Check if the property exists and is not empty, otherwise provide a fallback.
        if (property_exists($bookingoption->option, 'responsiblecontact') && !empty($bookingoption->option->responsiblecontact)) {
            return $bookingoption->option->responsiblecontact;
        }
        return 'Not specified';
    }

    /**
     * Provide default HTML for the checklist.
     *
     * @return string
     */
    protected function get_default_checklist_html(): string {
        return '<table cellpadding="5" width="100%" border="1">
    <thead>
        <tr>
            <th colspan="2" style="background-color: #cce5ff; text-align: left;"><b>Seminar Information</b></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>[[booking_text]]</td>
            <td>'
            .  get_string('checklistfirstcourseday', 'booking') . ' [[coursestarttime]]</td>
        </tr>
        <tr>
            <td>'
            .  get_string('checklistdaten', 'booking') . ': [[dates]]  <br>'
            .  get_string('checklistreferentin', 'booking') . ': [[teachers]]</td>
            <td>'
            .  get_string('checklistraum', 'booking') . ': [[location]] [[institution]]</td>
        </tr>
        <tr>
            <th colspan="2" style="background-color: #cce5ff; text-align: left;"><b>'
            .  get_string('checklistpreparation', 'booking') . '</b></th>
        </tr>
        <tr>
            <td colspan="2">
              ☐ Check 1
            </td>
        </tr>
        <tr>
            <td colspan="2">
              ☐ Check 2
            </td>
        </tr>
        <tr>
            <td >
              ☐ Check 3
            </td>
            <td bgcolor="gray">
              &#8594; SubCheck 3
            </td>
        </tr>
        <tr>
            <td >
              ☐ Check 4
            </td>
            <td bgcolor="gray">
              ☐ SubCheck 4
            </td>
        </tr>
        <tr>
            <td >
              ☐ Check 5
            </td>
            <td bgcolor="gray">
              ☐ SubCheck 5
            </td>
        </tr>
                <tr>
            <td colspan="2">
              ☐ Check 6
            </td>
        </tr>
        <tr>
            <th colspan="2" style="background-color: #cce5ff; text-align: left;"><b>'
            .  get_string('checklisttwoweeksprior', 'booking') . '</b></th>
        </tr>
        <tr>
            <td colspan="2">
                ☐ Check 1<br>
                ☐ Check 2<br>
                ☐ Check 3<br>
            </td>
        </tr>

        <tr>
            <th colspan="2" style="background-color: #cce5ff; text-align: left;"><b>'
            .  get_string('checklistseminarabschluss', 'booking') . '</b></th>
        </tr>
        <tr>
            <td colspan="2">
                ☐ Check 1<br>
                ☐ Check 2<br>
                ☐ Check 3<br>
            </td>
        </tr>
    </tbody>
    </table>';
    }

    /**
     * Cleanup and sanitize filename.
     *
     * @param string $filename
     * @return string
     */
    private function cleanup_filename(string $filename): string {
        $filename = str_replace(' ', '_', $filename); // Replaces all spaces with underscores.
        $filename = preg_replace('/[^A-Za-z0-9\_]/', '', $filename); // Removes special chars.
        return preg_replace('/\_+/', '_', $filename); // Replace multiple underscores with exactly one.
    }
}
