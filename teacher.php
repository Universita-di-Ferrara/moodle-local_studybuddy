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
 * Teacher generation, review and publish page.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_studybuddy\local\activity_generator;
use local_studybuddy\local\async_generation_service;
use local_studybuddy\local\h5p_publisher;
use local_studybuddy\local\moodle_publisher;
use local_studybuddy\local\source_service;
use local_studybuddy\output\navigation;
use local_studybuddy\output\renderer;

/**
 * Returns the current correct answer index for a multichoice question.
 *
 * @param array $answers Question answers.
 * @return int
 */
function local_studybuddy_teacher_correct_answer_index(array $answers): int {
    $bestindex = 0;
    $bestfraction = null;

    foreach ($answers as $index => $answer) {
        $fraction = (float)($answer['fraction'] ?? 0);
        if ($bestfraction === null || $fraction > $bestfraction) {
            $bestfraction = $fraction;
            $bestindex = (int)$index;
        }
    }

    return $bestindex;
}

/**
 * Builds reviewed quiz JSON from the structured teacher form.
 *
 * @param array $original Original generated result.
 * @param array $submitted Submitted form data.
 * @return array
 */
function local_studybuddy_teacher_build_review_result(array $original, array $submitted): array {
    $result = $original;
    $result['questions'] = [];

    foreach (($original['questions'] ?? []) as $questionindex => $question) {
        if (!empty($submitted['removequestion'][$questionindex])) {
            continue;
        }

        $updated = $question;
        $updated['questiontext'] = clean_param(
            trim((string)($submitted['questiontext'][$questionindex] ?? $question['questiontext'] ?? '')),
            PARAM_CLEANHTML
        );
        $updated['feedback'] = clean_param(
            trim((string)($submitted['questionfeedback'][$questionindex] ?? $question['feedback'] ?? '')),
            PARAM_CLEANHTML
        );

        $originalanswers = array_values($question['answers'] ?? []);
        $submittedanswers = $submitted['answertext'][$questionindex] ?? [];
        $correctanswer = isset($submitted['correctanswer'][$questionindex]) ?
            (int)$submitted['correctanswer'][$questionindex] :
            local_studybuddy_teacher_correct_answer_index($originalanswers);

        $updatedanswers = [];
        foreach ($originalanswers as $answerindex => $answer) {
            $answertext = clean_param(
                trim((string)($submittedanswers[$answerindex] ?? $answer['text'] ?? '')),
                PARAM_TEXT
            );
            if ($answertext === '') {
                $answertext = clean_param(trim((string)($answer['text'] ?? '')), PARAM_TEXT);
            }

            $updatedanswers[] = [
                'text' => $answertext,
                'fraction' => ($answerindex === $correctanswer) ? 100 : 0,
            ];
        }

        $updated['answers'] = $updatedanswers;
        $result['questions'][] = $updated;
    }

    return $result;
}

/**
 * Return a default editable multichoice question.
 *
 * @return array
 */
function local_studybuddy_teacher_default_question(): array {
    return [
        'type' => 'multichoice',
        'questiontext' => get_string('newquestionplaceholder', 'local_studybuddy'),
        'answers' => [
            ['text' => get_string('newanswerplaceholder', 'local_studybuddy'), 'fraction' => 100],
            ['text' => get_string('newdistractorplaceholder', 'local_studybuddy', 1), 'fraction' => 0],
            ['text' => get_string('newdistractorplaceholder', 'local_studybuddy', 2), 'fraction' => 0],
            ['text' => get_string('newdistractorplaceholder', 'local_studybuddy', 3), 'fraction' => 0],
        ],
        'feedback' => '',
        'citations' => [],
    ];
}

/**
 * Builds reviewed H5P flashcards JSON from the structured teacher form.
 *
 * @param array $original Original generated result.
 * @param array $submitted Submitted form data.
 * @return array
 */
function local_studybuddy_teacher_build_h5p_flashcards_result(array $original, array $submitted): array {
    $result = $original;
    $result['activitytype'] = 'h5p_flashcards';
    $result['flashcards'] = [];

    foreach (array_values($original['flashcards'] ?? []) as $cardindex => $card) {
        if (!empty($submitted['removecard'][$cardindex])) {
            continue;
        }

        $front = clean_param(
            trim((string)($submitted['cardfront'][$cardindex] ?? $card['front'] ?? '')),
            PARAM_TEXT
        );
        $back = clean_param(
            trim((string)($submitted['cardback'][$cardindex] ?? $card['back'] ?? '')),
            PARAM_TEXT
        );
        if ($front === '' || $back === '') {
            continue;
        }

        $updated = $card;
        $updated['front'] = $front;
        $updated['back'] = $back;
        $updated['hint'] = clean_param(
            trim((string)($submitted['cardhint'][$cardindex] ?? $card['hint'] ?? '')),
            PARAM_TEXT
        );
        $result['flashcards'][] = $updated;
    }

    return $result;
}

