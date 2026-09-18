<?php
// This file is part of Moodle - http://moodle.org/
// Licensed under the GNU GPL v3 or later: http://www.gnu.org/copyleft/gpl.html.

/** Run on disposable local Moodle: php local/rapidcmi5/cli/bulk_update_smoke.php --run. */
if (PHP_SAPI !== 'cli' || !in_array('--run', $argv ?? [], true)) {
    exit(1);
}
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
\core\session\manager::set_user(get_admin());
$transaction = $DB->start_delegated_transaction();
$checks = 0;
function verify_update($condition, string $message): void {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
/** Draft area holding a RapidCMI5 package with one AU, so learner progress can be attached. */
function bulk_fixture_draft(string $identifier): int {
    global $USER;
    $dir = make_request_directory();
    file_put_contents($dir . '/cmi5.xml', '<courseStructure><course id="' . s($identifier) . '">' .
        '<title><langstring lang="en">Bulk fixture</langstring></title></course>' .
        '<au id="' . s($identifier) . '/au"><title><langstring lang="en">Lesson</langstring></title>' .
        '<url>index.html</url></au></courseStructure>');
    file_put_contents($dir . '/index.html', 'Revision ' . bin2hex(random_bytes(8)));
    file_put_contents($dir . '/player-manifest.json', '{"playerVersion":"test"}');
    $draftid = file_get_unused_draft_itemid();
    get_file_packer('application/zip')->archive_to_storage(['cmi5.xml' => $dir . '/cmi5.xml',
        'index.html' => $dir . '/index.html', 'player-manifest.json' => $dir . '/player-manifest.json'],
        context_user::instance($USER->id)->id, 'user', 'draft', $draftid, '/', 'bulk.zip', $USER->id, false);
    return $draftid;
}
function bulk_fixture_course(string $name): stdClass {
    global $DB;
    return create_course((object) ['fullname' => $name, 'shortname' => $name . '-' . bin2hex(random_bytes(4)),
        'category' => $DB->get_field_select('course_categories', 'id', '1 = 1', null, IGNORE_MULTIPLE)]);
}
$failure = null;
try {
    $prefix = 'urn:rapidcmi5:bulk-test:' . bin2hex(random_bytes(6));
    $first = \local_rapidcmi5\version_uploader::upload_package(bulk_fixture_draft($prefix), '1.0');
    $project = $first->project;
    $coursea = bulk_fixture_course('Bulk A');
    $courseb = bulk_fixture_course('Bulk B');
    $deploy = fn($course, $version) => \local_rapidcmi5\deployment_manager::deploy_to_course($project->id, $version->id,
        (int) $version->libraryversionid, $course->id, 'Deployed name');
    $deploya = $deploy($coursea, $first->version);
    $deployb = $deploy($courseb, $first->version);
    $second = \local_rapidcmi5\version_uploader::upload_package(bulk_fixture_draft($prefix), '2.0');
    // A teacher renamed one activity, and a learner has progress in it.
    $instanceid = $DB->get_field('course_modules', 'instance', ['id' => $deploya->cmid]);
    $DB->set_field('cmi5', 'name', 'Teacher name', ['id' => $instanceid]);
    $au = $DB->get_record('cmi5_aus', ['cmi5id' => $instanceid], '*', MUST_EXIST);
    $statusid = $DB->insert_record('cmi5_au_status', (object) ['registrationid' => 1, 'auid' => $au->id,
        'completed' => 1, 'timecreated' => time(), 'timemodified' => time()]);

    // A second project whose current version lost its library revision cannot be updated.
    $brokenprefix = $prefix . ':broken';
    $broken = \local_rapidcmi5\version_uploader::upload_package(bulk_fixture_draft($brokenprefix), '1.0');
    $brokendeploy = \local_rapidcmi5\deployment_manager::deploy_to_course($broken->project->id, $broken->version->id,
        (int) $broken->version->libraryversionid, $coursea->id, 'Broken');
    $brokennext = \local_rapidcmi5\version_uploader::upload_package(bulk_fixture_draft($brokenprefix), '2.0');
    $DB->set_field('local_rapidcmi5_versions', 'libraryversionid', -1, ['id' => $brokennext->version->id]);
    $DB->set_field('local_rapidcmi5_versions', 'sha256hash', null, ['id' => $brokennext->version->id]);

    // Finder.
    $found = \local_rapidcmi5\update_finder::find(['search' => $prefix]);
    $ids = fn($projectdata) => array_column($projectdata->activities, 'deploymentid');
    verify_update(count($found) === 2 && $ids($found[$project->id]) === [(int) $deploya->id, (int) $deployb->id],
        'Finder did not list the activities behind');
    verify_update($found[$project->id]->currentlabel === '2.0' && $found[$project->id]->canupdate &&
        $found[$project->id]->activities[0]->installedlabel === '1.0', 'Finder labels wrong');
    verify_update(!$found[$broken->project->id]->canupdate, 'Missing library revision not flagged');
    verify_update(\local_rapidcmi5\update_finder::count(['search' => $prefix]) === 3, 'Count does not match finder');
    verify_update(count(\local_rapidcmi5\update_finder::find(['projectid' => $broken->project->id])) === 1,
        'Project filter failed');

    // Page renders the grouped list for someone who can update.
    $data = \local_rapidcmi5\output\updates_page::data(['search' => $prefix], [(int) $deployb->id], true);
    verify_update(count($data['projects']) === 2 && $data['selectedcount'] === 1 && $data['hasselectable'],
        'Page data wrong');
    $html = $OUTPUT->render_from_template('local_rapidcmi5/updates', $data);
    verify_update(substr_count($html, 'name="deploymentids[]"') === 2 &&
        strpos($html, 'data-togglegroup="rcmi-updates project-' . $project->id . '-"') !== false,
        'Broken project should not be selectable, others grouped by project');

    // Bulk update: two to update, one already current, one unknown, one that fails.
    $DB->set_field('local_rapidcmi5_deployments', 'versionid', $second->version->id, ['id' => $deployb->id]);
    $result = \local_rapidcmi5\bulk_updater::update([$deploya->id, $deployb->id, 999999999, $brokendeploy->id,
        $deploya->id]);
    verify_update($result->updated === [(int) $deploya->id], 'Behind activity not updated');
    verify_update($result->skipped === [(int) $deployb->id, 999999999], 'Current or unknown activities not skipped');
    verify_update(count($result->failed) === 1 && $result->failed[0]->deploymentid === (int) $brokendeploy->id &&
        $result->failed[0]->activityname === 'Broken', 'Failure not isolated and reported');
    verify_update((int) $DB->get_field('local_rapidcmi5_deployments', 'versionid', ['id' => $deploya->id]) ===
        (int) $second->version->id, 'Deployment not moved to the current version');
    verify_update((int) $DB->get_field('cmi5', 'packageversionid', ['id' => $instanceid]) ===
        (int) $second->version->libraryversionid, 'Activity not moved to the current library revision');
    verify_update($DB->get_field('cmi5', 'name', ['id' => $instanceid]) === 'Teacher name', 'Activity renamed');
    verify_update($DB->record_exists('cmi5_au_status', ['id' => $statusid, 'auid' => $au->id]) &&
        $DB->record_exists('cmi5_aus', ['id' => $au->id, 'cmi5id' => $instanceid]), 'Learner progress lost');
    verify_update(\local_rapidcmi5\update_finder::count(['projectid' => $project->id]) === 0, 'Updated activity still listed');

    // Permission: viewing needs manage, updating needs deploy.
    \core\session\manager::set_user(guest_user());
    try {
        \local_rapidcmi5\bulk_updater::update([$brokendeploy->id]);
        throw new RuntimeException('Guest could update activities');
    } catch (required_capability_exception $e) {
        verify_update(true, '');
    }
    \core\session\manager::set_user(get_admin());
} catch (Throwable $e) {
    $failure = $e;
} finally {
    try {
        $transaction->rollback(new RuntimeException('Remove bulk update fixtures'));
    } catch (Throwable $ignored) {
    }
}
if ($failure) {
    fwrite(STDERR, $failure . "\n");
    exit(1);
}
echo "PASS: {$checks} bulk update checks; fixture database changes rolled back.\n";
