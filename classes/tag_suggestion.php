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

use block_fragenfilter\event\suggestion_reviewed;

/**
 * Lifecycle of AI tag suggestions: creation, applying tags and review.
 *
 * Statuses: pending (waits for review), applied (tags written), rejected (dropped), empty (no tag found).
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tag_suggestion {
    /**
     * Stores the suggestion of one question and, if requested, applies it at once.
     *
     * @param int      $questionid Question ID (mdl_question.id).
     * @param string[] $tags       Suggested tag values.
     * @param bool     $autoapply  Whether to write non-empty tags immediately.
     * @return string Recorded status ('applied', 'pending', or 'empty').
     */
    public static function create(int $questionid, array $tags, bool $autoapply): string {
        global $DB;

        if (empty($tags)) {
            $status = 'empty';
        } else if ($autoapply) {
            self::apply_tags($questionid, $tags);
            $status = 'applied';
        } else {
            $status = 'pending';
        }

        $now = time();
        $DB->insert_record('block_fragenfilter_tag_pending', (object)[
            'questionid'   => $questionid,
            'tags'         => implode(',', $tags),
            'status'       => $status,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);

        return $status;
    }

    /**
     * Applies one review action to the allowed pending suggestions.
     *
     * Approving writes the tags, rejecting only changes the status. Each review is
     * one transaction and triggers one suggestion_reviewed event.
     *
     * @param string      $action       One of suggestion_review::ACTIONS.
     * @param \stdClass[] $pending      Pending suggestions the user may review, keyed by id.
     * @param int         $suggestionid Suggestion id for single actions.
     * @throws \invalid_parameter_exception For an unknown action.
     * @throws \moodle_exception If a single suggestion is not available to the user.
     */
    public static function review(string $action, array $pending, int $suggestionid): void {
        global $DB;

        if (!in_array($action, suggestion_review::ACTIONS, true)) {
            throw new \invalid_parameter_exception('Unknown review action.');
        }
        $bulk = in_array($action, ['approveall', 'rejectall'], true);
        if (!$bulk && !isset($pending[$suggestionid])) {
            throw new \moodle_exception('error_review_unavailable', 'block_fragenfilter');
        }
        $selected = $bulk ? $pending : [$pending[$suggestionid]];
        $transaction = $DB->start_delegated_transaction();
        foreach ($selected as $row) {
            if (in_array($action, ['approve', 'approveall'], true)) {
                $tags = array_filter(array_map('trim', explode(',', $row->tags)));
                self::apply_tags((int)$row->questionid, $tags);
                $status = 'applied';
            } else {
                $status = 'rejected';
            }
            $DB->update_record('block_fragenfilter_tag_pending', (object)[
                'id' => $row->id, 'status' => $status, 'timemodified' => time(),
            ]);
            suggestion_reviewed::create([
                'objectid' => $row->id,
                'context' => \context::instance_by_id($row->contextid),
                'other' => ['status' => $status],
            ])->trigger();
        }
        $transaction->allow_commit();
    }

    /**
     * Writes tags into Moodle's question tag system. Existing tags are preserved.
     *
     * The caller authorises either manual review or the administrator-enabled scheduled task.
     *
     * @param int      $questionid Question ID (mdl_question.id).
     * @param string[] $tags       Tag values to write.
     */
    public static function apply_tags(int $questionid, array $tags): void {
        global $DB;

        $question = $DB->get_record_sql(
            "SELECT q.id, qc.contextid
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE q.id = :qid",
            ['qid' => $questionid],
            MUST_EXIST
        );
        $context = \context::instance_by_id($question->contextid);
        $existing = \core_tag_tag::get_item_tags_array('core_question', 'question', $questionid);
        \core_tag_tag::set_item_tags('core_question', 'question', $questionid, $context, array_merge($existing, $tags));
    }
}
