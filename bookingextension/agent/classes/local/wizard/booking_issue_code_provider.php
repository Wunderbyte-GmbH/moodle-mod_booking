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

namespace bookingextension_agent\local\wizard;

use bookingextension_agent\local\wizard\interfaces\issue_code_provider_interface;

/**
 * Issue code provider for bookingextension_agent.
 *
 * Defines all booking-specific issue codes and subscription URLs.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_issue_code_provider implements issue_code_provider_interface {
    /**
     * Return issue codes that indicate a specific confirmation is required.
     *
     * @return string[]
     */
    public function get_duplicate_confirmation_issue_codes(): array {
        return [
            'DUPLICATE_TITLE_CONFIRM_REQUIRED',
            'DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED',
            'REPORT_NAME_CONFLICT_CONFIRM_REQUIRED',
        ];
    }

    /**
     * Return issue codes indicating token/subscription problems.
     *
     * @return string[]
     */
    public function get_token_subscription_issue_codes(): array {
        return [
            'TRIAL_TOKEN_INVALID',
            'TRIAL_TOKEN_EXPIRED',
            'SUBSCRIPTION_REQUIRED',
            'AI_PROVIDER_AUTH_FAILED',
            'AI_PROVIDER_QUOTA_EXCEEDED',
        ];
    }

    /**
     * Return issue codes that may remain confirmation-gated despite pre-validation errors.
     *
     * @return string[]
     */
    public function get_prevalidation_confirmable_issue_codes(): array {
        return [
            'DUPLICATE_TITLE_CONFIRM_REQUIRED',
            'DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED',
            'CONFIRMATION_REQUIRED',
            'MISSING_LOCATION_CONFIRM_REQUIRED',
            'LOCATION_NOT_FOUND_POSSIBLE',
            'SLOTBOOKING_DURATION_EQUALS_WINDOW',
            'TEACHER_USER_NOT_FOUND',
            'REPORT_NAME_CONFLICT_CONFIRM_REQUIRED',
        ];
    }

    /**
     * Return the URL for purchasing a basic subscription or license upgrade.
     *
     * @return string
     */
    public function get_basic_subscription_url(): string {
        return 'https://showroom.wunderbyte.at/mod/booking/optionview.php?optionid=73&cmid=938&userid=1';
    }

    /**
     * Return the URL for purchasing a premium subscription or license upgrade.
     *
     * @return string
     */
    public function get_premium_subscription_url(): string {
        return 'https://showroom.wunderbyte.at/mod/booking/optionview.php?optionid=74&cmid=938&userid=1';
    }
}
