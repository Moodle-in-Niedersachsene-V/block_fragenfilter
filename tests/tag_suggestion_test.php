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

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Tag suggestion lifecycle: creation, applying and review through tag_suggestion.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class tag_suggestion_test extends \advanced_testcase {
    /**
     * Creates a question in a fresh category and returns its id.
     *
     * @return int Question id.
     */
    private function create_question(string $name = 'Frage'): int {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $question = $generator->create_question('truefalse', null, [
            'category' => $category->id, 'name' => $name,
            'questiontext' => ['text' => 'Text', 'format' => FORMAT_HTML],
        ]);
        return (int)$question->id;
    }

    /**
     * Returns the stored suggestion row for a question.
     *
     * @param int $questionid Question id.
     * @return \stdClass Suggestion record.
     */
    private function suggestion_for(int $questionid): \stdClass {
        global $DB;
        return $DB->get_record('block_fragenfilter_tag_pending', ['questionid' => $questionid], '*', MUST_EXIST);
    }

    /**
     * Returns the tag names of a question.
     *
     * @param int $questionid Question id.
     * @return string[] Sorted tag names.
     */
    private function tags_of(int $questionid): array {
        $tags = \core_tag_tag::get_item_tags_array('core_question', 'question', $questionid);
        sort($tags);
        return $tags;
    }

    public function test_create_stores_pending_applied_or_empty_outcomes(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $pendingid = $this->create_question('A');
        $appliedid = $this->create_question('B');
        $emptyid = $this->create_question('C');

        $this->assertSame('pending', tag_suggestion::create($pendingid, ['Mathe'], false));
        $this->assertSame('applied', tag_suggestion::create($appliedid, ['Physik'], true));
        $this->assertSame('empty', tag_suggestion::create($emptyid, [], true));

        $this->assertSame('pending', $this->suggestion_for($pendingid)->status);
        $this->assertSame('Mathe', $this->suggestion_for($pendingid)->tags);
        $this->assertSame([], $this->tags_of($pendingid));
        $this->assertSame('applied', $this->suggestion_for($appliedid)->status);
        $this->assertSame(['Physik'], $this->tags_of($appliedid));
        $this->assertSame('empty', $this->suggestion_for($emptyid)->status);
        $this->assertSame([], $this->tags_of($emptyid));
        $this->assertSame(3, $DB->count_records('block_fragenfilter_tag_pending'));
    }

    public function test_approve_keeps_existing_tags_and_emits_one_event_per_review(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $questionid = $this->create_question();
        \core_tag_tag::set_item_tags(
            'core_question',
            'question',
            $questionid,
            \context_system::instance(),
            ['Bestand']
        );
        tag_suggestion::create($questionid, ['Neu', 'Mehr'], false);
        $suggestion = $this->suggestion_for($questionid);

        $sink = $this->redirectEvents();
        $pending = suggestion_review::pending(suggestion_review::visible_records());
        tag_suggestion::review('approve', $pending, (int)$suggestion->id);
        $events = array_filter($sink->get_events(), static fn($e) => $e instanceof event\suggestion_reviewed);

        $this->assertSame(['Bestand', 'Mehr', 'Neu'], $this->tags_of($questionid));
        $this->assertSame('applied', $this->suggestion_for($questionid)->status);
        $this->assertCount(1, $events);
    }

    public function test_reject_changes_only_the_suggestion_status(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $questionid = $this->create_question();
        tag_suggestion::create($questionid, ['Verworfen'], false);
        $suggestion = $this->suggestion_for($questionid);

        $sink = $this->redirectEvents();
        $pending = suggestion_review::pending(suggestion_review::visible_records());
        tag_suggestion::review('reject', $pending, (int)$suggestion->id);
        $events = array_filter($sink->get_events(), static fn($e) => $e instanceof event\suggestion_reviewed);

        $this->assertSame('rejected', $this->suggestion_for($questionid)->status);
        $this->assertSame([], $this->tags_of($questionid));
        $this->assertCount(1, $events);
    }

    public function test_bulk_approve_applies_every_pending_suggestion_with_one_event_each(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $first = $this->create_question('Eins');
        $second = $this->create_question('Zwei');
        tag_suggestion::create($first, ['T1'], false);
        tag_suggestion::create($second, ['T2'], false);

        $sink = $this->redirectEvents();
        $pending = suggestion_review::pending(suggestion_review::visible_records());
        tag_suggestion::review('approveall', $pending, 0);
        $events = array_filter($sink->get_events(), static fn($e) => $e instanceof event\suggestion_reviewed);

        $this->assertSame(['T1'], $this->tags_of($first));
        $this->assertSame(['T2'], $this->tags_of($second));
        $this->assertSame('applied', $this->suggestion_for($first)->status);
        $this->assertSame('applied', $this->suggestion_for($second)->status);
        $this->assertCount(2, $events);
    }

    public function test_unavailable_single_suggestion_is_rejected_without_change(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $questionid = $this->create_question();
        tag_suggestion::create($questionid, ['Bleibt'], false);
        $suggestion = $this->suggestion_for($questionid);

        $pending = suggestion_review::pending(suggestion_review::visible_records());
        try {
            tag_suggestion::review('approve', $pending, (int)$suggestion->id + 999);
            $this->fail('Expected moodle_exception for an unknown suggestion.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_review_unavailable', $e->errorcode);
        }
        $this->assertSame('pending', $this->suggestion_for($questionid)->status);
        $this->assertSame([], $this->tags_of($questionid));
    }

    public function test_unknown_action_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectException(\invalid_parameter_exception::class);
        tag_suggestion::review('delete', [], 0);
    }
}
