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
 * Upgrade steps for block_fragenfilter.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @author     Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs the plugin's upgrade steps.
 *
 * @param int $oldversion Previously installed version.
 * @return bool Always true.
 */
function xmldb_block_fragenfilter_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    // Rename tables and migrate config from the old plugin name block_questionfilter.
    // This runs unconditionally (no version guard) so that a system upgraded from
    // block_questionfilter 1.x to block_fragenfilter picks up the data on the very
    // first upgrade run, regardless of which build number was installed before.
    $renames = [
        'block_questionfilter_ratings'     => 'block_fragenfilter_ratings',
        'block_questionfilter_tag_pending' => 'block_fragenfilter_tag_pending',
    ];
    foreach ($renames as $oldname => $newname) {
        $oldtable = new xmldb_table($oldname);
        $newtable = new xmldb_table($newname);
        if ($dbman->table_exists($oldtable) && !$dbman->table_exists($newtable)) {
            $dbman->rename_table($oldtable, $newname);
        }
    }

    // Migrate all plugin config values from the old component name.
    $oldconfigs = $DB->get_records('config_plugins', ['plugin' => 'block_questionfilter']);
    foreach ($oldconfigs as $row) {
        // Only copy if no value is already set under the new name.
        if (get_config('block_fragenfilter', $row->name) === false) {
            set_config($row->name, $row->value, 'block_fragenfilter');
        }
        unset_config($row->name, 'block_questionfilter');
    }

    // Transfer values from old configuration keys to the current keys.
    $migrations = [
        'nivelevel_levels' => 'difficulty_levels',
        'nivelevel_values' => 'difficulty_levels',
    ];
    foreach ($migrations as $oldkey => $newkey) {
        $oldval = get_config('block_fragenfilter', $oldkey);
        if ($oldval !== false) {
            $newval  = get_config('block_fragenfilter', $newkey);
            $default = "Leicht\nMittel\nSchwer";
            if ($newval === false || trim($newval) === trim($default)) {
                set_config($newkey, $oldval, 'block_fragenfilter');
            }
            unset_config($oldkey, 'block_fragenfilter');
        }
    }

    // Build 2026080501: create the ratings table.
    if ($oldversion < 2026080501) {
        $table = new xmldb_table('block_fragenfilter_ratings');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', XMLDB_NOTNULL);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null);
            $table->add_field('sessionhash', XMLDB_TYPE_CHAR, '64', null);
            $table->add_field('rating', XMLDB_TYPE_INTEGER, '1', XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_question', XMLDB_KEY_FOREIGN, ['questionid'], 'question', ['id']);
            $table->add_key('fk_user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index('idx_userid_question', XMLDB_INDEX_NOTUNIQUE, ['userid', 'questionid']);
            $table->add_index('idx_session_question', XMLDB_INDEX_NOTUNIQUE, ['sessionhash', 'questionid']);
            $dbman->create_table($table);
        }
        upgrade_block_savepoint(true, 2026080501, 'fragenfilter');
    }

    // Build 2026090514: create the question preview role and give it to the pool accounts.
    // The later role steps repeat the idempotent assignment, because each savepoint must stand on its own.
    if ($oldversion < 2026090514) {
        \block_fragenfilter\preview_role::assign_to_pool_accounts();
        upgrade_block_savepoint(true, 2026090514, 'fragenfilter');
    }

    // Build 2026090524: add the export capability to the preview role.
    if ($oldversion < 2026090524) {
        $roleshortname = 'fragenfilter_preview';
        $sysctx = context_system::instance();
        $role = $DB->get_record('role', ['shortname' => $roleshortname]);
        if ($role) {
            assign_capability('block/fragenfilter:export', CAP_ALLOW, $role->id, $sysctx->id, true);
        }
        upgrade_block_savepoint(true, 2026090524, 'fragenfilter');
    }

    // Build 2026101101: create the AI tag suggestions table.
    if ($oldversion < 2026101101) {
        $table = new xmldb_table('block_fragenfilter_tag_pending');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', XMLDB_NOTNULL);
            $table->add_field('tags', XMLDB_TYPE_TEXT, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '10', XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_questionid', XMLDB_INDEX_UNIQUE, ['questionid']);
            $table->add_index('idx_status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $dbman->create_table($table);
        }
        upgrade_block_savepoint(true, 2026101101, 'fragenfilter');
    }

    // Build 2026101601: Assign the preview role to renamed Kursfilter pool accounts (kursfilter_pool*).
    if ($oldversion < 2026101601) {
        \block_fragenfilter\preview_role::assign_to_pool_accounts();
        upgrade_block_savepoint(true, 2026101601, 'fragenfilter');
    }

    // Build 2026101603: The preview role goes to all pool accounts of block_kursfilter, including later created ones.
    if ($oldversion < 2026101603) {
        \block_fragenfilter\preview_role::assign_to_pool_accounts();
        upgrade_block_savepoint(true, 2026101603, 'fragenfilter');
    }

    // Unlink legacy identities while preserving their stars and creation times (ADR 0003).
    if ($oldversion < 2026101605) {
        $table = new xmldb_table('block_fragenfilter_ratings');
        $field = new xmldb_field('cookiehash', XMLDB_TYPE_CHAR, '64', null, null);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        foreach (
            [
                new xmldb_index('idx_userid_question', XMLDB_INDEX_NOTUNIQUE, ['userid', 'questionid']),
                new xmldb_index('idx_session_question', XMLDB_INDEX_NOTUNIQUE, ['sessionhash', 'questionid']),
            ] as $index
        ) {
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
        }
        $dbman->drop_key($table, new xmldb_key('fk_user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']));
        foreach (['userid', 'sessionhash', 'timemodified'] as $name) {
            $field = new xmldb_field($name);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }
        $field = new xmldb_field('rating', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL);
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'stars');
        }
        $index = new xmldb_index('question_cookie', XMLDB_INDEX_UNIQUE, ['questionid', 'cookiehash']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_block_savepoint(true, 2026101605, 'fragenfilter');
    }

    // Build 2026102104: add moodle/question:viewmine to the preview role so that
    // question_visibility::sql() actually finds questions for pool accounts.
    // (The role was previously missing this capability, so pool users saw zero results.)
    if ($oldversion < 2026102104) {
        $role = $DB->get_record('role', ['shortname' => 'fragenfilter_preview']);
        if ($role) {
            $sysctx = context_system::instance();
            assign_capability('moodle/question:viewmine', CAP_ALLOW, $role->id, $sysctx->id, true);
        }
        upgrade_block_savepoint(true, 2026102104, 'fragenfilter');
    }

    // Build 2026102108: add block/fragenfilter:view to the preview role so that
    // pool accounts can use the search block after occupying an account via guest_preview.
    if ($oldversion < 2026102108) {
        $role = $DB->get_record('role', ['shortname' => 'fragenfilter_preview']);
        if ($role) {
            $sysctx = context_system::instance();
            assign_capability('block/fragenfilter:view', CAP_ALLOW, $role->id, $sysctx->id, true);
        }
        upgrade_block_savepoint(true, 2026102108, 'fragenfilter');
    }

    // Build 2026102201: consolidate the preview role.
    //
    // Systems upgraded from block_questionfilter have two roles:
    // - questionfilter_preview  (the old one, with usemine + export only)
    // - fragenfilter_preview    (created fresh by block_fragenfilter, with the full set)
    //
    // We delete the old orphan role and make sure fragenfilter_preview has all capabilities.
    // If only the old role exists (no fragenfilter_preview yet), we rename it.
    if ($oldversion < 2026102201) {
        $sysctx = context_system::instance();

        $oldrole  = $DB->get_record('role', ['shortname' => 'questionfilter_preview']);
        $newrole  = $DB->get_record('role', ['shortname' => 'fragenfilter_preview']);

        if ($oldrole && $newrole) {
            // Both exist: migrate any role assignments from the old role to the new one,
            // then delete the old role so the unique constraint is no longer violated.
            $existing = $DB->get_records('role_assignments', ['roleid' => $newrole->id], '', 'userid,contextid');
            $existingkeys = [];
            foreach ($existing as $ra) {
                $existingkeys[$ra->contextid . '_' . $ra->userid] = true;
            }
            foreach ($DB->get_records('role_assignments', ['roleid' => $oldrole->id]) as $ra) {
                $key = $ra->contextid . '_' . $ra->userid;
                if (!isset($existingkeys[$key])) {
                    role_assign($newrole->id, $ra->userid, $ra->contextid);
                }
            }
            delete_role($oldrole->id);
            $role = $newrole;
        } else if ($oldrole && !$newrole) {
            // Only old role exists: rename it.
            $oldrole->shortname = 'fragenfilter_preview';
            $DB->update_record('role', $oldrole);
            $role = $oldrole;
        } else {
            // Fragenfilter_preview already exists (normal case after clean install).
            $role = $newrole;
        }

        if ($role) {
            // Ensure all required capabilities are present (idempotent).
            assign_capability('moodle/question:viewmine', CAP_ALLOW, $role->id, $sysctx->id, true);
            assign_capability('moodle/question:useall', CAP_ALLOW, $role->id, $sysctx->id, true);
            assign_capability('block/fragenfilter:view', CAP_ALLOW, $role->id, $sysctx->id, true);
            assign_capability('block/fragenfilter:export', CAP_ALLOW, $role->id, $sysctx->id, true);
        }

        // Re-assign the (now consolidated) role to all pool accounts.
        \block_fragenfilter\preview_role::assign_to_pool_accounts();

        upgrade_block_savepoint(true, 2026102201, 'fragenfilter');
    }

    // Build 2026102206: create fragenfilter's own pool accounts (fragenfilter_pool01-10).
    //
    // This decouples block_fragenfilter from block_kursfilter for the question preview:
    // - Own accounts use the 'fragenfilter_pool' username prefix.
    // - No per-IP lock — multiple tabs from the same visitor are each served their own slot.
    // - Sessions are cleaned up by the cleanup_pool_sessions scheduled task.
    //
    // The pool accounts are created here on upgrade. On a fresh install, db/install.php
    // calls pool_manager::create_pool_accounts() directly.
    if ($oldversion < 2026102206) {
        \block_fragenfilter\pool_manager::create_pool_accounts();

        // Ensure the preview role is assigned to both the new fragenfilter_pool* accounts
        // and the legacy kursfilter_pool* accounts.
        \block_fragenfilter\preview_role::assign_to_pool_accounts();

        upgrade_block_savepoint(true, 2026102206, 'fragenfilter');
    }

    // Build 2026102207: allow the fragenfilter_preview role in CONTEXT_MODULE.
    //
    // Moodle 5+ with mod_qbank: question_require_capability_on() checks the
    // question:usemine capability in the module context of the question bank activity
    // (CONTEXT_MODULE = 70). The role was previously limited to CONTEXT_SYSTEM, so
    // pool accounts received a "nopermissions (use)" error when opening the preview.
    if ($oldversion < 2026102207) {
        $role = $DB->get_record('role', ['shortname' => 'fragenfilter_preview']);
        if ($role) {
            // Remove existing context-level entries for this role and write the full set.
            $DB->delete_records('role_context_levels', ['roleid' => $role->id]);
            foreach ([CONTEXT_SYSTEM, CONTEXT_MODULE] as $contextlevel) {
                $record = new \stdClass();
                $record->roleid       = $role->id;
                $record->contextlevel = $contextlevel;
                $DB->insert_record('role_context_levels', $record, false, true);
            }
        }
        upgrade_block_savepoint(true, 2026102207, 'fragenfilter');
    }

    // Build 2026102208: replace question:usemine with question:useall on the preview role.
    //
    // question:usemine only allows previewing questions the user created themselves.
    // Pool accounts never create questions, so usemine always denied the preview.
    // question:useall allows previewing any question in the context — this is safe
    // because pool accounts only receive this role, not teacher or admin rights,
    // and question_visibility::is_public_user() already limits which questions
    // they can search and export.
    if ($oldversion < 2026102208) {
        $role = $DB->get_record('role', ['shortname' => 'fragenfilter_preview']);
        if ($role) {
            $sysctx = context_system::instance();
            // Remove the old usemine capability (if present) and grant useall.
            unassign_capability('moodle/question:usemine', $role->id, $sysctx->id);
            assign_capability('moodle/question:useall', CAP_ALLOW, $role->id, $sysctx->id, true);
        }
        upgrade_block_savepoint(true, 2026102208, 'fragenfilter');
    }

    // Establish the independent pool even when upgrading from a newer refactor build.
    if ($oldversion < 2026110704) {
        \block_fragenfilter\pool_manager::create_pool_accounts();
        $roleid = \block_fragenfilter\preview_role::ensure();
        $systemcontext = context_system::instance();
        foreach (['moodle/question:usemine', 'moodle/question:useall', 'moodle/question:viewmine'] as $capability) {
            unassign_capability($capability, $roleid, $systemcontext->id);
        }
        assign_capability('block/fragenfilter:view', CAP_ALLOW, $roleid, $systemcontext->id, true);
        assign_capability('block/fragenfilter:export', CAP_ALLOW, $roleid, $systemcontext->id, true);
        \block_fragenfilter\preview_role::assign_to_pool_accounts();
        upgrade_block_savepoint(true, 2026110704, 'fragenfilter');
    }

    return true;
}
