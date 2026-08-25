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

namespace local_studybuddy\local;

/**
 * Reads administrator-editable prompt settings.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prompt_config {
    /**
     * Returns a configured prompt with a language-string fallback.
     *
     * @param string $name Config setting name without component prefix.
     * @param string $fallbackstring Language string used when the setting is empty.
     * @return string
     */
    public static function get(string $name, string $fallbackstring): string {
        $value = trim((string)get_config('local_studybuddy', $name));

        if ($value !== '') {
            return $value;
        }

        return get_string($fallbackstring, 'local_studybuddy');
    }

    /**
     * Formats the common activity request sent to an AI provider.
     *
     * @param array $params Activity generation parameters.
     * @return string Formatted request.
     */
    public static function format_activity_input(array $params): string {
        $activitytype = (string)($params['activitytype'] ?? 'quiz');
        $count = in_array($activitytype, ['quiz', 'flashcards', 'h5p_flashcards'], true) ?
            (string)(int)($params['nquestions'] ?? 5) : '';
        $difficulty = in_array($activitytype, ['quiz', 'flashcards', 'h5p_flashcards'], true) ?
            (string)($params['difficulty'] ?? 'medium') : '';

        return trim(strtr(self::get('provideractivityinput', 'default:provideractivityinput'), [
            '%%QUERY%%' => (string)($params['query'] ?? ''),
            '%%TYPE%%' => $activitytype,
            '%%COUNT%%' => $count,
            '%%DIFFICULTY%%' => $difficulty,
        ]));
    }
}
