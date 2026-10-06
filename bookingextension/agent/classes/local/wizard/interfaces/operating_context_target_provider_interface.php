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

namespace bookingextension_agent\local\wizard\interfaces;

use bookingextension_agent\local\wizard\dto\context_target_resolution;
use bookingextension_agent\local\wizard\dto\target_selector;

/**
 * Domain-supplied resolver for operating-context targets the engine core does not handle itself.
 *
 * The engine resolves CONTEXT_COURSE targets generically (courses are a core concept). Any other
 * target level (e.g. a domain-specific module target) is delegated to a provider implementing this
 * interface, so the engine carries no compile-time dependency on a concrete domain.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface operating_context_target_provider_interface {
    /**
     * Whether this provider can resolve targets at the given Moodle context level.
     *
     * @param int $level A Moodle CONTEXT_* level constant.
     * @return bool
     */
    public function supports_level(int $level): bool;

    /**
     * Resolve a target selector to a concrete operating context.
     *
     * @param target_selector $target
     * @param int             $userid Acting user id (for visibility-aware resolution).
     * @return context_target_resolution
     */
    public function resolve_target(target_selector $target, int $userid): context_target_resolution;
}
