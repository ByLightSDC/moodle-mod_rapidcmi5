<?php
// This file is part of Moodle - http://moodle.org/
// Licensed under the GNU GPL v3 or later: http://www.gnu.org/copyleft/gpl.html.

/** Run on disposable local Moodle: php local/rapidcmi5/cli/version_upload_smoke.php --run. */
if (PHP_SAPI !== 'cli' || !in_array('--run', $argv ?? [], true)) {
    exit(1);
}
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
\core\session\manager::set_user(get_admin());
$transaction = $DB->start_delegated_transaction();
$checks = 0;
function verify_version($condition, string $message): void {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function expect_version_error(callable $action, string $code): void {
    try {
        $action();
    } catch (moodle_exception $e) {
        verify_version($e->errorcode === $code, 'Unexpected error: ' . $e->errorcode);
        return;
    }
    throw new RuntimeException('Expected error: ' . $code);
}
function version_draft(string $identifier, bool $rapid = true, bool $xml = true): int {
    global $USER;
    $dir = make_request_directory();
    $files = [];
    if ($xml) {
        file_put_contents($dir . '/cmi5.xml', '<courseStructure><course id="' . s($identifier) . '">' .
            '<title><langstring lang="en">Version test</langstring></title></course></courseStructure>');
        $files['cmi5.xml'] = $dir . '/cmi5.xml';
    }
    file_put_contents($dir . '/index.html', 'Revision ' . bin2hex(random_bytes(8)));
    $files['index.html'] = $dir . '/index.html';
    if ($rapid) {
        file_put_contents($dir . '/player-manifest.json', '{"playerVersion":"test"}');
        $files['player-manifest.json'] = $dir . '/player-manifest.json';
    }
    $draftid = file_get_unused_draft_itemid();
    $zip = get_file_packer('application/zip')->archive_to_storage($files, context_user::instance($USER->id)->id,
        'user', 'draft', $draftid, '/', 'revision.zip', $USER->id, false);
    if (!$zip) {
        throw new RuntimeException('Cannot build fixture');
    }
    return $draftid;
}
$failure = null;
try {
    $identifier = 'urn:rapidcmi5:version-test:' . bin2hex(random_bytes(8));
    $project = \local_rapidcmi5\project_manager::get_or_create_project($identifier, 'Version upload fixture')->project;
    $draftid = version_draft($identifier);
    $first = \local_rapidcmi5\version_uploader::upload($project->id, $draftid, '1.0', 'Initial notes');
    verify_version(!empty($first->libraryversionid), 'Exact library revision not stored');
    $firstlibrary = \local_rapidcmi5\project_manager::get_library_version($first);
    verify_version((int) $firstlibrary->packageid === (int) $first->packageid, 'Wrong library package');
    $hashless = clone $first;
    $hashless->libraryversionid = null;
    $hashless->sha256hash = null;
    verify_version(\local_rapidcmi5\project_manager::get_library_version($hashless) === false,
        'Hashless legacy revision resolved from the only revision in the package');
    $instanceid = $DB->insert_record('cmi5', (object) ['course' => $SITE->id, 'name' => 'Installed version',
        'packageid' => $first->packageid, 'packageversionid' => $first->libraryversionid]);
    $deploymentid = $DB->insert_record('local_rapidcmi5_deployments', (object) [
        'projectid' => $project->id, 'versionid' => $first->id, 'courseid' => $SITE->id,
        'cmid' => 0, 'timecreated' => time(), 'timemodified' => time()]);
    $second = \local_rapidcmi5\version_uploader::upload($project->id, version_draft($identifier), ' 2.0 ', 'Release notes');
    verify_version((int) $second->packageid === (int) $first->packageid, 'Created a separate package');
    verify_version((int) $second->libraryversionid !== (int) $first->libraryversionid, 'Reused the wrong revision');
    $updated = \local_rapidcmi5\project_manager::get_project($project->id);
    verify_version((int) $updated->currentversionid === (int) $second->id, 'Current version not advanced');
    verify_version($second->versionnumber === '2.0' && $second->releasenotes === 'Release notes', 'Metadata not saved');
    verify_version((int) $DB->get_field('cmi5', 'packageversionid', ['id' => $instanceid]) === (int) $first->libraryversionid,
        'Upload changed an installed activity');
    verify_version((int) $DB->get_field('local_rapidcmi5_deployments', 'versionid', ['id' => $deploymentid]) === (int) $first->id,
        'Upload changed a deployment');
    verify_version((int) \local_rapidcmi5\project_manager::get_library_version($first)->id === (int) $firstlibrary->id,
        'Old project version resolved to newer content');
    $legacy = clone $first;
    $legacy->libraryversionid = null;
    verify_version((int) \local_rapidcmi5\project_manager::get_library_version($legacy)->id === (int) $firstlibrary->id,
        'Legacy hash resolution failed');
    $legacy->sha256hash = null;
    verify_version(\local_rapidcmi5\project_manager::get_library_version($legacy) === false,
        'Ambiguous legacy revision silently resolved to latest');
    $duplicate = clone $firstlibrary;
    unset($duplicate->id);
    $duplicate->versionnumber = 1 + (int) $DB->get_field('cmi5_package_versions', 'MAX(versionnumber)',
        ['packageid' => $first->packageid]);
    $DB->insert_record('cmi5_package_versions', $duplicate);
    $legacy->sha256hash = $first->sha256hash;
    verify_version((int) \local_rapidcmi5\project_manager::get_library_version($legacy)->id === (int) $firstlibrary->id,
        'Identical re-upload made the legacy hash ambiguous');
    $deleted = clone $first;
    $deleted->libraryversionid = -1;
    verify_version(\local_rapidcmi5\project_manager::get_library_version($deleted) === false,
        'Missing stored revision was replaced by a hash match');
    $DB->delete_records('cmi5_package_versions', ['packageid' => $first->packageid,
        'versionnumber' => $duplicate->versionnumber]);
    $countbefore = $DB->count_records('cmi5_package_versions', ['packageid' => $first->packageid]);
    expect_version_error(fn() => \local_rapidcmi5\version_uploader::upload($project->id, $draftid, '2.0'), 'error:versionexists');
    expect_version_error(fn() => \local_rapidcmi5\version_uploader::upload($project->id, $draftid, ' '), 'error:invalidversionlabel');
    expect_version_error(fn() => \local_rapidcmi5\version_uploader::upload($project->id, $draftid, str_repeat('x', 65)),
        'error:invalidversionlabel');
    expect_version_error(fn() => \local_rapidcmi5\version_uploader::upload($project->id, version_draft('urn:other'), '3.0'),
        'error:projectmismatch');
    expect_version_error(fn() => \local_rapidcmi5\version_uploader::upload($project->id, version_draft($identifier, false), '3.0'),
        'error:notrapidpackage');
    expect_version_error(fn() => \local_rapidcmi5\version_uploader::upload($project->id, version_draft($identifier, true, false), '3.0'),
        'cmi5xmlnotfound');
    expect_version_error(fn() => \local_rapidcmi5\version_uploader::upload($project->id, file_get_unused_draft_itemid(), '3.0'),
        'error:singlepackage');
    $corruptid = file_get_unused_draft_itemid();
    get_file_storage()->create_file_from_string(['contextid' => context_user::instance($USER->id)->id,
        'component' => 'user', 'filearea' => 'draft', 'itemid' => $corruptid, 'filepath' => '/',
        'filename' => 'revision.zip'], 'not a zip archive');
    expect_version_error(fn() => \local_rapidcmi5\version_uploader::upload($project->id, $corruptid, '3.0'), 'error:invalidzip');
    verify_version($DB->count_records('cmi5_package_versions', ['packageid' => $first->packageid]) === $countbefore,
        'Rejected uploads left library revisions');
    verify_version((int) \local_rapidcmi5\project_manager::get_project($project->id)->currentversionid === (int) $second->id,
        'Rejected uploads changed current version');
    // Generic upload: the project comes from the package's cmi5.xml.
    $newidentifier = 'urn:rapidcmi5:version-test:' . bin2hex(random_bytes(8));
    $revisionsbefore = $DB->count_records('cmi5_package_versions');
    expect_version_error(fn() => \local_rapidcmi5\version_uploader::upload_package(version_draft($newidentifier, false), '1.0'),
        'error:notrapidpackage');
    verify_version(!$DB->record_exists('local_rapidcmi5_projects', ['identifier' => $newidentifier]) &&
        $DB->count_records('cmi5_package_versions') === $revisionsbefore, 'Rejected generic upload left records');
    $created = \local_rapidcmi5\version_uploader::upload_package(version_draft($newidentifier), ' 1.0 ', 'Notes',
        ['gitrepourl' => 'https://example.com/repo.git', 'commithash' => 'abc123']);
    verify_version($created->isnewproject && $created->project->identifier === $newidentifier &&
        $created->project->name === 'Version test', 'Project not created from cmi5.xml');
    verify_version($created->version->versionnumber === '1.0' && $created->version->commithash === 'abc123' &&
        !empty($created->version->libraryversionid), 'Generic version details not saved');
    $again = \local_rapidcmi5\version_uploader::upload_package(version_draft($newidentifier), '1.1');
    verify_version(!$again->isnewproject && (int) $again->project->id === (int) $created->project->id &&
        (int) $again->version->packageid === (int) $created->version->packageid &&
        $again->project->name === 'Version test', 'Second generic upload did not join the same project');
    $revisionsbefore = $DB->count_records('cmi5_package_versions');
    expect_version_error(fn() => \local_rapidcmi5\version_uploader::upload_package(version_draft($newidentifier), '1.1'),
        'error:versionexists');
    verify_version($DB->count_records('cmi5_package_versions') === $revisionsbefore, 'Duplicate label left a library revision');
    $service = \local_rapidcmi5\external\deploy_package::execute(version_draft($newidentifier), '', '', '2.0', 'def456', '',
        0, 'Service notes', [], 0);
    verify_version((int) $service['projectid'] === (int) $created->project->id && !$service['is_new_project'] &&
        $service['previous_version'] === '1.1', 'Web service did not use the package identifier');
    $results = \local_rapidcmi5\deployment_manager::deploy_to_courses($created->project->id, $created->version->id,
        (int) $created->version->libraryversionid, [999999999, 999999999], 'Fixture');
    verify_version(count($results) === 1 && $results[0]['status'] === 'error' && $results[0]['cmid'] === 0,
        'Deployment results not reported once per course');
    verify_version(\local_rapidcmi5\form\upload_package_form::parse_course_ids('12, 15,12,') === [12, 15] &&
        \local_rapidcmi5\form\upload_package_form::parse_course_ids('') === [] &&
        \local_rapidcmi5\form\upload_package_form::parse_course_ids('1.5') === null &&
        \local_rapidcmi5\form\upload_package_form::parse_course_ids('1e3') === null, 'Course ID parsing is loose');

    // Activities added from the mod_cmi5 activity library are tracked, and Deploy records each activity once.
    require_once($CFG->dirroot . '/course/lib.php');
    require_once($CFG->dirroot . '/course/modlib.php');
    $category = $DB->get_field_select('course_categories', 'id', '1 = 1', null, IGNORE_MULTIPLE);
    $librarycourse = create_course((object) ['fullname' => 'Tracking fixture', 'category' => $category,
        'shortname' => 'rcmi5-track-' . bin2hex(random_bytes(4))]);
    $libraryadd = fn(string $name) => add_moduleinfo((object) ['modulename' => 'cmi5', 'name' => $name,
        'module' => $DB->get_field('modules', 'id', ['name' => 'cmi5'], MUST_EXIST), 'course' => $librarycourse->id,
        'section' => 0, 'visible' => 1, 'visibleoncoursepage' => 1, 'packagesource' => 'library',
        'packageid' => $service['packageid'], 'profileid' => 0], $librarycourse)->coursemodule;
    $librarycmid = $libraryadd('Library add');
    $tracked = $DB->get_record('local_rapidcmi5_deployments', ['cmid' => $librarycmid]);
    verify_version($tracked && (int) $tracked->projectid === (int) $created->project->id &&
        (int) $tracked->versionid === (int) $service['versionid'], 'Activity library add was not tracked');
    $secondcmid = $libraryadd('Second library add');
    verify_version(!$DB->record_exists('local_rapidcmi5_deployments', ['cmid' => $secondcmid]),
        'Second activity for the same project and course was tracked');
    $deploycourse = create_course((object) ['fullname' => 'Deploy fixture', 'category' => $category,
        'shortname' => 'rcmi5-deploy-' . bin2hex(random_bytes(4))]);
    $deployed = \local_rapidcmi5\deployment_manager::deploy_to_course($created->project->id, $created->version->id,
        (int) $created->version->libraryversionid, $deploycourse->id, 'Deployed');
    $rows = $DB->get_records('local_rapidcmi5_deployments', ['cmid' => $deployed->cmid]);
    verify_version(count($rows) === 1 && (int) reset($rows)->versionid === (int) $created->version->id,
        'Deploy recorded the activity twice or at the wrong version');

    \core\session\manager::set_user(guest_user());
    expect_version_error(fn() => \local_rapidcmi5\version_uploader::upload($project->id, $draftid, '3.0'), 'nopermissions');
    \core\session\manager::set_user(get_admin());

    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/local/rapidcmi5/upload.php', ['projectid' => $project->id]));
    $form = new \local_rapidcmi5\form\upload_package_form(null, ['project' => $project]);
    $html = $form->render();
    verify_version(strpos($html, 'Upload new version') !== false, 'Upload form failed to render');
    verify_version(strpos($html, 'name="deploy_course_ids"') === false, 'Version form exposes automatic deployment');
    verify_version(!preg_match('/<input[^>]*name="project_identifier"/', $html), 'Project identifier is editable');
    $errors = $form->validation(['version' => '2.0', 'packagefile' => $draftid], []);
    verify_version(isset($errors['version']), 'Duplicate label not shown as a form error');
    $errors = $form->validation(['version' => '3.0', 'packagefile' => version_draft('urn:other')], []);
    verify_version(isset($errors['packagefile']), 'Wrong identifier not shown as a file error');
    $generic = new \local_rapidcmi5\form\upload_package_form();
    $generichtml = $generic->render();
    verify_version(strpos($generichtml, 'name="project_name"') !== false &&
        !preg_match('/<input[^>]*name="project_identifier"/', $generichtml), 'Generic form still asks for the identifier');
    $errors = $generic->validation(['version' => '1.0', 'packagefile' => version_draft($newidentifier, false),
        'deploy_course_ids' => '12, 1.5'], []);
    verify_version(isset($errors['deploy_course_ids']), 'Decimal course ID accepted');
    $errors = $generic->validation(['version' => '1.0', 'packagefile' => version_draft($newidentifier, false),
        'deploy_course_ids' => ''], []);
    verify_version(isset($errors['packagefile']), 'Generic form accepted a non-RapidCMI5 package');
    $_GET['id'] = $project->id;
    ob_start();
    try {
        require $CFG->dirroot . '/local/rapidcmi5/project.php';
        $html = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    verify_version(strpos($html, 'Upload new version') !== false && strpos($html, 'projectid=') !== false,
        'Project page does not link to the upload form');
    verify_version(strpos($html, 'Release notes') !== false, 'Project page does not display release notes');

} catch (Throwable $e) {
    $failure = $e;
} finally {
    try {
        $transaction->rollback(new RuntimeException('Remove version upload fixtures'));
    } catch (Throwable $ignored) {
    }
}
if ($failure) {
    fwrite(STDERR, $failure . "\n");
    exit(1);
}
echo "PASS: {$checks} version upload checks; fixture database changes rolled back.\n";
