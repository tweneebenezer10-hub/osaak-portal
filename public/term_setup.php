<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_fees.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
$schoolRole = (string) ($user['active_school_role'] ?? school_user_role_for_school((int) $user['id'], $schoolId));
$canManageAcademicCalendar = active_it_support_context($user) === null
    && in_array($schoolRole, ['school_headmaster', 'school_proprietor'], true);
if (!$canManageAcademicCalendar && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(403);
    exit('Only the Headmaster or Proprietor can change the academic calendar.');
}
$termMilestoneColumns = [
    'vacation_starts_on',
    'vacation_ends_on',
    'reopening_on',
    'examination_starts_on',
    'examination_ends_on',
    'report_preparation_on',
    'report_publication_on',
];
$termMilestoneSchemaCheck = db()->prepare(
    'SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN (?, ?, ?, ?, ?, ?, ?)'
);
$termMilestoneSchemaCheck->execute(array_merge(['school_terms'], $termMilestoneColumns));
$termMilestoneSchemaReady = (int) $termMilestoneSchemaCheck->fetchColumn() === count($termMilestoneColumns);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_academic_calendar_wizard') {
        if (!$termMilestoneSchemaReady) {
            http_response_code(503);
            render_header('Academic calendar update required');
            echo '<section class="panel"><h1>Academic calendar update required</h1><p>Apply database migration 092 before using the guided calendar setup. Existing academic terms and records are not changed by this check.</p><a class="button" href="term_setup.php">Return to calendar setup</a></section>';
            render_footer();
            exit;
        }
        $yearName = trim((string) ($_POST['academic_year'] ?? ''));
        $startsOn = trim((string) ($_POST['year_starts_on'] ?? ''));
        $endsOn = trim((string) ($_POST['year_ends_on'] ?? ''));
        $starts = $_POST['term_starts_on'] ?? [];
        $ends = $_POST['term_ends_on'] ?? [];
        $milestonesByTerm = $_POST['term_details'] ?? [];
        $standardTerms = school_standard_term_names();
        $yearDates = school_academic_year_dates($startsOn, $endsOn);
        if (!school_academic_year_name_is_valid($yearName) || $yearDates === null
            || !school_academic_year_dates_match_label($yearName, $startsOn, $endsOn)
            || !is_array($starts) || !is_array($ends) || !is_array($milestonesByTerm)
            || ($_POST['confirm_calendar'] ?? '') !== '1') {
            flash('error', 'Enter a valid academic-year label and start/end dates before setting up the three terms.');
            redirect('term_setup.php#calendar-wizard');
        }
        $configuredTerms = [];
        foreach ($standardTerms as $termName) {
            $termStarts = trim((string) ($starts[$termName] ?? ''));
            $termEnds = trim((string) ($ends[$termName] ?? ''));
            $termDates = school_academic_year_dates($termStarts, $termEnds);
            if ($termDates === null || $termStarts < $startsOn || $termEnds > $endsOn) {
                flash('error', $termName . ' needs valid dates within the academic year.');
                redirect('term_setup.php#calendar-wizard');
            }
            $rawMilestones = $milestonesByTerm[$termName] ?? [];
            if (!is_array($rawMilestones)) {
                flash('error', 'Review the optional calendar dates entered for ' . $termName . '.');
                redirect('term_setup.php#calendar-wizard');
            }
            $milestones = [];
            foreach ($termMilestoneColumns as $milestoneName) {
                $rawDate = trim((string) ($rawMilestones[$milestoneName] ?? ''));
                if ($rawDate === '') {
                    $milestones[$milestoneName] = null;
                    continue;
                }
                $milestoneDate = DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
                $milestoneErrors = DateTimeImmutable::getLastErrors();
                if ($milestoneDate === false || $milestoneDate->format('Y-m-d') !== $rawDate
                    || $rawDate < $startsOn || $rawDate > $endsOn
                    || ($milestoneErrors !== false && ($milestoneErrors['warning_count'] > 0 || $milestoneErrors['error_count'] > 0))) {
                    flash('error', 'Optional ' . str_replace('_', ' ', $milestoneName) . ' date for ' . $termName . ' must be a valid date inside the academic year.');
                    redirect('term_setup.php#calendar-wizard');
                }
                $milestones[$milestoneName] = $rawDate;
            }
            if (($milestones['vacation_starts_on'] === null) !== ($milestones['vacation_ends_on'] === null)
                || ($milestones['vacation_starts_on'] !== null && $milestones['vacation_ends_on'] < $milestones['vacation_starts_on'])
                || ($milestones['reopening_on'] !== null && $milestones['vacation_ends_on'] !== null
                    && $milestones['reopening_on'] < $milestones['vacation_ends_on'])
                || (($milestones['examination_starts_on'] === null) !== ($milestones['examination_ends_on'] === null))
                || ($milestones['examination_starts_on'] !== null
                    && ($milestones['examination_starts_on'] < $termStarts
                        || $milestones['examination_ends_on'] > $termEnds
                        || $milestones['examination_ends_on'] < $milestones['examination_starts_on']))
                || ($milestones['report_preparation_on'] !== null && $milestones['report_publication_on'] !== null
                    && $milestones['report_publication_on'] < $milestones['report_preparation_on'])) {
                flash('error', 'Review the optional vacation, reopening, examination, and report dates for ' . $termName . '.');
                redirect('term_setup.php#calendar-wizard');
            }
            $configuredTerms[] = [
                'name' => $termName,
                'starts_on' => $termStarts,
                'ends_on' => $termEnds,
                'milestones' => $milestones,
            ];
        }
        $orderedTerms = $configuredTerms;
        usort($orderedTerms, static fn(array $left, array $right): int => $left['starts_on'] <=> $right['starts_on']);
        for ($index = 1; $index < count($orderedTerms); $index++) {
            if ($orderedTerms[$index]['starts_on'] <= $orderedTerms[$index - 1]['ends_on']) {
                flash('error', 'Term dates overlap. Adjust the dates; vacation gaps between terms are allowed.');
                redirect('term_setup.php#calendar-wizard');
            }
        }
        $duplicateYear = db()->prepare(
            'SELECT id FROM school_academic_years WHERE school_id = ? AND academic_year = ? LIMIT 1'
        );
        $duplicateYear->execute([$schoolId, $yearName]);
        if ($duplicateYear->fetchColumn()) {
            flash('error', 'That academic year already exists. Edit the existing year instead of creating a duplicate.');
            redirect('term_setup.php#calendar-wizard');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM schools WHERE id = ? FOR UPDATE')->execute([$schoolId]);
            $lockedDuplicate = $pdo->prepare(
                'SELECT id FROM school_academic_years
                 WHERE school_id = ? AND academic_year = ? LIMIT 1 FOR UPDATE'
            );
            $lockedDuplicate->execute([$schoolId, $yearName]);
            if ($lockedDuplicate->fetchColumn()) {
                throw new RuntimeException('That academic year was created by another administrator. Refresh and edit it instead.');
            }
            $overlapYear = $pdo->prepare(
                'SELECT academic_year FROM school_academic_years
                 WHERE school_id = ? AND starts_on IS NOT NULL AND ends_on IS NOT NULL
                   AND starts_on <= ? AND ends_on >= ? LIMIT 1'
            );
            $overlapYear->execute([$schoolId, $endsOn, $startsOn]);
            if ($overlapYear->fetchColumn()) {
                throw new RuntimeException('These academic-year dates overlap another saved year.');
            }
            $yearInsert = $pdo->prepare(
                'INSERT INTO school_academic_years
                    (school_id, academic_year, starts_on, ends_on, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $yearInsert->execute([$schoolId, $yearName, $startsOn, $endsOn, (int) $user['id'], (int) $user['id']]);
            $yearId = (int) $pdo->lastInsertId();
            $termInsert = $pdo->prepare(
                'INSERT INTO school_terms
                    (school_id, academic_year_id, academic_year, term_name, starts_on, ends_on,
                     vacation_starts_on, vacation_ends_on, reopening_on,
                     examination_starts_on, examination_ends_on,
                     report_preparation_on, report_publication_on)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($configuredTerms as $term) {
                $overlapTerm = $pdo->prepare(
                    'SELECT id FROM school_terms
                     WHERE school_id = ? AND starts_on <= ? AND ends_on >= ? LIMIT 1'
                );
                $overlapTerm->execute([$schoolId, $term['ends_on'], $term['starts_on']]);
                if ($overlapTerm->fetchColumn()) {
                    throw new RuntimeException('The ' . $term['name'] . ' dates overlap an existing term. No calendar changes were saved.');
                }
                $termInsert->execute([
                    $schoolId,
                    $yearId,
                    $yearName,
                    $term['name'],
                    $term['starts_on'],
                    $term['ends_on'],
                    $term['milestones']['vacation_starts_on'],
                    $term['milestones']['vacation_ends_on'],
                    $term['milestones']['reopening_on'],
                    $term['milestones']['examination_starts_on'],
                    $term['milestones']['examination_ends_on'],
                    $term['milestones']['report_preparation_on'],
                    $term['milestones']['report_publication_on'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Academic calendar wizard save failed: ' . $exception->getMessage());
            if ($exception instanceof PDOException) {
                throw $exception;
            }
            flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'The academic calendar could not be saved.');
            redirect('term_setup.php#calendar-wizard');
        }
        audit((int) $user['id'], 'school_academic_calendar_wizard_saved',
            'school_id=' . $schoolId . ';academic_year_id=' . $yearId . ';academic_year=' . $yearName);
        flash('success', 'Academic year and all three terms were saved. Review the calendar and set the intended current term when ready.');
        redirect('term_setup.php#academic-terms');
    }

    if ($action === 'save_academic_year') {
        $yearId = filter_var($_POST['academic_year_id'] ?? null, FILTER_VALIDATE_INT);
        $yearName = trim((string) ($_POST['academic_year'] ?? ''));
        $startsOn = trim((string) ($_POST['year_starts_on'] ?? ''));
        $endsOn = trim((string) ($_POST['year_ends_on'] ?? ''));
        $makeActive = ($_POST['set_active'] ?? '') === '1';
        $dates = school_academic_year_dates($startsOn, $endsOn);
        if ($dates === null) {
            flash('error', 'Enter valid academic-year start and end dates.');
            redirect($yearId !== false && $yearId > 0 ? 'term_setup.php?edit_year=' . $yearId : 'term_setup.php');
        }
        $existingYear = null;
        if ($yearId !== false && $yearId > 0) {
            $ownedYear = db()->prepare(
                'SELECT id, academic_year, status, is_active
                 FROM school_academic_years WHERE id = ? AND school_id = ? LIMIT 1'
            );
            $ownedYear->execute([$yearId, $schoolId]);
            $existingYear = $ownedYear->fetch();
            if (!$existingYear) {
                flash('error', 'The academic year does not belong to this school.');
                redirect('term_setup.php');
            }
        }
        if (!school_academic_year_name_is_valid($yearName)
            && ($existingYear === null || $existingYear['academic_year'] !== $yearName)) {
            flash('error', 'Enter an academic year such as 2026/2027. Existing legacy labels may be kept while their dates are reviewed.');
            redirect($existingYear === null ? 'term_setup.php' : 'term_setup.php?edit_year=' . $yearId);
        }
        if (school_academic_year_name_is_valid($yearName)
            && !school_academic_year_dates_match_label($yearName, $startsOn, $endsOn)) {
            flash('error', 'The academic-year start date must fall in its first year and the end date in its second year.');
            redirect($existingYear === null ? 'term_setup.php' : 'term_setup.php?edit_year=' . $yearId);
        }
        $yearLookup = db()->prepare('SELECT id FROM school_academic_years WHERE school_id = ? AND academic_year = ? LIMIT 1');
        $yearLookup->execute([$schoolId, $yearName]);
        $duplicateYearId = $yearLookup->fetchColumn();
        if ($duplicateYearId && ($existingYear === null || (int) $duplicateYearId !== (int) $existingYear['id'])) {
            flash('error', 'That academic year already exists. Edit the existing year instead.');
            redirect($existingYear === null ? 'term_setup.php' : 'term_setup.php?edit_year=' . $yearId);
        }
        $yearKey = (string) ($existingYear['academic_year'] ?? '');
        $overlap = db()->prepare(
            'SELECT academic_year FROM school_academic_years
             WHERE school_id = ? AND id <> ? AND starts_on IS NOT NULL AND ends_on IS NOT NULL
               AND starts_on <= ? AND ends_on >= ? LIMIT 1'
        );
        $overlap->execute([$schoolId, $yearId !== false && $yearId > 0 ? $yearId : 0, $endsOn, $startsOn]);
        if ($overlap->fetchColumn()) {
            flash('error', 'Academic-year dates cannot overlap another configured year.');
            redirect($yearId !== false && $yearId > 0 ? 'term_setup.php?edit_year=' . $yearId : 'term_setup.php');
        }
        if ($yearKey !== '' && $yearKey !== $yearName) {
            $referencesStmt = db()->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM school_terms WHERE school_id = ? AND academic_year = ?)
                  + (SELECT COUNT(*) FROM school_classes WHERE school_id = ? AND academic_year = ?)
                  + (SELECT COUNT(*) FROM school_fee_structures WHERE school_id = ? AND academic_year = ?)
                  + (SELECT COUNT(*) FROM school_invoices WHERE school_id = ? AND academic_year = ?)
                  + (SELECT COUNT(*) FROM student_graduations WHERE school_id = ? AND academic_year = ?)
                  + (SELECT COUNT(*) FROM school_completion_certificates WHERE school_id = ? AND academic_year = ?)
                  + (SELECT COUNT(*) FROM school_report_card_batches WHERE school_id = ? AND academic_year = ?)'
            );
            $referenceParams = [];
            for ($index = 0; $index < 7; $index++) {
                array_push($referenceParams, $schoolId, $yearKey);
            }
            $referencesStmt->execute($referenceParams);
            if ((int) $referencesStmt->fetchColumn() > 0) {
                flash('error', 'The year label is already used by historical records and cannot be renamed. Update its dates without changing the label.');
                redirect('term_setup.php?edit_year=' . $yearId);
            }
        }
        if ($yearKey !== '') {
            $termBounds = db()->prepare('SELECT MIN(starts_on), MAX(ends_on) FROM school_terms WHERE school_id = ? AND academic_year_id = ?');
            $termBounds->execute([$schoolId, $yearId]);
            [$firstTerm, $lastTerm] = $termBounds->fetch(PDO::FETCH_NUM);
            if (($firstTerm !== null && $startsOn > $firstTerm) || ($lastTerm !== null && $endsOn < $lastTerm)) {
                flash('error', 'The academic-year dates must contain all of its saved terms.');
                redirect('term_setup.php?edit_year=' . $yearId);
            }
        }
        if ($makeActive && ($existingYear['status'] ?? 'open') !== 'open') {
            flash('error', 'A closed academic year cannot be made active.');
            redirect('term_setup.php?edit_year=' . $yearId);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM schools WHERE id = ? FOR UPDATE')->execute([$schoolId]);
            if ($existingYear !== null) {
                $lockedYear = $pdo->prepare(
                    'SELECT status FROM school_academic_years WHERE id = ? AND school_id = ? FOR UPDATE'
                );
                $lockedYear->execute([$yearId, $schoolId]);
                $currentYearStatus = $lockedYear->fetchColumn();
                if ($currentYearStatus === false) {
                    throw new RuntimeException('The academic year no longer belongs to this school.');
                }
                if ($makeActive && $currentYearStatus !== 'open') {
                    throw new RuntimeException('A closed academic year cannot be made active.');
                }
            }
            $lockedDuplicate = $pdo->prepare(
                'SELECT id FROM school_academic_years WHERE school_id = ? AND academic_year = ? AND id <> ? LIMIT 1'
            );
            $lockedDuplicate->execute([$schoolId, $yearName, $yearId !== false && $yearId > 0 ? $yearId : 0]);
            if ($lockedDuplicate->fetchColumn()) {
                throw new RuntimeException('That academic year was created by another administrator. Refresh and edit the existing year.');
            }
            $lockedOverlap = $pdo->prepare(
                'SELECT academic_year FROM school_academic_years
                 WHERE school_id = ? AND id <> ? AND starts_on IS NOT NULL AND ends_on IS NOT NULL
                   AND starts_on <= ? AND ends_on >= ? LIMIT 1'
            );
            $lockedOverlap->execute([$schoolId, $yearId !== false && $yearId > 0 ? $yearId : 0, $endsOn, $startsOn]);
            if ($lockedOverlap->fetchColumn()) {
                throw new RuntimeException('These dates now overlap another academic year. Refresh and review the calendar.');
            }
            if ($yearKey !== '') {
                $lockedTermBounds = $pdo->prepare(
                    'SELECT MIN(starts_on), MAX(ends_on) FROM school_terms WHERE school_id = ? AND academic_year_id = ?'
                );
                $lockedTermBounds->execute([$schoolId, $yearId]);
                [$firstLockedTerm, $lastLockedTerm] = $lockedTermBounds->fetch(PDO::FETCH_NUM);
                if (($firstLockedTerm !== null && $startsOn > $firstLockedTerm)
                    || ($lastLockedTerm !== null && $endsOn < $lastLockedTerm)) {
                    throw new RuntimeException('The academic-year dates must contain all of its saved terms.');
                }
            }
            if ($yearId !== false && $yearId > 0) {
                $saveYear = $pdo->prepare(
                    'UPDATE school_academic_years SET academic_year = ?, starts_on = ?, ends_on = ?, updated_by = ?
                     WHERE id = ? AND school_id = ?'
                );
                $saveYear->execute([$yearName, $startsOn, $endsOn, (int) $user['id'], $yearId, $schoolId]);
            } else {
                $saveYear = $pdo->prepare(
                    'INSERT INTO school_academic_years
                     (school_id, academic_year, starts_on, ends_on, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $saveYear->execute([$schoolId, $yearName, $startsOn, $endsOn, (int) $user['id'], (int) $user['id']]);
                $yearId = (int) $pdo->lastInsertId();
            }
            if ($makeActive) {
                $pdo->prepare('UPDATE school_academic_years SET is_active = 0 WHERE school_id = ?')->execute([$schoolId]);
                $pdo->prepare('UPDATE school_terms SET is_current = 0 WHERE school_id = ?')->execute([$schoolId]);
                $pdo->prepare('UPDATE school_academic_years SET is_active = 1 WHERE id = ? AND school_id = ? AND status = "open"')
                    ->execute([$yearId, $schoolId]);
            } elseif ($existingYear !== null && (int) $existingYear['is_active'] === 1) {
                $pdo->prepare('UPDATE school_academic_years SET is_active = 0 WHERE id = ? AND school_id = ?')->execute([$yearId, $schoolId]);
                $pdo->prepare('UPDATE school_terms SET is_current = 0 WHERE school_id = ? AND academic_year = ?')->execute([$schoolId, $yearKey]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Academic year save failed: ' . $exception->getMessage());
            flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'The academic year could not be saved.');
            redirect($yearId !== false && $yearId > 0 ? 'term_setup.php?edit_year=' . $yearId : 'term_setup.php');
        }
        audit((int) $user['id'], 'school_academic_year_saved', 'school_id=' . $schoolId . ';academic_year=' . $yearName . ';active=' . ($makeActive ? 'yes' : 'no'));
        flash('success', 'Academic year saved.');
        redirect('term_setup.php');
    }

    if ($action === 'activate_academic_year' || $action === 'close_academic_year') {
        $yearId = filter_var($_POST['academic_year_id'] ?? null, FILTER_VALIDATE_INT);
        $yearStmt = db()->prepare('SELECT id, academic_year, starts_on, ends_on, status FROM school_academic_years WHERE id = ? AND school_id = ? LIMIT 1');
        $yearStmt->execute([$yearId, $schoolId]);
        $year = $yearStmt->fetch();
        if (!$year || ($action === 'activate_academic_year' && ($year['status'] !== 'open' || !$year['starts_on'] || !$year['ends_on']))) {
            flash('error', 'Select a configured, open academic year belonging to this school.');
            redirect('term_setup.php');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM schools WHERE id = ? FOR UPDATE')->execute([$schoolId]);
            if ($action === 'activate_academic_year') {
                $lockedYear = $pdo->prepare(
                    'SELECT status, starts_on, ends_on FROM school_academic_years WHERE id = ? AND school_id = ? FOR UPDATE'
                );
                $lockedYear->execute([$yearId, $schoolId]);
                $currentYear = $lockedYear->fetch();
                if (!$currentYear || $currentYear['status'] !== 'open' || !$currentYear['starts_on'] || !$currentYear['ends_on']) {
                    throw new RuntimeException('The selected academic year is no longer open and configured.');
                }
                $pdo->prepare('UPDATE school_academic_years SET is_active = 0 WHERE school_id = ?')->execute([$schoolId]);
                $pdo->prepare('UPDATE school_terms SET is_current = 0 WHERE school_id = ?')->execute([$schoolId]);
                $pdo->prepare('UPDATE school_academic_years SET is_active = 1, updated_by = ? WHERE id = ? AND school_id = ?')
                    ->execute([(int) $user['id'], $yearId, $schoolId]);
            } else {
                $pdo->prepare('UPDATE school_academic_years SET status = "closed", is_active = 0, updated_by = ? WHERE id = ? AND school_id = ?')
                    ->execute([(int) $user['id'], $yearId, $schoolId]);
                $pdo->prepare('UPDATE school_terms SET is_current = 0 WHERE school_id = ? AND academic_year_id = ?')
                    ->execute([$schoolId, $yearId]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Academic year state change failed: ' . $exception->getMessage());
            flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'The academic year state could not be changed.');
            redirect('term_setup.php');
        }
        audit((int) $user['id'], $action, 'school_id=' . $schoolId . ';academic_year=' . $year['academic_year']);
        flash('success', $action === 'activate_academic_year' ? 'Active academic year updated. Choose a current term for this year.' : 'Academic year closed; its historical records were retained.');
        redirect('term_setup.php');
    }

    if ($action === 'save_term') {
        $termId = filter_var($_POST['term_id'] ?? null, FILTER_VALIDATE_INT);
        $yearId = filter_var($_POST['academic_year_id'] ?? null, FILTER_VALIDATE_INT);
        $termName = trim((string) ($_POST['term_name'] ?? ''));
        $startsOn = trim((string) ($_POST['starts_on'] ?? ''));
        $endsOn = trim((string) ($_POST['ends_on'] ?? ''));
        $isCurrent = ($_POST['set_current'] ?? '') === '1';
        $dates = school_academic_year_dates($startsOn, $endsOn);
        if ($isCurrent && ($_POST['confirm_current_term'] ?? '') !== '1') {
            flash('error', 'Confirm the school, academic year, and term dates before making this the current term.');
            redirect($termId !== false && $termId > 0 ? 'term_setup.php?edit_term=' . $termId : 'term_setup.php');
        }
        $yearStmt = db()->prepare(
            'SELECT id, academic_year, starts_on, ends_on, status FROM school_academic_years
             WHERE id = ? AND school_id = ? LIMIT 1'
        );
        $yearStmt->execute([$yearId, $schoolId]);
        $parentYear = $yearStmt->fetch();
        if ($yearId === false || $yearId < 1 || !$parentYear) {
            flash('error', 'The selected academic-year ID was not found in this school. Choose one of this school’s saved academic years.');
            redirect($termId !== false && $termId > 0 ? 'term_setup.php?edit_term=' . $termId : 'term_setup.php');
        }
        $year = (string) ($parentYear['academic_year'] ?? '');
        if ($parentYear['status'] !== 'open' || !$parentYear['starts_on'] || !$parentYear['ends_on']
            || $termName === '' || strlen($termName) > 50 || $dates === null
            || $startsOn < (string) ($parentYear['starts_on'] ?? '') || $endsOn > (string) ($parentYear['ends_on'] ?? '')) {
            flash('error', 'Choose an open academic year belonging to this school, a term name, and dates contained within that year.');
            redirect($termId !== false && $termId > 0 ? 'term_setup.php?edit_term=' . $termId : 'term_setup.php');
        }
        $existingTerm = null;
        if ($termId !== false && $termId > 0) {
            $existingTermStmt = db()->prepare(
                'SELECT t.academic_year_id, t.academic_year, t.term_name, y.status
                 FROM school_terms t JOIN school_academic_years y
                   ON y.id = t.academic_year_id AND y.school_id = t.school_id
                 WHERE t.id = ? AND t.school_id = ? LIMIT 1'
            );
            $existingTermStmt->execute([$termId, $schoolId]);
            $existingTerm = $existingTermStmt->fetch();
            if (!$existingTerm || $existingTerm['status'] !== 'open') {
                flash('error', 'Only terms in an open academic year can be edited.');
                redirect('term_setup.php');
            }
            if ((int) $existingTerm['academic_year_id'] !== (int) $yearId) {
                flash('error', 'An existing term cannot be moved to a different academic year. Create a new term under the selected year instead.');
                redirect('term_setup.php?edit_term=' . $termId);
            }
        }
        $standardTermNames = ['First Term', 'Second Term', 'Third Term'];
        if (!in_array($termName, $standardTermNames, true)
            && ($existingTerm === null || $existingTerm['term_name'] !== $termName)) {
            flash('error', 'New academic years use First Term, Second Term, and Third Term. Existing historical term names may be retained.');
            redirect($termId !== false && $termId > 0 ? 'term_setup.php?edit_term=' . $termId : 'term_setup.php');
        }
        if ($existingTerm !== null && $existingTerm['term_name'] !== $termName) {
            $termReferences = db()->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM school_fee_structures WHERE school_id = ? AND academic_year = ? AND period_name = ?)
                  + (SELECT COUNT(*) FROM school_invoices WHERE school_id = ? AND academic_year = ? AND period_name = ?)
                  + (SELECT COUNT(*) FROM school_assessments a JOIN school_classes c
                     ON c.id = a.class_id AND c.school_id = a.school_id
                     WHERE a.school_id = ? AND c.academic_year = ? AND a.term_name = ?)
                  + (SELECT COUNT(*) FROM school_report_card_batches WHERE school_id = ? AND academic_year = ? AND term_name = ?)'
            );
            $termReferences->execute([
                $schoolId, $existingTerm['academic_year'], $existingTerm['term_name'],
                $schoolId, $existingTerm['academic_year'], $existingTerm['term_name'],
                $schoolId, $existingTerm['academic_year'], $existingTerm['term_name'],
                $schoolId, $existingTerm['academic_year'], $existingTerm['term_name'],
            ]);
            if ((int) $termReferences->fetchColumn() > 0) {
                flash('error', 'This term name is referenced by saved academic records and cannot be changed. Its dates may still be corrected.');
                redirect('term_setup.php?edit_term=' . $termId);
            }
        }
        $overlap = db()->prepare(
            'SELECT id FROM school_terms
             WHERE school_id = ? AND id <> ? AND starts_on <= ? AND ends_on >= ? LIMIT 1'
        );
        $overlap->execute([$schoolId, $termId !== false && $termId > 0 ? $termId : 0, $endsOn, $startsOn]);
        if ($overlap->fetchColumn()) {
            flash('error', 'Term dates cannot overlap another term in this school.');
            redirect($termId !== false && $termId > 0 ? 'term_setup.php?edit_term=' . $termId : 'term_setup.php');
        }
        $duplicate = db()->prepare('SELECT id FROM school_terms WHERE school_id = ? AND academic_year_id = ? AND term_name = ? LIMIT 1');
        $duplicate->execute([$schoolId, $yearId, $termName]);
        $duplicateId = $duplicate->fetchColumn();
        if ($duplicateId && ($termId === false || $termId < 1 || (int) $duplicateId !== $termId)) {
            flash('error', 'This term name already exists in the selected academic year.');
            redirect($termId !== false && $termId > 0 ? 'term_setup.php?edit_term=' . $termId : 'term_setup.php');
        }
        $pdo = db();
        $pdo->beginTransaction();
        $assessment = null;
        try {
            $pdo->prepare('SELECT id FROM schools WHERE id = ? FOR UPDATE')->execute([$schoolId]);
            if ($termId !== false && $termId > 0) {
                $lockedExistingTerm = $pdo->prepare(
                    'SELECT y.status FROM school_terms t JOIN school_academic_years y
                       ON y.id = t.academic_year_id AND y.school_id = t.school_id
                     WHERE t.id = ? AND t.school_id = ? FOR UPDATE'
                );
                $lockedExistingTerm->execute([$termId, $schoolId]);
                if ($lockedExistingTerm->fetchColumn() !== 'open') {
                    throw new RuntimeException('Only terms in an open academic year can be edited.');
                }
            }
            $lockedYear = $pdo->prepare(
                'SELECT id, starts_on, ends_on, status FROM school_academic_years
                 WHERE id = ? AND school_id = ? FOR UPDATE'
            );
            $lockedYear->execute([$yearId, $schoolId]);
            $currentParentYear = $lockedYear->fetch();
            if (!$currentParentYear || $currentParentYear['status'] !== 'open'
                || !$currentParentYear['starts_on'] || !$currentParentYear['ends_on']
                || $startsOn < $currentParentYear['starts_on'] || $endsOn > $currentParentYear['ends_on']) {
                throw new RuntimeException('The academic year changed while this term was being saved. Refresh and verify the year dates.');
            }
            $lockedOverlap = $pdo->prepare(
                'SELECT id FROM school_terms
                 WHERE school_id = ? AND id <> ? AND starts_on <= ? AND ends_on >= ? LIMIT 1'
            );
            $lockedOverlap->execute([$schoolId, $termId !== false && $termId > 0 ? $termId : 0, $endsOn, $startsOn]);
            if ($lockedOverlap->fetchColumn()) {
                throw new RuntimeException('These term dates now overlap another term. Refresh and review the calendar.');
            }
            $lockedDuplicate = $pdo->prepare(
                'SELECT id FROM school_terms WHERE school_id = ? AND academic_year_id = ? AND term_name = ? LIMIT 1'
            );
            $lockedDuplicate->execute([$schoolId, $yearId, $termName]);
            $lockedDuplicateId = $lockedDuplicate->fetchColumn();
            if ($lockedDuplicateId && ($termId === false || $termId < 1 || (int) $lockedDuplicateId !== $termId)) {
                throw new RuntimeException('This term name was added by another administrator. Refresh the calendar.');
            }
            if ($termId !== false && $termId > 0) {
                $ownedTerm = $pdo->prepare('SELECT id FROM school_terms WHERE id = ? AND school_id = ? FOR UPDATE');
                $ownedTerm->execute([$termId, $schoolId]);
                if (!$ownedTerm->fetchColumn()) {
                    throw new RuntimeException('The term does not belong to this school.');
                }
                $pdo->prepare('UPDATE school_terms SET academic_year_id = ?, academic_year = ?, term_name = ?, starts_on = ?, ends_on = ?, is_current = 0 WHERE id = ? AND school_id = ?')
                    ->execute([$yearId, $year, $termName, $startsOn, $endsOn, $termId, $schoolId]);
            } else {
                $pdo->prepare('INSERT INTO school_terms (school_id, academic_year_id, academic_year, term_name, starts_on, ends_on, is_current) VALUES (?, ?, ?, ?, ?, ?, 0)')
                    ->execute([$schoolId, $yearId, $year, $termName, $startsOn, $endsOn]);
                $termId = (int) $pdo->lastInsertId();
            }
            if ($isCurrent) {
                $pdo->prepare('UPDATE school_academic_years SET is_active = 0 WHERE school_id = ?')->execute([$schoolId]);
                $pdo->prepare('UPDATE school_terms SET is_current = 0 WHERE school_id = ?')->execute([$schoolId]);
                $pdo->prepare('UPDATE school_academic_years SET is_active = 1, updated_by = ? WHERE id = ? AND school_id = ?')
                    ->execute([(int) $user['id'], (int) $parentYear['id'], $schoolId]);
                $pdo->prepare('UPDATE school_terms SET is_current = 1 WHERE id = ? AND school_id = ?')
                    ->execute([$termId, $schoolId]);
                $assessment = school_assess_term_invoices($schoolId, (int) $termId, null, (int) $user['id']);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Academic term save failed: ' . $exception->getMessage());
            flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'The term could not be saved.');
            redirect($termId !== false && $termId > 0 ? 'term_setup.php?edit_term=' . $termId : 'term_setup.php');
        }
        audit((int) $user['id'], 'school_academic_term_saved', 'school_id=' . $schoolId . ';term_id=' . $termId . ';academic_year=' . $year . ';current=' . ($isCurrent ? 'yes' : 'no'));
        if ($assessment !== null) {
            foreach ($assessment['created_invoices'] as [$studentId, $invoiceId]) {
                school_fee_invoice_parent_notice(
                    $schoolId,
                    $studentId,
                    'School invoice issued',
                    'A new invoice has been issued for ' . $termName . ' ' . $year . '.',
                    $invoiceId
                );
            }
            audit((int) $user['id'], 'school_period_billing_generated', 'school_id=' . $schoolId . ';term_id=' . $termId . ';created=' . $assessment['created'] . ';existing=' . $assessment['existing'] . ';missing_schedule=' . $assessment['without_schedule']);
            $message = !$assessment['supported']
                ? 'Academic term saved and activated. Fee assessment was skipped; only First Term, Second Term, and Third Term are supported.'
                : 'Academic term saved and activated. ' . $assessment['created'] . ' invoices assessed; '
                    . $assessment['existing'] . ' already existed; '
                    . ($assessment['without_class_or_year'] + $assessment['without_schedule'] + $assessment['not_yet_enrolled'])
                    . ' students were skipped for enrollment, class/year, or fee-schedule review.';
        } else {
            $message = 'Academic term saved.';
        }
        flash('success', $message);
        redirect('term_setup.php');
    }

    if ($action === 'set_current_term') {
        $termId = filter_var($_POST['term_id'] ?? null, FILTER_VALIDATE_INT);
        if (($_POST['confirm_current_term'] ?? '') !== '1') {
            flash('error', 'Review the school and term details, then confirm before changing the current term.');
            redirect('term_setup.php');
        }
        $termStmt = db()->prepare(
            'SELECT t.academic_year, t.term_name, y.id AS academic_year_id, y.status, y.starts_on, y.ends_on
             FROM school_terms t JOIN school_academic_years y
               ON y.id = t.academic_year_id AND y.school_id = t.school_id
             WHERE t.id = ? AND t.school_id = ?
               AND t.starts_on >= y.starts_on AND t.ends_on <= y.ends_on
             LIMIT 1'
        );
        $termStmt->execute([$termId, $schoolId]);
        $term = $termStmt->fetch();
        if (!$term || $term['status'] !== 'open' || !$term['starts_on'] || !$term['ends_on']) {
            flash('error', 'The selected term is not in a configured, open academic year for this school.');
            redirect('term_setup.php');
        }
        $pdo = db();
        $pdo->beginTransaction();
        $assessment = null;
        try {
            $pdo->prepare('SELECT id FROM schools WHERE id = ? FOR UPDATE')->execute([$schoolId]);
            $lockedTerm = $pdo->prepare(
                'SELECT t.academic_year, y.id AS academic_year_id, y.status, y.starts_on, y.ends_on
                 FROM school_terms t JOIN school_academic_years y
                   ON y.id = t.academic_year_id AND y.school_id = t.school_id
                 WHERE t.id = ? AND t.school_id = ?
                   AND t.starts_on >= y.starts_on AND t.ends_on <= y.ends_on
                 FOR UPDATE'
            );
            $lockedTerm->execute([$termId, $schoolId]);
            $currentTerm = $lockedTerm->fetch();
            if (!$currentTerm || $currentTerm['status'] !== 'open'
                || !$currentTerm['starts_on'] || !$currentTerm['ends_on']) {
                throw new RuntimeException('The selected term is no longer in a configured, open academic year.');
            }
            $pdo->prepare('UPDATE school_terms SET is_current = 0 WHERE school_id = ?')->execute([$schoolId]);
            $pdo->prepare('UPDATE school_academic_years SET is_active = 0 WHERE school_id = ?')->execute([$schoolId]);
            $pdo->prepare('UPDATE school_academic_years SET is_active = 1, updated_by = ? WHERE id = ? AND school_id = ?')
                ->execute([(int) $user['id'], (int) $currentTerm['academic_year_id'], $schoolId]);
            $pdo->prepare('UPDATE school_terms SET is_current = 1 WHERE id = ? AND school_id = ?')
                ->execute([$termId, $schoolId]);
            $assessment = school_assess_term_invoices($schoolId, (int) $termId, null, (int) $user['id']);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Current term update failed: ' . $exception->getMessage());
            flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'The current term could not be changed.');
            redirect('term_setup.php');
        }
        audit((int) $user['id'], 'school_current_term_changed', 'school_id=' . $schoolId . ';term_id=' . $termId . ';academic_year=' . $term['academic_year']);
        foreach ($assessment['created_invoices'] as [$studentId, $invoiceId]) {
            school_fee_invoice_parent_notice(
                $schoolId,
                $studentId,
                'School invoice issued',
                'A new invoice has been issued for ' . $term['term_name'] . ' ' . $term['academic_year'] . '.',
                $invoiceId
            );
        }
        audit((int) $user['id'], 'school_period_billing_generated', 'school_id=' . $schoolId . ';term_id=' . $termId . ';created=' . $assessment['created'] . ';existing=' . $assessment['existing'] . ';missing_schedule=' . $assessment['without_schedule']);
        if (!$assessment['supported']) {
            flash('success', 'Current term and academic year updated. Fee assessment was skipped; only First Term, Second Term, and Third Term are supported.');
        } else {
            flash('success', 'Current term updated. ' . $assessment['created'] . ' invoices assessed; '
                . $assessment['existing'] . ' already existed; '
                . ($assessment['without_class_or_year'] + $assessment['without_schedule'] + $assessment['not_yet_enrolled'])
                . ' students were skipped for enrollment, class/year, or fee-schedule review.');
        }
        redirect('term_setup.php');
    }

    if ($action === 'save_calendar_day') {
        $calendarDate = trim((string) ($_POST['calendar_date'] ?? ''));
        $dayType = (string) ($_POST['day_type'] ?? '');
        $label = trim((string) ($_POST['label'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $calendarDate);
        if (!$date || $date->format('Y-m-d') !== $calendarDate
            || !in_array($dayType, ['holiday', 'closure', 'school_day'], true)
            || preg_match('/^.{1,120}$/us', $label) !== 1) {
            flash('error', 'Enter a valid calendar date, day type, and description (up to 120 characters).');
            redirect('term_setup.php');
        }
        $existingDayStmt = db()->prepare(
            'SELECT day_type FROM school_calendar_days WHERE school_id = ? AND calendar_date = ? LIMIT 1'
        );
        $existingDayStmt->execute([$schoolId, $calendarDate]);
        $existingDayType = $existingDayStmt->fetchColumn();
        $attendanceCountStmt = db()->prepare(
            'SELECT (SELECT COUNT(*) FROM student_attendance WHERE school_id = ? AND attendance_date = ?)
                 + (SELECT COUNT(*) FROM school_staff_attendance WHERE school_id = ? AND attendance_date = ?)'
        );
        $attendanceCountStmt->execute([$schoolId, $calendarDate, $schoolId, $calendarDate]);
        $conflictingAttendance = (int) $attendanceCountStmt->fetchColumn();
        $conflictReason = trim((string) ($_POST['attendance_conflict_reason'] ?? ''));
        if ($conflictingAttendance > 0 && $existingDayType !== $dayType
            && (($_POST['confirm_attendance_conflict'] ?? '') !== '1' || $conflictReason === '' || strlen($conflictReason) > 500)) {
            flash('error', 'Attendance already exists on this date. Confirm that it will be preserved and provide a review reason before changing the school-day status.');
            redirect('term_setup.php');
        }
        db()->prepare(
            'INSERT INTO school_calendar_days (school_id, calendar_date, day_type, label, created_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE day_type = VALUES(day_type), label = VALUES(label), created_by = VALUES(created_by)'
        )->execute([$schoolId, $calendarDate, $dayType, $label, (int) $user['id']]);
        audit((int) $user['id'], 'school_calendar_day_saved', 'school_id=' . $schoolId . ';date=' . $calendarDate . ';type=' . $dayType . ';conflicting_records=' . $conflictingAttendance . ';reason=' . $conflictReason);
        flash('success', 'School calendar day saved.');
        redirect('term_setup.php');
    }

    if ($action === 'save_attendance_weekdays') {
        $weekdays = $_POST['attendance_weekdays'] ?? [];
        $weekdays = is_array($weekdays)
            ? array_values(array_unique(array_filter(
                array_map('intval', $weekdays),
                static fn (int $weekday): bool => $weekday >= 1 && $weekday <= 7
            )))
            : [];
        sort($weekdays);
        if ($weekdays === []) {
            flash('error', 'Select at least one regular school day.');
            redirect('term_setup.php#school-calendar');
        }
        $settingStmt = db()->prepare(
            'INSERT INTO school_settings (school_id, setting_key, setting_value, updated_by)
             VALUES (?, "attendance_working_weekdays", ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                 updated_by = VALUES(updated_by), updated_at = NOW()'
        );
        $settingStmt->execute([$schoolId, json_encode($weekdays, JSON_THROW_ON_ERROR), (int) $user['id']]);
        audit((int) $user['id'], 'school_attendance_workdays_changed', 'school_id=' . $schoolId . ';weekdays=' . implode(',', $weekdays));
        flash('success', 'Regular attendance school days updated. Existing records were preserved and totals now use the updated calendar.');
        redirect('term_setup.php#school-calendar');
    }

    if ($action === 'delete_calendar_day') {
        $calendarDayId = filter_var($_POST['calendar_day_id'] ?? null, FILTER_VALIDATE_INT);
        if ($calendarDayId === false || $calendarDayId < 1) {
            flash('error', 'Choose a valid calendar entry to remove.');
            redirect('term_setup.php');
        }
        $calendarLookup = db()->prepare('SELECT calendar_date, day_type FROM school_calendar_days WHERE id = ? AND school_id = ? LIMIT 1');
        $calendarLookup->execute([$calendarDayId, $schoolId]);
        $calendarRecord = $calendarLookup->fetch();
        if (!$calendarRecord) {
            flash('error', 'The calendar entry was not found in this school.');
            redirect('term_setup.php');
        }
        $attendanceCountStmt = db()->prepare(
            'SELECT (SELECT COUNT(*) FROM student_attendance WHERE school_id = ? AND attendance_date = ?)
                 + (SELECT COUNT(*) FROM school_staff_attendance WHERE school_id = ? AND attendance_date = ?)'
        );
        $attendanceCountStmt->execute([$schoolId, $calendarRecord['calendar_date'], $schoolId, $calendarRecord['calendar_date']]);
        $conflictingAttendance = (int) $attendanceCountStmt->fetchColumn();
        $conflictReason = trim((string) ($_POST['attendance_conflict_reason'] ?? ''));
        if ($conflictingAttendance > 0
            && (($_POST['confirm_attendance_conflict'] ?? '') !== '1' || $conflictReason === '' || strlen($conflictReason) > 500)) {
            flash('error', 'Attendance exists on this date. Confirm that records will be preserved and provide a review reason before removing the calendar exception.');
            redirect('term_setup.php');
        }
        $deleteStmt = db()->prepare('DELETE FROM school_calendar_days WHERE id = ? AND school_id = ?');
        $deleteStmt->execute([$calendarDayId, $schoolId]);
        if ($deleteStmt->rowCount() !== 1) {
            flash('error', 'The calendar entry was not found in this school.');
            redirect('term_setup.php');
        }
        audit((int) $user['id'], 'school_calendar_day_removed', 'school_id=' . $schoolId . ';calendar_day_id=' . $calendarDayId . ';conflicting_records=' . $conflictingAttendance . ';reason=' . $conflictReason);
        flash('success', 'Calendar entry removed.');
        redirect('term_setup.php');
    }
}

$years = school_academic_years_for_school($schoolId);
$activeYear = school_active_academic_year($schoolId);
$termStmt = db()->prepare(
    'SELECT t.id, t.academic_year_id, y.academic_year, t.term_name, t.starts_on, t.ends_on, t.is_current,
            y.status AS year_status
     FROM school_terms t
     LEFT JOIN school_academic_years y
       ON y.id = t.academic_year_id AND y.school_id = t.school_id
     WHERE t.school_id = ? ORDER BY t.starts_on DESC'
);
$termStmt->execute([$schoolId]);
$terms = $termStmt->fetchAll();
$shiftCalendarDate = static function (?string $date): string {
    if ($date === null || $date === '') {
        return '';
    }
    $source = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($source === false || $source->format('Y-m-d') !== $date) {
        return '';
    }
    $year = (int) $source->format('Y') + 1;
    $month = (int) $source->format('m');
    $day = (int) $source->format('d');
    while ($day > 28 && !checkdate($month, $day, $year)) {
        $day--;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
};
$latestConfiguredYears = array_values(array_filter(
    $years,
    static fn(array $year): bool => !empty($year['starts_on']) && !empty($year['ends_on'])
));
usort($latestConfiguredYears, static fn(array $left, array $right): int => (string) $right['ends_on'] <=> (string) $left['ends_on']);
$latestConfiguredYear = $latestConfiguredYears[0] ?? null;
$nextYearLabel = (string) ((int) date('Y')) . '/' . (string) ((int) date('Y') + 1);
$nextYearStartsOn = '';
$nextYearEndsOn = '';
$nextTermDates = [];
if ($latestConfiguredYear !== null) {
    if (preg_match('/^(\d{4})\/(\d{4})$/', (string) $latestConfiguredYear['academic_year'], $latestYearParts) === 1) {
        $nextYearLabel = ((int) $latestYearParts[1] + 1) . '/' . ((int) $latestYearParts[2] + 1);
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $latestConfiguredYear['starts_on'], $latestStartParts) === 1
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $latestConfiguredYear['ends_on'], $latestEndParts) === 1) {
        $nextYearLabel = ((int) substr((string) $latestConfiguredYear['starts_on'], 0, 4) + 1)
            . '/' . ((int) substr((string) $latestConfiguredYear['ends_on'], 0, 4) + 1);
    }
    $nextYearStartsOn = $shiftCalendarDate((string) $latestConfiguredYear['starts_on']);
    $nextYearEndsOn = $shiftCalendarDate((string) $latestConfiguredYear['ends_on']);
    $latestTermsStmt = db()->prepare(
        'SELECT term_name, starts_on, ends_on FROM school_terms
         WHERE school_id = ? AND academic_year_id = ?
           AND term_name IN ("First Term", "Second Term", "Third Term")
         ORDER BY starts_on'
    );
    $latestTermsStmt->execute([$schoolId, (int) $latestConfiguredYear['id']]);
    foreach ($latestTermsStmt->fetchAll() as $latestTerm) {
        $nextTermDates[(string) $latestTerm['term_name']] = [
            'starts_on' => $shiftCalendarDate((string) $latestTerm['starts_on']),
            'ends_on' => $shiftCalendarDate((string) $latestTerm['ends_on']),
        ];
    }
}
$createNextYear = ($_GET['create_next'] ?? '') === '1';
$editYearId = filter_var($_GET['edit_year'] ?? null, FILTER_VALIDATE_INT);
$editYear = null;
if ($editYearId !== false && $editYearId > 0) {
    $editYearStmt = db()->prepare('SELECT id, academic_year, starts_on, ends_on, status, is_active FROM school_academic_years WHERE id = ? AND school_id = ? LIMIT 1');
    $editYearStmt->execute([$editYearId, $schoolId]);
    $editYear = $editYearStmt->fetch() ?: null;
    if ($editYear === null) {
        flash('error', 'The academic year was not found in this school.');
        redirect('term_setup.php');
    }
}
$editTermId = filter_var($_GET['edit_term'] ?? null, FILTER_VALIDATE_INT);
$editTerm = null;
if ($editTermId !== false && $editTermId > 0) {
    $editTermStmt = db()->prepare(
        'SELECT t.id, t.academic_year_id, y.academic_year, t.term_name, t.starts_on, t.ends_on, t.is_current, y.status AS year_status
         FROM school_terms t LEFT JOIN school_academic_years y
           ON y.id = t.academic_year_id AND y.school_id = t.school_id
         WHERE t.id = ? AND t.school_id = ? LIMIT 1'
    );
    $editTermStmt->execute([$editTermId, $schoolId]);
    $editTerm = $editTermStmt->fetch() ?: null;
    if ($editTerm === null) {
        redirect('term_setup.php');
    }
    if ($editTerm['year_status'] !== 'open') {
        flash('error', 'Terms in a closed academic year are retained as read-only history.');
        redirect('term_setup.php');
    }
}
$openYears = array_values(array_filter($years, static fn(array $year): bool => $year['status'] === 'open'
    && $year['starts_on'] !== null && $year['ends_on'] !== null));
$calendarStmt = db()->prepare(
    'SELECT id, calendar_date, day_type, label FROM school_calendar_days
     WHERE school_id = ? ORDER BY calendar_date DESC'
);
$calendarStmt->execute([$schoolId]);
$calendarDays = $calendarStmt->fetchAll();

if (!$canManageAcademicCalendar) {
    render_header('Academic calendar');
    ?>
    <section class="panel">
        <p class="eyebrow">ACADEMIC CALENDAR</p>
        <h1>Academic years, terms, and school days</h1>
        <p class="muted">This is the school’s published academic schedule. Teachers, staff, parents, and students can view it here. Only the Headmaster or Proprietor can change calendar settings. Use the reporting-period selector above when recording work for another academic period.</p>
    </section>
    <section class="panel panel-top-gap">
        <h2>Academic years</h2>
        <?php if ($years === []): ?>
            <p class="muted">No academic years have been configured yet.</p>
        <?php else: ?>
            <div class="table-scroll"><table>
                <thead><tr><th>Academic year</th><th>Dates</th><th>Status</th><th>Active</th></tr></thead>
                <tbody><?php foreach ($years as $year): ?>
                    <tr>
                        <td><?= e(school_academic_year_display_label((string) $year['academic_year'])) ?></td>
                        <td><?= e((string) ($year['starts_on'] ?: 'Dates not set')) ?> – <?= e((string) ($year['ends_on'] ?: 'Dates not set')) ?></td>
                        <td><?= e(ucfirst((string) $year['status'])) ?></td>
                        <td><?= (int) $year['is_active'] === 1 ? 'Active' : '—' ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <section class="panel panel-top-gap">
        <h2>Academic terms</h2>
        <?php if ($terms === []): ?>
            <p class="muted">No academic terms have been configured yet.</p>
        <?php else: ?>
            <div class="table-scroll"><table>
                <thead><tr><th>Academic year</th><th>Term</th><th>Dates</th><th>Status</th></tr></thead>
                <tbody><?php foreach ($terms as $term): ?>
                    <tr>
                        <td><?= e(school_academic_year_display_label((string) $term['academic_year'])) ?></td>
                        <td><?= e((string) $term['term_name']) ?></td>
                        <td><?= e((string) $term['starts_on']) ?> – <?= e((string) $term['ends_on']) ?></td>
                        <td><?= (int) $term['is_current'] === 1 && (int) (($activeYear['academic_year'] ?? '') === $term['academic_year']) ? 'Current' : '—' ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <section class="panel panel-top-gap">
        <h2>Holidays and school days</h2>
        <?php if ($calendarDays === []): ?>
            <p class="muted">No special school-day dates have been configured.</p>
        <?php else: ?>
            <div class="table-scroll"><table>
                <thead><tr><th>Date</th><th>Type</th><th>Description</th></tr></thead>
                <tbody><?php foreach ($calendarDays as $calendarDay): ?>
                    <tr>
                        <td><?= e((string) $calendarDay['calendar_date']) ?></td>
                        <td><?= e(ucwords(str_replace('_', ' ', (string) $calendarDay['day_type']))) ?></td>
                        <td><?= e((string) $calendarDay['label']) ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
    exit;
}

$attendanceWeekdaysStmt = db()->prepare(
    'SELECT setting_value FROM school_settings
     WHERE school_id = ? AND setting_key = "attendance_working_weekdays" LIMIT 1'
);
$attendanceWeekdaysStmt->execute([$schoolId]);
$attendanceWeekdays = json_decode((string) ($attendanceWeekdaysStmt->fetchColumn() ?: '[1,2,3,4,5]'), true);
$attendanceWeekdays = is_array($attendanceWeekdays) ? array_map('intval', $attendanceWeekdays) : [1, 2, 3, 4, 5];

render_header('Academic Years & Terms');
?>
<section class="panel">
    <p class="eyebrow">ACADEMIC CALENDAR</p>
    <h1>Academic Years &amp; Terms</h1>
    <p class="muted">Set the school’s saved academic years and terms. The active year and current term are used by attendance and school workflows; changing them does not rewrite historical records.</p>
</section>

<section class="report-action-grid" aria-label="Academic calendar actions" data-action-return="term_setup.php" data-action-start="<?= $editYear !== null ? 'academic-years' : ($editTerm !== null ? 'academic-terms' : '') ?>">
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('calendar') ?></span>
        <div><h2>Academic Years</h2><p>Configure school-year dates and choose the active year.</p></div>
        <a class="button button-primary" href="#academic-years" data-action-open="academic-years">Manage Years</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('calendar') ?></span>
        <div><h2>Academic Terms</h2><p>Set up First Term, Second Term, and Third Term within an open year.</p></div>
        <a class="button" href="#academic-terms" data-action-open="academic-terms">Manage Terms</a>
    </article>
    <article class="report-action-card">
        <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('attendance') ?></span>
        <div><h2>Holidays &amp; School Days</h2><p>Configure dates used by attendance and other school workflows.</p></div>
        <a class="button" href="#school-calendar" data-action-open="school-calendar">Manage Calendar</a>
    </article>
</section>

<section class="panel panel-top-gap" id="academic-years" data-action-view="academic-years">
    <p><a class="button button-primary" href="term_setup.php?create_next=1#calendar-wizard">Create Next Academic Year</a></p>
    <section class="panel panel-top-gap" id="calendar-wizard" aria-labelledby="calendar-wizard-heading">
        <h2 id="calendar-wizard-heading">Guided academic calendar setup</h2>
        <?php if (!$termMilestoneSchemaReady): ?>
            <p class="notice notice-warning">Apply database migration 092 to enable guided calendar setup with optional schedule dates. Existing calendar management remains available below.</p>
        <?php else: ?>
        <p class="muted">Create a new year and configure its three terms together. The suggested dates copy the latest saved year’s pattern and remain editable. This saves the calendar but does not activate it or change historical records.</p>
        <form method="post" action="term_setup.php#calendar-wizard" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_academic_calendar_wizard">
            <fieldset>
                <legend>Step 1 · Academic year</legend>
                <div class="form-grid two-columns">
                    <div><label for="wizard-academic-year">Academic year</label><input id="wizard-academic-year" name="academic_year" type="text" inputmode="numeric" pattern="[0-9]{4}/[0-9]{4}" value="<?= e($createNextYear ? $nextYearLabel : '') ?>" placeholder="<?= e($nextYearLabel) ?>" required></div>
                    <div><label for="wizard-year-start">Year starts</label><input id="wizard-year-start" name="year_starts_on" type="date" value="<?= e($createNextYear ? $nextYearStartsOn : '') ?>" required></div>
                    <div><label for="wizard-year-end">Year ends</label><input id="wizard-year-end" name="year_ends_on" type="date" value="<?= e($createNextYear ? $nextYearEndsOn : '') ?>" required></div>
                </div>
            </fieldset>
            <fieldset>
                <legend>Step 2 · Three school terms</legend>
                <p class="muted">Term dates must fall within the academic year and must not overlap. Gaps between terms are allowed for vacations and holidays.</p>
                <div class="form-grid two-columns">
                    <?php foreach (school_standard_term_names() as $termName):
                        $termStartId = 'wizard-term-start-' . strtolower(str_replace(' ', '-', $termName));
                        $termEndId = 'wizard-term-end-' . strtolower(str_replace(' ', '-', $termName));
                        $defaultTerm = $nextTermDates[$termName] ?? ['starts_on' => '', 'ends_on' => ''];
                    ?>
                        <fieldset>
                            <legend><?= e($termName) ?></legend>
                            <label for="<?= e($termStartId) ?>">Starts on</label>
                            <input id="<?= e($termStartId) ?>" name="term_starts_on[<?= e($termName) ?>]" type="date" value="<?= e($createNextYear ? $defaultTerm['starts_on'] : '') ?>" required>
                            <label for="<?= e($termEndId) ?>">Ends on</label>
                            <input id="<?= e($termEndId) ?>" name="term_ends_on[<?= e($termName) ?>]" type="date" value="<?= e($createNextYear ? $defaultTerm['ends_on'] : '') ?>" required>
                            <details class="panel-top-gap">
                                <summary>Optional schedule dates</summary>
                                <div class="form-stack">
                                    <div><label for="<?= e($termStartId) ?>-vacation-start">Vacation starts</label><input id="<?= e($termStartId) ?>-vacation-start" name="term_details[<?= e($termName) ?>][vacation_starts_on]" type="date"></div>
                                    <div><label for="<?= e($termStartId) ?>-vacation-end">Vacation ends</label><input id="<?= e($termStartId) ?>-vacation-end" name="term_details[<?= e($termName) ?>][vacation_ends_on]" type="date"></div>
                                    <div><label for="<?= e($termStartId) ?>-reopening">School reopens</label><input id="<?= e($termStartId) ?>-reopening" name="term_details[<?= e($termName) ?>][reopening_on]" type="date"></div>
                                    <div><label for="<?= e($termStartId) ?>-exam-start">Examinations start</label><input id="<?= e($termStartId) ?>-exam-start" name="term_details[<?= e($termName) ?>][examination_starts_on]" type="date"></div>
                                    <div><label for="<?= e($termStartId) ?>-exam-end">Examinations end</label><input id="<?= e($termStartId) ?>-exam-end" name="term_details[<?= e($termName) ?>][examination_ends_on]" type="date"></div>
                                    <div><label for="<?= e($termStartId) ?>-report-prep">Report preparation date</label><input id="<?= e($termStartId) ?>-report-prep" name="term_details[<?= e($termName) ?>][report_preparation_on]" type="date"></div>
                                    <div><label for="<?= e($termStartId) ?>-report-publish">Report publication date</label><input id="<?= e($termStartId) ?>-report-publish" name="term_details[<?= e($termName) ?>][report_publication_on]" type="date"></div>
                                </div>
                            </details>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <fieldset>
                <legend>Step 3 · Review and save</legend>
                <p>Check that the academic year and all term dates are correct. To make a term current later, use its “Set current” action after reviewing the school, year, dates, and any fee-assessment effect.</p>
                <label class="check-label"><input type="checkbox" name="confirm_calendar" value="1" required> I reviewed the academic year and all three term dates.</label>
            </fieldset>
            <button class="button button-primary" type="submit" data-confirm="Save this new academic year and its three terms? No existing academic records will be moved.">Save academic calendar</button>
        </form>
        <?php endif; ?>
    </section>

    <h2><?= $editYear === null ? 'Create academic year' : 'Edit academic year' ?></h2>
    <form method="post" action="term_setup.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_academic_year">
        <?php if ($editYear !== null): ?><input type="hidden" name="academic_year_id" value="<?= (int) $editYear['id'] ?>"><?php endif; ?>
        <div class="form-grid two-columns">
            <div><label for="academic_year">Academic year</label><input id="academic_year" name="academic_year" type="text" inputmode="numeric" pattern="[0-9]{4}/[0-9]{4}" placeholder="2026/2027" value="<?= e((string) ($editYear['academic_year'] ?? '')) ?>" required></div>
            <div><label for="year_starts_on">Year starts</label><input id="year_starts_on" name="year_starts_on" type="date" value="<?= e((string) ($editYear['starts_on'] ?? '')) ?>" required></div>
            <div><label for="year_ends_on">Year ends</label><input id="year_ends_on" name="year_ends_on" type="date" value="<?= e((string) ($editYear['ends_on'] ?? '')) ?>" required></div>
            <?php if (($editYear['status'] ?? 'open') === 'open'): ?>
                <div><label class="check-label"><input type="checkbox" name="set_active" value="1" <?= !empty($editYear['is_active']) ? 'checked' : '' ?>> Set as the active academic year</label></div>
            <?php else: ?><p class="muted">This year is closed. Its history is retained and it cannot be reactivated from this form.</p><?php endif; ?>
        </div>
        <div class="class-editor-actions"><button class="button button-primary" type="submit">Save academic year</button><?php if ($editYear !== null): ?><a class="button button-quiet" href="term_setup.php">Cancel</a><?php endif; ?></div>
    </form>
    <?php if ($years !== []): ?>
        <div class="table-scroll"><table>
            <thead><tr><th>Academic year</th><th>Dates</th><th>Status</th><th>Active</th><th>Actions</th></tr></thead>
            <tbody><?php foreach ($years as $year): ?><tr>
                <td><?= e(school_academic_year_display_label((string) $year['academic_year'])) ?></td>
                <td><?= e((string) ($year['starts_on'] ?: 'Dates required')) ?> – <?= e((string) ($year['ends_on'] ?: 'Dates required')) ?></td>
                <td><?= e(ucfirst((string) $year['status'])) ?></td>
                <td><?= (int) $year['is_active'] === 1 ? 'Yes' : 'No' ?></td>
                <td class="class-editor-actions">
                    <a class="button button-quiet button-small" href="term_setup.php?edit_year=<?= (int) $year['id'] ?>">Edit</a>
                    <?php if ($year['status'] === 'open' && (int) $year['is_active'] !== 1 && $year['starts_on'] && $year['ends_on']): ?>
                        <form method="post" action="term_setup.php"><?= csrf_field() ?><input type="hidden" name="action" value="activate_academic_year"><input type="hidden" name="academic_year_id" value="<?= (int) $year['id'] ?>"><button class="button button-small" type="submit" data-confirm="<?= e('Make ' . (string) $school['name'] . ' · ' . (string) $year['academic_year'] . ' (' . (string) $year['starts_on'] . ' to ' . (string) $year['ends_on'] . ') the active academic year? Its previous current term will be cleared, but historical records will be preserved.') ?>">Set active</button></form>
                    <?php endif; ?>
                    <?php if ($year['status'] === 'open'): ?>
                        <form method="post" action="term_setup.php"><?= csrf_field() ?><input type="hidden" name="action" value="close_academic_year"><input type="hidden" name="academic_year_id" value="<?= (int) $year['id'] ?>"><button class="button button-small" type="submit" data-confirm="Close this year? Historical records will be retained.">Close year</button></form>
                    <?php endif; ?>
                </td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
    <?php else: ?><p class="muted">No academic years are configured yet. Create one before setting up terms or assigning a class to that year.</p><?php endif; ?>
</section>

<section class="panel panel-top-gap" id="academic-terms" data-action-view="academic-terms">
    <h2><?= $editTerm === null ? 'Create term' : 'Edit term' ?></h2>
    <?php if ($openYears === []): ?>
        <p class="muted">Create an open academic year and set its dates before adding terms.</p>
    <?php else: ?>
    <form method="post" action="term_setup.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_term">
        <?php if ($editTerm !== null): ?><input type="hidden" name="term_id" value="<?= (int) $editTerm['id'] ?>"><?php endif; ?>
        <div class="form-grid two-columns">
            <div><label for="term_academic_year">Academic year</label><select id="term_academic_year" name="academic_year_id" required>
                <?php foreach ($openYears as $year): ?><option value="<?= (int) $year['id'] ?>" <?= (int) ($editTerm['academic_year_id'] ?? $activeYear['id'] ?? 0) === (int) $year['id'] ? 'selected' : '' ?>><?= e(school_academic_year_display_label((string) $year['academic_year'])) ?><?= (int) $year['is_active'] === 1 ? ' · Active' : '' ?></option><?php endforeach; ?>
            </select></div>
            <div><label for="term_name">Term name</label><select id="term_name" name="term_name" required>
                <option value="">Choose a term</option>
                <?php foreach (['First Term', 'Second Term', 'Third Term'] as $standardTerm): ?>
                    <option value="<?= e($standardTerm) ?>" <?= ($editTerm['term_name'] ?? '') === $standardTerm ? 'selected' : '' ?>><?= e($standardTerm) ?></option>
                <?php endforeach; ?>
                <?php if ($editTerm !== null && !in_array((string) $editTerm['term_name'], ['First Term', 'Second Term', 'Third Term'], true)): ?>
                    <option value="<?= e((string) $editTerm['term_name']) ?>" selected><?= e((string) $editTerm['term_name']) ?> (historical)</option>
                <?php endif; ?>
            </select></div>
            <div><label for="starts_on">Starts on</label><input id="starts_on" name="starts_on" type="date" value="<?= e((string) ($editTerm['starts_on'] ?? '')) ?>" required></div>
            <div><label for="ends_on">Ends on</label><input id="ends_on" name="ends_on" type="date" value="<?= e((string) ($editTerm['ends_on'] ?? '')) ?>" required></div>
            <div class="span-full">
                <label class="check-label"><input type="checkbox" name="set_current" value="1" <?= !empty($editTerm['is_current']) ? 'checked' : '' ?>> Set this as the current term and activate its academic year</label>
                <label class="check-label"><input type="checkbox" name="confirm_current_term" value="1"> I reviewed the school, academic year, and term dates; existing historical records will remain unchanged.</label>
            </div>
        </div>
        <div class="class-editor-actions"><button class="button button-primary" type="submit" data-confirm="<?= e('Save the selected term dates for ' . (string) $school['name'] . '? If you selected it as current, the active school term changes and historical records remain unchanged.') ?>"><?= $editTerm === null ? 'Save term' : 'Update term' ?></button><?php if ($editTerm !== null): ?><a class="button button-quiet" href="term_setup.php">Cancel</a><?php endif; ?></div>
    </form>
    <?php endif; ?>
    <h2>Academic terms</h2>
    <?php if ($terms === []): ?>
        <p class="muted">No academic terms have been configured for this school yet.</p>
    <?php else: ?>
        <div class="table-scroll"><table>
            <thead>
                <tr><th>Year</th><th>Term</th><th>Starts</th><th>Ends</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($terms as $term): ?>
                    <tr>
                        <td><?= e(school_academic_year_display_label((string) $term['academic_year'])) ?></td>
                        <td><?= e((string) $term['term_name']) ?></td>
                        <td><?= e((string) $term['starts_on']) ?></td>
                        <td><?= e((string) $term['ends_on']) ?></td>
                        <td><?= (int) $term['is_current'] === 1 && (int) (($activeYear['academic_year'] ?? '') === $term['academic_year']) ? 'Current' : '—' ?></td>
                        <td class="class-editor-actions"><?php if ($term['year_status'] === 'open'): ?><a class="button button-quiet button-small" href="term_setup.php?edit_term=<?= (int) $term['id'] ?>">Edit</a><?php endif; ?><?php if ($term['year_status'] === 'open' && ((int) $term['is_current'] !== 1 || ($activeYear['academic_year'] ?? '') !== $term['academic_year'])): ?>
                            <?php $termDatesHavePassed = (string) $term['ends_on'] < gmdate('Y-m-d'); ?>
                            <form method="post" action="term_setup.php"><?= csrf_field() ?><input type="hidden" name="action" value="set_current_term"><input type="hidden" name="term_id" value="<?= (int) $term['id'] ?>"><input type="hidden" name="confirm_current_term" value="1"><button class="button button-small" type="submit" data-confirm="<?= e('Set ' . (string) $school['name'] . ' · ' . (string) $term['academic_year'] . ' · ' . (string) $term['term_name'] . ' (' . (string) $term['starts_on'] . ' to ' . (string) $term['ends_on'] . ') as the current term?' . ($termDatesHavePassed ? ' These dates have passed; confirm this is intentional.' : '')) ?>">Set current</button></form><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<section class="panel panel-top-gap" id="school-calendar" data-action-view="school-calendar">
    <h2>Holidays, closures, and scheduled school days</h2>
    <p class="muted">Attendance summaries use the configured regular school days, exclude holidays and closures, and can include weekends when configured.</p>
    <form method="post" action="term_setup.php" class="form-stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_attendance_weekdays">
        <fieldset><legend>Regular school days</legend>
            <?php foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $weekdayNumber => $weekdayName): ?>
                <label class="check-label"><input type="checkbox" name="attendance_weekdays[]" value="<?= $weekdayNumber ?>" <?= in_array($weekdayNumber, $attendanceWeekdays, true) ? 'checked' : '' ?>> <?= e($weekdayName) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <button class="button" type="submit">Save regular school days</button>
    </form>
    <form method="post" action="term_setup.php" class="form-stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_calendar_day">
        <div class="form-grid two-columns">
            <div><label for="calendar_date">Date</label><input id="calendar_date" name="calendar_date" type="date" required></div>
            <div><label for="day_type">Calendar type</label><select id="day_type" name="day_type" required><option value="holiday">Holiday</option><option value="closure">School closure</option><option value="school_day">Scheduled school day</option></select></div>
            <div class="span-full"><label for="calendar_label">Description</label><input id="calendar_label" name="label" maxlength="120" required></div>
            <div class="span-full"><label class="check-label"><input type="checkbox" name="confirm_attendance_conflict" value="1"> I have reviewed any existing attendance on this date; records will be preserved.</label><label for="attendance-conflict-reason">Review reason if attendance already exists</label><input id="attendance-conflict-reason" name="attendance_conflict_reason" maxlength="500"></div>
        </div>
        <button class="button button-primary" type="submit">Save calendar day</button>
    </form>
    <?php if ($calendarDays !== []): ?><div class="table-scroll"><table><thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($calendarDays as $calendarDay): ?><tr><td><?= e((string) $calendarDay['calendar_date']) ?></td><td><?= e(ucwords(str_replace('_', ' ', (string) $calendarDay['day_type']))) ?></td><td><?= e((string) $calendarDay['label']) ?></td><td><form method="post" action="term_setup.php" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="delete_calendar_day"><input type="hidden" name="calendar_day_id" value="<?= (int) $calendarDay['id'] ?>"><label class="check-label"><input type="checkbox" name="confirm_attendance_conflict" value="1"> Preserve and review conflicting attendance</label><input name="attendance_conflict_reason" maxlength="500" placeholder="Reason if attendance exists"><button class="button" type="submit" data-confirm="Remove this school-calendar exception?">Remove</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<?php render_footer(); ?>
