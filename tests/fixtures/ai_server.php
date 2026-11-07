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
 * Unmanaged HTTP peer for connector tests; unavailable in a normal web process.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState -- Independent HTTP fixture, guarded by server-only environment.
if (!getenv('QUESTIONFILTER_AI_FIXTURE')) {
    http_response_code(404);
    exit;
}
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/followed') {
    file_put_contents(getenv('QUESTIONFILTER_AI_RECEIPT') . '.followed', 'followed');
    exit;
}
file_put_contents(getenv('QUESTIONFILTER_AI_RECEIPT'), file_get_contents('php://input'));
$mode = getenv('QUESTIONFILTER_AI_MODE');
if ($mode === 'slow') {
    sleep(32);
}
if ($mode === 'redirect') {
    header('Location: /followed', true, 302);
    exit;
}
if ($mode === 'error') {
    http_response_code(500);
}
header('Content-Type: application/json');
if ($mode === 'large') {
    echo '{"response":"';
    for ($i = 0; $i < 1024; $i++) {
        echo str_repeat('x', 1024);
        flush();
    }
    echo '"}';
} else if ($mode === 'many') {
    $tags = array_merge(['x', str_repeat('y', 51), "bad\x01tag"], array_map(static fn($i) => 'Tag' . $i, range(1, 14)));
    echo json_encode(['response' => implode(',', $tags)]);
} else if ($mode === 'invalid') {
    echo '{"response":["Mathematik"]}';
} else if ($mode === 'malformed') {
    echo 'not json';
} else {
    echo '{"response":"Mathematik, Algebra, Mathematik, <script>alert(1)</script>"}';
}