/**
 * Return a default editable H5P flashcard.
 *
 * @return array
 */
function local_studybuddy_teacher_default_h5p_flashcard(): array {
    return [
        'front' => get_string('newflashcardfrontplaceholder', 'local_studybuddy'),
        'back' => get_string('newflashcardbackplaceholder', 'local_studybuddy'),
        'hint' => '',
        'citations' => [],
    ];
}

/**
 * Prepare citations for template output.
 *
 * @param array $citations Citation list.
 * @return array
 */
function local_studybuddy_prepare_citations_for_template(array $citations): array {
    $items = [];

    foreach (array_values($citations) as $citation) {
        $label = (string)($citation['title'] ?? get_string('citations', 'local_studybuddy'));
        if (!empty($citation['chunkid'])) {
            $label .= ' #' . (int)$citation['chunkid'];
        }
        $items[] = ['label' => $label];
    }

    return $items;
}

/**
 * Prepare teacher draft questions for template rendering.
 *
 * @param array $draftdata Draft JSON decoded.
 * @return array
 */
function local_studybuddy_prepare_draft_questions_for_template(array $draftdata): array {
    $questions = [];

    foreach (array_values($draftdata['questions'] ?? []) as $questionindex => $question) {
        $questionanswers = array_values($question['answers'] ?? []);
        $correctanswer = local_studybuddy_teacher_correct_answer_index($questionanswers);
        $answers = [];

        foreach ($questionanswers as $answerindex => $answer) {
            $answers[] = [
                'index' => $answerindex,
                'questionindex' => $questionindex,
                'letter' => chr(65 + $answerindex),
                'text' => (string)($answer['text'] ?? ''),
                'checked' => $answerindex === $correctanswer,
                'correctanswerchoice' => get_string('correctanswerchoice', 'local_studybuddy'),
                'markascorrect' => get_string('markascorrect', 'local_studybuddy'),
            ];
        }

        $citations = local_studybuddy_prepare_citations_for_template($question['citations'] ?? []);
        $questions[] = [
            'index' => $questionindex,
            'number' => $questionindex + 1,
            'heading' => get_string('questionnumber', 'local_studybuddy', $questionindex + 1),
            'typelabel' => get_string('questiontype:multichoice', 'local_studybuddy'),
            'removequestionlabel' => get_string('removequestion', 'local_studybuddy'),
            'questiontextlabel' => get_string('questiontextlabel', 'local_studybuddy'),
            'questionfeedbacklabel' => get_string('questionfeedbacklabel', 'local_studybuddy'),
            'answeroptionslabel' => get_string('answeroptionslabel', 'local_studybuddy'),
            'answeroptionshelp' => get_string('answeroptionshelp', 'local_studybuddy'),
            'questiontext' => (string)($question['questiontext'] ?? ''),
            'feedback' => (string)($question['feedback'] ?? ''),
            'answers' => $answers,
            'hascitations' => !empty($citations),
            'citationslabel' => get_string('citations', 'local_studybuddy'),
            'citations' => $citations,
        ];
    }

    return $questions;
}

/**
 * Prepare teacher H5P flashcards for template rendering.
 *
 * @param array $draftdata Draft JSON decoded.
 * @param int $courseid Course id.
 * @param int $draftid Draft id.
 * @param int $selectedindex Selected flashcard index.
 * @return array
 */
function local_studybuddy_prepare_h5p_flashcards_for_template(
    array $draftdata,
    int $courseid,
    int $draftid,
    int $selectedindex
): array {
    $cards = [];

    foreach (array_values($draftdata['flashcards'] ?? []) as $cardindex => $card) {
        $citations = local_studybuddy_prepare_citations_for_template($card['citations'] ?? []);
        $cards[] = [
            'index' => $cardindex,
            'number' => $cardindex + 1,
            'heading' => get_string('flashcardnumber', 'local_studybuddy', $cardindex + 1),
            'typelabel' => get_string('activitytype:h5p_flashcards', 'local_studybuddy'),
            'front' => (string)($card['front'] ?? ''),
            'back' => (string)($card['back'] ?? ''),
            'hint' => (string)($card['hint'] ?? ''),
            'hascitations' => !empty($citations),
            'citationslabel' => get_string('citations', 'local_studybuddy'),
            'citations' => $citations,
            'removequestionlabel' => get_string('removeflashcard', 'local_studybuddy'),
            'isediting' => $cardindex === $selectedindex,
        ];
    }

    return $cards;
}

/**
 * Add review navigation metadata to draft questions.
 *
 * @param array $questions Prepared question data.
 * @param int $courseid Current course id.
 * @param int $draftid Current draft id.
 * @param int $selectedindex Selected question index.
 * @return array
 */
