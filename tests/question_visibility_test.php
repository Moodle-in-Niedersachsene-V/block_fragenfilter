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
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Question visibility: the single rule behind search, categories, export, ratings and preview.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(question_visibility::class)]
final class question_visibility_test extends \advanced_testcase {
    /**
     * Creates a ready top-level question in a category of the given context.
     *
     * @param \context $context Context of the category.
     * @param int $ownerid Creator of the question.
     * @param string $name Question name.
     * @return \stdClass Question record.
     */
    private function make_question(\context $context, int $ownerid, string $name): \stdClass {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => $context->id]);
        $DB->set_field('question_categories', 'contextid', $context->id, ['id' => $category->id]);
        return $generator->create_question('truefalse', null, [
            'category' => $category->id,
            'createdby' => $ownerid,
            'name' => $name,
        ]);
    }

    /**
     * Grants a capability to a new role and assigns it to the user at the context.
     *
     * @param string $capability Capability name.
     * @param \context $context Context of the assignment.
     * @param \stdClass $user User to receive the role.
     */
    private function grant(string $capability, \context $context, \stdClass $user): void {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
    }

    /**
     * Question IDs visible to the current user for a scope, through the public query.
     *
     * @param string $scope Search scope.
     * @param \context $context Current context.
     * @return int[] Sorted question IDs.
     */
    private function visible_in_scope(string $scope, \context $context): array {
        global $DB;
        [$from, $args] = question_visibility::visible_query($scope, $context->id);
        $ids = array_map('intval', $DB->get_fieldset_sql("SELECT DISTINCT q.id $from", $args));
        sort($ids);
        return $ids;
    }

    public function test_module_bank_viewmine_sees_only_own_ready_questions(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $pool = $this->getDataGenerator()->create_category(['name' => 'Aufgabenpool']);
        $course = $this->getDataGenerator()->create_course(['category' => $pool->id]);
        $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $bankctx = \context_module::instance($bank->cmid);
        $mine = $this->make_question($bankctx, $owner->id, 'Own');
        $foreign = $this->make_question($bankctx, $other->id, 'Foreign');
        $this->grant('moodle/question:viewmine', $bankctx, $owner);
        $this->setUser($owner);

        $this->assertSame([(int)$mine->id], $this->visible_in_scope('all', $bankctx));
        $this->assertTrue(question_visibility::is_visible((int)$mine->id));
        $this->assertFalse(question_visibility::is_visible((int)$foreign->id));
        $this->assertSame([(int)$mine->id], question_visibility::visible_ids([(int)$foreign->id, (int)$mine->id]));
    }

    public function test_module_bank_viewall_sees_every_ready_question(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $viewer = $this->getDataGenerator()->create_user();
        $pool = $this->getDataGenerator()->create_category(['name' => 'Aufgabenpool']);
        $course = $this->getDataGenerator()->create_course(['category' => $pool->id]);
        $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $bankctx = \context_module::instance($bank->cmid);
        $first = $this->make_question($bankctx, $owner->id, 'First');
        $second = $this->make_question($bankctx, $owner->id, 'Second');
        $this->grant('moodle/question:viewall', $bankctx, $viewer);
        $this->setUser($viewer);

        $this->assertEqualsCanonicalizing(
            [(int)$first->id, (int)$second->id],
            $this->visible_in_scope('all', $bankctx)
        );
    }

    public function test_legacy_course_questions_are_not_published(): void {
        $this->resetAfterTest();
        $viewer = $this->getDataGenerator()->create_user();
        $pool = $this->getDataGenerator()->create_category(['name' => 'Aufgabenpool']);
        $course = $this->getDataGenerator()->create_course(['category' => $pool->id]);
        $othercourse = $this->getDataGenerator()->create_course();
        $coursectx = \context_course::instance($course->id);
        $otherctx = \context_course::instance($othercourse->id);
        $inside = $this->make_question($coursectx, $viewer->id, 'Inside');
        $outside = $this->make_question($otherctx, $viewer->id, 'Outside');
        $this->grant('moodle/question:viewall', $coursectx, $viewer);
        $this->grant('moodle/question:viewall', $otherctx, $viewer);
        $this->setUser($viewer);

        $this->assertSame([], $this->visible_in_scope('course', $coursectx));
        $this->assertEqualsCanonicalizing(
            [],
            $this->visible_in_scope('all', $coursectx)
        );
    }

    public function test_legacy_system_questions_are_not_published(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $system = \context_system::instance();
        $coursectx = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $insystem = $this->make_question($system, 2, 'System');
        $incourse = $this->make_question($coursectx, 2, 'Course');

        $this->assertSame([], $this->visible_in_scope('system', $system));
        $this->assertNotContains((int)$incourse->id, $this->visible_in_scope('all', $system));
    }

    public function test_questions_that_are_not_ready_or_are_subquestions_are_invisible(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->get_plugin_generator('block_fragenfilter')->create_public_category();
        $system = \context::instance_by_id($category->contextid);
        $ready = $this->make_question($system, 2, 'Ready');
        $draft = $this->make_question($system, 2, 'Draft');
        $sub = $this->make_question($system, 2, 'Subquestion');
        $DB->set_field('question_versions', 'status', 'draft', ['questionid' => $draft->id]);
        $DB->set_field('question', 'parent', (int)$ready->id, ['id' => $sub->id]);

        $this->assertTrue(question_visibility::is_visible((int)$ready->id));
        $this->assertFalse(question_visibility::is_visible((int)$draft->id));
        $this->assertFalse(question_visibility::is_visible((int)$sub->id));
        $this->assertSame(
            [(int)$ready->id],
            question_visibility::visible_ids([(int)$draft->id, (int)$sub->id, (int)$ready->id])
        );
        $this->assertSame([(int)$ready->id], $this->visible_in_scope('all', $system));
    }

    public function test_unknown_ids_and_empty_input_are_not_visible(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertFalse(question_visibility::is_visible(999999));
        $this->assertSame([], question_visibility::visible_ids([]));
        $this->assertSame([], question_visibility::visible_ids([999999]));
    }

    public function test_public_visitors_only_see_questions_in_the_tasks_category_tree(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category(['name' => 'Aufgabenpool']);
        $child = $this->getDataGenerator()->create_category(['parent' => $category->id]);
        $course = $this->getDataGenerator()->create_course(['category' => $child->id]);
        $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $inside = $this->make_question(\context_module::instance($bank->cmid), 2, 'Published');
        $outside = $this->make_question(\context_system::instance(), 2, 'Private');
        $othercourse = $this->getDataGenerator()->create_course(['category' => $child->id]);
        $otherbank = $this->getDataGenerator()->create_module('qbank', ['course' => $othercourse->id]);
        $other = $this->make_question(\context_module::instance($otherbank->cmid), 2, 'Other course');
        $this->setGuestUser();

        $this->assertEqualsCanonicalizing(
            [(int)$inside->id, (int)$other->id],
            $this->visible_in_scope('all', \context_system::instance())
        );
        $this->assertSame([], $this->visible_in_scope('system', \context_system::instance()));
        $this->assertSame([(int)$inside->id], $this->visible_in_scope('course', \context_course::instance($course->id)));
        $this->assertTrue(question_visibility::is_visible((int)$inside->id));
        $this->assertFalse(question_visibility::is_visible((int)$outside->id));
    }

    public function test_unknown_scope_is_rejected_once_by_the_query(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        question_visibility::visible_query('everything', \context_system::instance()->id);
    }
}
