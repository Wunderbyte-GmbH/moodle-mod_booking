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

namespace bookingextension_agent\local\wizard\services\selection;

use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Lazy skill access wrapper for phase-3 skill selection.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lazy_skill_loader {
    /** @var skill_registry */
    private skill_registry $registry;

    /**
     * Constructor.
     *
     * @param skill_registry $registry
     */
    public function __construct(skill_registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Load one concrete skill lazily by canonical skill name.
     *
     * @param string $skillname
     * @param string[] $allowedskills Optional allow-list for phase-scoped loading.
     * @return skill_interface|null
     */
    public function load_skill(string $skillname, array $allowedskills = []): ?skill_interface {
        if (!empty($allowedskills) && !in_array($skillname, $allowedskills, true)) {
            return null;
        }

        return $this->registry->get_skill($skillname);
    }
}
