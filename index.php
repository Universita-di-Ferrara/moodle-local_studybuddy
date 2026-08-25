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
 * Entry point for local_studybuddy.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);

if (has_capability('local/studybuddy:chat', $context)) {
    redirect(new moodle_url('/local/studybuddy/chat.php', ['courseid' => $courseid]));
}

if (has_capability('local/studybuddy:practice', $context)) {
    redirect(new moodle_url('/local/studybuddy/practice.php', ['courseid' => $courseid]));
}

if (
    has_any_capability([
    'local/studybuddy:generate',
    'local/studybuddy:review',
    'local/studybuddy:publish',
    ], $context)
) {
    redirect(new moodle_url('/local/studybuddy/teacher.php', ['courseid' => $courseid]));
}

if (has_capability('local/studybuddy:managesources', $context)) {
    redirect(new moodle_url('/local/studybuddy/sources.php', ['courseid' => $courseid]));
}

require_capability('local/studybuddy:chat', $context);
