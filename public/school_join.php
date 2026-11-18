<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_login();
if (platform_super_admin_user($user)) {
    redirect('platform_admin.php');
}

$activeStmt = db()->prepare(
    'SELECT DISTINCT school_id FROM school_memberships WHERE user_id = ? AND status = "active"'
);
$activeStmt->execute([(int) $user['id']]);
$activeSchoolIds = array_map('intval', $activeStmt->fetchAll(PDO::FETCH_COLUMN));
if (count($activeSchoolIds) > 1) {
    flash('error', 'Your account is linked to multiple schools. Contact the Platform Super Admin to resolve the assignment.');
    redirect('schools.php');
}
$assignedSchoolId = $activeSchoolIds[0] ?? null;

$invitationToken = trim((string) ($_POST['proprietor_invite']
    ?? $_GET['proprietor_invite']
    ?? $_SESSION['proprietor_invite_token']
    ?? ''));
$proprietorInvitation = null;
if ($invitationToken !== '') {
    if (preg_match('/\A[a-f0-9]{64}\z/i', $invitationToken) !== 1) {
        unset($_SESSION['proprietor_invite_token']);
        flash('error', 'This Proprietor invitation link is invalid or has expired.');
        redirect('schools.php');
    }
    $_SESSION['proprietor_invite_token'] = $invitationToken;
    $inviteStmt = db()->prepare(
        'SELECT invitation.id, invitation.school_id, invitation.email, school.name, school.short_name
         FROM school_proprietor_invitations invitation
         JOIN schools school ON school.id = invitation.school_id
         WHERE invitation.token_hash = ? AND invitation.revoked_at IS NULL
           AND invitation.redeemed_at IS NULL AND invitation.expires_at > UTC_TIMESTAMP()
           AND school.deleted_at IS NULL AND school.status IN ("pending", "active")
           AND school.subscription_status IN ("trial", "active")
           AND (school.subscription_expires_at IS NULL OR school.subscription_expires_at > UTC_TIMESTAMP())
         LIMIT 1'
    );
    $inviteStmt->execute([hash('sha256', strtolower($invitationToken))]);
    $proprietorInvitation = $inviteStmt->fetch() ?: null;
    if (!$proprietorInvitation
        || strtolower(trim((string) $user['email'])) !== strtolower(trim((string) $proprietorInvitation['email']))
        || ($assignedSchoolId !== null && $assignedSchoolId !== (int) $proprietorInvitation['school_id'])) {
        unset($_SESSION['proprietor_invite_token']);
        flash('error', 'This Proprietor invitation is unavailable, belongs to another verified email, or conflicts with your existing school assignment.');
        redirect('schools.php');
    }
}

$requestableRoles = [
    'school_proprietor' => 'Proprietor / School Owner',
    'school_headmaster' => 'Headmaster',
    'school_assistant_headmaster' => 'Assistant Headmaster',
    'school_secretary' => 'Secretary / Administrator',
    'class_teacher' => 'Class Teacher',
    'subject_teacher' => 'Subject Teacher',
    'school_accountant' => 'Accountant',
    'school_parent' => 'Parent / Guardian',
    'school_student' => 'Student',
];

if (isset($_GET['change_school']) && $assignedSchoolId === null) {
    unset($_SESSION['school_join_id']);
}
$selectedSchoolId = $proprietorInvitation !== null
    ? (int) $proprietorInvitation['school_id']
    : ($assignedSchoolId ?? filter_var($_SESSION['school_join_id'] ?? null, FILTER_VALIDATE_INT));
