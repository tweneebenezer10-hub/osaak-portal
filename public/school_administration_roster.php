<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
if (!$school) {
    flash('error', 'No active school was found for your account.');
    redirect('schools.php');
}

$schoolId = (int) $school['id'];
$role = (string) ($user['active_school_role'] ?? school_user_role_for_school((int) $user['id'], $schoolId));
$supportContext = active_it_support_context($user);
if ($supportContext !== null || !in_array($role, ['school_headmaster', 'school_proprietor'], true)) {
    http_response_code(403);
    render_header('School permission denied');
    echo '<section class="panel"><h1>School permission denied</h1><p>Only an authorized Headmaster or Proprietor can manage administrative designations for this school.</p><a class="button" href="school_dashboard.php">Return to school dashboard</a></section>';
    render_footer();
    exit;
}

$redirectToRoster = static function (): never {
    redirect('school_administration_roster.php');
};

$validateText = static function (string $value, int $maxLength, string $field): string {
    $value = trim($value);
    if ($value === '' || preg_match('//u', $value) !== 1) {
        throw new RuntimeException('Enter a valid ' . $field . '.');
    }
    preg_match_all('/./us', $value, $characters);
    if (count($characters[0]) > $maxLength) {
        throw new RuntimeException($field . ' must be no longer than ' . $maxLength . ' characters.');
    }
    return $value;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if (!in_array($action, ['add_assignment', 'update_assignment', 'remove_assignment'], true)) {
        flash('error', 'Choose a valid roster action.');
        $redirectToRoster();
    }

    $assignmentId = filter_var($_POST['assignment_id'] ?? null, FILTER_VALIDATE_INT);
    $staffId = filter_var($_POST['staff_id'] ?? null, FILTER_VALIDATE_INT);
    try {
        $jobTitle = $action === 'remove_assignment'
            ? ''
            : $validateText((string) ($_POST['job_title'] ?? ''), 120, 'job title');
        $responsibilities = $action === 'remove_assignment'
            ? ''
            : $validateText((string) ($_POST['responsibilities'] ?? ''), 2000, 'responsibilities');
        if ($action === 'add_assignment' && ($staffId === false || $staffId < 1)) {
            throw new RuntimeException('Choose an active school staff member.');
        }
        if ($action !== 'add_assignment' && ($assignmentId === false || $assignmentId < 1)) {
            throw new RuntimeException('Choose a valid administrative assignment.');
        }

        db()->beginTransaction();
        if ($action === 'add_assignment') {
            $staffStmt = db()->prepare(
                'SELECT ss.id, u.full_name
                 FROM school_staff ss
                 JOIN users u ON u.id = ss.user_id
                 WHERE ss.id = ? AND ss.school_id = ? AND ss.status = "active"
                   AND ss.role_name NOT IN ("school_parent", "school_student")
                   AND u.status = "active" AND u.email_verified_at IS NOT NULL
                   AND EXISTS (
                       SELECT 1 FROM school_memberships sm
                       WHERE sm.school_id = ss.school_id AND sm.user_id = ss.user_id
                         AND sm.status = "active"
                         AND sm.role_name NOT IN ("school_parent", "school_student")
                   )
                 LIMIT 1 FOR UPDATE'
            );
            $staffStmt->execute([(int) $staffId, $schoolId]);
            $staffRecord = $staffStmt->fetch();
            if (!$staffRecord) {
                throw new RuntimeException('That person is not an active, verified staff member of the selected school.');
            }
            $existingStmt = db()->prepare(
                'SELECT id FROM school_staff_administrative_assignments
                 WHERE school_id = ? AND staff_id = ? AND status = "active"
                 LIMIT 1 FOR UPDATE'
            );
            $existingStmt->execute([$schoolId, (int) $staffRecord['id']]);
            if ($existingStmt->fetchColumn()) {
                throw new RuntimeException('That staff member already has an active administrative designation. Update it instead.');
            }
            $insertStmt = db()->prepare(
                'INSERT INTO school_staff_administrative_assignments
                    (school_id, staff_id, staff_name, job_title, responsibilities, status, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, "active", ?, ?)'
            );
            $insertStmt->execute([
                $schoolId,
                (int) $staffRecord['id'],
                (string) $staffRecord['full_name'],
                $jobTitle,
                $responsibilities,
                (int) $user['id'],
                (int) $user['id'],
            ]);
            $newAssignmentId = (int) db()->lastInsertId();
            audit(
                (int) $user['id'],
                'school_admin_assignment_added',
                'school_id=' . $schoolId . ';assignment_id=' . $newAssignmentId . ';staff_id=' . (int) $staffRecord['id']
            );
            db()->commit();
            flash('success', 'Administrative designation added.');
            $redirectToRoster();
        }

        $assignmentStmt = db()->prepare(
            'SELECT id, staff_id FROM school_staff_administrative_assignments
             WHERE id = ? AND school_id = ? AND status = "active"
             LIMIT 1 FOR UPDATE'
        );
        $assignmentStmt->execute([(int) $assignmentId, $schoolId]);
        $assignment = $assignmentStmt->fetch();
        if (!$assignment) {
            throw new RuntimeException('That active administrative assignment was not found in this school.');
        }

        if ($action === 'update_assignment') {
            $updateStmt = db()->prepare(
                'UPDATE school_staff_administrative_assignments
                 SET job_title = ?, responsibilities = ?, updated_by = ?
                 WHERE id = ? AND school_id = ? AND status = "active"'
            );
            $updateStmt->execute([
                $jobTitle,
                $responsibilities,
                (int) $user['id'],
                (int) $assignment['id'],
                $schoolId,
            ]);
            audit(
                (int) $user['id'],
                'school_admin_assignment_updated',
                'school_id=' . $schoolId . ';assignment_id=' . (int) $assignment['id'] . ';staff_id=' . (int) ($assignment['staff_id'] ?? 0)
            );
            db()->commit();
            flash('success', 'Administrative designation updated.');
            $redirectToRoster();
        }

        $removeStmt = db()->prepare(
            'UPDATE school_staff_administrative_assignments
             SET status = "removed", removed_at = UTC_TIMESTAMP(), updated_by = ?
             WHERE id = ? AND school_id = ? AND status = "active"'
        );
        $removeStmt->execute([(int) $user['id'], (int) $assignment['id'], $schoolId]);
        audit(
            (int) $user['id'],
            'school_admin_assignment_removed',
            'school_id=' . $schoolId . ';assignment_id=' . (int) $assignment['id'] . ';staff_id=' . (int) ($assignment['staff_id'] ?? 0)
        );
        db()->commit();
        flash('success', 'Administrative designation removed. Its history has been retained.');
        $redirectToRoster();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if ($exception instanceof RuntimeException) {
            flash('error', $exception->getMessage());
            $redirectToRoster();
        }
        throw $exception;
    }
}

