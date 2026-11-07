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

namespace block_fragenfilter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

/**
 * Search visibility and export contents through the external API.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(\block_fragenfilter_external::class)]
final class search_export_test extends \advanced_testcase {
    public function test_search_respects_ownership_and_course_scope(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/blocks/fragenfilter/classes/external.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $system = \context_system::instance();
        $coursectx = \context_course::instance($course->id);
        $bankctx = \context_module::instance($bank->cmid);
        $otherctx = \context_course::instance($othercourse->id);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $questions = [];
        foreach ([$system, $coursectx, $bankctx, $otherctx] as $ctx) {
            $cat = $generator->create_question_category(['contextid' => $ctx->id]);
            // Keep legacy system/course banks to exercise all supported context levels.
            $DB->set_field('question_categories', 'contextid', $ctx->id, ['id' => $cat->id]);
            $questions[$ctx->id] = $generator->create_question('truefalse', null, [
                'category' => $cat->id, 'createdby' => $user->id,
            ]);
        }
        $foreign = $generator->create_question('essay', null, [
            'category' => $questions[$bankctx->id]->category, 'name' => 'Limit tracer A foreign',
        ]);
        $DB->set_field('question', 'name', 'Limit tracer Z own', ['id' => $questions[$bankctx->id]->id]);
        $role = create_role('Search own', 'searchown', '');
        assign_capability('moodle/question:viewmine', CAP_ALLOW, $role, $system->id);
        role_assign($role, $user->id, $system->id);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->getDataGenerator()->enrol_user($user->id, $othercourse->id);
        $this->setUser($user);
        $limited = \block_fragenfilter_external::search_questions('Limit tracer', '', '', '', '', 'course', $coursectx->id, 1);
        $this->assertSame([(int)$questions[$bankctx->id]->id], array_column($limited['questions'], 'id'));
        foreach (
            ['system' => [$system->id], 'course' => [$coursectx->id, $bankctx->id],
                'all' => array_keys($questions)] as $scope => $contextids
        ) {
            $result = \block_fragenfilter_external::search_questions('', '', '', '', '', $scope, $coursectx->id, 200);
            $result = \external_api::clean_returnvalue(\block_fragenfilter_external::search_questions_returns(), $result);
            $expected = array_map(fn($id) => (int)$questions[$id]->id, $contextids);
            $this->assertEqualsCanonicalizing($expected, array_column($result['questions'], 'id'), $scope);
        }
        $types = \block_fragenfilter_external::get_questiontypes('course', $coursectx->id, 'existing');
        $types = \external_api::clean_returnvalue(\block_fragenfilter_external::get_questiontypes_returns(), $types);
        $this->assertSame(['truefalse'], array_column($types['qtypes'], 'key'));
        $categories = \block_fragenfilter_external::get_categories('course', $coursectx->id);
        $categories = \external_api::clean_returnvalue(\block_fragenfilter_external::get_categories_returns(), $categories);
        $this->assertCount(2, $categories['categories']);
        $this->assertSame([1, 1], array_column($categories['categories'], 'questioncount'));
        assign_capability('moodle/question:viewall', CAP_ALLOW, $role, $bankctx->id);
        accesslib_clear_all_caches_for_unit_testing();
        $result = \block_fragenfilter_external::search_questions('', '', '', '', '', 'course', $coursectx->id, 200);
        $this->assertContains((int)$foreign->id, array_column($result['questions'], 'id'));
        unassign_capability('moodle/question:viewmine', $role, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        $result = \block_fragenfilter_external::search_questions('', '', '', '', '', 'all', $system->id, 200);
        $this->assertEqualsCanonicalizing(
            [(int)$foreign->id, (int)$questions[$bankctx->id]->id],
            array_column($result['questions'], 'id')
        );
    }
    public function test_search_metadata_requires_block_and_question_permissions(): void {
        global $CFG;
        require_once($CFG->dirroot . '/blocks/fragenfilter/classes/external.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $generator->create_question('truefalse', null, ['category' => $category->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assertSame([], \block_fragenfilter_external::search_questions('', '', '', '', '', 'all', 1, 200)['questions']);
        $this->assertSame([], \block_fragenfilter_external::get_categories('all', 1)['categories']);
        $this->assertSame([], \block_fragenfilter_external::get_questiontypes('all', 1, 'existing')['qtypes']);
        $role = create_role('Denied block', 'deniedblock', '');
        assign_capability('block/fragenfilter:view', CAP_PROHIBIT, $role, 1);
        role_assign($role, $user->id, 1);
        accesslib_clear_all_caches_for_unit_testing();
        foreach (
            [
            fn() => \block_fragenfilter_external::search_questions('', '', '', '', '', 'all', 1, 200),
            fn() => \block_fragenfilter_external::get_categories('all', 1),
            fn() => \block_fragenfilter_external::get_questiontypes('all', 1, 'existing'),
            ] as $call
        ) {
            try {
                $call();
                $this->fail('Missing block permission must be rejected.');
            } catch (\moodle_exception $e) {
                $this->assertSame('nopermission', $e->errorcode);
            }
        }
    }

    public function test_search_rate_limit_is_kept_per_account_and_network_address(): void {
        global $CFG;
        require_once($CFG->dirroot . '/blocks/fragenfilter/classes/external.php');
        $this->resetAfterTest();
        set_config('forcelogin', 0);
        $search = fn() => \block_fragenfilter_external::search_questions('', '', '', '', '', 'all', 1, 1);
        $assertlimited = function () use ($search): void {
            try {
                $search();
                $this->fail('The request over the limit must be rejected.');
            } catch (\moodle_exception $e) {
                $this->assertSame('ratelimitexceeded', $e->errorcode);
            }
        };
        $exhaust = function () use ($search, $assertlimited): void {
            for ($i = 0; $i < \block_fragenfilter_external::RATE_LIMIT_REQUESTS; $i++) {
                $search();
            }
            $assertlimited();
        };

        // One anonymous visitor over the limit does not lock out another address.
        $this->setUser(0);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $exhaust();
        $_SERVER['REMOTE_ADDR'] = '192.0.2.11';
        $this->assertArrayHasKey('questions', $search());

        // A reused account is limited per address, too, and independent of the anonymous visitors.
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $exhaust();
        $_SERVER['REMOTE_ADDR'] = '192.0.2.12';
        $this->assertArrayHasKey('questions', $search());

        // The anonymous visitor on the exhausted address stays rejected within the window.
        $this->setUser(0);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $assertlimited();
    }

    public function test_search_validates_scope_context_ids_and_limits(): void {
        global $CFG;
        require_once($CFG->dirroot . '/blocks/fragenfilter/classes/external.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        foreach (
            [
            fn() => \block_fragenfilter_external::search_questions('', '', '', '', '', 'unknown', 1, 200),
            fn() => \block_fragenfilter_external::get_categories('unknown', 1),
            fn() => \block_fragenfilter_external::get_questiontypes('unknown', 1, 'existing'),
            fn() => \block_fragenfilter_external::get_questiontypes('unknown', 1, 'installed'),
            fn() => \block_fragenfilter_external::search_questions('', '1,bad', '', '', '', 'all', 1, 200),
            ] as $call
        ) {
            try {
                $call();
                $this->fail('Invalid input must be rejected.');
            } catch (\invalid_parameter_exception $e) {
                $this->assertSame('invalidparameter', $e->errorcode);
            }
        }
        try {
            \block_fragenfilter_external::search_questions('', '', '', '', '', 'all', 999999999, 200);
            $this->fail('An unknown context must not fall back to system.');
        } catch (\dml_missing_record_exception $e) {
            $this->assertSame('invalidrecord', $e->errorcode);
        }
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $questions = [];
        for ($i = 0; $i < 202; $i++) {
            $questions[] = $generator->create_question('truefalse', null, [
                'category' => $category->id, 'name' => sprintf('Question %03d', $i),
            ]);
        }
        set_config('resultlimit', 999999, 'block_fragenfilter');
        foreach ([0, -1, 999999] as $limit) {
            $result = \block_fragenfilter_external::search_questions('', '', '', '', '', 'all', 1, $limit);
            $this->assertSame(200, $result['total']);
        }
        $last = end($questions);
        \core_tag_tag::set_item_tags(
            'core_question',
            'question',
            $last->id,
            \context::instance_by_id($category->contextid),
            ["50%_ O'Reilly!"]
        );
        $result = \block_fragenfilter_external::search_questions('', '', '', '', "50%_ O'Reilly!", 'all', 1, 1);
        $this->assertSame([(int)$last->id], array_column($result['questions'], 'id'));
    }

    public function test_search_treats_special_characters_as_literal_text(): void {
        global $CFG;
        require_once($CFG->dirroot . '/blocks/fragenfilter/classes/external.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $generator->create_question_category();
        $name = "Literal 50%_ O'Reilly \\ example";
        $question = $generator->create_question('truefalse', null, ['category' => $cat->id, 'name' => $name]);
        $generator->create_question('truefalse', null, ['category' => $cat->id, 'name' => 'Unrelated question']);
        foreach (["50%_", "O'Reilly", "\\"] as $search) {
            $result = \block_fragenfilter_external::search_questions($search, '', '', '', '', 'all', 1, 200);
            $result = \external_api::clean_returnvalue(\block_fragenfilter_external::search_questions_returns(), $result);
            $this->assertSame([(int)$question->id], array_column($result['questions'], 'id'), $search . ' in ' . $question->name);
        }
        $result = \block_fragenfilter_external::search_questions("' OR 1=1 --", '', '', '', '', 'all', 1, 200);
        $this->assertSame([], $result['questions']);
    }

    public function test_export_widget_keeps_disabled_formats_disabled(): void {
        global $CFG;
        require_once($CFG->dirroot . '/blocks/fragenfilter/classes/external.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        foreach (['xml,csv,gift', 'csv', ''] as $formats) {
            set_config('exportformats', $formats, 'block_fragenfilter');
            $result = \block_fragenfilter_external::check_capabilities(1);
            $result = \external_api::clean_returnvalue(\block_fragenfilter_external::check_capabilities_returns(), $result);
            $this->assertTrue($result['canexport']);
            $this->assertSame($formats, $result['exportformats']);
        }
        $this->setGuestUser();
        $result = \block_fragenfilter_external::check_capabilities(1);
        $this->assertFalse($result['canexport']);
    }

    public function test_export_respects_capability_ownership_and_id_limits(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/blocks/fragenfilter/classes/external.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $own = $generator->create_question('truefalse', null, [
            'category' => $category->id, 'createdby' => $user->id, 'name' => 'Own export',
        ]);
        $foreign = $generator->create_question('truefalse', null, [
            'category' => $category->id, 'name' => 'Foreign export',
        ]);
        $role = create_role('Own exporter', 'ownexporter', '');
        role_assign($role, $user->id, 1);
        $this->setUser($user);
        try {
            \block_fragenfilter_external::export_questions((string)$own->id, 'csv', 1);
            $this->fail('Export requires the dedicated capability.');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopermission', $e->errorcode);
        }
        assign_capability('block/fragenfilter:export', CAP_ALLOW, $role, 1);
        assign_capability('moodle/question:viewmine', CAP_ALLOW, $role, $category->contextid);
        accesslib_clear_all_caches_for_unit_testing();
        $result = \block_fragenfilter_external::export_questions((string)$own->id, 'csv', 1);
        $result = \external_api::clean_returnvalue(\block_fragenfilter_external::export_questions_returns(), $result);
        $this->assertSame(1, $result['count']);
        $before = $DB->get_records('files', null, 'id');
        foreach ([(string)$foreign->id, $own->id . ',' . $foreign->id] as $ids) {
            try {
                \block_fragenfilter_external::export_questions($ids, 'csv', 1);
                $this->fail('Viewmine must not export another author.');
            } catch (\moodle_exception $e) {
                $this->assertSame('invalidquestion', $e->errorcode);
            }
            $this->assertEquals($before, $DB->get_records('files', null, 'id'));
        }
        foreach (['0', '-1', '1,bad', implode(',', range(1, 201))] as $ids) {
            try {
                \block_fragenfilter_external::export_questions($ids, 'csv', 1);
                $this->fail('Invalid IDs or excessive selection must be rejected.');
            } catch (\invalid_parameter_exception $e) {
                $this->assertSame('invalidparameter', $e->errorcode);
            }
            $this->assertEquals($before, $DB->get_records('files', null, 'id'));
        }
        assign_capability('moodle/question:viewall', CAP_ALLOW, $role, $category->contextid);
        accesslib_clear_all_caches_for_unit_testing();
        $result = \block_fragenfilter_external::export_questions($own->id . ',' . $foreign->id, 'csv', 1);
        $this->assertSame(2, $result['count']);
        $files = get_file_storage()->get_area_files(1, 'block_fragenfilter', 'export', $USER->id, 'id', false);
        $this->assertCount(1, $files);
        $this->assertStringContainsString('Foreign export', reset($files)->get_content());
    }

    public function test_export_contents_for_xml_csv_and_gift(): void {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/blocks/fragenfilter/classes/external.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['name' => 'Sample category']);
        $question = $generator->create_question('truefalse', null, [
            'category' => $category->id, 'name' => 'Sample "quoted", question',
            'questiontext' => ['text' => '<p>The sky is blue.</p>', 'format' => FORMAT_HTML], 'correctanswer' => 1,
        ]);
        \core_tag_tag::set_item_tags(
            'core_question',
            'question',
            $question->id,
            \context::instance_by_id($category->contextid),
            ['Example']
        );
        set_config('exportformats', 'xml,csv,gift', 'block_fragenfilter');
        foreach (['xml', 'csv', 'gift'] as $format) {
            $result = \block_fragenfilter_external::export_questions((string)$question->id, $format, 1);
            $result = \external_api::clean_returnvalue(\block_fragenfilter_external::export_questions_returns(), $result);
            $files = get_file_storage()->get_area_files(1, 'block_fragenfilter', 'export', $USER->id, 'id', false);
            $this->assertCount(1, $files, 'A new export replaces only this visitor export file.');
            $content = reset($files)->get_content();
            $this->assertSame(1, $result['count']);
            if ($format === 'xml') {
                $xml = simplexml_load_string($content);
                $this->assertSame('truefalse', (string)$xml->question['type'], $content);
                $this->assertSame('Sample "quoted", question', (string)$xml->question->name->text);
                $this->assertSame('<p>The sky is blue.</p>', (string)$xml->question->questiontext->text);
                $this->assertSame('true', (string)$xml->question->answer[0]->text);
                $this->assertSame('100', (string)$xml->question->answer[0]['fraction']);
            } else if ($format === 'csv') {
                $lines = explode("\r\n", $content);
                $this->assertSame([(string)$question->id, 'Sample "quoted", question', 'truefalse',
                    'Sample category', 'Example'], str_getcsv($lines[1], ',', '"', ''));
            } else {
                $this->assertStringContainsString('::Sample "quoted", question::', $content);
                $this->assertStringContainsString('The sky is blue.', $content);
                $this->assertStringContainsString('{TRUE#', $content);
                $this->assertStringContainsString('This is the right answer.', $content);
            }
        }
    }
}