$selectedSchool = null;
if ($selectedSchoolId !== false && $selectedSchoolId > 0) {
    $schoolStmt = db()->prepare(
        "SELECT id, name, short_name, status FROM schools
         WHERE id = ? AND deleted_at IS NULL
           AND status IN (" . ($proprietorInvitation !== null ? "'pending', 'active'" : "'active'") . ")
           AND subscription_status IN ('trial','active')
           AND (subscription_expires_at IS NULL OR subscription_expires_at > UTC_TIMESTAMP())
         LIMIT 1"
    );
    $schoolStmt->execute([$selectedSchoolId]);
    $selectedSchool = $schoolStmt->fetch();
    if (!$selectedSchool) {
        unset($_SESSION['school_join_id']);
        $selectedSchoolId = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'select_school') {
        $schoolId = filter_var(
            $_POST['school_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($schoolId === false) {
            flash('error', 'Select a valid school before requesting access.');
            redirect('school_join.php');
        }
        $schoolStmt = db()->prepare(
            "SELECT id FROM schools
             WHERE id = ? AND deleted_at IS NULL AND status = 'active'
               AND subscription_status IN ('trial','active')
               AND (subscription_expires_at IS NULL OR subscription_expires_at > UTC_TIMESTAMP())
             LIMIT 1"
        );
        $schoolStmt->execute([$schoolId]);
        if (!$schoolStmt->fetchColumn()) {
            flash('error', 'That school is not currently accepting account requests.');
            redirect('school_join.php');
        }
        $_SESSION['school_join_id'] = $schoolId;
        redirect('school_join.php');
    }

    if ($action === 'request_membership') {
        $roleNames = $_POST['role_names'] ?? [];
        if (!is_array($roleNames)) {
            $roleNames = [];
        }
        $roleNames = array_values(array_unique(array_filter(
            $roleNames,
            static fn(mixed $role): bool => is_string($role) && isset($requestableRoles[$role])
        )));
        $requestReason = trim((string) ($_POST['request_reason'] ?? ''));
        $supportingInformation = trim((string) ($_POST['supporting_information'] ?? ''));
        $admissionNumber = trim((string) ($_POST['student_admission_number'] ?? ''));
        $dateOfBirth = trim((string) ($_POST['student_date_of_birth'] ?? ''));
        $isInvitedProprietor = $proprietorInvitation !== null
            && $roleNames === ['school_proprietor'];
        if (!$selectedSchool || $selectedSchoolId === false || $roleNames === []
            || (!$isInvitedProprietor
                && (preg_match('/^.{5,500}$/us', $requestReason) !== 1
                    || preg_match('/^.{12,2000}$/us', $supportingInformation) !== 1))
            || (in_array('school_student', $roleNames, true) && count($roleNames) !== 1)) {
            flash('error', 'Select a school, one or more supported roles, a request reason, and supporting information.');
            redirect('school_join.php');
        }
        if ($proprietorInvitation !== null && $roleNames !== ['school_proprietor']) {
            flash('error', 'This invitation is limited to the designated first Proprietor role.');
            redirect('school_join.php');
        }
        if ($proprietorInvitation === null && in_array('school_proprietor', $roleNames, true)) {
            flash('error', 'Proprietor access is assigned only to the email designated by the Platform Super Admin.');
            redirect('school_join.php');
        }
        if (array_intersect($roleNames, ['school_headmaster', 'school_assistant_headmaster', 'school_accountant'])
            && strlen($supportingInformation) < 20) {
            flash('error', 'Sensitive-role requests require at least 20 characters describing supporting eligibility evidence.');
            redirect('school_join.php');
        }
        $candidateStudentId = null;
        if (in_array('school_student', $roleNames, true)) {
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOfBirth);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($admissionNumber === '' || strlen($admissionNumber) > 50
                || $parsedDate === false || $parsedDate->format('Y-m-d') !== $dateOfBirth
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                flash('error', 'Student role requests require the admission number and date of birth recorded by the school.');
                redirect('school_join.php');
            }
        }

        db()->beginTransaction();
        try {
            $lockUser = db()->prepare(
                "SELECT id, status, email, email_verified_at FROM users
                 WHERE id = ? FOR UPDATE"
            );
            $lockUser->execute([(int) $user['id']]);
            $account = $lockUser->fetch();
            if (!$account || $account['status'] !== 'active' || $account['email_verified_at'] === null) {
                throw new RuntimeException('An active, email-verified account is required for a school access request.');
            }
            if ($proprietorInvitation !== null) {
                $lockInvite = db()->prepare(
                    'SELECT invitation.id, invitation.school_id, invitation.email, school.proprietor_email
                     FROM school_proprietor_invitations invitation
                     JOIN schools school ON school.id = invitation.school_id
                     WHERE invitation.id = ? AND invitation.token_hash = ?
                       AND invitation.revoked_at IS NULL AND invitation.redeemed_at IS NULL
                       AND invitation.expires_at > UTC_TIMESTAMP()
                       AND school.deleted_at IS NULL AND school.status IN ("pending", "active")
                       AND school.subscription_status IN ("trial", "active")
                       AND (school.subscription_expires_at IS NULL OR school.subscription_expires_at > UTC_TIMESTAMP())
                     FOR UPDATE'
                );
                $lockInvite->execute([
                    (int) $proprietorInvitation['id'],
                    hash('sha256', strtolower($invitationToken)),
                ]);
                $lockedInvitation = $lockInvite->fetch();
                if (!$lockedInvitation
                    || (int) $lockedInvitation['school_id'] !== (int) $selectedSchoolId
                    || strtolower(trim((string) $account['email'])) !== strtolower(trim((string) $lockedInvitation['email']))) {
                    throw new RuntimeException('This invitation is unavailable or must be accepted by the designated email account.');
                }
                if ($lockedInvitation['proprietor_email'] !== null
                    && strtolower(trim((string) $lockedInvitation['proprietor_email'])) !== strtolower(trim((string) $account['email']))) {
                    throw new RuntimeException('The Platform Super Admin has designated a different email for this school’s Proprietor.');
                }
                db()->prepare(
                    'UPDATE schools SET proprietor_email = ? WHERE id = ? AND proprietor_email IS NULL'
                )->execute([strtolower(trim((string) $account['email'])), (int) $selectedSchoolId]);
            }
            if ($isInvitedProprietor) {
                if (!school_assign_designated_proprietor(db(), (int) $selectedSchoolId, (int) $user['id'])) {
                    throw new RuntimeException('The designated Proprietor account could not be activated. Contact the Platform Super Admin.');
                }
                $redeemInvite = db()->prepare(
                    'UPDATE school_proprietor_invitations
                     SET redeemed_by_user_id = ?, redeemed_at = UTC_TIMESTAMP()
                     WHERE id = ? AND redeemed_at IS NULL AND revoked_at IS NULL'
                );
                $redeemInvite->execute([(int) $user['id'], (int) $proprietorInvitation['id']]);
                if ($redeemInvite->rowCount() !== 1) {
                    throw new RuntimeException('This invitation has already been accepted or revoked.');
                }
            } else {
            $recentRequestCount = db()->prepare(
                'SELECT COUNT(*) FROM school_role_requests
                 WHERE user_id = ? AND requested_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR'
            );
            $recentRequestCount->execute([(int) $user['id']]);
            if ((int) $recentRequestCount->fetchColumn() + count($roleNames) > 5) {
                throw new RuntimeException('You may submit up to five role requests in 24 hours. Try again later.');
            }

            $otherSchoolPending = db()->prepare(
                'SELECT 1 FROM school_role_requests WHERE user_id = ? AND status = "pending"
                 AND school_id <> ? LIMIT 1 FOR UPDATE'
            );
            $otherSchoolPending->execute([(int) $user['id'], (int) $selectedSchoolId]);
            if ($otherSchoolPending->fetchColumn()) {
                throw new RuntimeException('You already have a pending request at another school. Resolve it before requesting another school role.');
            }
            $existingRole = db()->prepare(
                'SELECT 1 FROM school_memberships
                 WHERE school_id = ? AND user_id = ? AND role_name = ? AND status = "active"
                 LIMIT 1 FOR UPDATE'
            );
            $pendingRole = db()->prepare(
                'SELECT 1 FROM school_role_requests
                 WHERE school_id = ? AND user_id = ? AND role_name = ? AND status = "pending"
                 LIMIT 1 FOR UPDATE'
            );
            $requestStmt = db()->prepare(
                'INSERT INTO school_role_requests
                 (school_id, user_id, role_name, request_reason, supporting_information)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $eventStmt = db()->prepare(
                'INSERT INTO school_role_request_events
                 (request_id, school_id, subject_user_id, role_name, actor_user_id, event_type, reason)
                 VALUES (?, ?, ?, ?, ?, "requested", ?)'
            );
            if (in_array('school_student', $roleNames, true)) {
                $candidateStmt = db()->prepare(
                    'SELECT id FROM school_students
                     WHERE school_id = ? AND admission_number = ? AND date_of_birth = ?
                       AND school_status = "active" LIMIT 1'
                );
                $candidateStmt->execute([(int) $selectedSchoolId, $admissionNumber, $dateOfBirth]);
                $matchedStudent = $candidateStmt->fetchColumn();
                $candidateStudentId = $matchedStudent === false ? null : (int) $matchedStudent;
                $linkStmt = db()->prepare(
                    'SELECT id, status FROM school_student_account_links WHERE user_id = ? FOR UPDATE'
                );
                $linkStmt->execute([(int) $user['id']]);
                $oldLink = $linkStmt->fetch();
                if ($oldLink && in_array($oldLink['status'], ['pending', 'active'], true)) {
                    throw new RuntimeException('This account already has a pending or active student record link.');
                }
            }
            $proprietorRequestId = null;
            foreach ($roleNames as $roleName) {
                $existingRole->execute([(int) $selectedSchoolId, (int) $user['id'], $roleName]);
                $pendingRole->execute([(int) $selectedSchoolId, (int) $user['id'], $roleName]);
                if ($existingRole->fetchColumn() || $pendingRole->fetchColumn()) {
                    throw new RuntimeException(role_display_name($roleName) . ' is already active or pending for your account.');
                }
                $requestStmt->execute([
                    (int) $selectedSchoolId, (int) $user['id'], $roleName, $requestReason, $supportingInformation,
                ]);
                $requestId = (int) db()->lastInsertId();
                if ($roleName === 'school_proprietor') {
                    $proprietorRequestId = $requestId;
                }
                $eventStmt->execute([$requestId, (int) $selectedSchoolId, (int) $user['id'], $roleName, (int) $user['id'], $requestReason]);
                audit((int) $user['id'], 'school_role_requested',
                    'request_id=' . $requestId . ';school_id=' . (int) $selectedSchoolId . ';role=' . $roleName);
            }
            if ($proprietorInvitation !== null && $proprietorRequestId !== null) {
                $redeemInvite = db()->prepare(
                    'UPDATE school_proprietor_invitations
                     SET redeemed_by_user_id = ?, redeemed_request_id = ?, redeemed_at = UTC_TIMESTAMP()
                     WHERE id = ? AND redeemed_at IS NULL AND revoked_at IS NULL'
                );
                $redeemInvite->execute([
                    (int) $user['id'],
                    $proprietorRequestId,
                    (int) $proprietorInvitation['id'],
                ]);
                if ($redeemInvite->rowCount() !== 1) {
                    throw new RuntimeException('This invitation has already been accepted or revoked.');
                }
            }
            if (in_array('school_student', $roleNames, true)) {
                if ($oldLink) {
                    $linkUpdate = db()->prepare(
                        'UPDATE school_student_account_links
                         SET school_id = ?, student_id = ?, requested_admission_number = ?,
                             requested_date_of_birth = ?, status = "pending",
                             reviewed_by = NULL, reviewed_at = NULL, requested_at = UTC_TIMESTAMP()
                         WHERE id = ? AND status = "rejected"'
                    );
                    $linkUpdate->execute([
                        (int) $selectedSchoolId, $candidateStudentId, $admissionNumber,
                        $dateOfBirth, (int) $oldLink['id'],
                    ]);
                } else {
                    $linkInsert = db()->prepare(
                        'INSERT INTO school_student_account_links
                         (school_id, student_id, user_id, requested_admission_number, requested_date_of_birth, status)
                         VALUES (?, ?, ?, ?, ?, "pending")'
                    );
                    $linkInsert->execute([
                        (int) $selectedSchoolId, $candidateStudentId, (int) $user['id'],
                        $admissionNumber, $dateOfBirth,
                    ]);
                }
            }
            }
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                flash('error', $exception->getMessage());
                redirect('school_join.php');
            }
            throw $exception;
        }

        $notificationStatuses = [];
        if (!$isInvitedProprietor) {
            foreach ($roleNames as $roleName) {
                $notificationStatuses[] = notify_school_role_reviewers(
                    (int) $selectedSchoolId,
                    (int) $user['id'],
                    $roleName
                );
            }
        }
        if ($assignedSchoolId === null) {
            unset($_SESSION['school_join_id']);
        }
        unset($_SESSION['proprietor_invite_token']);
        $notificationNote = in_array('delivery_failed', $notificationStatuses, true)
            ? ' Email notification failed; the role request remains visible in the school review queue.'
            : '';
        flash(
            'success',
            $isInvitedProprietor
                ? ($selectedSchool['status'] === 'active'
                    ? 'Your email is verified and Proprietor access is active. You can now open the school workspace.'
                    : 'Your email is verified and Proprietor access is active. The Platform Super Admin must activate the school portal before it can be used.')
                : 'Each role request is pending review. It does not grant permissions until an authorized school administrator approves the role.' . $notificationNote
        );
        redirect('schools.php');
    }
}

