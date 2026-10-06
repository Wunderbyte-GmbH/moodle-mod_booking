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

declare(strict_types=1);

namespace bookingextension_agent\external;

use core\context;

/**
 * Shared formatter for assistant messages returned by external webservices.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ws_message_formatter {
    /**
     * Format a markdown-like assistant message as HTML for WS output.
     *
     * Deliberately uses clean_text() and NOT format_text(): the assistant reply is shown verbatim,
     * so Moodle text filters must NOT run on it. format_text() would execute filters such as the
     * booking shortcodes filter, which turns a literal "[bookingoptionview ...]" inside an
     * explanation (even within a code block) into a rendered booking button instead of showing the
     * shortcode the user asked about. clean_text() still purifies the HTML (XSS protection for the
     * LLM-generated output) but runs no filters, so shortcodes and other tag-like text are shown
     * literally.
     *
     * @param string $message
     * @param context $context retained for API stability; not needed without filtering
     * @return string
     */
    public static function format_ws_message(string $message, context $context): string {
        $message = trim($message);
        if ($message === '') {
            return '';
        }

        return clean_text(\markdown_to_html($message), FORMAT_HTML);
    }
}
