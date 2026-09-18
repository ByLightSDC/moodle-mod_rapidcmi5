<?php
// This file is part of Moodle - http://moodle.org/
// Licensed under the GNU GPL v3 or later: http://www.gnu.org/copyleft/gpl.html.

/** Run on disposable local Moodle: php local/rapidcmi5/cli/course_browser_smoke.php --run. */
if (PHP_SAPI !== 'cli' || !in_array('--run', $argv ?? [], true)) {
    exit(1);
}
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
\core\session\manager::set_user(get_admin());
$transaction = $DB->start_delegated_transaction();
$checks = 0;
function verify_course_view($condition, string $message): void {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function course_view_fixture(string $name): int {
    global $DB, $SITE;
    $course = clone $SITE;
    unset($course->id);
    $course->fullname = $name;
    $course->shortname = $name;
    $course->idnumber = '';
    return $DB->insert_record('course', $course);
}
function course_view_activity(int $courseid, stdClass $version): int {
    global $DB;
    $instanceid = $DB->insert_record('cmi5', (object) ['course' => $courseid, 'name' => 'Installed activity']);
    $cmid = $DB->insert_record('course_modules', (object) ['course' => $courseid,
        'module' => $DB->get_field('modules', 'id', ['name' => 'cmi5'], MUST_EXIST), 'instance' => $instanceid]);
    $DB->insert_record('local_rapidcmi5_deployments', (object) ['projectid' => $version->projectid,
        'versionid' => $version->id, 'courseid' => $courseid, 'cmid' => $cmid,
        'timecreated' => time(), 'timemodified' => time()]);
    return $cmid;
}
$failure = null;
try {
    $prefix = 'Course-view-' . bin2hex(random_bytes(8));
    $coursea = course_view_fixture($prefix . '-A');
    $courseb = course_view_fixture($prefix . '-B');
    $emptycourse = course_view_fixture($prefix . '-Empty');
    $projecta = \local_rapidcmi5\project_manager::get_or_create_project('urn:' . $prefix . ':a', 'Shared project')->project;
    $projectb = \local_rapidcmi5\project_manager::get_or_create_project('urn:' . $prefix . ':b', 'Second project')->project;
    $old = \local_rapidcmi5\project_manager::create_version($projecta->id, '1.0', 0);
    $latest = \local_rapidcmi5\project_manager::create_version($projecta->id, '2.0', 0);
    $other = \local_rapidcmi5\project_manager::create_version($projectb->id, '1.0', 0);
    course_view_activity($coursea, $old);
    $secondcm = course_view_activity($coursea, $other);
    course_view_activity($courseb, $latest);
    $result = \local_rapidcmi5\course_browser::list_courses($prefix);
    verify_course_view((int) $result->total === 2, 'Empty course included or shared project course missing');
    verify_course_view((int) $result->courses[0]->projectcount === 2, 'Incorrect distinct project count');
    verify_course_view((int) $result->courses[0]->activitycount === 2, 'Incorrect activity count');
    $paged = \local_rapidcmi5\course_browser::list_courses($prefix, 1, 1);
    verify_course_view(count($paged->courses) === 1 && (int) $paged->courses[0]->id === $courseb && (int) $paged->total === 2,
        'Pagination changed totals or order');
    verify_course_view((int) \local_rapidcmi5\course_browser::list_courses($prefix . '%')->total === 0,
        'Search wildcard was not escaped');
    $DB->set_field('course', 'shortname', $prefix . '-SHORT', ['id' => $courseb]);
    verify_course_view((int) \local_rapidcmi5\course_browser::list_courses($prefix . '-SHORT')->total === 1,
        'Short-name search failed');
    $projects = \local_rapidcmi5\course_browser::get_projects($coursea);
    verify_course_view(count($projects) === 2, 'Course does not show both projects');
    $shared = array_values(array_filter($projects, fn($p) => $p['name'] === 'Shared project'))[0];
    verify_course_view($shared['latestversion'] === '2.0' && $shared['activities'][0]['versionnumber'] === '1.0',
        'Current and installed versions mixed up');
    verify_course_view($shared['activities'][0]['isoutdated'], 'Older installation not marked');
    $projects = \local_rapidcmi5\course_browser::get_projects($courseb);
    verify_course_view(count($projects) === 1 && !$projects[0]['activities'][0]['isoutdated'], 'Shared project missing or current flagged');
    verify_course_view(\local_rapidcmi5\course_browser::get_projects($emptycourse) === [], 'Empty course has projects');
    $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $secondcm]);
    verify_course_view(count(\local_rapidcmi5\course_browser::get_projects($coursea)) === 1, 'Deleting activity included');
    $DB->delete_records('course_modules', ['id' => $secondcm]);
    verify_course_view((int) \local_rapidcmi5\course_browser::list_courses($prefix)->courses[0]->projectcount === 1,
        'Stale deployment counted');
    $DB->delete_records('local_rapidcmi5_versions', ['id' => $old->id]);
    $unknown = \local_rapidcmi5\course_browser::get_projects($coursea)[0]['activities'][0];
    verify_course_view($unknown['status'] === get_string('unknownversion', 'local_rapidcmi5'), 'Missing version treated as current');
    \core\session\manager::set_user(guest_user());
    try {
        \local_rapidcmi5\course_browser::list_courses();
        throw new RuntimeException('Course listing allowed without management permission');
    } catch (required_capability_exception $e) {
        $checks++;
    }
    try {
        \local_rapidcmi5\course_browser::get_projects($coursea);
        throw new RuntimeException('Course detail allowed without management permission');
    } catch (required_capability_exception $e) {
        $checks++;
    }
    \core\session\manager::set_user(get_admin());
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/local/rapidcmi5/courses.php'));
    $html = $OUTPUT->render_from_template('local_rapidcmi5/courses', [
        'isdetail' => true, 'hasprojects' => true, 'projects' => \local_rapidcmi5\course_browser::get_projects($courseb)]);
    verify_course_view(strpos($html, 'Shared project') !== false && strpos($html, '/mod/cmi5/view.php') !== false,
        'Detail template links or project missing');
    $html = $OUTPUT->render_from_template('local_rapidcmi5/courses', ['isdetail' => true, 'hasprojects' => false]);
    verify_course_view(strpos($html, get_string('nocourseprojects', 'local_rapidcmi5')) !== false, 'Empty state missing');
    $_GET['search'] = $prefix;
    ob_start();
    try {
        require $CFG->dirroot . '/local/rapidcmi5/courses.php';
        $html = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    verify_course_view(strpos($html, $prefix . '-A') !== false && strpos($html, 'courseid=') !== false,
        'Course list page did not render');
} catch (Throwable $e) {
    $failure = $e;
} finally {
    try {
        $transaction->rollback(new RuntimeException('Remove course view fixtures'));
    } catch (Throwable $ignored) {
    }
}
if ($failure) {
    fwrite(STDERR, $failure . "\n");
    exit(1);
}
echo "PASS: {$checks} course view checks; fixture database changes rolled back.\n";
