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
 * Review and approve AI tag suggestions.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('block/fragenfilter:reviewtags', context_system::instance());

require_once($CFG->libdir . '/questionlib.php');

$action = optional_param('action', '', PARAM_ALPHA);
$suggestionid = optional_param('id', 0, PARAM_INT);
$perpage = max(1, min(100, optional_param('perpage', 50, PARAM_INT)));
$page = max(0, optional_param('page', 0, PARAM_INT));

$PAGE->set_url(new moodle_url('/blocks/fragenfilter/review.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('review_title', 'block_fragenfilter'));
$PAGE->set_heading(get_string('review_title', 'block_fragenfilter'));

if ($action) {
    if (!data_submitted()) {
        throw new moodle_exception('error_review_needs_post', 'block_fragenfilter');
    }
}

$records = \block_fragenfilter\suggestion_review::visible_records();
$pending = \block_fragenfilter\suggestion_review::pending($records);

if ($action) {
    require_sesskey();
    \block_fragenfilter\suggestion_review::review($action, $pending, $suggestionid);
    redirect($PAGE->url);
}

$totalcount = count($pending);
$appliedcount = count(array_filter($records, static fn($row) => $row->status === 'applied'));
$rejectedcount = count(array_filter($records, static fn($row) => $row->status === 'rejected'));
$pending = array_slice($pending, $page * $perpage, $perpage, true);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('review_title', 'block_fragenfilter'));

// Statistics bar.
echo html_writer::div(
    html_writer::tag(
        'span',
        get_string('stat_pending', 'block_fragenfilter', $totalcount),
        ['class' => 'badge badge-warning mr-2', 'style' => 'font-size:14px;padding:6px 12px']
    )
    . html_writer::tag(
        'span',
        get_string('stat_approved', 'block_fragenfilter', $appliedcount),
        ['class' => 'badge badge-success mr-2', 'style' => 'font-size:14px;padding:6px 12px']
    )
    . html_writer::tag(
        'span',
        get_string('stat_rejected', 'block_fragenfilter', $rejectedcount),
        ['class' => 'badge badge-secondary', 'style' => 'font-size:14px;padding:6px 12px']
    ),
    'mb-3'
);

if (empty($pending) && $totalcount === 0) {
    echo $OUTPUT->notification(
        get_string('review_empty', 'block_fragenfilter'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->footer();
    exit;
}

// Bulk actions at the top.
if ($totalcount > 0) {
    $approveallurl = new moodle_url('/blocks/fragenfilter/review.php', [
        'action'  => 'approveall',
        'sesskey' => sesskey(),
    ]);
    $rejectallurl = new moodle_url('/blocks/fragenfilter/review.php', [
        'action'  => 'rejectall',
        'sesskey' => sesskey(),
    ]);

    $approvebutton = new single_button(
        $approveallurl,
        get_string('action_approveall', 'block_fragenfilter', $totalcount),
        'post'
    );
    $approvebutton->add_action(new confirm_action(get_string('confirm_approveall', 'block_fragenfilter')));
    $rejectbutton = new single_button(
        $rejectallurl,
        get_string('action_rejectall', 'block_fragenfilter'),
        'post'
    );
    $rejectbutton->add_action(new confirm_action(get_string('confirm_rejectall', 'block_fragenfilter')));
    echo html_writer::div($OUTPUT->render($approvebutton) . $OUTPUT->render($rejectbutton), 'mb-3');
}

if (empty($pending)) {
    echo $OUTPUT->footer();
    exit;
}

// Table.
$table = new \html_table();
$table->head = [
    get_string('col_question', 'block_fragenfilter'),
    get_string('col_type', 'block_fragenfilter'),
    get_string('col_tags', 'block_fragenfilter'),
    get_string('col_actions', 'block_fragenfilter'),
];
$table->attributes['class'] = 'generaltable';

foreach ($pending as $row) {
    $tags = array_filter(array_map('trim', explode(',', $row->tags)));
    $tagpills = implode(' ', array_map(function ($t) {
        return html_writer::tag('span', s($t), [
            'class' => 'badge badge-info',
            'style' => 'margin:2px',
        ]);
    }, $tags));

    $approveurl = new moodle_url('/blocks/fragenfilter/review.php', [
        'action'  => 'approve',
        'id'      => $row->id,
        'sesskey' => sesskey(),
    ]);
    $rejecturl = new moodle_url('/blocks/fragenfilter/review.php', [
        'action'  => 'reject',
        'id'      => $row->id,
        'sesskey' => sesskey(),
    ]);

    $actions = $OUTPUT->single_button($approveurl, get_string('action_approve', 'block_fragenfilter'), 'post');
    $actions .= $OUTPUT->single_button($rejecturl, get_string('action_reject', 'block_fragenfilter'), 'post');

    $table->data[] = [
        s($row->questionname),
        s($row->qtype),
        $tagpills ?: html_writer::tag('em', get_string('no_tags', 'block_fragenfilter')),
        $actions,
    ];
}

echo html_writer::table($table);

echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $PAGE->url);

echo $OUTPUT->footer();
