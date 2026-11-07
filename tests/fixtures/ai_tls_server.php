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
 * Independent TLS peer presenting an untrusted certificate.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState -- Standalone CLI TLS peer, guarded by server-only environment.
if (!getenv('QUESTIONFILTER_AI_FIXTURE')) {
    exit;
}
$context = stream_context_create(['ssl' => ['local_cert' => $argv[2], 'verify_peer' => false]]);
$server = stream_socket_server('tls://' . $argv[1], $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) {
    exit(1);
}
while (true) {
    $client = @stream_socket_accept($server, 5);
    if ($client) {
        file_put_contents(getenv('QUESTIONFILTER_AI_RECEIPT'), fread($client, 16384));
        $body = '{"response":"Mathematik"}';
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
        fclose($client);
    }
}
