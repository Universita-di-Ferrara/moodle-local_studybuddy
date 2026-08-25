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

use local_studybuddy\local\provider\ai_provider;
use local_studybuddy\local\provider\provider_factory;

/**
 * Generates structured e-tivity JSON from retrieved context.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_generator {
    /** @var ai_provider AI provider. */
    private ai_provider $provider;

    /**
     * Constructor.
     *
     * @param ai_provider|null $provider Provider.
     */
    public function __construct(?ai_provider $provider = null) {
        $this->provider = $provider ?? provider_factory::create();
    }

    /**
     * Generates a quiz JSON object.
     *
     * @param array $context Retrieved RAG context.
     * @param array $params Generation parameters.
     * @return array Structured quiz JSON.
     */
    public function generate_quiz(array $context, array $params): array {
        $params['activitytype'] = 'quiz';
        return $this->generate_activity($context, $params);
    }

    /**
     * Generates a study activity JSON object.
     *
     * @param array $context Retrieved RAG context.
     * @param array $params Generation parameters.
     * @return array Structured activity JSON.
     */
    public function generate_activity(array $context, array $params): array {
        $params['nquestions'] = max(1, min(10, (int)($params['nquestions'] ?? 5)));
        $params['difficulty'] = clean_param((string)($params['difficulty'] ?? 'medium'), PARAM_ALPHA);
        $params['activitytype'] = $this->normalise_activity_type((string)($params['activitytype'] ?? 'quiz'));

        $prompt = $this->build_controlled_prompt($params);
        $result = $this->provider->generate($prompt, [
            'context' => $context,
            'params' => $params,
        ]);

        return $this->normalise_activity_result($result, $params);
    }

    /**
     * Validates decoded quiz JSON.
     *
     * @param array $data Data to validate.
     * @return bool
     */
    public function is_valid_quiz(array $data): bool {
        if (empty($data['questions']) || !is_array($data['questions'])) {
            return false;
        }

        foreach ($data['questions'] as $question) {
            if (
                !is_array($question) || ($question['type'] ?? '') !== 'multichoice' ||
                    trim((string)($question['questiontext'] ?? '')) === ''
            ) {
                return false;
            }

            if (empty($question['answers']) || !is_array($question['answers'])) {
                return false;
            }

            $answers = [];
            foreach ($question['answers'] as $answer) {
                if (!is_array($answer)) {
                    return false;
                }
                $text = trim((string)($answer['text'] ?? ''));
                if ($text === '' || isset($answers[\core_text::strtolower($text)])) {
                    return false;
                }
                $answers[\core_text::strtolower($text)] = true;
            }
            if (count($answers) < 2) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validates decoded flashcards JSON.
     *
     * @param array $data Data to validate.
     * @return bool
     */
    public function is_valid_flashcards(array $data): bool {
        $cards = $data['flashcards'] ?? [];
        if (empty($cards) || !is_array($cards)) {
            return false;
        }

        foreach ($cards as $card) {
            if (trim((string)($card['front'] ?? '')) === '' || trim((string)($card['back'] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Builds the generation prompt constraints.
     *
     * @param array $params Generation params.
     * @return string Prompt.
     */
    private function build_controlled_prompt(array $params): string {
        $nquestions = (int)($params['nquestions'] ?? 5);
        $difficulty = (string)($params['difficulty'] ?? 'medium');
        $activitytype = $params['activitytype'];

        $schemas = json_decode(
            prompt_config::get('activityjsonschema', 'default:activityjsonschema'),
            true
        );
        if (!is_array($schemas) || !isset($schemas[$activitytype]) || !is_array($schemas[$activitytype])) {
            throw new \moodle_exception('invalidjson', 'local_studybuddy');
        }

        $format = prompt_config::get('activityformatinstructions', 'default:activityformatinstructions');
        $replacements = [
            '%%TYPE%%' => $activitytype,
            '%%COUNT%%' => in_array($activitytype, ['quiz', 'flashcards'], true) ? (string)$nquestions : '',
            '%%DIFFICULTY%%' => in_array($activitytype, ['quiz', 'flashcards'], true) ? $difficulty : '',
            '%%SCHEMA%%' => json_encode($schemas[$activitytype], JSON_UNESCAPED_UNICODE),
        ];
        $format = strtr($format, $replacements);

        $instructions = [
            prompt_config::get('activityinstructions', 'default:activityinstructions'),
            trim($format),
        ];

        return implode("\n", $instructions);
    }

    /**
     * Normalises provider output to the expected JSON shape.
     *
     * @param array $result Provider result.
     * @param array $params Generation parameters.
     * @return array
     */
    private function normalise_activity_result(array $result, array $params): array {
        $type = $this->normalise_activity_type((string)($result['activitytype'] ?? $params['activitytype'] ?? 'quiz'));

        if ($type === 'flashcards') {
            return $this->normalise_flashcards_result($result, $params);
        }

        if ($type === 'conceptmap') {
            return $this->normalise_conceptmap_result($result, $params);
        }

        return $this->normalise_quiz_result($result, $params);
    }

    /**
     * Normalises provider output to the expected quiz JSON shape.
     *
     * @param array $result Provider result.
     * @param array $params Generation parameters.
     * @return array
     */
    private function normalise_quiz_result(array $result, array $params): array {
        $result['activitytype'] = $result['activitytype'] ?? 'quiz';
        $result['title'] = $this->normalise_title($result, $params);
        $result['questions'] = array_values($result['questions'] ?? []);

        foreach ($result['questions'] as $index => $question) {
            $result['questions'][$index]['type'] = $question['type'] ?? 'multichoice';
            $result['questions'][$index]['questiontext'] = trim((string)($question['questiontext'] ?? ''));
            $result['questions'][$index]['feedback'] = trim((string)($question['feedback'] ?? ''));
            $result['questions'][$index]['citations'] = array_values($question['citations'] ?? []);

            if (($result['questions'][$index]['type'] ?? '') === 'multichoice') {
                $result['questions'][$index]['answers'] = array_values($question['answers'] ?? []);
            }
        }

        return $result;
    }

    /**
     * Normalise flashcards.
     *
     * @param array $result Provider result.
     * @param array $params Generation params.
     * @return array
     */
    private function normalise_flashcards_result(array $result, array $params): array {
        $result['activitytype'] = 'flashcards';
        $result['title'] = $this->normalise_title($result, $params);
        $result['difficulty'] = $result['difficulty'] ?? ($params['difficulty'] ?? 'medium');
        $result['flashcards'] = array_values($result['flashcards'] ?? $result['cards'] ?? []);

        foreach ($result['flashcards'] as $index => $card) {
            $result['flashcards'][$index] = [
                'front' => trim((string)($card['front'] ?? $card['question'] ?? '')),
                'back' => trim((string)($card['back'] ?? $card['answer'] ?? '')),
                'hint' => trim((string)($card['hint'] ?? '')),
                'citations' => array_values($card['citations'] ?? []),
            ];
        }

        return $result;
    }

    /**
     * Normalise concept map.
     *
     * @param array $result Provider result.
     * @param array $params Generation params.
     * @return array
     */
    private function normalise_conceptmap_result(array $result, array $params): array {
        return [
            'activitytype' => 'conceptmap',
            'title' => $this->normalise_title($result, $params),
            'root' => $this->normalise_conceptmap_node($result['root'] ?? [], 'n1', 1),
        ];
    }

    /**
     * Normalise an AI-generated activity title.
     *
     * @param array $result Provider result.
     * @param array $params Generation params.
     * @return string Title.
     */
    private function normalise_title(array $result, array $params): string {
        $title = trim((string)($result['title'] ?? $result['name'] ?? ''));
        if ($title === '') {
            $title = trim((string)($params['query'] ?? get_string('pluginname', 'local_studybuddy')));
        }

        return shorten_text($title, 120);
    }

    /**
     * Normalise a recursive concept map node.
     *
     * @param array $node Node data.
     * @param string $fallbackid Fallback id.
     * @param int $level Current level.
     * @return array
     */
    private function normalise_conceptmap_node(array $node, string $fallbackid, int $level): array {
        $id = trim((string)($node['id'] ?? $fallbackid));
        $children = [];

        if ($level < 4) {
            foreach (array_slice(array_values($node['children'] ?? []), 0, 5) as $index => $child) {
                if (!is_array($child)) {
                    continue;
                }
                $children[] = $this->normalise_conceptmap_node($child, $id . '_' . ($index + 1), $level + 1);
            }
        }

        return [
            'id' => $id !== '' ? $id : $fallbackid,
            'label' => trim((string)($node['label'] ?? $node['title'] ?? '')),
            'description' => trim((string)($node['description'] ?? '')),
            'relation' => trim((string)($node['relation'] ?? '')),
            'citations' => array_values($node['citations'] ?? []),
            'children' => $children,
        ];
    }

    /**
     * Normalise activity type.
     *
     * @param string $type Raw type.
     * @return string Supported type.
     */
    private function normalise_activity_type(string $type): string {
        return in_array($type, ['quiz', 'flashcards', 'conceptmap'], true) ? $type : 'quiz';
    }
}
