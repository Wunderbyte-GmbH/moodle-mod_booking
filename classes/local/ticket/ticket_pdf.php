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

namespace mod_booking\local\ticket;

use moodle_url;
use stdClass;
use tool_certificate\element_helper;
use tool_certificate\template;

/**
 * Renders an entry ticket as a PDF from a tool_certificate template.
 *
 * tool_certificate is used purely as a layout engine here. Unlike
 * \tool_certificate\template::generate_pdf() this renderer never needs a
 * {tool_certificate_issues} record, so creating a ticket does not write an issue,
 * does not fire certificate events and does not send the tool_certificate mail.
 *
 * Two element types cannot work without a real issue and are therefore intercepted:
 *
 * - certificateelement_program reads its value from core_customfield data keyed by the
 *   *issue id*. We instead take the value straight from the data snapshot stored on the
 *   ticket, so the same placeholders (bookingoptionname, sessions, location, ...) that
 *   mod_booking registers in mod_booking_tool_certificate_fields() keep working.
 * - certificateelement_code hardcodes the tool_certificate verification URL, which can
 *   never resolve a ticket code. We render the QR code against mod_booking's own
 *   verifyticket.php instead.
 *
 * Every other element type (text, userfield, image, userpicture, date, border,
 * digitalsignature) is delegated to the element class unchanged.
 *
 * Known fidelity gap: a real issue renders program values through
 * core_customfield data_controller::export_value(), which runs format_text() on textarea
 * fields. The values we pass are the raw (already cleaned) snapshot values, so multi-line
 * fields such as teachers or sessions can look slightly different from the preview shown
 * in the tool_certificate template designer.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_pdf {
    /** @var int Display value of a code element that renders a QR code. */
    private const CODE_DISPLAY_QRCODE = 4;

    /** @var int Display value of a code element that renders the verification URL as text. */
    private const CODE_DISPLAY_URL = 3;

    /** @var int Display value of a code element that renders the code as a link. */
    private const CODE_DISPLAY_CODELINK = 2;

    /**
     * Render a ticket PDF and return it as a binary string.
     *
     * @param int $templateid Id of the tool_certificate template used as layout.
     * @param stdClass $ticket A {booking_tickets} record (needs at least id, code, userid, timecreated).
     * @param stdClass $user The user the ticket belongs to.
     * @param array $data The placeholder snapshot, as built by certificateclass::build_certificate_data().
     *
     * @return string The PDF, or an empty string if it could not be rendered.
     */
    public static function render(int $templateid, stdClass $ticket, stdClass $user, array $data): string {
        global $CFG, $DB;

        if (empty($templateid) || !class_exists('tool_certificate\\template')) {
            return '';
        }
        if (!$DB->record_exists('tool_certificate_templates', ['id' => $templateid])) {
            return '';
        }

        require_once($CFG->libdir . '/pdflib.php');

        $template = template::instance($templateid);
        $pages = $template->get_pages();
        if (empty($pages)) {
            return '';
        }

        $fakeissue = self::build_fake_issue($ticket, $data);

        // Match tool_certificate: render in the recipient's language if configured, site language otherwise.
        if (get_config('tool_certificate', 'issuelang') && !empty($user->lang)) {
            $currentlang = force_current_language($user->lang);
        } else {
            $currentlang = force_current_language($CFG->lang);
        }

        $pdf = new \pdf();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetTitle($template->get_formatted_name());
        $pdf->setViewerPreferences(['DisplayDocTitle' => true]);
        $pdf->SetAutoPageBreak(true, 0);

        foreach ($pages as $page) {
            $pagerecord = $page->to_record();
            $orientation = $pagerecord->width > $pagerecord->height ? 'L' : 'P';
            $pdf->AddPage($orientation, [$pagerecord->width, $pagerecord->height]);
            $pdf->SetMargins($pagerecord->leftmargin, 0, $pagerecord->rightmargin);

            foreach ($page->get_elements() as $element) {
                try {
                    self::render_element($pdf, $element, $user, $fakeissue, $ticket, $data);
                } catch (\Throwable $e) {
                    // A single broken element must never stop a booking from producing a ticket.
                    debugging(
                        'mod_booking ticket_pdf: could not render element ' . $element->get_id() . ': ' . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }
        }

        force_current_language($currentlang);

        $output = $pdf->Output('', 'S');
        $pdf->_destroy(true);

        return (string) $output;
    }

    /**
     * Build the stdClass the stock element classes expect in place of an issue record.
     *
     * Only properties actually read by the shipped element types are set: date uses
     * timecreated and expires, code uses code, text uses courseid, program uses id.
     *
     * @param stdClass $ticket A {booking_tickets} record.
     * @param array $data The placeholder snapshot.
     *
     * @return stdClass
     */
    public static function build_fake_issue(stdClass $ticket, array $data): stdClass {
        return (object) [
            // Deliberately 0: no issue exists, and nothing may look up customfield data by this id.
            'id' => 0,
            'userid' => (int) ($ticket->userid ?? 0),
            'templateid' => (int) ($ticket->templateid ?? 0),
            'code' => (string) ($ticket->code ?? ''),
            'timecreated' => (int) ($ticket->timecreated ?? time()),
            'expires' => 0,
            'component' => 'mod_booking',
            'courseid' => (int) ($data['courseid'] ?? 0),
            'archived' => 0,
            'emailed' => 0,
            'data' => json_encode($data),
        ];
    }

    /**
     * Render a single element, intercepting the two types that need a real issue.
     *
     * @param \pdf $pdf
     * @param \tool_certificate\element $element
     * @param stdClass $user
     * @param stdClass $fakeissue
     * @param stdClass $ticket
     * @param array $data
     *
     * @return void
     */
    private static function render_element(
        \pdf $pdf,
        \tool_certificate\element $element,
        stdClass $user,
        stdClass $fakeissue,
        stdClass $ticket,
        array $data
    ): void {
        switch ($element->get_element()) {
            case 'program':
                self::render_program($pdf, $element, $data);
                break;
            case 'code':
                self::render_code($pdf, $element, $ticket);
                break;
            default:
                $element->render($pdf, false, $user, $fakeissue);
        }
    }

    /**
     * Render a program element from the ticket data snapshot instead of core_customfield data.
     *
     * @param \pdf $pdf
     * @param \tool_certificate\element $element
     * @param array $data
     *
     * @return void
     */
    private static function render_program(\pdf $pdf, \tool_certificate\element $element, array $data): void {
        $elementdata = json_decode($element->get_data() ?? '', true);
        $shortname = is_array($elementdata) ? ($elementdata['display'] ?? '') : '';
        $value = $shortname === '' ? '' : (string) ($data[$shortname] ?? '');
        element_helper::render_content($pdf, $element, $value);
    }

    /**
     * Render a code element against mod_booking's own ticket verification page.
     *
     * Mirrors certificateelement_code::render() / render_qrcode() with the URL swapped.
     *
     * @param \pdf $pdf
     * @param \tool_certificate\element $element
     * @param stdClass $ticket
     *
     * @return void
     */
    private static function render_code(\pdf $pdf, \tool_certificate\element $element, stdClass $ticket): void {
        $code = (string) ($ticket->code ?? '');
        $elementdata = json_decode($element->get_data() ?? '', true);
        $display = is_array($elementdata) ? (int) ($elementdata['display'] ?? 0) : 0;
        $codeurl = new moodle_url('/mod/booking/verifyticket.php', ['code' => $code]);

        if ($display !== self::CODE_DISPLAY_QRCODE) {
            switch ($display) {
                case self::CODE_DISPLAY_CODELINK:
                    $content = \html_writer::link($codeurl, $code);
                    break;
                case self::CODE_DISPLAY_URL:
                    $content = $codeurl->out(false);
                    break;
                default:
                    $content = $code;
            }
            element_helper::render_content($pdf, $element, $content);
            return;
        }

        $style = [
            'border' => 0,
            'vpadding' => 'auto',
            'hpadding' => 'auto',
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255],
            'module_width' => 1,
            'module_height' => 1,
        ];

        $x = $element->get_posx();
        $y = $element->get_posy();
        $w = $element->get_width();
        $refpoint = $element->get_refpoint();

        // Adjust X depending on the current refpoint.
        if ($refpoint == element_helper::CUSTOMCERT_REF_POINT_TOPRIGHT) {
            $x = $x - $w;
        } else if ($refpoint == element_helper::CUSTOMCERT_REF_POINT_TOPCENTER) {
            $x = $x - $w / 2;
        }
        // Same nudge tool_certificate applies, so a zero width does not collapse the barcode.
        $w += 0.0001;

        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->write2DBarcode($codeurl->out(false), 'QRCODE,M', $x, $y, $w, $w, $style, 'N');
        $pdf->SetXY($x, $y + 49);
        $pdf->SetFillColor(255, 255, 255);
    }
}