$availableStaffStmt = db()->prepare(
    'SELECT ss.id, u.full_name
     FROM school_staff ss
     JOIN users u ON u.id = ss.user_id
     WHERE ss.school_id = ? AND ss.status = "active"
       AND ss.role_name NOT IN ("school_parent", "school_student")
       AND u.status = "active" AND u.email_verified_at IS NOT NULL
       AND EXISTS (
           SELECT 1 FROM school_memberships sm
           WHERE sm.school_id = ss.school_id AND sm.user_id = ss.user_id
             AND sm.status = "active"
             AND sm.role_name NOT IN ("school_parent", "school_student")
       )
       AND NOT EXISTS (
           SELECT 1 FROM school_staff_administrative_assignments assignment
           WHERE assignment.school_id = ss.school_id AND assignment.staff_id = ss.id
             AND assignment.status = "active"
       )
     ORDER BY u.full_name, ss.id'
);
$availableStaffStmt->execute([$schoolId]);
$availableStaff = $availableStaffStmt->fetchAll();

$assignmentsStmt = db()->prepare(
    'SELECT assignment.id, assignment.staff_id, assignment.staff_name,
            assignment.job_title, assignment.responsibilities, assignment.status,
            assignment.created_at, assignment.removed_at,
            COALESCE(NULLIF(u.full_name, ""), assignment.staff_name) AS display_name
     FROM school_staff_administrative_assignments assignment
     LEFT JOIN school_staff ss
       ON ss.id = assignment.staff_id AND ss.school_id = assignment.school_id
     LEFT JOIN users u ON u.id = ss.user_id
     WHERE assignment.school_id = ?
     ORDER BY (assignment.status = "active") DESC, assignment.created_at DESC, assignment.id DESC'
);
$assignmentsStmt->execute([$schoolId]);
$assignments = $assignmentsStmt->fetchAll();

