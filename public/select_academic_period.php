<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

require_school_context();
$school = active_school_context();
if ($school === null) {
    http_response_code(403);
    exit('Choose a school workspace before selecting an academic period.');
}
$user = current_user();
$schoolRole = school_user_role_for_school((int) $user['id'], (int) $school['id']);
if (active_it_support_context($user) !== null
    || !in_array($schoolRole, ['school_headmaster', 'school_proprietor'], true)) {
    http_response_code(403);
    exit('Only the Headmaster or Proprietor can change the workspace academic period.');
}
$schoolId = (int) $school['id'];
$periodValue = (string) ($_GET['period'] ?? '');
if (preg_match('/^([1-9]\d*)\|(First Term|Second Term|Third Term)$/D', $periodValue, $matches) !== 1) {
    http_response_code(400);
    exit('Choose a valid academic year and standard term.');
}

$yearId = filter_var($matches[1], FILTER_VALIDATE_INT);
if ($yearId === false || $yearId < 1) {
    http_response_code(400);
    exit('Choose a valid academic year.');
}
$periodStmt = db()->prepare(
    'SELECT y.id
     FROM school_academic_years y
     JOIN school_terms t
       ON t.academic_year_id = y.id AND t.school_id = y.school_id
     WHERE y.id = ? AND y.school_id = ? AND t.term_name = ? LIMIT 1'
);
$periodStmt->execute([$yearId, $schoolId, $matches[2]]);
if (!$periodStmt->fetchColumn()) {
    http_response_code(403);
    exit('The selected academic period is not configured for this school.');
}

$_SESSION['school_page_academic_periods'][(string) $schoolId] = [
    'academic_year_id' => $yearId,
    'term_name' => $matches[2],
];

$returnRoute = basename((string) parse_url((string) ($_GET['return'] ?? ''), PHP_URL_PATH));
if ($returnRoute === '' || !is_file(APP_ROOT . '/public/' . $returnRoute)) {
    $returnRoute = 'school_dashboard.php';
}
$returnParams = [];
parse_str((string) ($_GET['return_query'] ?? ''), $returnParams);
foreach (['period', 'return', 'return_query', 'term_id', 'term_name', 'results_term', 'prepare_term', 'edit_term', 'edit_year'] as $contextKey) {
    unset($returnParams[$contextKey]);
}
$returnQuery = http_build_query($returnParams);
redirect($returnRoute . ($returnQuery === '' ? '' : '?' . $returnQuery));
