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
 * Preview endpoint for block_fragenfilter.
 *
 * Starts the question preview using block_fragenfilter's own pool accounts
 * (fragenfilter_pool01 … fragenfilter_pool10). Real users are redirected
 * directly to the preview. Guests get a dedicated pool account with the
 * fragenfilter_preview role, which grants question:viewmine, question:usemine,
 * fragenfilter:view and fragenfilter:export.
 *
 * Unlike block_kursfilter's pool, there is NO per-IP lock: multiple browser
 * tabs from the same visitor may each hold a different pool account and open
 * a different question preview simultaneously.
 *
 * Usage: POST /blocks/fragenfilter/guest_preview.php, body qid=42&sesskey=...
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing,moodle.Commenting.InlineComment.NotCapital,moodle.Commenting.InlineComment.InvalidEndChar -- ADR 0002: public pool-login entry; POST and require_sesskey() protect the login. Semgrep uses a fixed marker syntax.
require_once(__DIR__ . '/../../config.php'); // nosemgrep: moodle-einstiegsdatei-ohne-login

$qid = required_param('qid', PARAM_INT);
if (!data_submitted()) {
    throw new moodle_exception('error_preview_needs_post', 'block_fragenfilter');
}
require_sesskey();

// The question must exist and be visible to the current visitor; otherwise we do
// not consume a pool slot.
if (!\block_fragenfilter\question_visibility::is_visible($qid)) {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/blocks/fragenfilter/guest_preview.php'));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('invalidquestion', 'block_fragenfilter'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    exit;
}

// In Moodle 5+ with mod_qbank, preview.php requires a cmid parameter so that
// $PAGE->set_cm() can resolve the correct course context. Without it Moodle throws
// a coding_error ("course does not correspond to the cm"). We look up the cmid from
// the question's question bank entry.
$previewparams = ['id' => $qid];

// Resolve the Aufgabenpool category so we can restrict the cmid lookup to courses
// that belong to it (or any of its sub-categories). We use the category's stored
// path (e.g. "/1/17") to filter with a LIKE, which covers arbitrary nesting depth
// without a recursive walk and avoids false positives from same-named categories
// in other branches.
$poolcatname = \block_fragenfilter\pool_manager::POOL_ENROL_CATEGORY;
$poolcat     = $DB->get_record('course_categories', ['name' => $poolcatname], 'id,path', IGNORE_MISSING);
$cmid        = null;
if ($poolcat) {
    // Match the category itself and all descendants: path = '/…/17' or starts with '/…/17/'.
    $pathprefix = $DB->sql_like_escape($poolcat->path);
    $cmid = $DB->get_field_sql(
        "SELECT cm.id
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
           JOIN {course} c ON c.id = cm.course
           JOIN {course_categories} cat ON cat.id = c.category
           JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :ctxlvl
           JOIN {question_categories} qc ON qc.contextid = ctx.id
           JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc.id
           JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
          WHERE m.name = 'qbank'
            AND qv.questionid = :qid
            AND (" . $DB->sql_like('cat.path', ':exactpath') . "
              OR " . $DB->sql_like('cat.path', ':subpath') . ")
          LIMIT 1",
        [
            'ctxlvl'    => CONTEXT_MODULE,
            'qid'       => $qid,
            'exactpath' => $pathprefix,
            'subpath'   => $pathprefix . '/%',
        ]
    );
}
if ($cmid) {
    $previewparams['cmid'] = (int)$cmid;
}
$previewurl = new moodle_url('/question/bank/previewquestion/preview.php', $previewparams);
$hasreallogin = isloggedin() && !isguestuser();

// Real users (including already-logged-in pool accounts) go straight to the preview.
if ($hasreallogin) {
    // Ensure the current user has the preview role (idempotent; no-op for real teachers).
    \block_fragenfilter\preview_role::assign_to_pool_account($USER);
    // Pool accounts are not enrolled in courses. Moodle 5+ preview.php calls
    // require_login($course) internally via set_cm(), which blocks unenrolled users
    // and redirects them to the course page. We enrol pool accounts as students
    // in the qbank course so the preview can open — enrolment is revoked by the
    // cleanup_pool_sessions scheduled task when the session expires.
    if ($cmid && \block_fragenfilter\preview_role::is_pool_account($USER)) {
        $courseid = $DB->get_field('course_modules', 'course', ['id' => (int)$cmid]);
        if ($courseid) {
            \block_fragenfilter\pool_manager::ensure_course_enrolment($USER->id, (int)$courseid);
        }
    }
    redirect($previewurl);
}

// Guest visitor: occupy a fragenfilter pool account.
try {
    $account = \block_fragenfilter\pool_manager::occupy_free_account();
} catch (\moodle_exception $e) {
    // Lock timeout — extremely unlikely but handled gracefully.
    $account = null;
}

if ($account === null) {
    // All pool accounts are currently occupied.
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/blocks/fragenfilter/guest_preview.php'));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('preview_pool_full', 'block_fragenfilter'),
        \core\output\notification::NOTIFY_WARNING
    );
    echo $OUTPUT->footer();
    exit;
}

// Pool account is now logged in via complete_user_login() inside occupy_free_account().
// Give it the preview role (idempotent).
\block_fragenfilter\preview_role::assign_to_pool_account($USER);

// Enrol the pool account in the course that holds the mod_qbank activity.
// preview.php calls require_login($course) internally; without enrolment Moodle
// blocks access and redirects the pool account to the course page instead of the preview.
if ($cmid) {
    $courseid = $DB->get_field('course_modules', 'course', ['id' => (int)$cmid]);
    if ($courseid) {
        \block_fragenfilter\pool_manager::ensure_course_enrolment($USER->id, (int)$courseid);
    }
}

// Notify the opener tab via postMessage so refreshCapabilities() activates the export buttons.
// Then navigate this popup window to the actual question preview.
$previewurlstring = $previewurl->out(false);
$wwwrootjson      = json_encode($CFG->wwwroot);
$previewurljson   = json_encode($previewurlstring);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/blocks/fragenfilter/guest_preview.php', ['qid' => $qid]));
$PAGE->set_pagelayout('embedded');
echo $OUTPUT->header();

echo <<<HTML
<script>
(function() {
    // Notify the opener (block page) that pool login has completed.
    if (window.opener && !window.opener.closed) {
        try {
            window.opener.postMessage(
                {type: 'qf_pool_login_done'},
                {$wwwrootjson}
            );
        } catch (e) { /* cross-origin guard */ }
    }
    // Navigate directly to the question preview.
    window.location.replace({$previewurljson});
})();
</script>
HTML;

echo $OUTPUT->footer();
exit;
