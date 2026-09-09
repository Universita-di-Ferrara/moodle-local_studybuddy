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
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * StudyBuddy RAG chat page.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_studybuddy\local\chat_service;
use local_studybuddy\local\source_service;
use local_studybuddy\output\navigation;
use local_studybuddy\output\renderer;

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/studybuddy:chat', $context);

$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_url(new moodle_url('/local/studybuddy/chat.php', ['courseid' => $courseid]));
$PAGE->set_title(get_string('chatheading', 'local_studybuddy'));
$PAGE->set_heading(format_string($course->fullname));
$chat = (new chat_service())->get_or_create_chat($courseid, (int)$USER->id);
$status = (new source_service())->get_sync_status($courseid, (int)$USER->id);
$technicalstatus = has_any_capability([
    'local/studybuddy:managesources',
    'local/studybuddy:generate',
    'local/studybuddy:review',
    'local/studybuddy:publish',
    'local/studybuddy:manage',
], $context);
$statuslabel = !empty($status['needsreindex']) ?
    ($technicalstatus ? str_replace(
        '%%PROVIDER%%',
        $status['providerlabel'],
        get_string('syncstatus:providerchanged', 'local_studybuddy')
    ) : get_string('syncstatus:temporarilydisabled', 'local_studybuddy')) :
    ($technicalstatus ? get_string('sourcestatus', 'local_studybuddy', (object)$status) :
        get_string('syncstatus:temporarilydisabled', 'local_studybuddy'));
$PAGE->requires->js_call_amd('local_studybuddy/studybuddy', 'initChat', [
    $courseid,
    (int)$chat->id,
]);

/** @var renderer $renderer */
$renderer = $PAGE->get_renderer('local_studybuddy');

echo $OUTPUT->header();
echo $renderer->render_chat_page([
    'courseid' => $courseid,
    'chatid' => (int)$chat->id,
    'sesskey' => sesskey(),
    'sectionnav' => navigation::section_nav($courseid, $context, 'chat'),
    'heading' => get_string('chatheading', 'local_studybuddy'),
    'intro' => get_string('chatintro', 'local_studybuddy'),
    'placeholder' => get_string('chatplaceholder', 'local_studybuddy'),
    'inputlabel' => get_string('chatinputlabel', 'local_studybuddy'),
    'inputhelp' => get_string('chatinputhelp', 'local_studybuddy'),
    'inputid' => \uniqid('local-studybuddy-chat-input-'),
    'welcomemessage' => get_string('welcomemessage', 'local_studybuddy'),
    'sendlabel' => get_string('send', 'local_studybuddy'),
    'statuslabel' => $statuslabel,
    'statuswarning' => !empty($status['needsreindex']),
    'technicalstatus' => $technicalstatus,
]);
echo $OUTPUT->footer();