function local_studybuddy_enrich_draft_questions_for_review(
    array $questions,
    int $courseid,
    int $draftid,
    int $selectedindex
): array {
    $lastindex = max(0, count($questions) - 1);

    foreach ($questions as $index => $question) {
        $previewurl = new moodle_url('/local/studybuddy/teacher.php', [
            'courseid' => $courseid,
            'draftid' => $draftid,
            'tab' => 'review',
            'question' => $index,
        ]);
        $questions[$index]['previewurl'] = $previewurl->out(false);
        $questions[$index]['active'] = $index === $selectedindex;
        $questions[$index]['hidden'] = $index !== $selectedindex;
        $questions[$index]['previousurl'] = (new moodle_url('/local/studybuddy/teacher.php', [
            'courseid' => $courseid,
            'draftid' => $draftid,
            'tab' => 'review',
            'question' => max(0, $index - 1),
        ]))->out(false);
        $questions[$index]['nexturl'] = (new moodle_url('/local/studybuddy/teacher.php', [
            'courseid' => $courseid,
            'draftid' => $draftid,
            'tab' => 'review',
            'question' => min($lastindex, $index + 1),
        ]))->out(false);
        $questions[$index]['hasprevious'] = $index > 0;
        $questions[$index]['hasnext'] = $index < $lastindex;
        $questions[$index]['previouslabel'] = get_string('previousquestion', 'local_studybuddy');
        $questions[$index]['nextlabel'] = get_string('nextquestion', 'local_studybuddy');
    }

    return $questions;
}

/**
 * Prepare recent drafts for template rendering.
 *
 * @param array $recentdrafts Draft records.
 * @param moodle_publisher $publisher Publisher helper.
 * @param int $courseid Current course id.
 * @param int $currentdraftid Currently open draft id.
 * @return array
 */
function local_studybuddy_prepare_recent_drafts_for_template(
    array $recentdrafts,
    moodle_publisher $publisher,
    int $courseid,
    int $currentdraftid = 0
): array {
    $items = [];

    foreach (array_values($recentdrafts) as $recentdraft) {
        $activitytype = (string)($recentdraft->activitytype ?? 'quiz');
        if (!get_string_manager()->string_exists('activitytype:' . $activitytype, 'local_studybuddy')) {
            $activitytype = 'quiz';
        }
        $reviewurl = new moodle_url('/local/studybuddy/teacher.php', [
            'courseid' => $courseid,
            'draftid' => $recentdraft->id,
            'tab' => 'review',
        ]);
        $showmissingbadge = !empty($recentdraft->publishedcmid) &&
            !$publisher->published_activity_exists((int)$recentdraft->publishedcmid);

        $items[] = [
            'id' => (int)$recentdraft->id,
            'active' => (int)$recentdraft->id === $currentdraftid,
            'courseid' => $courseid,
            'sesskey' => sesskey(),
            'title' => format_string($recentdraft->title),
            'typelabel' => get_string('activitytype:' . $activitytype, 'local_studybuddy'),
            'isquiz' => $activitytype === 'quiz',
            'isflashcards' => $activitytype === 'h5p_flashcards',
            'status' => get_string('generationstatus:' . $recentdraft->status, 'local_studybuddy'),
            'ispending' => in_array($recentdraft->status, ['pending', 'running'], true),
            'isfailed' => $recentdraft->status === 'failed',
            'isready' => !in_array($recentdraft->status, ['pending', 'running', 'failed'], true),
            'canreuse' => !in_array($recentdraft->status, ['pending', 'running', 'failed'], true),
            'timeupdated' => userdate(
                (int)$recentdraft->timemodified,
                get_string('strftimedatefullshort')
            ),
            'reviewurl' => $reviewurl->out(false),
            'reviewlabel' => get_string('reviewdraft', 'local_studybuddy'),
            'reuselabel' => get_string('reusedraft', 'local_studybuddy'),
            'deletelabel' => get_string('deletedraft', 'local_studybuddy'),
            'reuseconfirmtitle' => get_string('reusedraftconfirm', 'local_studybuddy'),
            'reuseconfirmbody' => get_string('reusedraftconfirmbody', 'local_studybuddy'),
            'deleteconfirmtitle' => get_string('deletedraftconfirm', 'local_studybuddy'),
            'deleteconfirmbody' => get_string('deletedraftconfirmbody', 'local_studybuddy'),
            'showmissingbadge' => $showmissingbadge,
            'missinglabel' => get_string('publishedactivitymissing_short', 'local_studybuddy'),
        ];
    }

    return $items;
}

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
if (
    !has_any_capability([
    'local/studybuddy:generate',
    'local/studybuddy:review',
    'local/studybuddy:publish',
    ], $context)
) {
    require_capability('local/studybuddy:generate', $context);
}
$syncstatus = (new source_service())->get_sync_status($courseid, (int)$USER->id);
$generationdisabled = !empty($syncstatus['needsreindex']) || empty($syncstatus['available']);
$generationdisabledmessage = !empty($syncstatus['needsreindex']) ?
    str_replace(
        '%%PROVIDER%%',
        $syncstatus['providerlabel'],
        get_string('syncstatus:providerchanged', 'local_studybuddy')
    ) :
    get_string('syncstatus:empty', 'local_studybuddy');

