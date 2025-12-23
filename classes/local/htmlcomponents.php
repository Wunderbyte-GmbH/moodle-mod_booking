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

namespace mod_booking\local;

use html_writer;

/**
 * Class scheduledmails
 * @package mod_booking
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class htmlcomponents {
    /**
     * Render Bootstrap tabs (BS4 & BS5 compatible) using html_writer.
     *
     * @param array $tabs Array of tabs:
     *        [
     *          [
     *            'title' => 'Tab title (string)',
     *            'body'  => 'HTML content (string)'
     *          ],
     *          ...
     *        ]
     * @param string $id Unique ID prefix for the tabs (optional).
     * @return string Rendered HTML.
     */
    public static function render_bootstrap_tabs(array $tabs, string $id = 'moodle-tabs'): string {
        if (empty($tabs)) {
            return '';
        }

        $navid = $id . '-nav';
        $contentid = $id . '-content';

        // Tabs navigation.
        $output = html_writer::start_tag('ul', [
            'class' => 'nav nav-tabs',
            'id' => $navid,
            'role' => 'tablist',
        ]);

        foreach ($tabs as $index => $tab) {
            $tabid = $id . '-tab-' . $index;
            $paneid = $id . '-pane-' . $index;
            $active = ($index === 0);

            $output .= html_writer::tag(
                'li',
                html_writer::link(
                    '#' . $paneid,
                    $tab['title'],
                    [
                        'class' => 'nav-link' . ($active ? ' active' : ''),
                        'id' => $tabid,
                        'data-toggle' => 'tab',
                        'data-bs-toggle' => 'tab',
                        'role' => 'tab',
                        'aria-controls' => $paneid,
                        'aria-selected' => $active ? 'true' : 'false',
                    ]
                ),
                [
                    'class' => 'nav-item',
                    'role' => 'presentation',
                ]
            );
        }

        $output .= html_writer::end_tag('ul');

        // Tabs content.
        $output .= html_writer::start_div('tab-content pt-3', [
            'id' => $contentid,
        ]);

        foreach ($tabs as $index => $tab) {
            $paneid = $id . '-pane-' . $index;
            $tabid = $id . '-tab-' . $index;
            $active = ($index === 0);

            $output .= html_writer::start_div(
                'tab-pane fade' . ($active ? ' show active' : ''),
                [
                    'id' => $paneid,
                    'role' => 'tabpanel',
                    'aria-labelledby' => $tabid,
                ]
            );

            $output .= $tab['body'];

            $output .= html_writer::end_div();
        }

        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render Bootstrap collapsible component.
     *
     * @param string $headertext
     * @param string $bodytext
     *
     * @return string
     *
     */
    public static function render_bootstrap_collapsible(string $headertext, string $bodytext) {
        // Example function body.
        $returnstring = html_writer::tag(
            'p',
            html_writer::link(
                '#pollurlplaceholders',
                $headertext,
                [
                    'class' => 'btn btn-link p-0',
                    'data-toggle' => 'collapse',
                    'role' => 'button',
                    'aria-expanded' => 'false',
                    'aria-controls' => 'pollurlplaceholders',
                ]
            )
        ) .
        html_writer::div(
            html_writer::div(
                $bodytext,
                'card card-body'
            ),
            '',
            [
                'class' => 'collapse',
                'id' => 'pollurlplaceholders',
            ]
        );

        return $returnstring;
    }
}
