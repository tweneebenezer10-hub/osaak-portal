<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_login();
$platformReviewer = platform_super_admin_user($user);
$schoolId = filter_var($_REQUEST['school_id'] ?? null, FILTER_VALIDATE_INT);
if ($platformReviewer) {
    if ($schoolId === false || $schoolId < 1) {
        flash('error', 'Choose a school before reviewing a bootstrap role request.');
        redirect('platform_admin.php');
    }
    $schoolStmt = db()->prepare('SELECT id, name FROM schools WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    if (!$school) {
        http_response_code(404);
        exit('The selected school does not exist.');
    }
} else {
    $user = require_school_context();
    $school = active_school_context();
    $schoolId = (int) ($school['id'] ?? 0);
    $selectedRole = $school
        ? school_user_role_for_school((int) $user['id'], $schoolId)
        : null;
    if (!$school || !in_array($selectedRole, ['school_proprietor', 'school_headmaster'], true)) {
        http_response_code(403);
        exit('Only an authorized Proprietor or Headmaster can review school role requests.');
    }
    $reviewerRoles = [$selectedRole];
}

$sensitiveRoles = ['school_proprietor', 'school_headmaster', 'school_assistant_headmaster', 'school_accountant'];
$requestableRoles = [
    'school_proprietor',
    'school_headmaster',
    'school_assistant_headmaster',
    'school_secretary',
    'class_teacher',
    'subject_teacher',
    'school_accountant',
    'school_parent',
    'school_student',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
    $decision = (string) ($_POST['decision'] ?? '');
    $reason = trim((string) ($_POST['decision_reason'] ?? ''));
    if ($requestId === false || $requestId < 1 || !in_array($action, ['review_request', 'revoke_role'], true)) {
        flash('error', 'Choose a valid role request or active assignment.');
        redirect($platformReviewer ? 'platform_admin.php' : 'school_role_requests.php');
    }
    if (!$platformReviewer && (int) $user['id'] === (int) ($_POST['target_user_id'] ?? 0)) {
        flash('error', 'You cannot change your own school roles.');
        redirect('school_role_requests.php');
    }

    db()->beginTransaction();
    try {
        if ($platformReviewer) {
            $schoolLock = db()->prepare(
                'SELECT id FROM schools WHERE id = ? AND deleted_at IS NULL FOR UPDATE'
            );
            $schoolLock->execute([$schoolId]);
            if (!$schoolLock->fetchColumn()) {
                throw new RuntimeException('The selected school is no longer available for initial Proprietor review.');
            }
        }
        if ($action === 'review_request') {
            if (!in_array($decision, ['approve', 'reject', 'transfer'], true)
                || (in_array($decision, ['reject', 'transfer'], true)
                    && preg_match('/^.{5,500}$/us', $reason) !== 1)) {
                throw new RuntimeException('Provide a valid decision; rejection and ownership-transfer reasons must be between 5 and 500 characters.');
            }
            if ($decision === 'approve') {
                $reason = '';
            }
            $stmt = db()->prepare(
                'SELECT srr.id, srr.school_id, srr.user_id, srr.role_name, srr.status, srr.reviewed_at, srr.request_reason,
                        srr.supporting_information, u.full_name, u.email, u.status AS user_status,
                        u.email_verified_at
                 FROM school_role_requests srr
                 JOIN users u ON u.id = srr.user_id
                 WHERE srr.id = ? AND srr.school_id = ?
                   AND srr.role_name <> "school_bursar"
                 FOR UPDATE'
            );
            $stmt->execute([$requestId, $schoolId]);
            $request = $stmt->fetch();
            if (!$request) {
                throw new RuntimeException('The role request could not be found in this school.');
            }
            if (!in_array($request['role_name'], $requestableRoles, true)) {
                throw new RuntimeException('This role is no longer available for approval.');
            }
            if ($request['status'] !== 'pending') {
                db()->rollBack();
                flash(
                    'success',
                    $request['status'] === 'approved'
                        ? 'This role request was already approved and its approval email was sent. No further action is needed.'
                        : 'This role request was already rejected. Refresh the queue to see its current status.'
                );
                redirect($platformReviewer ? 'platform_admin.php' : 'school_role_requests.php');
            }
            if (!in_array($request['role_name'], $requestableRoles, true)
                || $request['user_status'] !== 'active' || $request['email_verified_at'] === null) {
                throw new RuntimeException('This request is unavailable or the applicant’s email is not verified. Refresh the list and check the applicant status.');
            }
            if ((int) $request['user_id'] === (int) $user['id']) {
                throw new RuntimeException('You cannot approve or reject your own role request.');
            }
            if ($platformReviewer) {
                $authorityStmt = db()->prepare(
                    'SELECT COUNT(*) FROM school_memberships
                     WHERE school_id = ? AND status = "active"
                       AND role_name IN ("school_proprietor", "school_headmaster")'
                );
                $authorityStmt->execute([$schoolId]);
                if ($request['role_name'] !== 'school_proprietor' || (int) $authorityStmt->fetchColumn() > 0) {
                    throw new RuntimeException('Platform review is limited to appointing or rejecting the first Proprietor request when no school authority exists.');
                }
                $invitationStmt = db()->prepare(
                    'SELECT id FROM school_proprietor_invitations
                     WHERE school_id = ? AND redeemed_request_id = ?
                       AND redeemed_by_user_id = ? AND LOWER(TRIM(email)) = LOWER(TRIM(?))
                       AND revoked_at IS NULL AND redeemed_at IS NOT NULL
                     LIMIT 1 FOR UPDATE'
                );
                $invitationStmt->execute([
                    $schoolId,
                    $requestId,
                    (int) $request['user_id'],
                    (string) $request['email'],
                ]);
                if (!$invitationStmt->fetchColumn()) {
                    throw new RuntimeException('Platform review requires a current, designated Proprietor invitation tied to this exact verified applicant and request.');
                }
            } else {
                $mayApprove = in_array('school_proprietor', $reviewerRoles, true)
                    || (in_array('school_headmaster', $reviewerRoles, true)
                        && $request['role_name'] !== 'school_headmaster');
                if (!$mayApprove) {
                    throw new RuntimeException('Only a Proprietor may approve or reject Headmaster role requests.');
                }
            }

            if (in_array($decision, ['approve', 'transfer'], true)
                && in_array($request['role_name'], $sensitiveRoles, true)
                && empty($_POST['eligibility_confirmed'])) {
                throw new RuntimeException('Confirm that the supporting eligibility evidence was reviewed before approval.');
            }
            if ($decision === 'transfer'
                && ($platformReviewer
                    || $request['role_name'] !== 'school_proprietor'
                    || !in_array('school_proprietor', $reviewerRoles, true)
                    || empty($_POST['ownership_transfer_confirmed']))) {
                throw new RuntimeException('Only the active Proprietor can explicitly confirm a Proprietor transfer.');
            }
            if ($decision === 'approve' && $request['role_name'] === 'school_parent') {
                $guardianStmt = db()->prepare(
                    'SELECT 1 FROM school_guardians
                     WHERE school_id = ? AND LOWER(TRIM(email)) = ? LIMIT 1'
                );
                $guardianStmt->execute([$schoolId, strtolower(trim((string) $request['email']))]);
                if (!$guardianStmt->fetchColumn()) {
                    throw new RuntimeException('Parent access requires a guardian record at this school with the same email address as the applicant. Ask the Headmaster or Proprietor to add or correct the guardian email before approving this request.');
                }
            }

            $studentLink = null;
            if ($decision === 'approve' && $request['role_name'] === 'school_student') {
                $linkStmt = db()->prepare(
                    'SELECT id, student_id FROM school_student_account_links
                     WHERE school_id = ? AND user_id = ? AND status = "pending"
                     FOR UPDATE'
                );
                $linkStmt->execute([$schoolId, (int) $request['user_id']]);
                $studentLink = $linkStmt->fetch();
                $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$studentLink || $studentId === false || $studentId < 1
                    || ($studentLink['student_id'] !== null && (int) $studentLink['student_id'] !== $studentId)) {
                    throw new RuntimeException('Select the matched active student record before approving student access.');
                }
                $studentStmt = db()->prepare(
                    'SELECT id FROM school_students
                     WHERE id = ? AND school_id = ? AND school_status = "active" FOR UPDATE'
                );
                $studentStmt->execute([$studentId, $schoolId]);
                if (!$studentStmt->fetchColumn()) {
                    throw new RuntimeException('The selected student record is not active at this school.');
                }
                $duplicateLink = db()->prepare(
                    'SELECT user_id FROM school_student_account_links
                     WHERE student_id = ? AND status IN ("pending", "active") AND id <> ? LIMIT 1 FOR UPDATE'
                );
                $duplicateLink->execute([$studentId, (int) $studentLink['id']]);
                if ($duplicateLink->fetchColumn()) {
                    throw new RuntimeException('This student record is already linked to another account.');
                }
            }

            $transferredFromUserId = null;
            if (in_array($decision, ['approve', 'transfer'], true)) {
                if ($request['role_name'] === 'school_proprietor') {
                    $schoolLock = db()->prepare('SELECT id FROM schools WHERE id = ? FOR UPDATE');
                    $schoolLock->execute([$schoolId]);
                    if (!$schoolLock->fetchColumn()) {
                        throw new RuntimeException('The selected school could not be found.');
                    }
                    $proprietorStmt = db()->prepare(
                        'SELECT user_id FROM school_memberships
                         WHERE school_id = ? AND role_name = "school_proprietor" AND status = "active"
                         FOR UPDATE'
                    );
                    $proprietorStmt->execute([$schoolId]);
                    $activeProprietors = array_map('intval', $proprietorStmt->fetchAll(PDO::FETCH_COLUMN));
                    if ($decision === 'approve' && $activeProprietors !== []) {
                        throw new RuntimeException('A school can have only one active Proprietor. Use the explicit ownership-transfer workflow.');
                    }
                    if ($decision === 'transfer') {
                        if (count($activeProprietors) !== 1 || $activeProprietors[0] !== (int) $user['id']) {
                            throw new RuntimeException('Only the sole active Proprietor can transfer ownership.');
                        }
                        $transferredFromUserId = $activeProprietors[0];
                        $disableProprietor = db()->prepare(
                            'UPDATE school_memberships SET status = "disabled"
                             WHERE school_id = ? AND user_id = ? AND role_name = "school_proprietor" AND status = "active"'
                        );
                        $disableProprietor->execute([$schoolId, $transferredFromUserId]);
                        if ($disableProprietor->rowCount() !== 1) {
                            throw new RuntimeException('The current Proprietor assignment changed. Refresh and try again.');
                        }
                        $remainingRoleStmt = db()->prepare(
                            'SELECT role_name FROM school_memberships
                             WHERE school_id = ? AND user_id = ? AND status = "active"
                               AND role_name NOT IN ("school_parent", "school_student")
                             ORDER BY id LIMIT 1'
                        );
                        $remainingRoleStmt->execute([$schoolId, $transferredFromUserId]);
                        $remainingRole = $remainingRoleStmt->fetchColumn();
                        $staffUpdate = db()->prepare(
                            'UPDATE school_staff SET role_name = ?, status = ?
                             WHERE school_id = ? AND user_id = ?'
                        );
                        $staffUpdate->execute([
                            $remainingRole === false ? 'school_proprietor' : (string) $remainingRole,
                            $remainingRole === false ? 'inactive' : 'active',
                            $schoolId,
                            $transferredFromUserId,
                        ]);
                    }
                }
                $otherSchool = db()->prepare(
                    'SELECT 1 FROM school_memberships
                     WHERE user_id = ? AND status = "active" AND school_id <> ? LIMIT 1 FOR UPDATE'
                );
                $otherSchool->execute([(int) $request['user_id'], $schoolId]);
                if ($otherSchool->fetchColumn()) {
                    throw new RuntimeException('This account already has access to another school. Use the audited school-transfer workflow first.');
                }
                $membershipStmt = db()->prepare(
                    'INSERT INTO school_memberships (school_id, user_id, role_name, status, approved_by)
                     VALUES (?, ?, ?, "active", ?)
                     ON DUPLICATE KEY UPDATE status = "active", approved_by = VALUES(approved_by)'
                );
                $membershipStmt->execute([$schoolId, (int) $request['user_id'], (string) $request['role_name'], (int) $user['id']]);

                if (!in_array($request['role_name'], ['school_parent', 'school_student'], true)) {
                    $staffStmt = db()->prepare(
                        'INSERT INTO school_staff (school_id, user_id, role_name, status)
                         VALUES (?, ?, ?, "active")
                         ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), status = "active"'
                    );
                    $staffStmt->execute([$schoolId, (int) $request['user_id'], (string) $request['role_name']]);
                }
                if ($studentLink) {
                    $linkApprove = db()->prepare(
                        'UPDATE school_student_account_links
                         SET student_id = ?, status = "active", requested_date_of_birth = NULL,
                             reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                         WHERE id = ? AND status = "pending"'
                    );
                    $linkApprove->execute([$studentId, (int) $user['id'], (int) $studentLink['id']]);
                }
            }

            $requestUpdate = db()->prepare(
                'UPDATE school_role_requests
                 SET status = ?, reviewer_user_id = ?, reviewed_at = UTC_TIMESTAMP(), decision_reason = ?
                 WHERE id = ? AND status = "pending"'
            );
            $requestStatus = in_array($decision, ['approve', 'transfer'], true) ? 'approved' : 'rejected';
            $requestUpdate->execute([
                $requestStatus,
                (int) $user['id'],
                $reason === '' ? null : $reason,
                $requestId,
            ]);
            if ($requestUpdate->rowCount() !== 1) {
                throw new RuntimeException('The request changed while it was being reviewed. Refresh and try again.');
            }
            if ($decision === 'reject' && $request['role_name'] === 'school_student') {
                $rejectStudentLink = db()->prepare(
                    'UPDATE school_student_account_links
                     SET status = "rejected", student_id = NULL, requested_date_of_birth = NULL,
                         reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                     WHERE school_id = ? AND user_id = ? AND status = "pending"'
                );
                $rejectStudentLink->execute([(int) $user['id'], $schoolId, (int) $request['user_id']]);
            }
            $eventStmt = db()->prepare(
                'INSERT INTO school_role_request_events
                 (request_id, school_id, subject_user_id, role_name, actor_user_id, event_type, reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $eventReason = $decision === 'approve' ? '' : $reason;
            $eventStmt->execute([
                $requestId, $schoolId, (int) $request['user_id'], (string) $request['role_name'],
                (int) $user['id'], $requestStatus, $eventReason,
            ]);
            if ($transferredFromUserId !== null) {
                $transferEvent = db()->prepare(
                    'INSERT INTO school_role_request_events
                     (request_id, school_id, subject_user_id, role_name, actor_user_id, event_type, reason)
                     VALUES (?, ?, ?, "school_proprietor", ?, "revoked", ?)'
                );
                $transferEvent->execute([
                    $requestId,
                    $schoolId,
                    $transferredFromUserId,
                    (int) $user['id'],
                    $reason,
                ]);
            }
            audit((int) $user['id'], 'school_role_request_' . $decision,
                'request_id=' . $requestId . ';school_id=' . $schoolId . ';user_id=' . (int) $request['user_id']
                    . ';role=' . (string) $request['role_name'] . ';reason=' . substr($eventReason, 0, 140));
            if ($transferredFromUserId !== null) {
                audit((int) $user['id'], 'school_proprietor_transferred',
                    'school_id=' . $schoolId . ';from_user_id=' . $transferredFromUserId
                        . ';to_user_id=' . (int) $request['user_id'] . ';request_id=' . $requestId);
            }
        } else {
            if ($platformReviewer) {
                throw new RuntimeException('Platform administration cannot revoke school roles through the bootstrap review workflow.');
            }
            if (preg_match('/^.{5,500}$/us', $reason) !== 1) {
                throw new RuntimeException('Provide a revocation reason of 5 to 500 characters.');
            }
            $membershipStmt = db()->prepare(
                'SELECT sm.id, sm.user_id, sm.role_name, u.full_name, u.email
                 FROM school_memberships sm JOIN users u ON u.id = sm.user_id
                 WHERE sm.id = ? AND sm.school_id = ? AND sm.status = "active"
                 FOR UPDATE'
            );
            $membershipStmt->execute([$requestId, $schoolId]);
            $membership = $membershipStmt->fetch();
            if (!$membership || (int) $membership['user_id'] === (int) $user['id']) {
                throw new RuntimeException('The role is unavailable or you cannot revoke your own role.');
            }
            if (!$platformReviewer) {
                $mayRevoke = in_array('school_proprietor', $reviewerRoles, true)
                    || (in_array('school_headmaster', $reviewerRoles, true)
                        && !in_array($membership['role_name'], ['school_proprietor', 'school_headmaster'], true));
                if (!$mayRevoke) {
                    throw new RuntimeException('Your approved school role does not authorize this revocation.');
                }
            }
            if ($membership['role_name'] === 'school_proprietor') {
                $countStmt = db()->prepare(
                    'SELECT COUNT(*) FROM school_memberships
                     WHERE school_id = ? AND role_name = "school_proprietor" AND status = "active"'
                );
                $countStmt->execute([$schoolId]);
                if ((int) $countStmt->fetchColumn() <= 1) {
                    throw new RuntimeException('The last Proprietor cannot be revoked here. Assign and approve a replacement first.');
                }
            }
            $disable = db()->prepare('UPDATE school_memberships SET status = "disabled" WHERE id = ? AND status = "active"');
            $disable->execute([(int) $membership['id']]);
            if ($membership['role_name'] === 'class_teacher') {
                $clearClassAssignments = db()->prepare(
                    'DELETE FROM teacher_class_assignments WHERE school_id = ? AND teacher_user_id = ?'
                );
                $clearClassAssignments->execute([$schoolId, (int) $membership['user_id']]);
                $clearClassTeacher = db()->prepare(
                    'UPDATE school_classes SET class_teacher_id = NULL
                     WHERE school_id = ? AND class_teacher_id = ?'
                );
                $clearClassTeacher->execute([$schoolId, (int) $membership['user_id']]);
            } elseif ($membership['role_name'] === 'subject_teacher') {
                $clearSubjectTeacher = db()->prepare(
                    'UPDATE class_subjects SET teacher_user_id = NULL
                     WHERE school_id = ? AND teacher_user_id = ?'
                );
                $clearSubjectTeacher->execute([$schoolId, (int) $membership['user_id']]);
            } elseif ($membership['role_name'] === 'school_student') {
                $unlinkStudent = db()->prepare(
                    'UPDATE school_student_account_links
                     SET status = "rejected", student_id = NULL, requested_date_of_birth = NULL,
                         reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                     WHERE school_id = ? AND user_id = ? AND status = "active"'
                );
                $unlinkStudent->execute([(int) $user['id'], $schoolId, (int) $membership['user_id']]);
            }
            $remainingRoleStmt = db()->prepare(
                'SELECT role_name FROM school_memberships
                 WHERE school_id = ? AND user_id = ? AND status = "active"
                   AND role_name NOT IN ("school_parent", "school_student")
                 ORDER BY id LIMIT 1'
            );
            $remainingRoleStmt->execute([$schoolId, (int) $membership['user_id']]);
            $remainingRole = $remainingRoleStmt->fetchColumn();
            $staffUpdate = db()->prepare(
                'UPDATE school_staff SET role_name = ?, status = ?
                 WHERE school_id = ? AND user_id = ?'
            );
            $staffUpdate->execute([
                $remainingRole === false ? (string) $membership['role_name'] : (string) $remainingRole,
                $remainingRole === false ? 'inactive' : 'active',
                $schoolId, (int) $membership['user_id'],
            ]);
            $eventStmt = db()->prepare(
                'INSERT INTO school_role_request_events
                 (school_id, subject_user_id, role_name, actor_user_id, event_type, reason)
                 VALUES (?, ?, ?, ?, "revoked", ?)'
            );
            $eventStmt->execute([$schoolId, (int) $membership['user_id'], (string) $membership['role_name'], (int) $user['id'], $reason]);
            audit((int) $user['id'], 'school_role_revoked',
                'school_id=' . $schoolId . ';user_id=' . (int) $membership['user_id']
                    . ';role=' . (string) $membership['role_name'] . ';reason=' . $reason);
            $request = [
                'user_id' => (int) $membership['user_id'],
                'full_name' => (string) $membership['full_name'],
                'email' => (string) $membership['email'],
                'role_name' => (string) $membership['role_name'],
            ];
            $decision = 'revoked';
        }

        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if ($exception instanceof RuntimeException) {
            flash('error', $exception->getMessage());
            redirect($platformReviewer ? 'platform_admin.php' : 'school_role_requests.php');
        }
        throw $exception;
    }

    if (isset($request) && is_array($request) && isset($request['email'])) {
        $schoolName = (string) ($school['name'] ?? '');
        $roleLabel = role_display_name((string) $request['role_name']);
        $subject = in_array($decision, ['approve', 'transfer'], true) ? 'School role request approved'
            : ($decision === 'reject' ? 'School role request decision' : 'School role access revoked');
        $message = $decision === 'transfer'
            ? 'Your request for the ' . $roleLabel . ' role at ' . $schoolName . ' has been approved through an explicit ownership transfer. Reason: ' . $reason
            : ($decision === 'approve'
            ? 'Your request for the ' . $roleLabel . ' role at ' . $schoolName . ' has been approved.'
            : ($decision === 'reject'
                ? 'Your request for the ' . $roleLabel . ' role at ' . $schoolName . ' was not approved. Reason: ' . $reason
                : 'Your ' . $roleLabel . ' access at ' . $schoolName . ' has been revoked. Reason: ' . $reason));
        $sent = send_mail(
            (string) $request['email'],
            $subject,
            '<p>Hello ' . e((string) $request['full_name']) . ',</p><p>' . e($message) . '</p>',
            'Hello ' . (string) $request['full_name'] . ",\n\n" . $message
        );
        if ($decision === 'transfer' && isset($user['email'], $user['full_name'])) {
            $outgoingMessage = 'Your Proprietor ownership at ' . $schoolName . ' was transferred to '
                . (string) $request['full_name'] . '. Reason: ' . $reason;
            $outgoingSent = send_mail(
                (string) $user['email'],
                'Proprietor ownership transferred',
                '<p>Hello ' . e((string) $user['full_name']) . ',</p><p>' . e($outgoingMessage) . '</p>',
                'Hello ' . (string) $user['full_name'] . ",\n\n" . $outgoingMessage
            );
            $sent = $sent && $outgoingSent;
        }
        flash('success', ($decision === 'transfer' ? 'Ownership transfer' : ucfirst($decision)) . ' recorded. ' . ($sent
            ? 'The applicant was notified by email.'
            : 'One or more email notifications failed; check SMTP configuration and server logs.'));
    } else {
        flash('success', 'Role decision recorded.');
    }
    redirect($platformReviewer ? 'platform_admin.php' : 'school_role_requests.php');
}

