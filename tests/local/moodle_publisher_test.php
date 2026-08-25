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
 * Tests Moodle quiz publication normalisation.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_studybuddy\local\moodle_publisher
 */
final class moodle_publisher_test extends \advanced_testcase {
    /**
     * Published multichoice questions always have one 100-percent answer.
     *
     * @return void
     */
    public function test_normalises_multichoice_answers(): void {
        $publisher = new moodle_publisher();
        $method = new \ReflectionMethod(moodle_publisher::class, 'normalise_multichoice_answers');
        $method->setAccessible(true);

        $answers = $method->invoke($publisher, [
            ['text' => 'A', 'fraction' => 50],
            ['text' => 'B', 'fraction' => 100],
            ['text' => 'C', 'fraction' => 100],
        ]);

        $this->assertSame([0, 100, 0], array_column($answers, 'fraction'));
    }
}
