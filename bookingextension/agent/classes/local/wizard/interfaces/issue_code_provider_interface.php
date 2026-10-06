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

namespace bookingextension_agent\local\wizard\interfaces;

/**
 * Provider interface for domain-specific issue codes and resolution URLs.
 *
 * Issue codes and their meanings are domain-specific. This interface allows
 * each plugin to define its own issue codes for confirmation contexts,
 * token/subscription errors, and other validation scenarios.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface issue_code_provider_interface {
    /**
     * Return issue codes that indicate a specific confirmation is required.
     *
     * Examples: DUPLICATE_TITLE_CONFIRM_REQUIRED, MISSING_LOCATION_CONFIRM_REQUIRED
     *
     * @return string[]
     */
    public function get_duplicate_confirmation_issue_codes(): array;

    /**
     * Return issue codes indicating token/subscription problems.
     *
     * These errors typically block further processing until resolved externally.
     *
     * Examples: TRIAL_TOKEN_INVALID, SUBSCRIPTION_REQUIRED, AI_PROVIDER_AUTH_FAILED
     *
     * @return string[]
     */
    public function get_token_subscription_issue_codes(): array;

    /**
     * Return issue codes that may remain confirmation-gated despite pre-validation errors.
     *
     * These codes indicate a state where the user is presented with options
     * to confirm and proceed, even if earlier validation issues were found.
     *
     * Examples: CONFIRMATION_REQUIRED, MISSING_LOCATION_CONFIRM_REQUIRED
     *
     * @return string[]
     */
    public function get_prevalidation_confirmable_issue_codes(): array;

    /**
     * Return the URL for purchasing a basic subscription or license upgrade.
     *
     * @return string
     */
    public function get_basic_subscription_url(): string;

    /**
     * Return the URL for purchasing a premium subscription or license upgrade.
     *
     * @return string
     */
    public function get_premium_subscription_url(): string;
}
