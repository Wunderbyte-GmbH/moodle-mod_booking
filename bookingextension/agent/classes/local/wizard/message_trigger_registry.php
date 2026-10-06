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
 * Message trigger catalog for robust LLM-side classification.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard;

use core_text;

/**
 * Builds and validates the trigger catalog shared between prompt, interpreter and runtime flow.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_trigger_registry {
    /** Marker for unknown response_type values coming from model output. */
    public const UNKNOWN_RESPONSE_TYPE = 'UNKNOWN_TYPE';

    /** Allowed response_type values in runtime/decision routing. */
    private const KNOWN_RESPONSE_TYPES = [
        'skill_call',
        'confirmation_request',
        'confirm_pending',
        'clarification',
        'sufficient',
        'error',
        'execution_result',
    ];

    /** @var skill_registry */
    private skill_registry $skillregistry;

    /**
     * Constructor.
     *
     * @param skill_registry $skillregistry
     */
    public function __construct(skill_registry $skillregistry) {
        $this->skillregistry = $skillregistry;
    }

    /**
     * Normalize response_type into an explicit known set.
     *
     * @param string $responsetype
     * @return string
     */
    public function normalize_response_type(string $responsetype): string {
        $normalized = trim(core_text::strtolower($responsetype));
        if ($normalized === '') {
            return self::UNKNOWN_RESPONSE_TYPE;
        }

        if (!in_array($normalized, self::KNOWN_RESPONSE_TYPES, true)) {
            return self::UNKNOWN_RESPONSE_TYPE;
        }

        return $normalized;
    }
}
