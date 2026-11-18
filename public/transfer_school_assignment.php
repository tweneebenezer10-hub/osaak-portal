<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_platform_super_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the platform school-assignment transfer form.');
}
require_csrf();

$accountEmail = strtolower(trim((string) ($_POST['account_email'] ?? '')));
$sourceSchoolId = filter_var($_POST['source_school_id'] ?? null, FILTER_VALIDATE_INT);
$targetSchoolId = filter_var($_POST['target_school_id'] ?? null, FILTER_VALIDATE_INT);
$roleName = (string) ($_POST['role_name'] ?? '');
$reason = trim((string) ($_POST['reason'] ?? ''));
$assignableRoles = [
    'school_proprietor',
    'school_headmaster',
    'school_assistant_headmaster',
    'school_secretary',
    'class_teacher',
    'subject_teacher',
    'school_accountant',
    'school_parent',
];
if (!filter_var($accountEmail, FILTER_VALIDATE_EMAIL)
    || $sourceSchoolId === false || $sourceSchoolId < 1
    || $targetSchoolId === false || $targetSchoolId < 1 || $sourceSchoolId === $targetSchoolId
    || !in_array($roleName, $assignableRoles, true)
    || strlen($reason) < 12 || strlen($reason) > 200) {
    flash('error', 'Enter a valid account email, different source and target schools, supported role, and transfer reason of 12 to 200 characters.');
    redirect('platform_admin.php');
}

db()->beginTransaction();
try {
    $accountStmt = db()->prepare(
        'SELECT id, email, status, email_verified_at
         FROM users WHERE LOWER(TRIM(email)) = ? FOR UPDATE'
    );
    $accountStmt->execute([$accountEmail]);
    $account = $accountStmt->fetch();
    if (!$account || $account['status'] !== 'active' || $account['email_verified_at'] === null
        || strtolower(trim((string) $account['email'])) === PLATFORM_SUPER_ADMIN_EMAIL) {
        throw new RuntimeException('Only an active, email-verified ordinary account can be transferred.');
    }

    $targetSchoolStmt = db()->prepare(
        "SELECT id FROM schools WHERE id = ? AND deleted_at IS NULL AND status = 'active' LIMIT 1"
    );
    $targetSchoolStmt->execute([$targetSchoolId]);
    if (!$targetSchoolStmt->fetchColumn()) {
        throw new RuntimeException('The target school must exist and be active.');
    }
    $sourceSchoolStmt = db()->prepare('SELECT id FROM schools WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $sourceSchoolStmt->execute([$sourceSchoolId]);
    if (!$sourceSchoolStmt->fetchColumn()) {
        throw new RuntimeException('The source school could not be found.');
    }

    if ($roleName === 'school_parent') {
        $guardianStmt = db()->prepare(
            'SELECT 1 FROM school_guardians
             WHERE school_id = ? AND LOWER(TRIM(email)) = ? LIMIT 1'
        );
        $guardianStmt->execute([$targetSchoolId, $accountEmail]);
        if (!$guardianStmt->fetchColumn()) {
            throw new RuntimeException('The verified account email must match a guardian record at the target school.');
        }
    }

    $assignmentStmt = db()->prepare(
        "SELECT school_id, role_name FROM school_memberships
         WHERE user_id = ? AND status = 'active' FOR UPDATE"
    );
    $assignmentStmt->execute([(int) $account['id']]);
    $assignments = $assignmentStmt->fetchAll();
    $sourceAssigned = false;
    $sourceSchoolIds = [];
    foreach ($assignments as $assignment) {
        $assignedSchoolId = (int) $assignment['school_id'];
        $sourceAssigned = $sourceAssigned || $assignedSchoolId === $sourceSchoolId;
        $sourceSchoolIds[$assignedSchoolId] = $assignedSchoolId;
    }
    if (!$sourceAssigned) {
        throw new RuntimeException('The account has no active assignment at the selected source school.');
    }

    if ($roleName === 'school_proprietor') {
        $targetSchoolLock = db()->prepare('SELECT id FROM schools WHERE id = ? FOR UPDATE');
        $targetSchoolLock->execute([$targetSchoolId]);
        if (!$targetSchoolLock->fetchColumn()) {
            throw new RuntimeException('The target school could not be found.');
        }
        $existingProprietor = db()->prepare(
            'SELECT 1 FROM school_memberships
             WHERE school_id = ? AND role_name = "school_proprietor" AND status = "active"
               AND user_id <> ? LIMIT 1'
        );
        $existingProprietor->execute([$targetSchoolId, (int) $account['id']]);
        if ($existingProprietor->fetchColumn()) {
            throw new RuntimeException('The target school already has an active Proprietor. Complete an explicit ownership transfer before assigning another.');
        }
    }

    $disableMemberships = db()->prepare(
        "UPDATE school_memberships SET status = 'disabled'
         WHERE user_id = ? AND status = 'active'"
    );
    $disableMemberships->execute([(int) $account['id']]);
    $disableStaff = db()->prepare(
        "UPDATE school_staff SET status = 'inactive'
         WHERE user_id = ? AND school_id = ?"
    );
    $clearClassTeacher = db()->prepare(
        'UPDATE school_classes SET class_teacher_id = NULL
         WHERE school_id = ? AND class_teacher_id = ?'
    );
    $clearClassAssignments = db()->prepare(
        'DELETE FROM teacher_class_assignments WHERE school_id = ? AND teacher_user_id = ?'
    );
    $clearSubjectTeacher = db()->prepare(
        'UPDATE class_subjects SET teacher_user_id = NULL
         WHERE school_id = ? AND teacher_user_id = ?'
    );
    foreach ($sourceSchoolIds as $assignedSchoolId) {
        $disableStaff->execute([(int) $account['id'], $assignedSchoolId]);
        $clearClassAssignments->execute([$assignedSchoolId, (int) $account['id']]);
        $clearClassTeacher->execute([$assignedSchoolId, (int) $account['id']]);
        $clearSubjectTeacher->execute([$assignedSchoolId, (int) $account['id']]);
    }

    $targetMembershipStmt = db()->prepare(
        'INSERT INTO school_memberships (school_id, user_id, role_name, status, approved_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), approved_by = VALUES(approved_by), joined_at = UTC_TIMESTAMP()'
    );
    $targetMembershipStmt->execute([
        $targetSchoolId,
        (int) $account['id'],
        $roleName,
        'active',
        (int) $user['id'],
    ]);

    if ($roleName !== 'school_parent') {
        $staffStmt = db()->prepare(
            'INSERT INTO school_staff (school_id, user_id, role_name, status)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), status = VALUES(status)'
        );
        $staffStmt->execute([$targetSchoolId, (int) $account['id'], $roleName, 'active']);
    }

    audit(
        (int) $user['id'],
        'school_assignment_transferred',
        'user_id=' . (int) $account['id'] . ';from=' . implode(',', $sourceSchoolIds)
            . ';selected_source=' . $sourceSchoolId . ';to=' . $targetSchoolId . ';role=' . $roleName
    );
    audit((int) $user['id'], 'school_assignment_transfer_reason', 'user_id=' . (int) $account['id'] . ';reason=' . $reason);
    db()->commit();
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    if ($exception instanceof RuntimeException) {
        flash('error', $exception->getMessage());
        redirect('platform_admin.php');
    }
    throw $exception;
}

flash('success', 'School assignment transferred. The account can access only the target school.');
redirect('platform_admin.php');
