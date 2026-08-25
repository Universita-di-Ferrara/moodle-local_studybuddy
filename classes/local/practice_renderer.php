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
 * Practice quiz helper and Mustache context exporter.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class practice_renderer {
    /** @var int Preview mode without submission form. */
    public const MODE_PREVIEW = 0;

    /** @var int Interactive attempt mode. */
    public const MODE_INTERACTIVE = 1;

    /**
     * Renders an interactive temporary practice quiz.
     *
     * @param \renderer_base $output Moodle renderer.
     * @param array $result Structured generation result.
     * @param int $courseid Course id.
     * @param int $practiceid Practice record id.
     * @param array|null $evaluation Submitted answers evaluation.
     * @param int $mode Render mode.
     * @return string
     */
    public function render_quiz(
        \renderer_base $output,
        array $result,
        int $courseid,
        int $practiceid,
        ?array $evaluation = null,
        int $mode = self::MODE_INTERACTIVE
    ): string {
        return $output->render_from_template(
            'local_studybuddy/practice_quiz',
            $this->export_for_template($result, $courseid, $practiceid, $evaluation, $mode)
        );
    }

    /**
     * Renders generated flashcards.
     *
     * @param \renderer_base $output Moodle renderer.
     * @param array $result Structured result.
     * @return string
     */
    public function render_flashcards(\renderer_base $output, array $result): string {
        $cards = [];
        $flashcards = array_values($result['flashcards'] ?? []);
        $total = count($flashcards);

        foreach ($flashcards as $index => $card) {
            $citations = $this->prepare_citations($card['citations'] ?? []);
            $number = $index + 1;
            $cards[] = [
                'number' => $number,
                'total' => $total,
                'progresslabel' => get_string('flashcardprogress', 'local_studybuddy', [
                    'number' => $number,
                    'total' => $total,
                ]),
                'front' => (string)($card['front'] ?? ''),
                'back' => (string)($card['back'] ?? ''),
                'hint' => (string)($card['hint'] ?? ''),
                'showhint' => !empty($card['hint']),
                'citations' => $citations,
                'hascitations' => !empty($citations),
            ];
        }
        $cardsjson = json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $output->render_from_template('local_studybuddy/practice_flashcards', [
            'hascards' => !empty($cards),
            'cards' => $cards,
            'cardsjson' => s($cardsjson),
            'emptylabel' => get_string('noflashcards', 'local_studybuddy'),
            'backlabel' => get_string('flashcardback', 'local_studybuddy'),
            'revealanswerlabel' => get_string('flashcardrevealanswer', 'local_studybuddy'),
            'hideanswerlabel' => get_string('flashcardhideanswer', 'local_studybuddy'),
            'previouslabel' => get_string('flashcardprevious', 'local_studybuddy'),
            'nextlabel' => get_string('flashcardnext', 'local_studybuddy'),
            'hintlabel' => get_string('hint', 'local_studybuddy'),
            'citationslabel' => get_string('citations', 'local_studybuddy'),
        ]);
    }

    /**
     * Renders a generated concept map.
     *
     * @param \renderer_base $output Moodle renderer.
     * @param array $result Structured result.
     * @return string
     */
    public function render_conceptmap(\renderer_base $output, array $result): string {
        $hasroot = !empty($result['root']) && is_array($result['root']);

        return $output->render_from_template('local_studybuddy/practice_conceptmap', [
            'hasroot' => $hasroot,
            'canvasid' => \uniqid('local-studybuddy-mindmap-'),
            'mindmapjson' => $hasroot ? json_encode(
                $this->prepare_mindelixir_map($result['root']),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) : '',
            'emptylabel' => get_string('noconceptmap', 'local_studybuddy'),
            'nodeslabel' => get_string('conceptmapnodes', 'local_studybuddy'),
            'layoutlabel' => get_string('conceptmaplayout', 'local_studybuddy'),
            'verticallabel' => get_string('conceptmaplayout:vertical', 'local_studybuddy'),
            'horizontallabel' => get_string('conceptmaplayout:horizontal', 'local_studybuddy'),
            'alternativelabel' => get_string('conceptmapalternative', 'local_studybuddy'),
            'alternativehelp' => get_string('conceptmapalternative_help', 'local_studybuddy'),
            'accessibleroot' => $hasroot ? $this->prepare_accessible_node($result['root'], 1) : [],
            'citationslabel' => get_string('citations', 'local_studybuddy'),
        ]);
    }

    /**
     * Prepare a recursive text outline for users who do not use the visual map.
     *
     * @param array $node Concept map node.
     * @param int $level Current tree level.
     * @return array
     */
    private function prepare_accessible_node(array $node, int $level): array {
        $children = [];
        $label = trim((string)($node['label'] ?? $node['title'] ?? ''));
        $description = trim((string)($node['description'] ?? ''));
        foreach (array_values($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $children[] = $this->prepare_accessible_node($child, $level + 1);
            }
        }

        return [
            'label' => $label !== '' ? $label : get_string('conceptmapnodes', 'local_studybuddy'),
            'description' => $description,
            'level' => $level,
            'hasdescription' => $description !== '',
            'haschildren' => !empty($children),
            'children' => $children,
        ];
    }

    /**
     * Prepare a concept map for the MindElixir renderer used by SmartEdu.
     *
     * @param array $root Root concept map node.
     * @return array
     */
    private function prepare_mindelixir_map(array $root): array {
        return [
            'nodeData' => $this->prepare_mindelixir_node($root, 'n1', true, 1),
            'linkData' => new \stdClass(),
        ];
    }

    /**
     * Prepare one recursive MindElixir node.
     *
     * @param array $node Node data.
     * @param string $fallbackid Fallback id.
     * @param bool $isroot Whether this is the root node.
     * @param int $level Node depth.
     * @return array
     */
    private function prepare_mindelixir_node(array $node, string $fallbackid, bool $isroot = false, int $level = 1): array {
        $id = trim((string)($node['id'] ?? $fallbackid));
        $label = trim((string)($node['label'] ?? $node['title'] ?? ''));
        $description = trim((string)($node['description'] ?? ''));
        $children = [];

        foreach (array_values($node['children'] ?? []) as $index => $child) {
            if (!is_array($child)) {
                continue;
            }
            $children[] = $this->prepare_mindelixir_node(
                $child,
                ($id !== '' ? $id : $fallbackid) . '_' . ($index + 1),
                false,
                $level + 1
            );
        }

        $mindnode = [
            'id' => $id !== '' ? $id : $fallbackid,
            'topic' => $this->prepare_mindelixir_topic($label, $description),
            'expanded' => true,
            'children' => $children,
        ];

        if ($isroot) {
            $mindnode['root'] = true;
        }

        return $mindnode;
    }

    /**
     * Prepare plain text for a MindElixir node.
     *
     * @param string $label Node label.
     * @param string $description Node description.
     * @return string
     */
    private function prepare_mindelixir_topic(string $label, string $description): string {
        $lines = [$label !== '' ? $label : get_string('conceptmapnodes', 'local_studybuddy')];

        if ($description !== '') {
            $lines[] = $description;
        }

        return implode("\n", $lines);
    }

    /**
     * Evaluates submitted responses against quiz answers.
     *
     * @param array $result Quiz result.
     * @param array $responses Submitted answer indexes.
     * @return array
     */
    public function evaluate_quiz(array $result, array $responses): array {
        $evaluation = [
            'score' => 0.0,
            'maxscore' => 0,
            'percentage' => 0,
            'questions' => [],
        ];

        foreach (($result['questions'] ?? []) as $index => $question) {
            $selectedanswer = array_key_exists($index, $responses) ? (int)$responses[$index] : -1;
            $answers = $question['answers'] ?? [];
            $bestfraction = 0.0;
            foreach ($answers as $answer) {
                $bestfraction = max($bestfraction, (float)($answer['fraction'] ?? 0));
            }

            $selectedfraction = ($selectedanswer >= 0 && isset($answers[$selectedanswer])) ?
                (float)($answers[$selectedanswer]['fraction'] ?? 0) : 0.0;
            $questionscore = ($bestfraction > 0) ? max(0.0, min(1.0, $selectedfraction / $bestfraction)) : 0.0;

            $evaluation['questions'][$index] = [
                'selectedanswer' => $selectedanswer,
                'selectedfraction' => $selectedfraction,
                'iscorrect' => $questionscore >= 1.0,
                'unanswered' => $selectedanswer < 0,
                'questionscore' => $questionscore,
            ];
            $evaluation['score'] += $questionscore;
            $evaluation['maxscore']++;
        }

        if ($evaluation['maxscore'] > 0) {
            $evaluation['percentage'] = (int)round(($evaluation['score'] / $evaluation['maxscore']) * 100);
        }

        return $evaluation;
    }

    /**
     * Export quiz data for the Mustache template.
     *
     * @param array $result Structured generation result.
     * @param int $courseid Course id.
     * @param int $practiceid Practice record id.
     * @param array|null $evaluation Submitted answers evaluation.
     * @param int $mode Render mode.
     * @return array
     */
    private function export_for_template(
        array $result,
        int $courseid,
        int $practiceid,
        ?array $evaluation,
        int $mode
    ): array {
        $interactive = $mode === self::MODE_INTERACTIVE;
        $questions = [];

        foreach (array_values($result['questions'] ?? []) as $index => $question) {
            $questionresult = $evaluation['questions'][$index] ?? null;
            $answers = [];

            foreach (array_values($question['answers'] ?? []) as $answerindex => $answer) {
                $isselected = $questionresult !== null && (int)$questionresult['selectedanswer'] === $answerindex;
                $iscorrect = ((float)($answer['fraction'] ?? 0) > 0);
                $classes = 'local-studybuddy-answer-option custom-control custom-radio border rounded p-3 mb-2';

                if ($interactive && $evaluation !== null) {
                    if ($iscorrect) {
                        $classes .= ' local-studybuddy-answer-correct border-success bg-light';
                    } else if ($isselected) {
                        $classes .= ' local-studybuddy-answer-selected border-danger bg-light';
                    }
                }

                $answers[] = [
                    'interactive' => $interactive,
                    'classes' => $classes,
                    'inputid' => 'id_practice_q' . $index . '_a' . $answerindex,
                    'name' => 'responses[' . $index . ']',
                    'value' => $answerindex,
                    'checked' => $isselected,
                    'text' => (string)($answer['text'] ?? ''),
                    'showcorrectbadge' => ($interactive && $evaluation !== null && $iscorrect) || (!$interactive && $iscorrect),
                    'showyouranswerbadge' => $interactive && $evaluation !== null && $isselected && !$iscorrect,
                ];
            }

            $evaluationclass = '';
            $evaluationtext = '';
            if ($interactive && $questionresult !== null) {
                $evaluationclass = !empty($questionresult['iscorrect']) ? 'alert alert-success mb-2' : 'alert alert-warning mb-2';
                $evaluationtext = !empty($questionresult['iscorrect']) ?
                    get_string('questioncorrect', 'local_studybuddy') :
                    get_string('questionincorrect', 'local_studybuddy');
                if (!empty($questionresult['unanswered'])) {
                    $evaluationclass = 'alert alert-secondary mb-2';
                    $evaluationtext = get_string('questionunanswered', 'local_studybuddy');
                }
            }

            $citations = [];
            foreach (array_values($question['citations'] ?? []) as $citation) {
                $label = (string)($citation['title'] ?? get_string('citations', 'local_studybuddy'));
                if (!empty($citation['chunkid'])) {
                    $label .= ' #' . $citation['chunkid'];
                }
                $citations[] = ['label' => $label];
            }

            $questions[] = [
                'number' => $index + 1,
                'questiontext' => (string)($question['questiontext'] ?? ''),
                'showanswers' => ($question['type'] ?? 'multichoice') === 'multichoice',
                'answers' => $answers,
                'showevaluation' => $interactive && $questionresult !== null,
                'evaluationclass' => $evaluationclass,
                'evaluationtext' => $evaluationtext,
                'showfeedback' => !empty($question['feedback']) && $evaluation !== null,
                'feedback' => (string)($question['feedback'] ?? ''),
                'hascitations' => !empty($citations),
                'citations' => $citations,
            ];
        }

        $summarymessage = '';
        if ($interactive && $evaluation !== null) {
            $summarymessage = get_string(
                'practiceoverallscore',
                'local_studybuddy',
                (object)[
                    'score' => (int)round($evaluation['score']),
                    'maxscore' => (int)$evaluation['maxscore'],
                    'percentage' => (int)$evaluation['percentage'],
                ]
            );
        }

        return [
            'hasquestions' => !empty($questions),
            'interactive' => $interactive,
            'sesskey' => sesskey(),
            'courseid' => $courseid,
            'practiceid' => $practiceid,
            'showinstructions' => $interactive && $evaluation === null,
            'instructionsmessage' => $interactive ?
                get_string('practiceinstructions', 'local_studybuddy') :
                get_string('previewinstructions', 'local_studybuddy'),
            'showsummary' => $interactive && $evaluation !== null,
            'summarymessage' => $summarymessage,
            'questions' => $questions,
            'submitlabel' => $evaluation === null ?
                get_string('submitquizanswers', 'local_studybuddy') :
                get_string('resubmitquizanswers', 'local_studybuddy'),
            'submitsecondary' => $evaluation !== null,
            'noquestionsmessage' => get_string('noquestions', 'local_studybuddy'),
        ];
    }

    /**
     * Prepare citation labels.
     *
     * @param array $citations Citation data.
     * @return array
     */
    private function prepare_citations(array $citations): array {
        $items = [];
        $seen = [];
        foreach (array_values($citations) as $citation) {
            $label = trim((string)($citation['title'] ?? get_string('citations', 'local_studybuddy')));
            if ($label === '') {
                continue;
            }
            $key = \core_text::strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = ['label' => $label];
        }

        return $items;
    }
}
