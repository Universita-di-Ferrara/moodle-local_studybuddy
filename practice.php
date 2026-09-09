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
 * Temporary student practice page.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_studybuddy\local\async_generation_service;
use local_studybuddy\local\practice_renderer;
use local_studybuddy\local\source_service;
use local_studybuddy\output\navigation;
use local_studybuddy\output\renderer;

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/studybuddy:practice', $context);
$syncstatus = (new source_service())->get_sync_status($courseid, (int)$USER->id);
$generationdisabled = !empty($syncstatus['needsreindex']) || empty($syncstatus['available']);
$technicalstatus = has_any_capability([
    'local/studybuddy:managesources',
    'local/studybuddy:generate',
    'local/studybuddy:review',
    'local/studybuddy:publish',
    'local/studybuddy:manage',
], $context);
$generationdisabledmessage = !empty($syncstatus['needsreindex']) ?
    ($technicalstatus ? str_replace(
        '%%PROVIDER%%',
        $syncstatus['providerlabel'],
        get_string('syncstatus:providerchanged', 'local_studybuddy')
    ) : get_string('syncstatus:temporarilydisabled', 'local_studybuddy')) :
    ($technicalstatus ? get_string('syncstatus:empty', 'local_studybuddy') :
        get_string('syncstatus:temporarilydisabled', 'local_studybuddy'));

