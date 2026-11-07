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

/**
 * Starts a question preview with the dedicated Fragenfilter pool.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview {
    /**
     * Keeps an existing login or occupies a pool account and prepares its question-bank access.
     *
     * @param int $questionid Published question ID.
     * @return array|null Preview URL and whether login changed; null when the pool is full.
     */
    public static function start(int $questionid): ?array {
        global $DB, $USER;

        if (!question_visibility::is_visible($questionid)) {
            throw new \moodle_exception('invalidquestion', 'block_fragenfilter');
        }
        $cmid = (int)$DB->get_field_sql(
            "SELECT ctx.instanceid
               FROM {question_versions} qv
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
               JOIN {context} ctx ON ctx.id = qc.contextid
              WHERE qv.questionid = :qid AND ctx.contextlevel = :level",
            ['qid' => $questionid, 'level' => CONTEXT_MODULE]
        );
        $newlogin = !visitor_access::has_real_login();
        if ($newlogin && !pool_manager::occupy_free_account()) {
            return null;
        }
        if (preview_role::is_pool_account($USER)) {
            preview_role::assign_to_pool_account($USER);
            $courseid = (int)$DB->get_field('course_modules', 'course', ['id' => $cmid], MUST_EXIST);
            pool_manager::ensure_course_enrolment((int)$USER->id, $courseid);
            assign_capability(
                'moodle/question:useall',
                CAP_ALLOW,
                preview_role::ensure(),
                \context_module::instance($cmid)->id,
                true
            );
        }
        return [
            'url' => new \moodle_url('/question/bank/previewquestion/preview.php', ['id' => $questionid, 'cmid' => $cmid]),
            'newlogin' => $newlogin,
        ];
    }
}
