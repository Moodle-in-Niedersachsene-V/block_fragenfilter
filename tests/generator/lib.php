<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Published question-bank fixtures for the materials hub.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_fragenfilter_generator extends component_generator_base {
    /**
     * Creates a question category in a question bank under Aufgabenpool.
     *
     * @param array $record Question category fields.
     * @return stdClass Question category.
     */
    public function create_public_category(array $record = []): stdClass {
        global $DB;
        $generator = $this->datagenerator;
        $pool = $DB->get_record('course_categories', ['name' => 'Aufgabenpool']);
        if (!$pool) {
            $pool = $generator->create_category(['name' => 'Aufgabenpool']);
        }
        $course = $generator->create_course(['category' => $pool->id]);
        $bank = $generator->create_module('qbank', ['course' => $course->id]);
        $record['contextid'] = context_module::instance($bank->cmid)->id;
        return $generator->get_plugin_generator('core_question')->create_question_category($record);
    }
}