$pendingSql = 'SELECT srr.id, srr.user_id, srr.role_name, srr.request_reason,
            srr.supporting_information, srr.requested_at, u.full_name, u.username, u.email
     FROM school_role_requests srr
     JOIN users u ON u.id = srr.user_id
     WHERE srr.school_id = ? AND srr.status = "pending"
       AND srr.role_name <> "school_bursar"
       AND u.status = "active" AND u.email_verified_at IS NOT NULL';
if ($platformReviewer) {
    $pendingSql .= ' AND srr.role_name = "school_proprietor"
       AND EXISTS (
           SELECT 1 FROM school_proprietor_invitations invitation
           WHERE invitation.school_id = srr.school_id
             AND invitation.redeemed_request_id = srr.id
             AND invitation.redeemed_by_user_id = srr.user_id
             AND LOWER(TRIM(invitation.email)) = LOWER(TRIM(u.email))
             AND invitation.revoked_at IS NULL AND invitation.redeemed_at IS NOT NULL
       )';
}
$pendingSql .= ' ORDER BY srr.requested_at, srr.id';
$pendingStmt = db()->prepare($pendingSql);
$pendingStmt->execute([$schoolId]);
$requests = $pendingStmt->fetchAll();
$studentOptions = [];
if (array_filter($requests, static fn(array $request): bool => $request['role_name'] === 'school_student')) {
    foreach ($requests as $studentRequest) {
        if ($studentRequest['role_name'] !== 'school_student') {
            continue;
        }
        $studentsStmt = db()->prepare(
            'SELECT ss.id, ss.full_name, ss.admission_number, ss.date_of_birth
             FROM school_students ss
             WHERE ss.school_id = ? AND ss.school_status = "active"
               AND NOT EXISTS (
                   SELECT 1 FROM school_student_account_links active_link
                   WHERE active_link.student_id = ss.id AND active_link.status = "active"
               )
               AND NOT EXISTS (
                   SELECT 1 FROM school_student_account_links pending_link
                   WHERE pending_link.student_id = ss.id AND pending_link.status = "pending"
                     AND pending_link.user_id <> ?
               )
             ORDER BY ss.full_name, ss.id'
        );
        $studentsStmt->execute([$schoolId, (int) $studentRequest['user_id']]);
        $studentOptions[(int) $studentRequest['id']] = $studentsStmt->fetchAll();
    }
}
$activeStmt = db()->prepare(
    'SELECT sm.id, sm.user_id, sm.role_name, u.full_name, u.email
     FROM school_memberships sm JOIN users u ON u.id = sm.user_id
     WHERE sm.school_id = ? AND sm.status = "active"
     ORDER BY u.full_name, sm.role_name'
);
$activeStmt->execute([$schoolId]);
$activeRoles = $activeStmt->fetchAll();

