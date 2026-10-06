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
 * Event fired when a user confirms the GDPR consent before starting an AI trial.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\event;

use core\event\base;

/**
 * Audit-trail event: the data-protection consent for the AI trial was given.
 *
 * Recorded server-side when {@see \bookingextension_agent\external\request_trial_key}
 * is called with the consent flag set, so there is a durable record of who agreed,
 * when and from which context — the trial key request is gated on this consent.
 */
class trial_consent_given extends base {
    /**
     * Initialise the event metadata.
     */
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('aitrial_event_consent_given', 'bookingextension_agent');
    }

    /**
     * Human-readable description for the log report.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' confirmed the data-protection consent and "
            . "requested an AI trial in the context with id '{$this->contextid}'.";
    }
}
