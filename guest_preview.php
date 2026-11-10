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
 * Starts the question preview with dedicated fragenfilter_pool accounts (ADR 0002).
 * Existing logins are kept. POST and sesskey protect the public pool-login entry.
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

$preview = \block_fragenfilter\preview::start($qid);
if ($preview === null) {
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
$previewurl = $preview['url'];
if (!$preview['newlogin']) {
    redirect($previewurl);
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
