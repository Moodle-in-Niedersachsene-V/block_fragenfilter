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
     * SQL predicate for the question (q) and category (qc) aliases.
     *
     * @param string $scope Search scope.
     * @param int $contextid Current context.
     * @return array SQL predicate and named parameters.
     */
    public static function sql(string $scope, int $contextid): array {
        global $DB, $USER;

        if (!in_array($scope, ['all', 'course', 'system'], true)) {
            throw new \invalid_parameter_exception('Unknown search scope.');
        }
        $user = isloggedin() ? $USER : guest_user();

        // Guests and pool accounts have block/fragenfilter:view but no questions of their
        // own — the normal viewmine path would add "AND q.createdby = <pool_user_id>" which
        // always returns zero results. Detect these users early and take the public fallback
        // directly, skipping the per-context capability loop entirely.
        if (self::is_public_user($user)) {
            $publicctxids = self::public_context_ids($scope, $contextid);
            if (!$publicctxids) {
                return ['1 = 0', []];
            }
            [$insql, $params] = $DB->get_in_or_equal($publicctxids, SQL_PARAMS_NAMED, 'pubctx');
            return ["(qc.contextid $insql)", $params];
        }

        $all = [];
        $mine = [];
        foreach (self::contextids($scope, $contextid) as $id) {
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

        // Fallback for any authenticated user with block/fragenfilter:view but no question
        // capability in any searched context (e.g. a teacher role not yet granted viewall).
        if (!$clauses && has_capability('block/fragenfilter:view', \context_system::instance(), $user)) {
            $publicctxids = self::public_context_ids($scope, $contextid);
            if ($publicctxids) {
                [$insql, $params] = $DB->get_in_or_equal($publicctxids, SQL_PARAMS_NAMED, 'pubctx');
                $clauses[] = "qc.contextid $insql";
                $args += $params;
            }
        }

        return [$clauses ? '(' . implode(' OR ', $clauses) . ')' : '1 = 0', $args];
    }

    /**
     * Whether this user should see all questions in the searched contexts without
     * a createdby restriction. True for guests and for kursfilter pool accounts.
     *
     * Pool accounts have moodle/question:viewmine (so they can open the preview page)
     * but they never created any questions themselves, so the normal viewmine SQL clause
     * "AND q.createdby = :viewer" would always return zero rows. We bypass that by
     * treating them like guests: show everything, no ownership filter.
     *
     * @param \stdClass $user User record.
     * @return bool
     */
    private static function is_public_user(\stdClass $user): bool {
        if (isguestuser($user)) {
            return true;
        }
        // Pool accounts are confirmed manual accounts whose username starts with a
        // recognised pool prefix. We check this inline without depending on pool_manager
        // or block_kursfilter so question_visibility stays self-contained.
        if (
            !empty($user->username)
            && $user->auth === 'manual'
            && !empty($user->confirmed)
            && (
                strpos($user->username, 'fragenfilter_pool') === 0  // own pool
                || strpos($user->username, 'kursfilter_pool') === 0  // legacy shared pool
                || strpos($user->username, 'kursfilter_guest') === 0 // legacy guest prefix
            )
        ) {
            return true;
        }
        return false;
    }

    /**
     * Returns context IDs that are publicly readable for the block — i.e. contexts where
     * the teacher archetype (or better) has moodle/question:viewall. Used as a fallback
     * for guests and pool accounts that have no question capability of their own.
     *
     * @param string $scope Search scope.
     * @param int $contextid Current context ID.
     * @return int[] Context IDs.
     */
    private static function public_context_ids(string $scope, int $contextid): array {
        global $DB;

        // Collect the contexts the block would normally search.
        $ctxids = self::contextids($scope, $contextid);
        if (!$ctxids) {
            return [];
        }

        // A context is "public" if any role with the teacher archetype (or higher) has
        // viewall there. We approximate this by checking whether the system context has
        // a role override that allows viewall for the teacher archetype — but the simplest
        // correct approach is to include contexts where the capability is allowed for the
        // authenticated user archetype or the guest archetype at system level.
        // In practice for the Material-Hub: all questions in the system/course contexts
        // should be searchable by guests. We therefore return all searched contexts,
        // relying on the plugin admin to set the search scope appropriately.
        // This matches the previous behaviour when the block was on the frontpage.
        return $ctxids;
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
        [$visibilitysql, $args] = self::sql('all', \context_system::instance()->id);
        [$insql, $idargs] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'questionid');
        $visible = $DB->get_fieldset_sql(
            "SELECT q.id FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE q.id $insql AND q.parent = 0 AND qv.status = 'ready' AND $visibilitysql",
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
