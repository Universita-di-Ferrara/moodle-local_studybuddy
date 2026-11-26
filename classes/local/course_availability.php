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
 * Determines whether StudyBuddy is enabled for a course.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_availability {
    /**
     * Checks the site-wide course scope setting.
     *
     * @param int $courseid Course id.
     * @return bool
     */
    public static function is_enabled(int $courseid): bool {
        $enabledids = self::get_enabled_course_ids();
        if ($enabledids === null) {
            return true;
        }

        return in_array($courseid, $enabledids, true);
    }

    /**
     * Returns the configured course ids, or null when all courses are enabled.
     *
     * @return int[]|null Null means that the site-wide scope is all courses.
     */
    public static function get_enabled_course_ids(): ?array {
        if (get_config('local_studybuddy', 'course_scope') !== 'selected') {
            return null;
        }

        $configuredids = (string)get_config('local_studybuddy', 'enabled_course_ids');
        $tokens = preg_split('/[,;\s]+/', $configuredids, -1, PREG_SPLIT_NO_EMPTY);
        $ids = [];
        foreach ($tokens as $token) {
            if (preg_match('/^[1-9][0-9]*$/', $token) && (int)$token > 1) {
                $ids[] = (int)$token;
            }
        }
        $ids = array_unique($ids);

        return array_values($ids);
    }

    /**
     * Enforces the course scope for a course context.
     *
     * @param int $courseid Course id.
     * @return void
     */
    public static function require_enabled(int $courseid): void {
        if (!self::is_enabled($courseid)) {
            throw new \moodle_exception('courseunavailable', 'local_studybuddy');
        }
    }
}
