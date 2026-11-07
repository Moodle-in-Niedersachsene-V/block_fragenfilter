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
 * Review of AI tag suggestions: visibility, authorisation and state changes.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_fragenfilter;

use block_fragenfilter\event\suggestion_reviewed;
use block_fragenfilter\task\generate_tags;

/**
 * Decides which suggestions a reviewer may see and applies review actions.
 */
class suggestion_review {
    /** @var string[] Actions that change suggestions. */
    public const ACTIONS = ['approve', 'reject', 'approveall', 'rejectall'];

    /**
     * Returns all suggestions whose question the current user may view and tag.
     *
     * A system review role does not grant access to every question bank.
     *
     * @return \stdClass[] Suggestion records keyed by suggestion id.
     */
    public static function visible_records(): array {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT p.*, q.name AS questionname, q.qtype, q.createdby, qc.contextid
               FROM {block_fragenfilter_tag_pending} p
               JOIN {question} q ON q.id = p.questionid
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
           ORDER BY p.timecreated ASC, p.id ASC"
        );
        return array_filter($records, static function ($row) {
            return question_has_capability_on($row, 'view') && question_has_capability_on($row, 'tag');
        });
    }

    /**
     * Filters suggestions that still wait for review.
     *
     * @param \stdClass[] $records Visible suggestion records.
     * @return \stdClass[] Pending suggestions keyed by id.
     */
    public static function pending(array $records): array {
        return array_filter($records, static fn($row) => $row->status === 'pending');
    }

    /**
     * Applies one review action to the allowed pending suggestions.
     *
     * @param string      $action    One of self::ACTIONS.
     * @param \stdClass[] $pending   Pending suggestions the user may review.
     * @param int         $suggestionid Suggestion id for single actions.
     * @throws \invalid_parameter_exception For an unknown action.
     * @throws \moodle_exception If a single suggestion is not available to the user.
     */
    public static function review(string $action, array $pending, int $suggestionid): void {
        global $DB;

        if (!in_array($action, self::ACTIONS, true)) {
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
                generate_tags::apply_tags((int)$row->questionid, $tags);
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
}
