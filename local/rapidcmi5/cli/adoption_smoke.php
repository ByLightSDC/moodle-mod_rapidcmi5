<?php
// This file is part of Moodle - http://moodle.org/
// Licensed under the GNU GPL v3 or later: http://www.gnu.org/copyleft/gpl.html.

/**
 * Transactional integration check for adoption, for a disposable local Moodle site.
 * Run: php local/rapidcmi5/cli/adoption_smoke.php --run
 * Fixture database changes are always rolled back; database sequences may advance.
 */
if (PHP_SAPI !== 'cli' || !in_array('--run', $argv ?? [], true)) {
    exit(1);
}
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

\core\session\manager::set_user(get_admin());
$transaction = $DB->start_delegated_transaction();
$checks = 0;
function verify_adoption($condition, string $message): void {
    global $checks;
    if (!$condition) {
        throw new \RuntimeException($message);
    }
    $checks++;
}
function expect_adoption_error(callable $action, string $code): void {
    try {
        $action();
    } catch (\moodle_exception $e) {
        verify_adoption($e->errorcode === $code, 'Unexpected error: ' . $e->errorcode);
        return;
    }
    throw new \RuntimeException('Expected error: ' . $code);
}
function adoption_fixture(string $identifier, bool $library = true): int {
    global $DB, $USER, $SITE;
    $instance = (object) ['course' => $SITE->id, 'name' => 'Adoption smoke fixture',
        'courseid_iri' => $identifier];
    if ($library) {
        $packageid = $DB->insert_record('cmi5_packages', (object) ['title' => $instance->name]);
        $versionid = $DB->insert_record('cmi5_package_versions', (object) [
            'packageid' => $packageid, 'versionnumber' => 1, 'courseid_iri' => $identifier,
            'sha256hash' => sha1($identifier), 'createdby' => $USER->id, 'timecreated' => time()]);
        $DB->set_field('cmi5_packages', 'latestversion', $versionid, ['id' => $packageid]);
        $instance->packageid = $packageid;
        $instance->packageversionid = $versionid;
    }
    $instance->id = $DB->insert_record('cmi5', $instance);
    $sectionid = $DB->get_field('course_sections', 'id', ['course' => $SITE->id, 'section' => 0]);
    if (!$sectionid) {
        $sectionid = $DB->insert_record('course_sections', (object) [
            'course' => $SITE->id, 'section' => 0, 'summary' => '', 'sequence' => '']);
    }
    $cmid = $DB->insert_record('course_modules', (object) ['course' => $SITE->id,
        'module' => $DB->get_field('modules', 'id', ['name' => 'cmi5'], MUST_EXIST),
        'instance' => $instance->id, 'section' => $sectionid]);
    $context = \context_module::instance($cmid);
    get_file_storage()->create_file_from_string([
        'contextid' => $library ? \context_system::instance()->id : $context->id,
        'component' => 'mod_cmi5', 'filearea' => $library ? 'library_content' : 'content',
        'itemid' => $library ? $versionid : 0, 'filepath' => '/', 'filename' => 'player-manifest.json',
    ], '{"playerVersion":"test-current"}');
    return $cmid;
}

