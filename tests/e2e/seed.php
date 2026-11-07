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
 * Opt-in browser fixtures created through Moodle core APIs on a dedicated test site.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../../config.php');
if (PHP_SAPI !== 'cli' || getenv('FRAGENFILTER_E2E_SETUP') !== '1') {
    die("Set FRAGENFILTER_E2E_SETUP=1 and run this script only on a dedicated test site.\n");
}
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->libdir . '/questionlib.php');
\core\session\manager::set_user(get_admin());
$token = getenv('FRAGENFILTER_E2E_TOKEN') ?: 'QFE2E' . date('YmdHis');
if (!preg_match('/^QFE2E[0-9]+$/D', $token)) {
    throw new RuntimeException('The fixture token must start with QFE2E followed by digits.');
}
if ($DB->record_exists('course', ['shortname' => $token])) {
    throw new RuntimeException('Fixture already exists; reuse its saved manifest.');
}
$pool = $DB->get_record('course_categories', ['name' => 'Aufgabenpool']);
if (!$pool) {
    $pool = \core_course_category::create(['name' => 'Aufgabenpool', 'parent' => 0]);
}
$private = \core_course_category::create(['name' => $token . ' privat', 'parent' => 0]);

$result = [
    'token' => $token, 'contextid' => context_system::instance()->id, 'courses' => [],
    'questions' => [], 'outside' => null, 'privatecategory' => $private->id,
];
foreach ([$pool->id, $private->id] as $index => $categoryid) {
    $course = create_course((object)[
        'fullname' => $token . ($index ? ' privat' : ' Aufgabenpool'),
        'shortname' => $token . ($index ? '-private' : ''),
        'category' => $categoryid, 'format' => 'topics', 'visible' => 1,
    ]);
    $result['courses'][] = (int)$course->id;
    $bank = add_moduleinfo((object)[
        'modulename' => 'qbank', 'module' => $DB->get_field('modules', 'id', ['name' => 'qbank']),
        'course' => $course->id, 'section' => 0, 'visible' => 1,
        'name' => $token . ' Fragebank', 'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
    ], $course);
    $context = context_module::instance($bank->coursemodule);
    $category = question_get_default_category($context->id, true);
    $names = $index ? [$token . ' Privat'] : [$token . ' Erste', $token . ' " autofocus onfocus="window.__qf_xss=1"'];
    foreach ($names as $name) {
        $question = (object)['qtype' => 'truefalse', 'defaultmark' => 1];
        $editor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $form = (object)[
            'name' => $name, 'category' => (string)$category->id,
            'questiontext' => ['text' => '<p>' . $token . ': Zwei plus zwei ist vier.</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
            'generalfeedback' => $editor, 'feedbacktrue' => $editor, 'feedbackfalse' => $editor,
            'correctanswer' => 1, 'defaultmark' => 1, 'penalty' => 1, 'status' => 'ready',
        ];
        $saved = question_bank::get_qtype('truefalse')->save_question($question, $form);
        if ($index) {
            $result['outside'] = (int)$saved->id;
        } else {
            $result['questions'][] = ['id' => (int)$saved->id, 'name' => $name, 'cmid' => (int)$bank->coursemodule];
        }
    }
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