$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_url(new moodle_url('/local/studybuddy/teacher.php', ['courseid' => $courseid]));
$PAGE->set_title(get_string('teacherheading', 'local_studybuddy'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js_call_amd('local_studybuddy/studybuddy', 'initTeacher');

$message = '';
$warningmessage = '';
$draft = null;
$publisher = new moodle_publisher();
$activetab = '';
$skipdraftautoload = false;
$showquestionpreviewpage = false;
$openquestionindex = -1;
$openflashcardindex = -1;

$formdata = data_submitted();
if ($formdata && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_ALPHA);
    $deleteitem = optional_param('deleteitem', -1, PARAM_INT);
    if ($deleteitem >= 0) {
        $action = 'deleteitem';
    }

    if ($action === 'generate' && $generationdisabled) {
        require_capability('local/studybuddy:generate', $context);
        $activetab = 'generate';
        $warningmessage = $generationdisabledmessage;
    } else if ($action === 'generate') {
        require_capability('local/studybuddy:generate', $context);
        $activetab = 'review';
        $prompt = required_param('prompttext', PARAM_TEXT);
        $title = required_param('title', PARAM_TEXT);
        $activitytype = optional_param('activitytype', 'quiz', PARAM_ALPHANUMEXT);
        if (!in_array($activitytype, ['quiz', 'h5p_flashcards'], true)) {
            $activitytype = 'quiz';
        }
        $params = [
            'query' => $prompt,
            'courseid' => $courseid,
            'activitytype' => $activitytype,
            'nquestions' => $activitytype === 'quiz' ?
                max(4, min(10, optional_param('nquestions', 5, PARAM_INT))) :
                max(1, min(10, optional_param('nquestions', 5, PARAM_INT))),
            'difficulty' => optional_param('difficulty', 'medium', PARAM_ALPHA),
        ];

        $draftid = (new async_generation_service())->queue_draft($courseid, (int)$USER->id, $title, $prompt, $params);
        redirect(
            new moodle_url('/local/studybuddy/teacher.php', [
                'courseid' => $courseid,
                'draftid' => $draftid,
                'tab' => 'review',
            ]),
            get_string('generationqueued:draft', 'local_studybuddy'),
            0,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'save' || $action === 'publish' || $action === 'additem' || $action === 'deleteitem') {
        if ($action === 'publish') {
            require_capability('local/studybuddy:publish', $context);
        } else {
            require_capability('local/studybuddy:review', $context);
        }
        $activetab = 'review';
        $draftid = required_param('draftid', PARAM_INT);
        $deleteindex = $deleteitem;
        $draft = $DB->get_record('local_studybuddy_drafts', ['id' => $draftid, 'courseid' => $courseid], '*', MUST_EXIST);
        if (in_array($draft->status, ['pending', 'running', 'failed'], true)) {
            throw new moodle_exception('generationnotready', 'local_studybuddy');
        }
        $original = json_decode((string)$draft->resultjson, true);
        $draftactivitytype = (string)($draft->activitytype ?? ($original['activitytype'] ?? 'quiz'));
        if ($draftactivitytype === 'h5p_flashcards') {
            $decoded = local_studybuddy_teacher_build_h5p_flashcards_result($original ?: [], (array)$formdata);
            if ($action === 'additem') {
                $decoded['flashcards'][] = local_studybuddy_teacher_default_h5p_flashcard();
                $openflashcardindex = count($decoded['flashcards']) - 1;
            } else if ($action === 'deleteitem' && isset($decoded['flashcards'][$deleteindex])) {
                array_splice($decoded['flashcards'], $deleteindex, 1);
            }
        } else {
            $decoded = local_studybuddy_teacher_build_review_result($original ?: [], (array)$formdata);
            if ($action === 'additem') {
                $decoded['questions'][] = local_studybuddy_teacher_default_question();
                $openquestionindex = count($decoded['questions']) - 1;
            } else if ($action === 'deleteitem' && isset($decoded['questions'][$deleteindex])) {
                if (count($decoded['questions']) <= 4) {
                    throw new moodle_exception('minimumquizquestions', 'local_studybuddy');
                }
                array_splice($decoded['questions'], $deleteindex, 1);
            }
        }
        $generator = new activity_generator();

        $isvalid = $draftactivitytype === 'h5p_flashcards' ?
            $generator->is_valid_flashcards($decoded) :
            $generator->is_valid_quiz($decoded);
        if (!is_array($decoded) || !$isvalid) {
            throw new moodle_exception('invalidjson', 'local_studybuddy');
        }

        $update = (object)[
            'id' => $draft->id,
            'resultjson' => json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'status' => 'reviewed',
            'timemodified' => time(),
        ];
        $DB->update_record('local_studybuddy_drafts', $update);
        $draft = $DB->get_record('local_studybuddy_drafts', ['id' => $draft->id], '*', MUST_EXIST);
        if ($action === 'additem') {
            $message = get_string(
                $draftactivitytype === 'h5p_flashcards' ? 'flashcardadded' : 'questionadded',
                'local_studybuddy'
            );
        } else if ($action === 'deleteitem') {
            $message = get_string(
                $draftactivitytype === 'h5p_flashcards' ? 'flashcarddeleted' : 'questiondeleted',
                'local_studybuddy'
            );
        } else {
            $message = get_string('draftsaved', 'local_studybuddy');
        }

        if ($action === 'publish') {
            if ($draftactivitytype === 'h5p_flashcards') {
                $moduleinfo = (new h5p_publisher())->publish_flashcards((int)$draft->id);
                $url = new moodle_url('/mod/h5pactivity/view.php', ['id' => $moduleinfo->coursemodule]);
                $message = get_string('h5ppublished', 'local_studybuddy', html_writer::link($url, format_string($draft->title)));
                $draft = $DB->get_record('local_studybuddy_drafts', ['id' => $draft->id], '*', MUST_EXIST);
            } else {
                $moduleinfo = $publisher->publish_quiz((int)$draft->id);
                $url = new moodle_url('/mod/quiz/view.php', ['id' => $moduleinfo->coursemodule]);
                $message = get_string('quizpublished', 'local_studybuddy', html_writer::link($url, format_string($draft->title)));
                $draft = $DB->get_record('local_studybuddy_drafts', ['id' => $draft->id], '*', MUST_EXIST);
            }
        }
    }

    if ($action === 'delete') {
        $activetab = 'review';
        require_capability('local/studybuddy:review', $context);
        $draftid = required_param('draftid', PARAM_INT);
        $draft = $DB->get_record('local_studybuddy_drafts', ['id' => $draftid, 'courseid' => $courseid], '*', MUST_EXIST);
        $DB->delete_records('local_studybuddy_drafts', ['id' => $draft->id]);
        $message = get_string('draftdeleted', 'local_studybuddy', format_string($draft->title));
        $draft = null;
        $skipdraftautoload = true;
    }

    if ($action === 'duplicate') {
        $activetab = 'review';
        require_capability('local/studybuddy:review', $context);
        $draftid = required_param('draftid', PARAM_INT);
        $sourcedraft = $DB->get_record('local_studybuddy_drafts', ['id' => $draftid, 'courseid' => $courseid], '*', MUST_EXIST);
        if (in_array($sourcedraft->status, ['pending', 'running', 'failed'], true)) {
            throw new moodle_exception('generationnotready', 'local_studybuddy');
        }
        $copy = clone $sourcedraft;
        unset($copy->id);
        $copy->title = shorten_text($sourcedraft->title . ' (' . get_string('draftcopylabel', 'local_studybuddy') . ')', 255);
        $copy->status = 'draft';
        $copy->publishedcmid = null;
        $copy->timecreated = time();
        $copy->timemodified = time();
        $copy->id = $DB->insert_record('local_studybuddy_drafts', $copy);
        $draft = $DB->get_record('local_studybuddy_drafts', ['id' => $copy->id], '*', MUST_EXIST);
        $message = get_string('draftduplicated', 'local_studybuddy', format_string($draft->title));
    }
}

$draftid = optional_param('draftid', 0, PARAM_INT);
if (!$draft && $draftid && !$skipdraftautoload) {
    $draft = $DB->get_record('local_studybuddy_drafts', ['id' => $draftid, 'courseid' => $courseid]);
}

$requestedtab = optional_param('tab', '', PARAM_ALPHA);
$cangenerate = has_capability('local/studybuddy:generate', $context);
$canreview = has_capability('local/studybuddy:review', $context);
if ($activetab === '') {
    if ($requestedtab === 'generate' && $cangenerate) {
        $activetab = 'generate';
    } else if ($requestedtab === 'review' && $canreview) {
        $activetab = $requestedtab;
    } else if ($cangenerate) {
        $activetab = 'generate';
    } else {
        $activetab = 'review';
    }
}

$generatetaburl = new moodle_url('/local/studybuddy/teacher.php', [
    'courseid' => $courseid,
    'tab' => 'generate',
]);
$reviewtabparams = [
    'courseid' => $courseid,
    'tab' => 'review',
];
if ($draft) {
    $reviewtabparams['draftid'] = $draft->id;
}
$reviewtaburl = new moodle_url('/local/studybuddy/teacher.php', $reviewtabparams);

/** @var renderer $pluginoutput */
$pluginoutput = $PAGE->get_renderer('local_studybuddy');

$recentdrafts = $DB->get_records('local_studybuddy_drafts', ['courseid' => $courseid], 'timemodified DESC', '*', 0, 20);
$recentdraftshtml = $pluginoutput->render_teacher_recent_drafts([
    'hasdrafts' => !empty($recentdrafts),
    'heading' => get_string('recentdrafts', 'local_studybuddy'),
    'drafts' => local_studybuddy_prepare_recent_drafts_for_template(
        $recentdrafts,
        $publisher,
        $courseid,
        $draft ? (int)$draft->id : 0
    ),
]);

if ($draft) {
    $selectedquestion = $openquestionindex >= 0 ?
        $openquestionindex : optional_param('question', -1, PARAM_INT);
    $selectedflashcard = $openflashcardindex >= 0 ?
        $openflashcardindex : optional_param('flashcard', -1, PARAM_INT);
    $draftactivitytype = (string)($draft->activitytype ?? 'quiz');
    $ish5pflashcards = $draftactivitytype === 'h5p_flashcards';
    $canpublishdraft = has_capability('local/studybuddy:publish', $context) &&
        !in_array($draft->status, ['pending', 'running', 'failed'], true) &&
        ($draft->status !== 'published' || !$publisher->published_activity_exists((int)$draft->publishedcmid));
    $publishedurl = null;
    if (!empty($draft->publishedcmid) && $publisher->published_activity_exists((int)$draft->publishedcmid)) {
        $publishedurl = new moodle_url($ish5pflashcards ? '/mod/h5pactivity/view.php' : '/mod/quiz/view.php', [
            'id' => $draft->publishedcmid,
        ]);
    }

    $draftdata = json_decode((string)$draft->resultjson, true);
    $questions = is_array($draftdata) && !$ish5pflashcards ?
        local_studybuddy_prepare_draft_questions_for_template($draftdata) :
        [];
    $flashcardcount = is_array($draftdata) && $ish5pflashcards ? count($draftdata['flashcards'] ?? []) : 0;
    if ($selectedflashcard >= $flashcardcount) {
        $selectedflashcard = max(0, $flashcardcount - 1);
    }
    if ($selectedflashcard < 0) {
        $selectedflashcard = -1;
    }
    $flashcards = is_array($draftdata) && $ish5pflashcards ?
        local_studybuddy_prepare_h5p_flashcards_for_template(
            $draftdata,
            $courseid,
            (int)$draft->id,
            $selectedflashcard
        ) :
        [];
    $questioncount = count($questions);
    $itemcount = $ish5pflashcards ? $flashcardcount : $questioncount;
    if ($selectedquestion >= $questioncount) {
        $selectedquestion = max(0, $questioncount - 1);
    }
    $showquestionpreview = !$ish5pflashcards && $selectedquestion >= 0 && $questioncount > 0;
    $showquestionpreviewpage = $showquestionpreview;
    $questions = local_studybuddy_enrich_draft_questions_for_review(
        $questions,
        $courseid,
        (int)$draft->id,
        max(0, $selectedquestion)
    );
    $selectedquestiondata = $showquestionpreview ? $questions[$selectedquestion] : [];
    $backtoquestionsurl = (new moodle_url('/local/studybuddy/teacher.php', [
        'courseid' => $courseid,
        'draftid' => $draft->id,
        'tab' => 'review',
    ]))->out(false);
    $drafteditorhtml = $pluginoutput->render_teacher_draft_editor([
        'hasdraft' => true,
        'ispending' => in_array($draft->status, ['pending', 'running'], true),
        'isfailed' => $draft->status === 'failed',
        'showquestionlist' => !$ish5pflashcards && !$showquestionpreview &&
            !in_array($draft->status, ['pending', 'running', 'failed'], true),
        'showh5pflashcards' => $ish5pflashcards && !in_array($draft->status, ['pending', 'running', 'failed'], true),
        'showquestionpreview' => $showquestionpreview,
        'recentdraftshtml' => $recentdraftshtml,
        'recentdraftsheading' => get_string('recentdrafts', 'local_studybuddy'),
        'courseid' => $courseid,
        'draftid' => (int)$draft->id,
        'sesskey' => sesskey(),
        'drafttitle' => format_string($draft->title),
        'pendingtitle' => get_string('generationpending:title', 'local_studybuddy'),
        'pendingdescription' => get_string('generationpending:draft', 'local_studybuddy'),
        'failedtitle' => get_string('generationfailed:title', 'local_studybuddy'),
        'faileddescription' => get_string('generationfailed:draft', 'local_studybuddy'),
        'refreshurl' => (new moodle_url('/local/studybuddy/teacher.php', [
            'courseid' => $courseid,
            'draftid' => $draft->id,
            'tab' => 'review',
        ]))->out(false),
        'refreshstatuslabel' => get_string('refreshstatus', 'local_studybuddy'),
        'haspublishedurl' => $publishedurl !== null,
        'publishedurl' => $publishedurl ? $publishedurl->out(false) : '',
        'publishedlabel' => get_string($ish5pflashcards ? 'openpublishedh5p' : 'openpublishedquiz', 'local_studybuddy'),
        'showmissingbadge' => !empty($draft->publishedcmid) && $publishedurl === null,
        'missinglabel' => get_string('publishedactivitymissing', 'local_studybuddy'),
        'questions' => $questions,
        'flashcards' => $flashcards,
        'questioncount' => $questioncount,
        'flashcardcount' => $flashcardcount,
        'selectedquestion' => $selectedquestiondata,
        'backtoquestionsurl' => $backtoquestionsurl,
        'backtoquestionslabel' => get_string('backtoallquestions', 'local_studybuddy'),
        'questionsheading' => get_string('questionscount', 'local_studybuddy', $questioncount),
        'flashcardsheading' => get_string('flashcardscount', 'local_studybuddy', $flashcardcount),
        'flashcardfrontlabel' => get_string('flashcardfrontlabel', 'local_studybuddy'),
        'flashcardbacklabel' => get_string('flashcardbacklabel', 'local_studybuddy'),
        'flashcardhintlabel' => get_string('flashcardhintlabel', 'local_studybuddy'),
        'deletequestionlabel' => get_string('deletequestion', 'local_studybuddy'),
        'deleteflashcardlabel' => get_string('deleteflashcard', 'local_studybuddy'),
        'editflashcardlabel' => get_string('editflashcard', 'local_studybuddy'),
        'flashcardcompactfrontlabel' => get_string('flashcardcompactfront', 'local_studybuddy'),
        'deleteitemconfirmtitle' => get_string('deleteitemconfirm', 'local_studybuddy'),
        'deletequestionconfirmbody' => get_string('deletequestionconfirmbody', 'local_studybuddy'),
        'deleteflashcardconfirmbody' => get_string('deleteflashcardconfirmbody', 'local_studybuddy'),
        'editquestionlabel' => get_string('editquestion', 'local_studybuddy'),
        'changedraftlabel' => get_string('changedraft', 'local_studybuddy'),
        'reorderlabel' => get_string('reorderdragdrop', 'local_studybuddy'),
        'previewandeditheading' => get_string('previewandeditquestion', 'local_studybuddy'),
        'previewandeditdesc' => get_string('previewandeditquestion_desc', 'local_studybuddy'),
        'previewmodelabel' => get_string('previewmode', 'local_studybuddy'),
        'editmodelabel' => get_string('editmode', 'local_studybuddy'),
        'addquestionlabel' => get_string('addquestion', 'local_studybuddy'),
        'addanswerlabel' => get_string('addansweroption', 'local_studybuddy'),
        'autosavedlabel' => get_string('autosavedhint', 'local_studybuddy'),
        'questiontypelabel' => get_string('questiontype', 'local_studybuddy'),
        'questionsettingslabel' => get_string('questionsettings', 'local_studybuddy'),
        'shuffleanswerslabel' => get_string('shuffleanswers', 'local_studybuddy'),
        'requiredquestionlabel' => get_string('requiredquestion', 'local_studybuddy'),
        'cancelbutton' => get_string('cancel'),
        'closepreviewlabel' => get_string('closepreview', 'local_studybuddy'),
        'savebutton' => get_string('savechanges', 'local_studybuddy'),
        'savecontinuebutton' => get_string('saveandcontinue', 'local_studybuddy'),
        'canpublish' => $canpublishdraft,
        'publishbutton' => $ish5pflashcards ?
            get_string('publishh5pflashcards', 'local_studybuddy') :
            ($draft->status === 'published' ?
                get_string('publishagainquiz', 'local_studybuddy') :
                get_string('publishquiz', 'local_studybuddy')),
        'emptytitle' => get_string('reviewemptytitle', 'local_studybuddy'),
        'emptydescription' => get_string('reviewemptydesc', 'local_studybuddy'),
        'showgenerateaction' => $cangenerate,
        'generateurl' => (new moodle_url('/local/studybuddy/teacher.php', [
            'courseid' => $courseid,
            'tab' => 'generate',
        ]))->out(false),
        'generatelabel' => get_string('gotogenerate', 'local_studybuddy'),
    ]);
} else {
    $drafteditorhtml = $pluginoutput->render_teacher_draft_editor([
        'hasdraft' => false,
        'emptytitle' => get_string('reviewemptytitle', 'local_studybuddy'),
        'emptydescription' => get_string('reviewemptydesc', 'local_studybuddy'),
        'showgenerateaction' => $cangenerate,
        'generateurl' => (new moodle_url('/local/studybuddy/teacher.php', [
            'courseid' => $courseid,
            'tab' => 'generate',
        ]))->out(false),
        'generatelabel' => get_string('gotogenerate', 'local_studybuddy'),
    ]);
}
$tabs = [];
if ($cangenerate) {
    $tabs[] = [
        'url' => $generatetaburl->out(false),
        'label' => get_string('generatetab', 'local_studybuddy'),
        'active' => $activetab === 'generate',
    ];
}
if ($canreview) {
    $tabs[] = [
        'url' => $reviewtaburl->out(false),
        'label' => get_string('reviewtab', 'local_studybuddy'),
        'active' => $activetab === 'review',
    ];
}
$pagecontext = [
    'courseid' => $courseid,
    'sesskey' => sesskey(),
    'sectionnav' => navigation::section_nav($courseid, $context, 'teacher'),
    'herotitle' => get_string('teacherheading', 'local_studybuddy'),
    'herolead' => get_string('teacherlead', 'local_studybuddy'),
    'heropills' => [
        ['label' => get_string('teacherfeature:drafts', 'local_studybuddy')],
        ['label' => get_string('teacherfeature:review', 'local_studybuddy')],
        ['label' => get_string('teacherfeature:publish', 'local_studybuddy')],
    ],
    'messagehtml' =>
        ($message !== '' ? $OUTPUT->notification($message, 'notifysuccess') : '') .
        ($warningmessage !== '' ? $OUTPUT->notification($warningmessage, 'notifywarning') : ''),
    'tabaria' => get_string('teacherheading', 'local_studybuddy'),
    'tabs' => $tabs,
    'showgenerate' => $draft === null && $cangenerate,
    'showreview' => $draft !== null && $canreview,
    'canbacktogenerate' => $draft !== null && $cangenerate,
    'generatetaburl' => $generatetaburl->out(false),
    'backtogeneratelabel' => get_string('gotogenerate', 'local_studybuddy'),
    'generateheading' => get_string('generatedraftcta', 'local_studybuddy'),
    'activitytypelabel' => get_string('activitytype', 'local_studybuddy'),
    'activitytypes' => [
        [
            'value' => 'quiz',
            'label' => get_string('activitytype:quiz', 'local_studybuddy'),
            'description' => get_string('activitytype:quiz_desc', 'local_studybuddy'),
            'isquiz' => true,
            'selected' => true,
        ],
        [
            'value' => 'h5p_flashcards',
            'label' => get_string('activitytype:h5p_flashcards', 'local_studybuddy'),
            'description' => get_string('activitytype:h5p_flashcards_desc', 'local_studybuddy'),
            'isflashcards' => true,
        ],
    ],
    'drafttitlelabel' => get_string('drafttitle', 'local_studybuddy'),
    'defaulttitle' => get_string('defaultdrafttitle', 'local_studybuddy'),
    'querylabel' => get_string('query', 'local_studybuddy'),
    'queryplaceholder' => get_string('teacherqueryplaceholder', 'local_studybuddy'),
    'nquestionslabel' => get_string('nquestions', 'local_studybuddy'),
    'difficultylabel' => get_string('difficulty', 'local_studybuddy'),
    'defaultnquestions' => 5,
    'minimumquizquestions' => 4,
    'minimumflashcards' => 1,
    'difficulties' => [
        ['value' => 'easy', 'label' => get_string('difficulty_easy', 'local_studybuddy')],
        ['value' => 'medium', 'label' => get_string('difficulty_medium', 'local_studybuddy'), 'selected' => true],
        ['value' => 'hard', 'label' => get_string('difficulty_hard', 'local_studybuddy')],
    ],
    'generatebutton' => get_string('generate', 'local_studybuddy'),
    'generatingbutton' => get_string('generating', 'local_studybuddy'),
    'generatehint' => get_string('teacheractionhint', 'local_studybuddy'),
    'generationdisabled' => $generationdisabled,
    'generationdisabledmessage' => $generationdisabledmessage,
    'helplabel' => get_string('help', 'local_studybuddy'),
    'reviewheading' => get_string('reviewworkspace', 'local_studybuddy'),
    'reviewdescription' => get_string('reviewworkspace_desc', 'local_studybuddy'),
    'showreviewdrafttitle' => $draft !== null,
    'reviewdrafttitlelabel' => get_string('drafttitle', 'local_studybuddy'),
    'reviewdrafttitle' => $draft ? format_string($draft->title) : '',
    'showreviewquestionpreview' => $showquestionpreviewpage,
    'showreviewrecentdrafts' => !$draft || in_array($draft->status, ['pending', 'running', 'failed'], true),
    'drafteditorhtml' => $drafteditorhtml,
    'recentdraftshtml' => $recentdraftshtml,
];

echo $OUTPUT->header();
echo $pluginoutput->render_teacher_page($pagecontext);
echo $OUTPUT->footer();
