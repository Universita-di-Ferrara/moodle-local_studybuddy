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
 * Hooks for local_studybuddy.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds course navigation links for StudyBuddy.
 *
 * @param navigation_node $navigation Course navigation node.
 * @param stdClass $course Course record.
 * @param context_course $context Course context.
 * @return void
 */
function local_studybuddy_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context): void {
    if (!isloggedin() || isguestuser()) {
        return;
    }

    $canaccess = has_capability('local/studybuddy:chat', $context) ||
        has_capability('local/studybuddy:practice', $context) ||
        has_capability('local/studybuddy:generate', $context) ||
        has_capability('local/studybuddy:review', $context) ||
        has_capability('local/studybuddy:publish', $context) ||
        has_capability('local/studybuddy:managesources', $context);

    if ($canaccess) {
        $navigation->add(
            get_string('coursemenulabel', 'local_studybuddy'),
            new moodle_url('/local/studybuddy/index.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_studybuddy'
        );
    }
}
