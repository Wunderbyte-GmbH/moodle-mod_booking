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
 * Single source of truth for "is the documentation skill active?".
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\lookup;

use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\wizard\skills\explain_docs_skill;

/**
 * Gate that decides whether documentation embeddings may be built at all.
 *
 * When the {@see explain_docs_skill} is not active, documentation embeddings are never produced:
 * scheduling is suppressed (Phase E1/A5), a queued task opts out (E2), and the index service
 * skips (E3). This class is the *only* place that answers "is the docs skill active?", so the
 * scheduling path, the task, the index service and the settings indicator never diverge.
 *
 * The check is intentionally config-only (no skill-registry construction): it must be cheap and
 * safe to call from the settings page render and from background scheduling alike. The semantics
 * match {@see skill_registry::is_skill_active()} (enable-all, then per-skill toggle, default-off)
 * minus the registry's skill-discovery guard — the docs skill is a built-in core skill that is
 * always present.
 */
class docs_embeddings_gate {
    /**
     * Whether the documentation (explain_docs) skill is currently active.
     *
     * @return bool
     */
    public static function is_docs_skill_active(): bool {
        // System-wide "enable all skills" short-circuit.
        if ((bool)get_config('bookingextension_agent', 'aiskillenableall')) {
            return true;
        }

        $settingname = skill_registry::get_skill_toggle_setting_name(explain_docs_skill::SKILL_NAME);
        $configured = get_config('bookingextension_agent', $settingname);
        if ($configured !== false) {
            return (bool)$configured;
        }

        // Default-off until explicitly enabled.
        return false;
    }
}
