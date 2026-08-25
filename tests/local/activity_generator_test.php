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
 * Tests the local validation contract for generated activities.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_studybuddy\local\activity_generator
 */
final class activity_generator_test extends \advanced_testcase {
    /**
     * Tests valid and invalid quiz payloads.
     *
     * @return void
     */
    public function test_quiz_validation(): void {
        $generator = new activity_generator();
        $valid = [
            'questions' => [[
                'type' => 'multichoice',
                'questiontext' => 'Which answer is correct?',
                'answers' => [
                    ['text' => 'Correct', 'fraction' => 100],
                    ['text' => 'Incorrect', 'fraction' => 0],
                ],
            ], ],
        ];

        $this->assertTrue($generator->is_valid_quiz($valid));
        $valid['questions'][0]['answers'][1]['text'] = 'Correct';
        $this->assertFalse($generator->is_valid_quiz($valid));
        $valid['questions'][0]['answers'][1]['text'] = 'Incorrect';
        $valid['questions'][0]['type'] = 'shortanswer';
        $this->assertFalse($generator->is_valid_quiz($valid));
    }

    /**
     * Tests flashcard validation.
     *
     * @return void
     */
    public function test_flashcard_validation(): void {
        $generator = new activity_generator();
        $this->assertTrue($generator->is_valid_flashcards([
            'flashcards' => [['front' => 'Question', 'back' => 'Answer']],
        ]));
        $this->assertFalse($generator->is_valid_flashcards([
            'flashcards' => [['front' => 'Question', 'back' => '']],
        ]));
    }
}
