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
 * Review of AI tag suggestions: which suggestions a user may see and review.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_fragenfilter;

/**
 * Decides which suggestions a reviewer may see; state changes live in tag_suggestion.
 */
class suggestion_review {
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
}
