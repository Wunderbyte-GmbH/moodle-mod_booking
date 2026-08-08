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

use context_system;
use tool_certificate\persistent\element;
use tool_certificate\template;

/**
 * Creates a ready-made entry ticket design in tool_certificate.
 *
 * Admins trigger this once from the booking site settings. The result is an ordinary, shared
 * certificate template that can be selected in the "Ticketing" section of a booking option, or
 * duplicated and restyled. Nothing here runs automatically on install or upgrade, so it never
 * competes with the tool_certificate installation order.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_template_installer {
    /** @var int Page width of the ticket in mm. */
    private const PAGE_WIDTH = 210;

    /** @var int Page height of the ticket in mm. */
    private const PAGE_HEIGHT = 99;

    /**
     * The name of the shipped ticket template.
     *
     * @return string
     */
    public static function get_template_name(): string {
        return get_string('tickettemplatename', 'mod_booking');
    }

    /**
     * Whether the shipped ticket template already exists.
     *
     * @return bool
     */
    public static function is_installed(): bool {
        if (!class_exists('tool_certificate\\template')) {
            return false;
        }
        return !empty(template::find_by_name(self::get_template_name()));
    }

    /**
     * Create the shipped ticket template, unless it already exists.
     *
     * @return int The template id, or 0 if it could not be created.
     */
    public static function ensure_installed(): int {
        global $CFG;

        if (!class_exists('tool_certificate\\template')) {
            return 0;
        }

        $name = self::get_template_name();
        if ($existing = template::find_by_name($name)) {
            return (int) $existing->get_id();
        }

        // Shared, so the template is selectable in every context.
        $template = template::create((object) [
            'name' => $name,
            'contextid' => context_system::instance()->id,
            'shared' => 1,
        ]);

        $page = $template->new_page();
        $page->save((object) [
            'width' => self::PAGE_WIDTH,
            'height' => self::PAGE_HEIGHT,
            'leftmargin' => 10,
            'rightmargin' => 10,
        ]);
        $pageid = $page->get_id();

        $black = '#000000';
        $grey = '#555555';

        // The layout: title and event data on the left, QR code and logo on the right.
        $elements = [
            [
                'name' => get_string('tickettitle', 'mod_booking'),
                'element' => 'text',
                'data' => get_string('tickettitle', 'mod_booking'),
                'font' => 'freesansb',
                'fontsize' => 22,
                'colour' => $grey,
                'posx' => 12,
                'posy' => 12,
                'sequence' => 1,
                'refpoint' => 0,
            ],
            [
                'name' => get_string('bookingoptionname', 'mod_booking'),
                'element' => 'program',
                'data' => json_encode(['display' => 'bookingoptionname']),
                'font' => 'freesansb',
                'fontsize' => 18,
                'colour' => $black,
                'posx' => 12,
                'posy' => 26,
                'width' => 130,
                'sequence' => 2,
                'refpoint' => 0,
            ],
            [
                'name' => get_string('sessions', 'mod_booking'),
                'element' => 'program',
                'data' => json_encode(['display' => 'sessions']),
                'font' => 'freesans',
                'fontsize' => 11,
                'colour' => $black,
                'posx' => 12,
                'posy' => 40,
                'width' => 130,
                'sequence' => 3,
                'refpoint' => 0,
            ],
            [
                'name' => get_string('location', 'mod_booking'),
                'element' => 'program',
                'data' => json_encode(['display' => 'location']),
                'font' => 'freesans',
                'fontsize' => 11,
                'colour' => $black,
                'posx' => 12,
                'posy' => 52,
                'width' => 130,
                'sequence' => 4,
                'refpoint' => 0,
            ],
            [
                'name' => get_string('ticketholder', 'mod_booking'),
                'element' => 'userfield',
                'data' => 'fullname',
                'font' => 'freesansb',
                'fontsize' => 14,
                'colour' => $black,
                'posx' => 12,
                'posy' => 64,
                'width' => 130,
                'sequence' => 5,
                'refpoint' => 0,
            ],
            [
                'name' => get_string('ticketextrainfo', 'mod_booking'),
                'element' => 'program',
                'data' => json_encode(['display' => 'ticketextrainfo']),
                'font' => 'freesans',
                'fontsize' => 9,
                'colour' => $grey,
                'posx' => 12,
                'posy' => 76,
                'width' => 130,
                'sequence' => 6,
                'refpoint' => 0,
            ],
            [
                'name' => get_string('ticketissuedon', 'mod_booking'),
                'element' => 'date',
                'data' => json_encode(['dateitem' => -1, 'dateformat' => 'strftimedatetime']),
                'font' => 'freesans',
                'fontsize' => 8,
                'colour' => $grey,
                'posx' => 12,
                'posy' => 88,
                'sequence' => 7,
                'refpoint' => 0,
            ],
            // Intercepted by ticket_pdf: encodes mod_booking's own verification URL, not tool_certificate's.
            [
                'name' => get_string('ticketcode', 'mod_booking'),
                'element' => 'code',
                'data' => json_encode(['display' => 4]),
                'font' => 'freesans',
                'fontsize' => 10,
                'colour' => $black,
                'posx' => 160,
                'posy' => 26,
                'width' => 35,
                'sequence' => 8,
                'refpoint' => 0,
            ],
        ];

        foreach ($elements as $elementrecord) {
            $elementrecord['pageid'] = $pageid;
            (new element(0, (object) $elementrecord))->save();
        }

        // Site logo, top right. Optional — a missing file simply leaves the element empty.
        $logo = $CFG->dirroot . '/pix/moodlelogo.png';
        if (file_exists($logo)) {
            $logoelement = new element(0, (object) [
                'pageid' => $pageid,
                'name' => get_string('logo', 'admin'),
                'element' => 'image',
                'data' => json_encode(['width' => 35, 'height' => 0, 'isbackground' => false]),
                'posx' => 160,
                'posy' => 70,
                'sequence' => 9,
            ]);
            $logoelement->save();
            self::create_element_file((int) $logoelement->get('id'), $logo);
        }

        return (int) $template->get_id();
    }

    /**
     * Store an image file for a template element.
     *
     * Mirrors the private \tool_certificate\certificate::create_demo_element_file().
     *
     * @param int $elementid
     * @param string $filepath
     *
     * @return void
     */
    private static function create_element_file(int $elementid, string $filepath): void {
        $fs = get_file_storage();

        $filerecord = [
            'contextid' => context_system::instance()->id,
            'component' => 'tool_certificate',
            'filearea' => 'element',
            'itemid' => $elementid,
            'filepath' => '/',
            'filename' => basename($filepath),
        ];

        $exists = $fs->file_exists(
            $filerecord['contextid'],
            $filerecord['component'],
            $filerecord['filearea'],
            $filerecord['itemid'],
            $filerecord['filepath'],
            $filerecord['filename']
        );
        if ($exists) {
            return;
        }

        $fs->create_file_from_pathname($filerecord, $filepath);
    }
}
