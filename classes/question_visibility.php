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
 * Resolves question visibility before queries apply result limits.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_visibility {
    /**
     * Return a SQL fragment and parameter array that restrict a question query to
     * mod_qbank activities in courses belonging to the Aufgabenpool category tree.
     *
     * The fragment must be added to the WHERE clause of a query that already joins
     * {question_categories} as "qc". It adds the necessary extra JOINs via a
     * sub-select so it can be safely appended with AND.
     *
     * Uses the category's stored path column (e.g. "/1/17") to match the category
     * itself and all its descendants with a single LIKE, which handles arbitrary
     * nesting depth and avoids false positives from identically-named sibling
     * categories.
     *
     * @return array{string, array<string,mixed>} [sql_fragment, params]
     */
    public static function pool_category_sql(): array {
        global $DB;

        $poolcatname = \block_fragenfilter\pool_manager::POOL_ENROL_CATEGORY;
        $poolcat     = $DB->get_record('course_categories', ['name' => $poolcatname], 'id,path', IGNORE_MISSING);

        if (!$poolcat) {
            // No Aufgabenpool category found — return a fragment that matches nothing
            // so the filter is safely restrictive rather than leaking all questions.
            return ['1 = 0', []];
        }

        $pathprefix = $DB->sql_like_escape($poolcat->path);

        $sql = "EXISTS (
            SELECT 1
              FROM {course_modules} cm2
              JOIN {modules} m2 ON m2.id = cm2.module AND m2.name = 'qbank'
              JOIN {context} ctx2 ON ctx2.instanceid = cm2.id AND ctx2.contextlevel = :poolctxlvl
              JOIN {course} c2 ON c2.id = cm2.course
              JOIN {course_categories} cat2 ON cat2.id = c2.category
             WHERE ctx2.id = qc.contextid
               AND (" . $DB->sql_like('cat2.path', ':poolexact') . "
                OR " . $DB->sql_like('cat2.path', ':poolsub') . ")
        )";

        $params = [
            'poolctxlvl' => CONTEXT_MODULE,
            'poolexact'  => $pathprefix,
            'poolsub'    => $pathprefix . '/%',
        ];

        return [$sql, $params];
    }


    /**
     * The one query every list of questions starts from: ready, top-level questions the current user may see.
     *
     * Aliases: q (question), qv (version), qbe (bank entry), qc (category), ctx (category context).
     * Callers append further conditions with AND, and their own SELECT, GROUP BY and ORDER BY.
     *
     * @param string $scope Search scope: all, course or system.
     * @param int $contextid Current context ID.
     * @return array [FROM ... WHERE fragment, named parameters]
     * @throws \invalid_parameter_exception For an unknown scope.
     */
    public static function visible_query(string $scope, int $contextid): array {
        global $DB, $USER;

        if (!in_array($scope, ['all', 'course', 'system'], true)) {
            throw new \invalid_parameter_exception('Unknown search scope.');
        }
        $user = isloggedin() ? $USER : guest_user();
        $all = [];
        $mine = [];
        $contextids = self::contextids($scope, $contextid);
        foreach ($contextids as $id) {
            $context = \context::instance_by_id($id);
            if (has_capability('moodle/question:viewall', $context, $user)) {
                $all[] = $id;
            } else if (has_capability('moodle/question:viewmine', $context, $user)) {
                $mine[] = $id;
            }
        }
        $clauses = [];
        $args = [];
        if ($all) {
            [$insql, $params] = $DB->get_in_or_equal($all, SQL_PARAMS_NAMED, 'viewall');
            $clauses[] = "qc.contextid $insql";
            $args += $params;
        }
        if ($mine) {
            [$insql, $params] = $DB->get_in_or_equal($mine, SQL_PARAMS_NAMED, 'viewmine');
            $clauses[] = "(qc.contextid $insql AND q.createdby = :viewer)";
            $args += $params;
            $args['viewer'] = $user->id;
        }
        if (isguestuser($user) || !isloggedin() || preview_role::is_pool_account($user)) {
            $clauses = ['1 = 0'];
            $args = [];
            if ($contextids) {
                [$insql, $args] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'publicctx');
                $clauses = ["qc.contextid $insql"];
            }
        }
        [$poolsql, $poolargs] = self::pool_category_sql();
        $args += $poolargs;
        $visibility = $clauses ? '(' . implode(' OR ', $clauses) . ')' : '1 = 0';
        $from = "FROM {question} q
                   JOIN {question_versions} qv ON qv.questionid = q.id
                   JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                   JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                   JOIN {context} ctx ON ctx.id = qc.contextid
                  WHERE q.parent = 0 AND qv.status = 'ready' AND $visibility AND $poolsql";
        return [$from, $args];
    }

    /**
     * Whether the current user may see a ready question, as the search would list it.
     *
     * @param int $questionid Question ID.
     * @return bool True if the question exists in a category the current user may view.
     */
    public static function is_visible(int $questionid): bool {
        return self::visible_ids([$questionid]) === [$questionid];
    }

    /**
     * Filters question IDs to the ready questions the current user may see, as the search would list them.
     *
     * Unknown IDs and IDs the user may not see are dropped alike, so the result tells nothing about them.
     *
     * @param int[] $questionids Question IDs.
     * @return int[] The visible IDs in the given order.
     */
    public static function visible_ids(array $questionids): array {
        global $DB;

        if (!$questionids) {
            return [];
        }
        [$from, $args] = self::visible_query('all', \context_system::instance()->id);
        [$insql, $idargs] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'questionid');
        $visible = $DB->get_fieldset_sql(
            "SELECT q.id $from AND q.id $insql",
            $args + $idargs
        );
        return array_values(array_intersect($questionids, array_map('intval', $visible)));
    }

    /**
     * Resolves all searchable context IDs for the given scope.
     *
     * Moodle 4.x:  Questions belong to course and system contexts.
     * Moodle 5.0+: Question banks are standalone mod_qbank activities.
     *                           Each question bank has its own CONTEXT_MODULE context.
     *                           Each course also has an automatically migrated
     *                           "shared question bank" as another mod_qbank instance.
     *
     * Scopes:
     * - 'system' : system context only
     * - 'course' : current course context (+ its mod_qbank contexts in Moodle 5)
     * - 'all'    : system + all courses + all mod_qbank contexts (Moodle 5)
     *
     * @param string $scope Search scope: all, course or system.
     * @param int $contextid Current context ID.
     * @return array Context IDs.
     */
    private static function contextids(string $scope, int $contextid): array {
        global $DB, $CFG;

        $sysctx = \context_system::instance();
        $ismoodle5 = (int)$CFG->version >= 2024100700; // Corresponds to Moodle 5.0.

        if ($scope === 'system') {
            return [$sysctx->id];
        }

        if ($scope === 'course') {
            $ctx = $contextid > 0
                ? (\context::instance_by_id($contextid, IGNORE_MISSING) ?? $sysctx)
                : $sysctx;
            $coursectx = $ctx->get_course_context(false);
            $ids = $coursectx ? [$coursectx->id] : [];

            // Moodle 5+: include the course's mod_qbank contexts.
            if ($ismoodle5 && $coursectx) {
                $qbankctxids = self::get_qbank_module_contextids($coursectx->instanceid);
                $ids = array_unique(array_merge($ids, $qbankctxids));
            }
            return $ids;
        }

        // The all scope includes the system context and all course contexts.
        $coursectxids = $DB->get_fieldset_sql(
            "SELECT id FROM {context} WHERE contextlevel = :lvl",
            ['lvl' => CONTEXT_COURSE]
        );
        $ids = array_merge([$sysctx->id], $coursectxids);

        // Moodle 5+: include all mod_qbank module contexts site-wide.
        if ($ismoodle5) {
            $qbankctxids = self::get_qbank_module_contextids(null);
            $ids = array_unique(array_merge($ids, $qbankctxids));
        }

        return $ids;
    }

    /**
     * Returns all CONTEXT_MODULE contexts belonging to mod_qbank instances.
     * $courseid = null  → site-wide
     * $courseid = int   → this course only
     *
     * Moodle 5.0+ stores question banks as mod_qbank activities.
     * Question categories belong to the module context (contextlevel = 70).
     *
     * @param int|null $courseid Course ID or null for all courses.
     * @return array Context IDs.
     */
    private static function get_qbank_module_contextids(?int $courseid): array {
        global $DB;

        // Mod_qbank only exists in Moodle 5+; check whether the module is available.
        if (!$DB->get_record('modules', ['name' => 'qbank'], 'id', IGNORE_MISSING)) {
            return [];
        }

        $sql = "SELECT ctx.id
                   FROM {context} ctx
                   JOIN {course_modules} cm ON cm.id = ctx.instanceid
                   JOIN {modules} m ON m.id = cm.module
                  WHERE ctx.contextlevel = :ctxlvl
                    AND m.name = 'qbank'";
        $args = ['ctxlvl' => CONTEXT_MODULE];

        if ($courseid !== null) {
            $sql  .= " AND cm.course = :courseid";
            $args['courseid'] = $courseid;
        }

        return $DB->get_fieldset_sql($sql, $args) ?: [];
    }
}
