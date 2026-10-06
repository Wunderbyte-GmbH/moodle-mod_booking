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

namespace bookingextension_agent\local\wizard\services;

/**
 * Text an LLM wrote travels in one privacy direction only (George 2026-09-26).
 *
 * The model sees masked data only, so its words hold placeholders or words it saw in clear text (e.g. shipped
 * documentation). Masking them again protects nothing and corrupts them: N36 thread 13358 turned the quoted
 * "Max. number of participants" into "ANON_USER_2_firstname. number of participants". Such text is resolved from
 * placeholders to names for display only, never masked again.
 *
 * The engine marks a result's message as the model's by recording the exact string the model returned. The mark holds
 * only while the message is byte-identical to that string: any engine rewrite (a gate question built with a
 * de-anonymized word, an error text) breaks the equality and the text is masked as before. The decision reads engine
 * state, never the text.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class model_authored_text {
    /** Result key holding the message exactly as the model returned it. */
    public const KEY = 'model_message';

    /**
     * Record the result's current message as model-written.
     *
     * @param array $result
     * @param string $modelmessage The message as the model returned it.
     * @return array
     */
    public static function mark(array $result, string $modelmessage): array {
        $message = (string)($result['message'] ?? '');
        if ($message !== '' && $message === $modelmessage) {
            $result[self::KEY] = $message;
        } else {
            unset($result[self::KEY]);
        }
        return $result;
    }

    /**
     * Whether the result's message is still exactly what the model wrote.
     *
     * @param array $result
     * @return bool
     */
    public static function is_model_message(array $result): bool {
        $message = (string)($result['message'] ?? '');
        return $message !== '' && isset($result[self::KEY]) && (string)$result[self::KEY] === $message;
    }
}
