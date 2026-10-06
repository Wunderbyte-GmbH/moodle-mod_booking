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

/**
 * Contract for resolving a chat-upload attachment token to a temp file.
 *
 * Engine-owned (attachments are agent-chat uploads). Skills depend on this contract
 * via base_skill::attachments(), never on the concrete engine service - so the same
 * skill runs against any engine that provides an implementation.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface attachment_resolver {
    /**
     * Resolve an attachment token to a temp file.
     *
     * @param string $token
     * @param int $userid token owner (conversation user)
     * @param int $contextid
     * @return array{path:string,filename:string} empty path when unresolved
     */
    public function resolve(string $token, int $userid, int $contextid): array;

    /**
     * Invalidate a token after use (one-shot lifecycle cleanup of the temp file).
     *
     * @param string $token
     * @return void
     */
    public function invalidate(string $token): void;
}
