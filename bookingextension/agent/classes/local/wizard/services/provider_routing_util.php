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
 * Shared provider routing helpers.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services;

use core_ai\manager as ai_manager;
use core_text;

/**
 * Resolves AI provider routing details in one place.
 */
class provider_routing_util {
    /**
     * Resolve the primary enabled provider plugin for an action.
     *
     * @param ai_manager $manager
     * @param string $actionclass
     * @return string
     */
    public static function resolve_primary_provider_for_action(ai_manager $manager, string $actionclass): string {
        try {
            $providers = $manager->get_providers_for_actions([$actionclass], true);
            $list = (array)($providers[$actionclass] ?? []);
            if (empty($list)) {
                return '';
            }
            $primary = reset($list);
            return (string)($primary->provider ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Convert provider plugin names to short debug tokens.
     *
     * @param string $provider
     * @return string
     */
    public static function short_provider_for_debug(string $provider): string {
        $value = trim(core_text::strtolower($provider));
        if ($value === '') {
            return 'na';
        }
        if ($value === 'aiprovider_openai') {
            return 'oai';
        }
        if (str_starts_with($value, 'aiprovider_')) {
            $value = substr($value, 11);
        }
        if ($value === '') {
            return 'na';
        }
        return core_text::substr($value, 0, 10);
    }
}