render_header($platformReviewer ? 'Bootstrap school roles' : 'School role requests');
?>
<section class="panel">
    <p class="eyebrow"><?= $platformReviewer ? 'PLATFORM ADMIN' : 'SCHOOL PERSONNEL' ?></p>
    <h1><?= $platformReviewer ? 'Initial Proprietor appointment' : 'Role requests and access' ?></h1>
    <p class="muted"><?= $platformReviewer
        ? 'Platform approval is limited to a request linked to a current first-Proprietor invitation when the school has no approved Proprietor or Headmaster.'
        : 'Review role requests for this school only. Applicants keep only their already-approved permissions until a decision is recorded.' ?></p>
</section>

<section class="panel spaced-top">
    <h2>Pending role requests</h2>
    <?php if ($requests === []): ?><p class="muted">There are no pending role requests.</p>
    <?php else: ?><div class="table-scroll"><table>
        <thead><tr><th>Applicant</th><th>Role and supporting information</th><th>Request reason</th><th>Decision</th></tr></thead>
        <tbody><?php foreach ($requests as $request): ?>
            <?php
            $bootstrapRequest = $request['role_name'] === 'school_proprietor';
            if ($platformReviewer) {
                $authorityStmt = db()->prepare(
                    'SELECT COUNT(*) FROM school_memberships
                     WHERE school_id = ? AND status = "active"
                       AND role_name IN ("school_proprietor", "school_headmaster")'
                );
                $authorityStmt->execute([$schoolId]);
                if (!$bootstrapRequest || (int) $authorityStmt->fetchColumn() > 0) {
                    continue;
                }
            }
            $isSensitive = in_array($request['role_name'], $sensitiveRoles, true);
            $mayReviewRequest = $platformReviewer
                || in_array('school_proprietor', $reviewerRoles ?? [], true)
                || (in_array('school_headmaster', $reviewerRoles ?? [], true)
                    && $request['role_name'] !== 'school_headmaster');
            ?>
            <tr>
                <td><strong><?= e((string) $request['full_name']) ?></strong><br>@<?= e((string) $request['username']) ?><br><?= e((string) $request['email']) ?><br><small><?= e((string) $request['requested_at']) ?></small></td>
                <td><strong><?= e(role_display_name((string) $request['role_name'])) ?></strong><p><?= nl2br(e((string) $request['supporting_information'])) ?></p></td>
                <td><?= nl2br(e((string) $request['request_reason'])) ?></td>
                <td>
                    <?php if (!$mayReviewRequest): ?>
                        <p class="muted">Only a Proprietor may decide a Headmaster role request.</p>
                    <?php else: ?><form method="post" class="form-stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="review_request">
                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                    <?php if ($platformReviewer): ?><input type="hidden" name="school_id" value="<?= $schoolId ?>"><?php endif; ?>
                    <?php if ($request['role_name'] === 'school_student'): ?>
                        <label for="student-id-<?= (int) $request['id'] ?>">Verified active student record (required to approve)</label>
                        <select id="student-id-<?= (int) $request['id'] ?>" name="student_id">
                            <option value="">Select student record</option>
                            <?php foreach ($studentOptions[(int) $request['id']] ?? [] as $studentOption): ?>
                                <option value="<?= (int) $studentOption['id'] ?>"><?= e((string) $studentOption['full_name']) ?><?= $studentOption['admission_number'] ? ' · ' . e((string) $studentOption['admission_number']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <?php if ($isSensitive): ?><label class="check-label"><input type="checkbox" name="eligibility_confirmed" value="1"> I verified the required eligibility evidence (required to approve)</label><?php endif; ?>
                    <?php if (!$platformReviewer && $selectedRole === 'school_proprietor' && $request['role_name'] === 'school_proprietor'): ?><label class="check-label"><input type="checkbox" name="ownership_transfer_confirmed" value="1"> I explicitly authorize transferring Proprietor ownership to this applicant</label><?php endif; ?>
                    <div data-role-decision-reason>
                        <label for="decision-reason-<?= (int) $request['id'] ?>">Decision reason <span class="label-hint">Required for rejection or ownership transfer</span></label>
                        <textarea id="decision-reason-<?= (int) $request['id'] ?>" name="decision_reason" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="inline-form"><button class="button button-primary" name="decision" value="approve" type="submit" data-role-decision>Approve role</button><button class="button button-quiet" name="decision" value="reject" type="submit" data-role-decision>Reject role</button><?php if (!$platformReviewer && $selectedRole === 'school_proprietor' && $request['role_name'] === 'school_proprietor'): ?><button class="button button-quiet" name="decision" value="transfer" type="submit" data-role-decision>Transfer ownership to applicant</button><?php endif; ?></div>
                    </form><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
</section>

<?php if (!$platformReviewer): ?>
<section class="panel spaced-top">
    <h2>Approved roles</h2>
    <?php if ($activeRoles === []): ?><p class="muted">No active roles are assigned.</p>
    <?php else: ?><div class="table-scroll"><table><thead><tr><th>Account</th><th>Approved role</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($activeRoles as $activeRole): ?>
        <?php
        $canRevoke = in_array('school_proprietor', $reviewerRoles, true)
            || (in_array('school_headmaster', $reviewerRoles, true)
                && !in_array($activeRole['role_name'], ['school_proprietor', 'school_headmaster'], true));
        if (!$canRevoke || (int) $activeRole['user_id'] === (int) $user['id']) {
            continue;
        }
        ?>
        <tr><td><?= e((string) $activeRole['full_name']) ?><br><?= e((string) $activeRole['email']) ?></td><td><?= e(role_display_name((string) $activeRole['role_name'])) ?></td><td>
            <form method="post" class="form-stack" onsubmit="return confirm('Revoke this role? Its permissions will be removed immediately.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="revoke_role">
                <input type="hidden" name="request_id" value="<?= (int) $activeRole['id'] ?>">
                <label for="revoke-reason-<?= (int) $activeRole['id'] ?>">Reason</label>
                <input id="revoke-reason-<?= (int) $activeRole['id'] ?>" name="decision_reason" minlength="5" maxlength="500" required>
                <button class="button button-quiet" type="submit">Revoke role</button>
            </form>
        </td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<?php endif; ?>
<?php render_footer(); ?>