$failure = null;
try {
    $identifier = 'urn:rapidcmi5:adoption-test:' . bin2hex(random_bytes(8));
    $cmid = adoption_fixture($identifier);
    $before = $DB->get_record('cmi5', ['id' => get_coursemodule_from_id('cmi5', $cmid)->instance]);
    $deployment = \local_rapidcmi5\activity_manager::adopt($cmid);
    verify_adoption((int) $deployment->cmid === $cmid, 'Existing activity was not retained');
    verify_adoption($before == $DB->get_record('cmi5', ['id' => $before->id]), 'Activity was modified');
    verify_adoption((int) \local_rapidcmi5\activity_manager::adopt($cmid)->id === (int) $deployment->id,
        'Repeated adoption was not idempotent');
    $other = adoption_fixture($identifier);
    expect_adoption_error(fn() => \local_rapidcmi5\activity_manager::adopt($other), 'error:managedcourseconflict');

    $identifier .= ':existing';
    $existingcmid = adoption_fixture($identifier);
    $project = \local_rapidcmi5\project_manager::get_or_create_project($identifier, 'Keep project name')->project;
    $current = \local_rapidcmi5\project_manager::create_version($project->id, 'release-latest', $before->packageid);
    $adopted = \local_rapidcmi5\activity_manager::adopt($existingcmid);
    $after = \local_rapidcmi5\project_manager::get_project($project->id);
    verify_adoption((int) $adopted->projectid === (int) $project->id, 'Project was not reused');
    verify_adoption((int) $after->currentversionid === (int) $current->id, 'Current version was replaced');
    verify_adoption($after->name === 'Keep project name', 'Project was renamed');

    $legacy = adoption_fixture($identifier . ':legacy', false);
    $context = \context_module::instance($legacy);
    $temp = make_request_directory();
    file_put_contents($temp . '/cmi5.xml', '<courseStructure><course id="' . $identifier . ':legacy">' .
        '<title><langstring lang="en">Fixture</langstring></title></course></courseStructure>');
    $zip = get_file_packer('application/zip')->archive_to_storage(['cmi5.xml' => $temp . '/cmi5.xml'],
        $context->id, 'mod_cmi5', 'package', 0, '/', 'fixture.zip');
    verify_adoption((bool) $zip, 'Could not build test ZIP');
    $legacyinstance = get_coursemodule_from_id('cmi5', $legacy)->instance;
    $auid = $DB->insert_record('cmi5_aus', (object) ['cmi5id' => $legacyinstance,
        'auid' => 'urn:fixture:au', 'title' => 'AU', 'url' => 'index.html']);
    $registrationid = $DB->insert_record('cmi5_registrations', (object) ['cmi5id' => $legacyinstance,
        'userid' => $USER->id, 'registrationid' => '12345678-1234-1234-1234-123456789012', 'coursesatisfied' => 1]);
    $statusid = $DB->insert_record('cmi5_au_status', (object) ['registrationid' => $registrationid,
        'auid' => $auid, 'completed' => 1, 'passed' => 1, 'score_scaled' => 0.9]);
    $statusbefore = $DB->get_record('cmi5_au_status', ['id' => $statusid]);
    $adopted = \local_rapidcmi5\activity_manager::adopt($legacy);
    verify_adoption($statusbefore == $DB->get_record('cmi5_au_status', ['id' => $statusid]),
        'Learner progress changed');
    verify_adoption($DB->record_exists('cmi5_aus', ['id' => $auid, 'cmi5id' => $legacyinstance]),
        'AU identity changed');
    verify_adoption((int) $DB->get_field('cmi5_registrations', 'coursesatisfied', ['id' => $registrationid]) === 1,
        'Registration completion changed');
    $project = \local_rapidcmi5\project_manager::get_project($adopted->projectid);
    $package = $DB->get_record('cmi5_packages', ['id' => $project->currentpackageid], '*', MUST_EXIST);
    verify_adoption(\local_rapidcmi5\player_manager::detect_player_in_library($package->latestversion)->player_version
        === 'test-current', 'Snapshot did not retain current player content');
    verify_adoption(empty($DB->get_field('cmi5', 'packageversionid',
        ['id' => get_coursemodule_from_id('cmi5', $legacy)->instance])), 'Legacy activity was relinked');

    $invalid = adoption_fixture('');
    expect_adoption_error(fn() => \local_rapidcmi5\activity_manager::adopt($invalid), 'error:invalidprojectidentifier');
    $notrapid = adoption_fixture($identifier . ':notrapid', false);
    get_file_storage()->delete_area_files(\context_module::instance($notrapid)->id, 'mod_cmi5', 'content');
    expect_adoption_error(fn() => \local_rapidcmi5\activity_manager::adopt($notrapid), 'error:notrapidcmi5');
    \core\session\manager::set_user(guest_user());
    expect_adoption_error(fn() => \local_rapidcmi5\activity_manager::adopt($cmid), 'nopermissions');
    \core\session\manager::set_user(get_admin());

    $PAGE->set_context(\context_system::instance());
    $PAGE->set_url(new moodle_url('/local/rapidcmi5/unmanaged.php'));
    $renderdata = ['hasactivities' => true, 'activities' => [[
        'activityname' => 'Fixture', 'canmanage' => true, 'hasplayerversions' => false,
        'manageurl' => (new moodle_url('/local/rapidcmi5/adopt.php', ['cmid' => $cmid]))->out(false),
    ]]];
    $html = $OUTPUT->render_from_template('local_rapidcmi5/unmanaged_activities', $renderdata);
    verify_adoption(strpos($html, 'Add to managed projects') !== false,
        'Adoption action is missing when no player versions exist');
    $renderdata['activities'][0]['canmanage'] = false;
    $html = $OUTPUT->render_from_template('local_rapidcmi5/unmanaged_activities', $renderdata);
    verify_adoption(strpos($html, 'Add to managed projects') === false,
        'Adoption action shown without permission');

    // Last scenario deliberately rolls back a nested transaction after creating a project.
    $brokenidentifier = $identifier . ':broken';
    $broken = adoption_fixture($brokenidentifier, false);
    expect_adoption_error(fn() => \local_rapidcmi5\activity_manager::adopt($broken), 'packagenotfound');
} catch (\Throwable $e) {
    $failure = $e;
} finally {
    try {
        $transaction->rollback(new \RuntimeException('Roll back smoke-test fixtures'));
    } catch (\Throwable $ignored) {
        // rollback() rethrows the supplied exception after reverting the transaction.
    }
}
if ($failure) {
    fwrite(STDERR, $failure . "\n");
    exit(1);
}
verify_adoption(!$DB->record_exists('local_rapidcmi5_projects', ['identifier' => $brokenidentifier]),
    'Failed adoption left a project behind');
echo "PASS: {$checks} adoption checks; fixture database changes rolled back.\n";