$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_url(new moodle_url('/local/studybuddy/practice.php', ['courseid' => $courseid]));
$PAGE->set_title(get_string('practiceheading', 'local_studybuddy'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css(new moodle_url('/local/studybuddy/lib/mindelixir/style.css'));
$PAGE->requires->js_call_amd('local_studybuddy/studybuddy', 'initPractice');
$PAGE->requires->js_call_amd('local_studybuddy/flashcards', 'init');

$message = '';
$warningmessage = '';
$result = null;
$practiceid = optional_param('practiceid', 0, PARAM_INT);
$evaluation = null;
$selectedpractice = null;

$formdata = data_submitted();
if ($formdata && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_ALPHA);

    if ($action === 'generate' && $generationdisabled) {
        $warningmessage = $generationdisabledmessage;
    } else if ($action === 'generate') {
        $query = required_param('query', PARAM_TEXT);
        $activitytype = optional_param('activitytype', 'quiz', PARAM_ALPHA);
        if (!in_array($activitytype, ['quiz', 'flashcards', 'conceptmap'], true)) {
            $activitytype = 'quiz';
        }
        $params = [
            'query' => $query,
            'courseid' => $courseid,
            'activitytype' => $activitytype,
        ];

        if ($activitytype === 'quiz') {
            $params['nquestions'] = optional_param('nquestions', 5, PARAM_INT);
            $params['difficulty'] = optional_param('difficulty', 'medium', PARAM_ALPHA);
        } else if ($activitytype === 'flashcards') {
            $params['nquestions'] = optional_param('nquestions', 5, PARAM_INT);
            $params['difficulty'] = optional_param('difficulty', 'medium', PARAM_ALPHA);
        }

        $practiceid = (new async_generation_service())->queue_practice($courseid, (int)$USER->id, $query, $params);
        $activityurl = new moodle_url('/local/studybuddy/practice.php', [
            'courseid' => $courseid,
            'practiceid' => $practiceid,
        ]);
        redirect(
            $activityurl,
            get_string('generationqueued:practice', 'local_studybuddy'),
            0,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'submitquiz') {
        $practiceid = required_param('practiceid', PARAM_INT);
        $practice = $DB->get_record('local_studybuddy_practice', [
            'id' => $practiceid,
            'courseid' => $courseid,
            'userid' => $USER->id,
        ], '*', MUST_EXIST);
        if ($practice->status !== 'ready') {
            throw new moodle_exception('generationnotready', 'local_studybuddy');
        }
        $result = json_decode((string)$practice->resultjson, true);
        if (!is_array($result)) {
            throw new moodle_exception('invalidjson', 'local_studybuddy');
        }

        $responses = optional_param_array('responses', [], PARAM_INT);
        $evaluation = (new practice_renderer())->evaluate_quiz($result, $responses);
    }

    if ($action === 'delete') {
        $deleteid = required_param('practiceid', PARAM_INT);
        $practice = $DB->get_record('local_studybuddy_practice', [
            'id' => $deleteid,
            'courseid' => $courseid,
            'userid' => $USER->id,
        ], '*', MUST_EXIST);

        $DB->delete_records('local_studybuddy_practice', ['id' => $practice->id]);
        redirect(
            new moodle_url('/local/studybuddy/practice.php', ['courseid' => $courseid]),
            get_string('practiceactivitydeleted', 'local_studybuddy'),
            0,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

if ($practiceid > 0) {
    $selectedpractice = $DB->get_record('local_studybuddy_practice', [
        'id' => $practiceid,
        'courseid' => $courseid,
        'userid' => $USER->id,
    ]);
    if ($selectedpractice && $selectedpractice->status === 'ready' && $result === null) {
        $decoded = json_decode((string)$selectedpractice->resultjson, true);
        if (is_array($decoded)) {
            $result = $decoded;
        }
    }
}

/** @var renderer $pluginoutput */
$pluginoutput = $PAGE->get_renderer('local_studybuddy');
$quizhtml = '';
$resulttitle = '';
if ($result !== null) {
    $practicerenderer = new practice_renderer();
    $activitytype = (string)($result['activitytype'] ?? 'quiz');
    $resulttitle = trim((string)($result['title'] ?? ''));
    if ($activitytype === 'flashcards') {
        $quizhtml = $practicerenderer->render_flashcards($pluginoutput, $result);
    } else if ($activitytype === 'conceptmap') {
        $quizhtml = $practicerenderer->render_conceptmap($pluginoutput, $result);
        $PAGE->requires->js_call_amd('local_studybuddy/conceptmap', 'init');
    } else {
        $quizhtml = $practicerenderer->render_quiz($pluginoutput, $result, $courseid, $practiceid, $evaluation);
    }
}
if ($resulttitle === '' && $selectedpractice) {
    $resulttitle = shorten_text((string)$selectedpractice->querytext, 120);
}

$recentactivities = [];
$recentrecords = $DB->get_records('local_studybuddy_practice', [
    'courseid' => $courseid,
    'userid' => $USER->id,
], 'timemodified DESC', '*', 0, 10);
foreach ($recentrecords as $recentrecord) {
    $params = json_decode((string)$recentrecord->paramsjson, true);
    if (!is_array($params)) {
        $params = [];
    }
    $activitytype = (string)($params['activitytype'] ?? 'quiz');
    if (!in_array($activitytype, ['quiz', 'flashcards', 'conceptmap'], true)) {
        $activitytype = 'quiz';
    }
    $decodedresult = json_decode((string)$recentrecord->resultjson, true);
    if (!is_array($decodedresult)) {
        $decodedresult = [];
    }
    $activitytitle = trim((string)($decodedresult['title'] ?? ''));
    if ($activitytitle === '') {
        $activitytitle = shorten_text((string)$recentrecord->querytext, 80);
    }
    $status = (string)$recentrecord->status;
    $activityurl = new moodle_url('/local/studybuddy/practice.php', [
        'courseid' => $courseid,
        'practiceid' => $recentrecord->id,
    ]);
    $recentactivities[] = [
        'id' => (int)$recentrecord->id,
        'title' => shorten_text($activitytitle, 80),
        'promptlabel' => get_string('query', 'local_studybuddy'),
        'prompt' => shorten_text((string)$recentrecord->querytext, 110),
        'type' => get_string('activitytype:' . $activitytype, 'local_studybuddy'),
        'isquiz' => $activitytype === 'quiz',
        'isflashcards' => $activitytype === 'flashcards',
        'isconceptmap' => $activitytype === 'conceptmap',
        'status' => get_string('generationstatus:' . $status, 'local_studybuddy'),
        'ispending' => $status === 'pending',
        'isready' => $status === 'ready',
        'isfailed' => $status === 'failed',
        'active' => (int)$recentrecord->id === $practiceid,
        'url' => $activityurl->out(false),
        'deletebutton' => get_string('deletepracticeactivity', 'local_studybuddy'),
        'deleteconfirm' => get_string('deletepracticeactivityconfirm', 'local_studybuddy'),
    ];
}

$showpending = $selectedpractice && $selectedpractice->status === 'pending';
$showfailed = $selectedpractice && $selectedpractice->status === 'failed';
$refreshurl = new moodle_url('/local/studybuddy/practice.php', [
    'courseid' => $courseid,
    'practiceid' => $practiceid,
]);
$contextdata = [
    'courseid' => $courseid,
    'sesskey' => sesskey(),
    'sectionnav' => navigation::section_nav($courseid, $context, 'practice'),
    'herotitle' => get_string('practiceheading', 'local_studybuddy'),
    'herolead' => get_string('practicelead', 'local_studybuddy'),
    'heropills' => [
        ['label' => get_string('practicefeature:rag', 'local_studybuddy')],
        ['label' => get_string('practicefeature:feedback', 'local_studybuddy')],
        ['label' => get_string('practicefeature:temporary', 'local_studybuddy')],
    ],
    'messagehtml' =>
        ($message !== '' ? $OUTPUT->notification($message, 'notifysuccess') : '') .
        ($warningmessage !== '' ? $OUTPUT->notification($warningmessage, 'notifywarning') : ''),
    'generateheading' => get_string('generatepracticequiz', 'local_studybuddy'),
    'querylabel' => get_string('query', 'local_studybuddy'),
    'queryplaceholder' => get_string('query_help', 'local_studybuddy'),
    'activitytypelabel' => get_string('studyactivitytype', 'local_studybuddy'),
    'activitytypes' => [
        [
            'value' => 'quiz',
            'label' => get_string('activitytype:quiz', 'local_studybuddy'),
            'description' => get_string('activitytype:quiz_desc', 'local_studybuddy'),
            'isquiz' => true,
            'selected' => true,
        ],
        [
            'value' => 'flashcards',
            'label' => get_string('activitytype:flashcards', 'local_studybuddy'),
            'description' => get_string('activitytype:flashcards_desc', 'local_studybuddy'),
            'isflashcards' => true,
        ],
        [
            'value' => 'conceptmap',
            'label' => get_string('activitytype:conceptmap', 'local_studybuddy'),
            'description' => get_string('activitytype:conceptmap_desc', 'local_studybuddy'),
            'isconceptmap' => true,
        ],
    ],
    'nquestionslabel' => get_string('nitems', 'local_studybuddy'),
    'difficultylabel' => get_string('difficulty', 'local_studybuddy'),
    'defaultnquestions' => 5,
    'difficulties' => [
        ['value' => 'easy', 'label' => get_string('difficulty_easy', 'local_studybuddy')],
        ['value' => 'medium', 'label' => get_string('difficulty_medium', 'local_studybuddy'), 'selected' => true],
        ['value' => 'hard', 'label' => get_string('difficulty_hard', 'local_studybuddy')],
    ],
    'generatebutton' => get_string('generate', 'local_studybuddy'),
    'generatingbutton' => get_string('generating', 'local_studybuddy'),
    'generatehint' => get_string('practiceactionhint', 'local_studybuddy'),
    'generationdisabled' => $generationdisabled,
    'generationdisabledmessage' => $generationdisabledmessage,
    'hasrecentactivities' => !empty($recentactivities),
    'recentactivitiesheading' => get_string('recentstudyactivities', 'local_studybuddy'),
    'emptyrecentactivities' => get_string('emptyrecentactivities', 'local_studybuddy'),
    'recentactivities' => $recentactivities,
    'openactivitylabel' => get_string('openactivity', 'local_studybuddy'),
    'deleteconfirmtitle' => get_string('deletepracticeactivityconfirm', 'local_studybuddy'),
    'deleteconfirmbody' => get_string('deletepracticeactivityconfirmbody', 'local_studybuddy'),
    'showpending' => $showpending,
    'pendingtitle' => get_string('generationpending:title', 'local_studybuddy'),
    'pendingdescription' => get_string('generationpending:practice', 'local_studybuddy'),
    'showfailed' => $showfailed,
    'failedtitle' => get_string('generationfailed:title', 'local_studybuddy'),
    'faileddescription' => get_string('generationfailed:practice', 'local_studybuddy'),
    'refreshurl' => $refreshurl->out(false),
    'refreshstatuslabel' => get_string('refreshstatus', 'local_studybuddy'),
    'showresults' => $result !== null,
    'backurl' => (new moodle_url('/local/studybuddy/practice.php', ['courseid' => $courseid]))->out(false),
    'backlabel' => get_string('backtoactivities', 'local_studybuddy'),
    'resultsheading' => $resulttitle !== '' ? $resulttitle : get_string('generatedpractice', 'local_studybuddy'),
    'quizhtml' => $quizhtml,
];

echo $OUTPUT->header();
echo $pluginoutput->render_practice_page($contextdata);
echo $OUTPUT->footer();
