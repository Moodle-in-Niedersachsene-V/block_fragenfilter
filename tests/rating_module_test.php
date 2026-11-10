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
 * Tests the rating module interface without going through the web service (ADR 0003).
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(rating::class)]
final class rating_module_test extends \advanced_testcase {
    /** @var string First browser cookie hash. */
    private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var string Second browser cookie hash. */
    private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * Creates a visible question for the admin user.
     *
     * @return \stdClass Question record.
     */
    private function question(): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $this->getDataGenerator()->get_plugin_generator('block_fragenfilter')->create_public_category();
        return $generator->create_question('essay', null, ['category' => $category->id]);
    }

    public function test_rating_once_per_browser_keeps_first_stars(): void {
        global $DB;

        $question = $this->question();
        $context = \context_system::instance();
        $first = rating::rate($question->id, 4, $context, self::HASH_A);
        $this->assertSame(['average' => 4.0, 'count' => 1, 'myrating' => 4], $first);

        $again = rating::rate($question->id, 1, $context, self::HASH_A);
        $this->assertSame($first, $again);
        $this->assertSame(1, $DB->count_records('block_fragenfilter_ratings', ['questionid' => $question->id]));
    }

    public function test_second_browser_on_same_account_rates_separately(): void {
        $question = $this->question();
        $context = \context_system::instance();
        rating::rate($question->id, 4, $context, self::HASH_A);
        $second = rating::rate($question->id, 2, $context, self::HASH_B);

        $this->assertSame(['average' => 3.0, 'count' => 2, 'myrating' => 2], $second);
        $own = rating::for_questions([$question->id], self::HASH_A);
        $this->assertSame(4, $own[0]['myrating']);
    }

    public function test_simultaneous_request_returns_the_stored_rating(): void {
        global $DB;

        $question = $this->question();
        $DB->insert_record('block_fragenfilter_ratings', (object)[
            'questionid' => $question->id, 'cookiehash' => self::HASH_A, 'stars' => 5, 'timecreated' => time(),
        ]);
        // The unique index rejects the second insert; the stored rating is answered instead.
        $result = rating::rate($question->id, 1, \context_system::instance(), self::HASH_A);

        $this->assertSame(['average' => 5.0, 'count' => 1, 'myrating' => 5], $result);
        $this->assertSame(1, $DB->count_records('block_fragenfilter_ratings', ['questionid' => $question->id]));
    }

    public function test_rating_without_cookie_counts_in_average(): void {
        global $DB;

        $question = $this->question();
        $DB->insert_record('block_fragenfilter_ratings', (object)[
            'questionid' => $question->id, 'cookiehash' => null, 'stars' => 2, 'timecreated' => time(),
        ]);
        $result = rating::rate($question->id, 4, \context_system::instance(), self::HASH_A);

        $this->assertSame(['average' => 3.0, 'count' => 2, 'myrating' => 4], $result);
        $own = rating::for_questions([$question->id], self::HASH_B);
        $this->assertSame(0, $own[0]['myrating']);
    }

    public function test_invisible_question_is_rejected_without_rating(): void {
        global $DB;

        $this->question();
        $this->expectException(\moodle_exception::class);
        try {
            rating::rate(999999, 3, \context_system::instance(), self::HASH_A);
        } finally {
            $this->assertSame(0, $DB->count_records('block_fragenfilter_ratings'));
        }
    }

    public function test_rating_triggers_one_event_without_cookie_hash(): void {
        $question = $this->question();
        $sink = $this->redirectEvents();
        rating::rate($question->id, 3, \context_system::instance(), self::HASH_A);
        rating::rate($question->id, 5, \context_system::instance(), self::HASH_A);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(event\question_rated::class, $events[0]);
        $this->assertSame(['questionid' => (int)$question->id, 'stars' => 3], $events[0]->other);
        $this->assertStringNotContainsString(self::HASH_A, json_encode($events[0]->get_data()));
    }

    public function test_unknown_questions_have_no_rating_data(): void {
        $question = $this->question();
        $result = rating::for_questions([$question->id, 999999], self::HASH_A);

        $this->assertSame([
            ['questionid' => $question->id, 'average' => 0.0, 'count' => 0, 'myrating' => 0],
            ['questionid' => 999999, 'average' => 0.0, 'count' => 0, 'myrating' => 0],
        ], $result);
    }
}