render_header('School administration roster');
?>
<section class="panel">
    <p class="eyebrow">SCHOOL PERSONNEL</p>
    <h1>School administration roster</h1>
    <p class="muted">Designate administrative job titles and responsibilities for existing, active school staff. These designations do not create accounts or grant roles or permissions.</p>
</section>

<section class="panel panel-top-gap">
    <h2>Add an administrative designation</h2>
    <?php if ($availableStaff === []): ?>
        <p class="muted">There are no eligible active staff members without an active designation. Existing staff assignments can be edited below.</p>
    <?php else: ?>
        <form method="post" action="school_administration_roster.php" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_assignment">
            <label for="new-staff-id">Existing active staff member</label>
            <select id="new-staff-id" name="staff_id" required>
                <option value="">Choose a staff member</option>
                <?php foreach ($availableStaff as $staffMember): ?>
                    <option value="<?= (int) $staffMember['id'] ?>"><?= e((string) $staffMember['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="new-job-title">Job title</label>
            <input id="new-job-title" name="job_title" maxlength="120" required>
            <label for="new-responsibilities">Responsibilities</label>
            <textarea id="new-responsibilities" name="responsibilities" maxlength="2000" rows="4" required></textarea>
            <button class="button button-primary" type="submit">Add designation</button>
        </form>
    <?php endif; ?>
</section>

<section class="panel panel-top-gap">
    <h2>Current assignments and history</h2>
    <?php if ($assignments === []): ?>
        <p class="muted">No administrative designations have been recorded for this school.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Name</th><th>Job title</th><th>Responsibilities</th><th>Status / history</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($assignments as $assignment): ?>
                    <tr>
                        <td><?= e((string) $assignment['display_name']) ?></td>
                        <td><?= e((string) $assignment['job_title']) ?></td>
                        <td><?= nl2br(e((string) $assignment['responsibilities'])) ?></td>
                        <td><?= $assignment['status'] === 'active' ? 'Active' : 'Removed' ?>
                            <?php if ($assignment['removed_at'] !== null): ?><br><small>Removed <?= e((string) $assignment['removed_at']) ?> UTC</small><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($assignment['status'] === 'active'): ?>
                                <details>
                                    <summary>Edit designation</summary>
                                    <form method="post" action="school_administration_roster.php" class="form-stack">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_assignment">
                                        <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
                                        <label for="job-title-<?= (int) $assignment['id'] ?>">Job title</label>
                                        <input id="job-title-<?= (int) $assignment['id'] ?>" name="job_title" maxlength="120" value="<?= e((string) $assignment['job_title']) ?>" required>
                                        <label for="responsibilities-<?= (int) $assignment['id'] ?>">Responsibilities</label>
                                        <textarea id="responsibilities-<?= (int) $assignment['id'] ?>" name="responsibilities" maxlength="2000" rows="4" required><?= e((string) $assignment['responsibilities']) ?></textarea>
                                        <button class="button button-primary" type="submit">Save changes</button>
                                    </form>
                                    <form method="post" action="school_administration_roster.php" class="panel-top-gap-xs">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="remove_assignment">
                                        <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
                                        <button class="button" type="submit">Remove designation</button>
                                    </form>
                                </details>
                            <?php else: ?>
                                <span class="muted">History retained</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