if ($selectedSchool === null) {
    $schoolListStmt = db()->query(
        "SELECT id, name, short_name, region
         FROM schools
         WHERE deleted_at IS NULL AND status = 'active'
           AND subscription_status IN ('trial','active')
           AND (subscription_expires_at IS NULL OR subscription_expires_at > UTC_TIMESTAMP())
         ORDER BY name"
    );
    $schools = $schoolListStmt->fetchAll();
}

render_header('Request school access', false);
?>
<section class="auth-card auth-card-wide">
    <p class="eyebrow">SCHOOL PORTAL</p>
    <?php if ($selectedSchool === null): ?>
        <h1>Select your school</h1>
        <p class="muted">Select your school. Each role is requested and reviewed separately before any permissions are granted.</p>
        <?php if ($schools === []): ?>
            <p class="notice notice-error">No active schools are accepting access requests.</p>
        <?php else: ?>
            <form method="post" action="school_join.php" class="form-stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="select_school">
                <label for="school_id">School</label>
                <select id="school_id" name="school_id" required>
                    <option value="">Choose a school</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= (int) $school['id'] ?>"><?= e(strtoupper((string) $school['name'])) ?> (<?= e((string) $school['short_name']) ?>)<?= $school['region'] ? ' · ' . e((string) $school['region']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button button-primary button-wide" type="submit">Continue</button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <h1><?= $proprietorInvitation !== null ? 'Activate Proprietor access' : 'Request school access' ?></h1>
        <?php if ($proprietorInvitation !== null): ?>
            <p class="muted">You are the designated first Proprietor for <strong><?= e(strtoupper((string) $selectedSchool['name'])) ?></strong>. Accepting the invitation activates your role without another approval. The Platform Super Admin must activate the school portal before it can be used.</p>
        <?php else: ?>
            <p class="muted">Selected school: <strong><?= e(strtoupper((string) $selectedSchool['name'])) ?></strong>. Request another role under your account; your existing approved permissions remain unchanged.</p>
        <?php endif; ?>
        <?php if ($assignedSchoolId === null): ?><p><a href="school_join.php?change_school=1">Choose a different school</a></p><?php endif; ?>
        <form method="post" action="school_join.php" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request_membership">
            <?php if ($proprietorInvitation !== null): ?>
                <input type="hidden" name="proprietor_invite" value="<?= e($invitationToken) ?>">
            <?php endif; ?>
            <fieldset class="role-request-options">
                <legend>School roles requested</legend>
                <?php if ($proprietorInvitation !== null): ?>
                    <p class="muted">Designated role: <strong><?= e($requestableRoles['school_proprietor']) ?></strong></p>
                    <input type="hidden" name="role_names[]" value="school_proprietor">
                <?php else: ?>
                    <?php foreach ($requestableRoles as $role => $label): ?>
                        <?php if ($role === 'school_proprietor') { continue; } ?>
                        <label class="check-label"><input type="checkbox" name="role_names[]" value="<?= e($role) ?>" <?= $role === 'school_student' ? 'data-student-role-select' : '' ?>> <?= e($label) ?></label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </fieldset>
            <div <?= $proprietorInvitation !== null ? 'hidden' : '' ?>>
                <label for="request_reason">Why are you requesting this role?</label>
                <textarea id="request_reason" name="request_reason" rows="3" minlength="5" maxlength="500" <?= $proprietorInvitation === null ? 'required' : '' ?>></textarea>
                <label for="supporting_information">Supporting information or eligibility evidence</label>
                <textarea id="supporting_information" name="supporting_information" rows="4" minlength="12" maxlength="2000" <?= $proprietorInvitation === null ? 'required' : '' ?>></textarea>
                <small class="muted">For Headmaster and Accountant requests, describe the evidence or authorized confirmation. A reviewer must independently verify it.</small>
            </div>
            <div data-student-registration-fields hidden>
                <label for="student_admission_number">Student admission number</label>
                <input id="student_admission_number" name="student_admission_number" type="text" maxlength="50" autocomplete="off">
                <label for="student_date_of_birth">Date of birth recorded by the school</label>
                <input id="student_date_of_birth" name="student_date_of_birth" type="date" autocomplete="bday">
            </div>
            <button class="button button-primary button-wide" type="submit"><?= $proprietorInvitation !== null ? 'Activate Proprietor access' : 'Submit access request' ?></button>
        </form>
    <?php endif; ?>
    <p class="auth-switch"><a href="schools.php">Return to my school assignment</a></p>
</section>
<?php render_footer(); ?>
