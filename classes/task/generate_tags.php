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
 * Scheduled task: AI-based tag generation for questions.
 *
 * Uses block_fragenfilter\ai_connector (direct Ollama/Claude call),
 * NOT Moodle's core_ai subsystem.
 *
 * Each question is processed once; the outcome is stored in
 * block_fragenfilter_tag_pending with status:
 *   'applied'  — tags were written directly to Moodle's tag system
 *   'pending'  — suggestion stored, waiting for manual review
 *   'empty'    — no suitable tag found
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @author     Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_fragenfilter\task;

use block_fragenfilter\ai_connector;

/**
 * Processes a batch of questions without AI-generated tags and
 * stores suggestions in the pending-review table.
 */
class generate_tags extends \core\task\scheduled_task {
    /** Questions per run when the setting is missing. */
    const DEFAULT_BATCH_SIZE = 20;

    /**
     * Returns the task name shown in the admin interface.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_generate_tags', 'block_fragenfilter');
    }

    /**
     * Processes the next batch of questions.
     *
     * A failed question does not stop the others, but causes the task to
     * report a failure at the end.
     *
     * @throws \moodle_exception If AI tagging is disabled or any question could not be processed.
     */
    public function execute(): void {
        if (!get_config('block_fragenfilter', 'ai_enabled')) {
            mtrace('block_fragenfilter: KI-Verschlagwortung ist in den Einstellungen deaktiviert.');
            return;
        }

        $autoapply = (bool)get_config('block_fragenfilter', 'ai_autoapply');
        $batchsize = max(1, (int)(get_config('block_fragenfilter', 'ai_batch_size') ?: self::DEFAULT_BATCH_SIZE));
        $connector = new ai_connector();

        $questions = self::next_questions($batchsize);

        if (empty($questions)) {
            mtrace('block_fragenfilter: Keine unverarbeiteten Fragen gefunden.');
            return;
        }

        $failed = 0;
        foreach ($questions as $question) {
            try {
                $tags   = $connector->suggest_tags($question->name, $question->questiontext, $question->qtype);
                $status = self::record_outcome($question, $tags, $autoapply);
                mtrace("block_fragenfilter: Frage {$question->id} ({$question->name}): "
                    . $status . ' (' . implode(', ', $tags) . ')');
            } catch (\Throwable $e) {
                mtrace("block_fragenfilter: Frage {$question->id} fehlgeschlagen: " . $e->getMessage());
                $failed++;
            }
        }

        if ($failed > 0) {
            throw new \moodle_exception(
                'error_ai_tagging_failed',
                'block_fragenfilter',
                '',
                (object)['failed' => $failed, 'total' => count($questions)]
            );
        }
    }

    /**
     * Returns questions that have not yet been processed by AI tagging.
     *
     * Only ready, non-template questions are included. Questions with an
     * existing entry in block_fragenfilter_tag_pending are skipped.
     *
     * @param int $limit Maximum number of questions.
     * @return \stdClass[] Question records.
     */
    private static function next_questions(int $limit): array {
        global $DB;

        // Resolve the Aufgabenpool category path so we can restrict tagging to
        // questions that live in courses under that category subtree. This mirrors
        // the restriction in external.php (search_questions / get_categories) and
        // guest_preview.php: questions from other categories (e.g. "Kurse") must
        // never appear here.
        $poolcatname = \block_fragenfilter\pool_manager::POOL_ENROL_CATEGORY;
        $poolcat     = $DB->get_record('course_categories', ['name' => $poolcatname], 'id,path', IGNORE_MISSING);

        if (!$poolcat) {
            // Aufgabenpool category not found — abort safely rather than tagging everything.
            mtrace('block_fragenfilter generate_tags: Aufgabenpool-Kategorie nicht gefunden – Abbruch.');
            return [];
        }

        $pathprefix = $DB->sql_like_escape($poolcat->path);
        $exactlike  = $DB->sql_like('cat.path', ':exactpath');
        $sublike    = $DB->sql_like('cat.path', ':subpath');

        // Only select questions whose question_bank_entry lives in a qbank activity
        // that belongs to a course under the Aufgabenpool category (or the category
        // itself). The JOIN chain: question → question_versions → question_bank_entries
        // → question_categories → context (contextlevel=70 = CONTEXT_MODULE) →
        // course_modules (module name = 'qbank') → course → course_categories.
        $sql = "SELECT q.id, q.name, q.qtype, q.questiontext
                  FROM {question} q
                  JOIN {question_versions}     qv  ON qv.questionid        = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id               = qv.questionbankentryid
                  JOIN {question_categories}   qc  ON qc.id                = qbe.questioncategoryid
                  JOIN {context}               ctx ON ctx.id               = qc.contextid
                                                  AND ctx.contextlevel     = :ctxlvl
                  JOIN {course_modules}        cm  ON cm.id                = ctx.instanceid
                  JOIN {modules}               m   ON m.id                 = cm.module
                                                  AND m.name               = 'qbank'
                  JOIN {course}                c   ON c.id                 = cm.course
                  JOIN {course_categories}     cat ON cat.id               = c.category
                 WHERE q.parent = 0
                   AND ($exactlike OR $sublike)
                   AND NOT EXISTS (
                       SELECT 1 FROM {block_fragenfilter_tag_pending} tp
                        WHERE tp.questionid = q.id
                   )
              ORDER BY q.id ASC";

        $params = [
            'ctxlvl'    => CONTEXT_MODULE,
            'exactpath' => $pathprefix,
            'subpath'   => $pathprefix . '/%',
        ];

        return $DB->get_records_sql($sql, $params, 0, $limit);
    }

    /**
     * Stores the tagging result and optionally writes tags to Moodle's tag system.
     *
     * @param \stdClass $question   Question record (must have id, name, qtype).
     * @param string[]  $tags       Suggested tag values.
     * @param bool      $autoapply  Whether to write tags immediately.
     * @return string               Recorded status ('applied', 'pending', or 'empty').
     */
    private static function record_outcome(\stdClass $question, array $tags, bool $autoapply): string {
        global $DB;

        if (empty($tags)) {
            $status = 'empty';
        } else if ($autoapply) {
            self::apply_tags((int)$question->id, $tags);
            $status = 'applied';
        } else {
            $status = 'pending';
        }

        $now = time();
        $DB->insert_record('block_fragenfilter_tag_pending', (object)[
            'questionid'   => (int)$question->id,
            'tags'         => implode(',', $tags),
            'status'       => $status,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);

        return $status;
    }

    /**
     * Writes approved tags directly into Moodle's question tag system.
     *
     * Existing tags are preserved. The caller authorises either manual review
     * or the administrator-enabled scheduled task before calling this method.
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
