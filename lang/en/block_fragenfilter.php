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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * English language strings for block_fragenfilter.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action_approve'] = 'Approve';
$string['action_approveall'] = 'Approve all {$a}';
$string['action_reject'] = 'Reject';
$string['action_rejectall'] = 'Reject all';
$string['addtoquiz'] = 'Add to quiz';
$string['ai_backend_claude'] = 'Anthropic Claude API';
$string['ai_backend_ollama'] = 'Ollama (local)';
$string['approved'] = 'Tag suggestion has been approved.';
$string['approved_all'] = '{$a} tag suggestions have been approved.';
$string['block_fragenfilter:addinstance'] = 'Add a Question filter block';
$string['block_fragenfilter:export'] = 'Export questions';
$string['block_fragenfilter:myaddinstance'] = 'Add a Question filter block to Dashboard';
$string['block_fragenfilter:reviewtags'] = 'Review and approve AI tag suggestions';
$string['block_fragenfilter:view'] = 'Use the question filter block';
$string['btn_reset'] = 'Reset';
$string['categories'] = 'Question collections';
$string['col_actions'] = 'Actions';
$string['col_question'] = 'Question';
$string['col_tags'] = 'Tags';
$string['col_type'] = 'Type';
$string['confirm_approveall'] = 'Really approve all pending suggestions?';
$string['confirm_rejectall'] = 'Really reject all pending suggestions?';
$string['coresystem'] = 'System-wide';
$string['ctxbank'] = '{$a->course} › {$a->name}';
$string['ctxcategory'] = 'Category: {$a}';
$string['ctxcategoryid'] = 'Category #{$a}';
$string['ctxcontextid'] = 'Context #{$a}';
$string['ctxcoursedefault'] = 'Course';
$string['ctxcourseid'] = 'Course #{$a}';
$string['ctxmoduleid'] = 'Module #{$a}';
$string['ctxquiz'] = '{$a->course} › Quiz: {$a->name}';
$string['difficulty'] = 'Difficulty levels';
$string['entertosearch'] = 'Set filters to search for questions.';
$string['error_ai_answer_invalid'] = 'AI response does not contain a valid JSON object: {$a}';
$string['error_ai_key'] = 'Configure the Claude API key before using this backend.';
$string['error_ai_request_failed'] = 'AI request failed: {$a}';
$string['error_ai_request_limit'] = 'AI request exceeds the 16 KiB limit. Reduce the title or configured categories/model.';
$string['error_ai_response'] = 'Invalid AI response structure. Check the configured provider.';
$string['error_ai_response_limit'] = 'AI response exceeds the 16 KiB limit. Check the configured provider.';
$string['error_ai_tagging_failed'] = 'AI tagging: {$a->failed} of {$a->total} questions could not be processed.';
$string['error_ai_target'] = 'Invalid AI target. Use an HTTP(S) service URL without credentials, query or fragment.';
$string['error_ai_transport'] = 'AI request failed or returned a non-200 status. Check the provider, TLS certificate and Moodle HTTP security settings.';
$string['error_ai_unavailable'] = 'AI tagging is enabled but no AI provider is configured in Moodle (Site administration → AI → AI providers).';
$string['error_pool_lock'] = 'The preview pool is busy. Please try again.';
$string['error_preview_needs_post'] = 'Start the preview using the preview button.';
$string['error_review_needs_post'] = 'Tag review actions require a POST request.';
$string['error_review_unavailable'] = 'This suggestion is unavailable or you cannot review its question.';
$string['event_question_rated'] = 'Question rated';
$string['event_suggestion_reviewed'] = 'AI tag suggestion reviewed';
$string['export_csv'] = 'CSV spreadsheet';
$string['export_gift'] = 'GIFT format';
$string['export_xml'] = 'Moodle XML';
$string['exportfailed'] = 'Export failed. Please check the Moodle logs.';
$string['exportformatdisabled'] = 'The requested export format is disabled or unknown.';
$string['exportunsupportedquestion'] = 'A selected question cannot be exported in this format.';
$string['fragenfilter:addinstance'] = 'Add a Question filter block';
$string['fragenfilter:export'] = 'Export questions';
$string['fragenfilter:myaddinstance'] = 'Add a Question filter block to Dashboard';
$string['fragenfilter:reviewtags'] = 'Review and approve AI tag suggestions';
$string['fragenfilter:view'] = 'Use the question filter block';
$string['invalidquestion'] = 'Question not found.';
$string['loading'] = 'Loading …';
$string['loadingcategories'] = 'Loading collections …';
$string['no_tags'] = '(no tags)';
$string['nopermission'] = 'You do not have permission to view the question bank.';
$string['noquestionsselected'] = 'No questions selected.';
$string['pluginname'] = 'Fragenfilter';
$string['pool_account_description'] = 'Automatically created Fragenfilter preview account. Do not delete.';
$string['preview_back_btn'] = 'Back to home page';
$string['preview_in_use'] = 'A preview is already running for this IP address. Please log out of it first.';
$string['preview_open_btn'] = 'Open preview:';
$string['preview_pool_full'] = 'All pool accounts are currently occupied. Please try again in a few minutes.';
$string['preview_ready_desc'] = 'You are now logged in. Open the preview and then return to the home page to export questions.';
$string['preview_ready_title'] = 'Question preview ready';
$string['privacy:metadata'] = 'The Question Filter stores pseudonymous ratings under a browser cookie and, per account, a temporary export file. Rating and review events can link a user account and IP address in Moodle logs. AI tagging sends question data to the configured AI provider; shortening the text does not anonymise it.';
$string['privacy:metadata:ai:questiontext'] = 'Question text as plain text, shortened to 800 characters. Personal data in the text is not removed.';
$string['privacy:metadata:ai:questiontitle'] = 'The question name.';
$string['privacy:metadata:ai:questiontype'] = 'The internal question type.';
$string['privacy:metadata:ai:tagcategories'] = 'The subject categories configured by the administrator.';
$string['privacy:metadata:anthropic'] = 'Optional AI backend Claude: the prompt is sent to the Anthropic API (api.anthropic.com) when AI tagging is enabled. Moodle\'s AI subsystem is not used.';
$string['privacy:metadata:cache'] = 'A short-lived rate-limit counter per account and network address (about two minutes) in the application cache. The key is a hash; the address itself is not stored.';
$string['privacy:metadata:cache:userid'] = 'The account (together with a hash of the network address) whose requests are counted.';
$string['privacy:metadata:cookie'] = 'The kf_rater_id cookie is shared with the Course Filter. It is stored in the visitor browser for one year, renewed with each rating, and recognises existing ratings.';
$string['privacy:metadata:files'] = 'The latest question export of an account is kept as a file in Moodle\'s file store and replaced by the next export of that account.';
$string['privacy:metadata:files:filename'] = 'The exported questions with the time of the export in the file name.';
$string['privacy:metadata:files:userid'] = 'The account that created the export. Pool accounts are reused by different visitors.';
$string['privacy:metadata:logs'] = 'Moodle logstores record rating events (question and stars, no cookie value) and tag suggestion reviews with user ID, IP address and a reference to the rating row or suggestion.';
$string['privacy:metadata:logs:ip'] = 'The IP address recorded by the logstore.';
$string['privacy:metadata:logs:objectid'] = 'The ID of the rating row or tag suggestion that the event refers to.';
$string['privacy:metadata:logs:other'] = 'The star count of a rating or the outcome of a review.';
$string['privacy:metadata:logs:userid'] = 'The account that rated or reviewed; for visitors without an account, the pool account in use.';
$string['privacy:metadata:ollama'] = 'Optional AI backend Ollama: the prompt is sent to the Ollama server configured by the administrator when AI tagging is enabled. The server may be external.';
$string['privacy:metadata:ratings'] = 'Question ratings identified only by a pseudonymous browser identifier. No account is stored, so ratings cannot be exported or deleted per account. Rating events in the logs can link a rating to an account.';
$string['privacy:metadata:ratings:cookiehash'] = 'Random SHA-256 browser identifier shared by the cookie and the stored ratings. Legacy ratings have none.';
$string['privacy:metadata:ratings:questionid'] = 'The rated question.';
$string['privacy:metadata:ratings:stars'] = 'The rating from one to five stars.';
$string['privacy:metadata:ratings:timecreated'] = 'The time the rating was submitted.';
$string['privacy:path:export'] = 'Question exports';
$string['qtypes_existing'] = 'Only types with existing questions';
$string['qtypes_installed'] = 'All installed question types';
$string['questiontypes'] = 'Question type';
$string['ratelimitexceeded'] = 'Too many requests. Please wait {$a->seconds} seconds.';
$string['rejected'] = 'Tag suggestion has been rejected.';
$string['rejected_all'] = 'All pending tag suggestions have been rejected.';
$string['review_empty'] = 'No pending tag suggestions found.';
$string['review_title'] = 'Review AI tag suggestions';
$string['role_preview_bank_desc'] = 'Temporary question preview access in a published question-bank course.';
$string['role_preview_bank_name'] = 'Question bank preview';
$string['role_preview_desc'] = 'Role for the pool accounts of the course filter. Grants question preview access (moodle/question:usemine) and export (block/fragenfilter:export).';
$string['role_preview_name'] = 'Question preview';
$string['scope_all'] = 'All question collections (cross-course)';
$string['scope_course'] = 'Current course only';
$string['scope_system'] = 'System question bank only';
$string['searchplaceholder'] = 'Search question or tag …';
$string['selectall'] = 'Select all';
$string['settings_ai_autoapply'] = 'Automatically apply suggestions';
$string['settings_ai_autoapply_help'] = 'When enabled, AI suggestions are written directly as Moodle tags. Otherwise they are stored for manual review.';
$string['settings_ai_backend'] = 'AI backend';
$string['settings_ai_backend_desc'] = 'Choose whether to use Ollama (local) or the Anthropic Claude API for tag generation.';
$string['settings_ai_backend_heading'] = 'AI backend selection';
$string['settings_ai_backend_help'] = 'Ollama runs locally on the server. Claude API requires an Anthropic API key.';
$string['settings_ai_batch_size'] = 'Questions per run';
$string['settings_ai_batch_size_help'] = 'Questions per task run, limited to 1–20. Provider quotas must also be configured.';
$string['settings_ai_claude_apikey'] = 'Claude API key';
$string['settings_ai_claude_apikey_help'] = 'Anthropic API key. Stored in Moodle plugin configuration, accessible to site administrators and database/backup operators. Never include it in an Ollama URL.';
$string['settings_ai_claude_desc'] = 'Enter your Anthropic API key and the desired model. Question titles and text are minimised (no author name, no Moodle IDs) but not anonymised before being sent.';
$string['settings_ai_claude_heading'] = 'Claude API settings';
$string['settings_ai_claude_model'] = 'Claude model';
$string['settings_ai_claude_model_help'] = 'Model identifier, e.g. claude-haiku-4-5-20251001. Haiku is fast and cost-effective for tag generation.';
$string['settings_ai_desc'] = 'Direct AI tag suggestions via Ollama or Anthropic. Question text is minimised, not anonymised. The configured administrator release applies to all question banks.';
$string['settings_ai_enabled'] = 'Enable AI tagging';
$string['settings_ai_enabled_help'] = 'Releases titles and up to 800 characters of question text from all question banks to the configured AI provider. Free text may contain personal data; check the content, provider and HTTP security settings before enabling. Disabled by default.';
$string['settings_ai_heading'] = 'AI tagging';
$string['settings_ai_max_tags'] = 'Maximum number of tags';
$string['settings_ai_max_tags_help'] = 'Maximum number of tags the AI may suggest per question (default: 5, limited to 1–10).';
$string['settings_ai_ollama_heading'] = 'Ollama settings';
$string['settings_ai_ollama_model'] = 'Ollama model';
$string['settings_ai_ollama_model_help'] = 'Name of the Ollama model, e.g. gemma3:4b or llama3.2. The model must be available on the Ollama server.';
$string['settings_ai_ollama_url'] = 'Ollama URL';
$string['settings_ai_ollama_url_help'] = 'Base URL of the Ollama server, e.g. http://localhost:11434. No trailing slash. Only HTTP(S) URLs without credentials or query are used and redirects are not followed; limit internal targets in Moodle\'s HTTP security settings (blocked hosts, allowed ports).';
$string['settings_ai_tag_categories'] = 'Subject areas';
$string['settings_ai_tag_categories_help'] = 'Possible subject areas for the AI (one per line). The AI selects matching ones and ignores those that do not apply.';
$string['settings_ai_tagging_heading'] = 'Tagging options';
$string['settings_custom_tags'] = 'Suggested tags';
$string['settings_custom_tags_help'] = 'Comma-separated list of tags offered as quick filters in the block.';
$string['settings_customfields_heading'] = 'Custom tags';
$string['settings_difficulty_desc'] = 'Levels are offered as tag filters. Extensible — one level per line.';
$string['settings_difficulty_heading'] = 'Difficulty levels';
$string['settings_difficulty_levels'] = 'Difficulty levels';
$string['settings_difficulty_levels_help'] = 'One level per line, e.g. Easy, Medium, Hard. Freely extensible.';
$string['settings_export_heading'] = 'Export formats';
$string['settings_exportformats'] = 'Available formats';
$string['settings_exportformats_help'] = 'Which export formats are shown in the block.';
$string['settings_questiontypes_heading'] = 'Question types';
$string['settings_questiontypes_source'] = 'Displayed question types';
$string['settings_questiontypes_source_help'] = 'All installed: shows all qtype plugins. Existing only: shows only types that have questions in the bank.';
$string['settings_resultlimit'] = 'Max. results';
$string['settings_resultlimit_help'] = 'Maximum number of questions returned per search request (default: 200).';
$string['settings_review_heading'] = 'Review tag suggestions';
$string['settings_review_link'] = 'Go to review and approval page';
$string['settings_searchscope'] = 'Search scope';
$string['settings_searchscope_desc'] = 'Defines which question collections the block searches across.';
$string['settings_searchscope_heading'] = 'Search scope';
$string['settings_searchscope_help'] = 'All: system-wide and cross-course. Course: current course only. System: system question bank only.';
$string['stat_approved'] = 'Approved: {$a}';
$string['stat_pending'] = 'Pending: {$a}';
$string['stat_rejected'] = 'Rejected: {$a}';
$string['tagplaceholder'] = 'Enter tag and press Enter …';
$string['tags'] = 'Tags';
$string['task_cleanup_pool_sessions'] = 'Fragenfilter: Clean up idle pool sessions';
$string['task_generate_tags'] = 'AI tag generation for questions';
