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
 * Tests the configured StudyBuddy course scope.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_studybuddy\local\course_availability
 */
final class course_availability_test extends \advanced_testcase {
    /**
     * All-course scope enables every course.
     *
     * @return void
     */
    public function test_all_courses_scope(): void {
        $this->resetAfterTest(true);
        set_config('course_scope', 'all', 'local_studybuddy');

        $this->assertNull(course_availability::get_enabled_course_ids());
        $this->assertTrue(course_availability::is_enabled(42));
    }

    /**
     * Selected-course scope parses supported separators and excludes invalid ids.
     *
     * @return void
     */
    public function test_selected_courses_scope(): void {
        $this->resetAfterTest(true);
        set_config('course_scope', 'selected', 'local_studybuddy');
        set_config('enabled_course_ids', '2, 42; 99 invalid 42', 'local_studybuddy');

        $this->assertSame([2, 42, 99], course_availability::get_enabled_course_ids());
        $this->assertTrue(course_availability::is_enabled(42));
        $this->assertFalse(course_availability::is_enabled(43));
        $this->assertFalse(course_availability::is_enabled(1));
    }
}
