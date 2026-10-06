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

use bookingextension_agent\local\wizard\dto\skill_selection_result;

/**
 * Resolve one selected skill from a raw command payload.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_selector {
    /** @var lazy_skill_loader */
    private lazy_skill_loader $loader;

    /** @var skill_selection_overlap_policy */
    private skill_selection_overlap_policy $overlappolicy;

    /**
     * Constructor.
     *
     * @param lazy_skill_loader $loader
     * @param skill_selection_overlap_policy|null $overlappolicy
     */
    public function __construct(lazy_skill_loader $loader, ?skill_selection_overlap_policy $overlappolicy = null) {
        $this->loader = $loader;
        $this->overlappolicy = $overlappolicy ?? new skill_selection_overlap_policy();
    }

    /**
     * Select one valid skill from a raw command payload.
     *
     * @param array $command
     * @param string[] $allowedskills
     * @param string $label
     * @return skill_selection_result
     */
    public function select(array $command, array $allowedskills, string $label): skill_selection_result {
        if (!isset($command['skill'])) {
            return new skill_selection_result('', 1, null, false, ["$label: missing 'skill' key."]);
        }

        $resolvedskill = $this->overlappolicy->resolve((string)$command['skill'], $allowedskills);
        if ($resolvedskill === null) {
            $skillname = trim((string)($command['skill'] ?? ''));
            return new skill_selection_result(
                $skillname,
                max(1, (int)($command['version'] ?? 1)),
                null,
                false,
                ["$label: skill '$skillname' denied by governance gate (not_allowed)."]
            );
        }

        $skill = $this->loader->load_skill($resolvedskill, $allowedskills);
        if ($skill === null) {
            return new skill_selection_result(
                $resolvedskill,
                max(1, (int)($command['version'] ?? 1)),
                null,
                false,
                ["$label: skill '$resolvedskill' is not registered."]
            );
        }

        return new skill_selection_result(
            $resolvedskill,
            max(1, (int)($command['version'] ?? 1)),
            $skill,
            true,
            []
        );
    }
}
