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

namespace local_studybuddy\output;

/**
 * Builds page-level StudyBuddy navigation.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class navigation {
    /**
     * Build section nav context for templates.
     *
     * @param int $courseid Course id.
     * @param \context_course $context Course context.
     * @param string $active Active section key.
     * @return array Template context.
     */
    public static function section_nav(int $courseid, \context_course $context, string $active): array {
        $items = [];

        if (has_capability('local/studybuddy:chat', $context)) {
            $items[] = self::item($courseid, 'chat', 'chat', '/local/studybuddy/chat.php', $active);
        }

        if (has_capability('local/studybuddy:practice', $context)) {
            $items[] = self::item($courseid, 'practice', 'practiceheading', '/local/studybuddy/practice.php', $active);
        }

        if (
            has_any_capability([
            'local/studybuddy:generate',
            'local/studybuddy:review',
            'local/studybuddy:publish',
            ], $context)
        ) {
            $items[] = self::item($courseid, 'teacher', 'teacherheading', '/local/studybuddy/teacher.php', $active);
        }

        if (has_capability('local/studybuddy:managesources', $context)) {
            $items[] = self::item($courseid, 'sources', 'sources', '/local/studybuddy/sources.php', $active);
        }

        return [
            'arialabel' => get_string('sectionnav', 'local_studybuddy'),
            'items' => $items,
        ];
    }

    /**
     * Build one navigation item.
     *
     * @param int $courseid Course id.
     * @param string $key Item key.
     * @param string $stringid Language string id.
     * @param string $path Target path.
     * @param string $active Active key.
     * @return array Template item.
     */
    private static function item(int $courseid, string $key, string $stringid, string $path, string $active): array {
        return [
            'key' => $key,
            'label' => get_string($stringid, 'local_studybuddy'),
            'url' => (new \moodle_url($path, ['courseid' => $courseid]))->out(false),
            'active' => $key === $active,
        ];
    }
}
