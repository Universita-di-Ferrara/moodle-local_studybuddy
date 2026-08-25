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
 * External services for StudyBuddy.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_studybuddy_create_chat' => [
        'classname' => 'local_studybuddy\external\create_chat',
        'methodname' => 'execute',
        'description' => get_string('service:create_chat', 'local_studybuddy'),
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_studybuddy_send_message' => [
        'classname' => 'local_studybuddy\external\send_message',
        'methodname' => 'execute',
        'description' => get_string('service:send_message', 'local_studybuddy'),
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_studybuddy_get_history' => [
        'classname' => 'local_studybuddy\external\get_history',
        'methodname' => 'execute',
        'description' => get_string('service:get_history', 'local_studybuddy'),
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_studybuddy_sync_course' => [
        'classname' => 'local_studybuddy\external\sync_course',
        'methodname' => 'execute',
        'description' => get_string('service:sync_course', 'local_studybuddy'),
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_studybuddy_get_sync_status' => [
        'classname' => 'local_studybuddy\external\get_sync_status',
        'methodname' => 'execute',
        'description' => get_string('service:get_sync_status', 'local_studybuddy'),
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_studybuddy_toggle_source' => [
        'classname' => 'local_studybuddy\external\toggle_source',
        'methodname' => 'execute',
        'description' => get_string('service:toggle_source', 'local_studybuddy'),
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
