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
 * Tests the search module interface without going through the web service.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(search::class)]
#[CoversClass(search_filters::class)]
final class search_module_test extends \advanced_testcase {
    /**
     * Creates a system-level category and returns it with its question generator.
     *
     * @return array [category, generator]
     */
    private function category_and_generator(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        return [$this->getDataGenerator()->get_plugin_generator('block_fragenfilter')->create_public_category(), $generator];
    }

    public function test_limit_is_applied_after_filters(): void {
        [$cat, $generator] = $this->category_and_generator();
        for ($i = 0; $i < 3; $i++) {
            $generator->create_question('truefalse', null, ['category' => $cat->id]);
        }
        $essays = [];
        for ($i = 0; $i < 2; $i++) {
            $essays[] = $generator->create_question('essay', null, ['category' => $cat->id]);
        }
        $filters = new search_filters(types: ['essay'], limit: 1);
        $result = search::questions($filters, \context_system::instance(), 2, '127.0.0.1');
        $this->assertCount(1, $result['questions']);
        $this->assertSame('essay', $result['questions'][0]['qtype']);
        $this->assertSame(1, $result['total']);
    }

    public function test_text_filter_treats_wildcards_and_backslash_literally(): void {
        [$cat, $generator] = $this->category_and_generator();
        $target = $generator->create_question('truefalse', null, [
            'category' => $cat->id, 'name' => "Literal 50%_ O'Reilly \\ bang! example",
        ]);
        $generator->create_question('truefalse', null, ['category' => $cat->id, 'name' => 'Unrelated question']);
        foreach (['50%_', "O'Reilly", '\\', 'bang!'] as $term) {
            $filters = new search_filters(text: $term);
            $result = search::questions($filters, \context_system::instance(), 2, '127.0.0.1');
            $this->assertSame([(int)$target->id], array_column($result['questions'], 'id'), $term);
        }
        $filters = new search_filters(text: "' OR 1=1 --");
        $this->assertSame([], search::questions($filters, \context_system::instance(), 2, '127.0.0.1')['questions']);
    }

    public function test_category_filter_rejects_malformed_ids(): void {
        $this->expectException(\invalid_parameter_exception::class);
        search_filters::from_input('', '1,x', '', '', '', 'all', 200, 200);
    }

    public function test_rate_limit_is_kept_per_account_and_address(): void {
        $this->resetAfterTest();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        for ($i = 0; $i < search::RATE_LIMIT_REQUESTS; $i++) {
            search::enforce_rate_limit((int)$first->id, '192.0.2.10');
        }
        try {
            search::enforce_rate_limit((int)$first->id, '192.0.2.10');
            $this->fail('Expected rate limit for the same account and address.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ratelimitexceeded', $e->errorcode);
        }
        // Same account from another address and another account from the same address stay open.
        search::enforce_rate_limit((int)$first->id, '192.0.2.11');
        search::enforce_rate_limit((int)$second->id, '192.0.2.10');
        $this->assertTrue(true);
    }

    public function test_tags_of_all_results_are_loaded_in_one_query(): void {
        global $DB;
        [$cat, $generator] = $this->category_and_generator();
        $ids = [];
        foreach (['A', 'B', 'C'] as $name) {
            $q = $generator->create_question('truefalse', null, ['category' => $cat->id, 'name' => $name]);
            \core_tag_tag::set_item_tags('core_question', 'question', $q->id, \context_system::instance(), [$name . 'tag']);
            $ids[] = (int)$q->id;
        }
        $before = $DB->perf_get_reads();
        $tags = search::tags_for_questions($ids);
        $this->assertSame(1, $DB->perf_get_reads() - $before);
        $this->assertCount(1, $tags[$ids[0]]);
        // Moodle normalises tag names to lowercase.
        $this->assertSame('atag', $tags[$ids[0]][0]['name']);
        $this->assertSame([], $tags[999999] ?? []);
    }

    public function test_context_labels_use_language_strings(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Kurs Eins']);
        $this->assertSame(get_string('coresystem', 'block_fragenfilter'), search::context_label(CONTEXT_SYSTEM, 0));
        $this->assertSame('Kurs Eins', search::context_label(CONTEXT_COURSE, (int)$course->id));
        $this->assertSame(
            get_string('ctxcourseid', 'block_fragenfilter', 999999),
            search::context_label(CONTEXT_COURSE, 999999)
        );
    }
}
