<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

if (current_user() !== null) {
    flash('error', 'Sign out before creating a separate school account.');
    redirect('dashboard.php');
}

$registrationRoles = [
    'school_headmaster' => 'Headmaster',
    'school_assistant_headmaster' => 'Assistant Headmaster',
    'school_secretary' => 'Secretary / Administrator',
    'class_teacher' => 'Class Teacher',
    'subject_teacher' => 'Subject Teacher',
    'school_accountant' => 'Accountant',
    'school_parent' => 'Parent / Guardian',
    'school_student' => 'Student',
];

if (isset($_GET['change_school'])) {
    unset($_SESSION['school_registration_id']);
}

$schoolId = filter_var($_SESSION['school_registration_id'] ?? null, FILTER_VALIDATE_INT);
$school = null;
if ($schoolId !== false && $schoolId > 0) {
    $schoolStmt = db()->prepare(
        "SELECT id, name, short_name, status
         FROM schools
         WHERE id = ? AND deleted_at IS NULL
           AND status IN ('pending', 'active')
           AND subscription_status IN ('trial','active')
           AND (subscription_expires_at IS NULL OR subscription_expires_at > UTC_TIMESTAMP())
         LIMIT 1"
    );
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    if (!$school) {
        unset($_SESSION['school_registration_id']);
        $schoolId = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'select_school') {
        $selectedSchoolId = filter_var(
            $_POST['school_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($selectedSchoolId === false) {
            flash('error', 'Select a valid school before continuing.');
            redirect('register.php');
        }
        $confirmedSchoolId = filter_var(
            $_POST['confirmed_school_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($confirmedSchoolId === false || $confirmedSchoolId !== $selectedSchoolId) {
            flash('error', 'Confirm that the selected school is correct before continuing.');
            redirect('register.php');
        }
        $schoolStmt = db()->prepare(
            "SELECT id FROM schools
             WHERE id = ? AND deleted_at IS NULL AND status IN ('pending', 'active')
               AND subscription_status IN ('trial','active')
               AND (subscription_expires_at IS NULL OR subscription_expires_at > UTC_TIMESTAMP())
             LIMIT 1"
        );
        $schoolStmt->execute([$selectedSchoolId]);
        if (!$schoolStmt->fetchColumn()) {
            flash('error', 'That school is not currently accepting account applications.');
            redirect('register.php');
        }
        $_SESSION['school_registration_id'] = $selectedSchoolId;
        redirect('register.php');
    }

    if ($action === 'create_school_account') {
        if (!$school || $schoolId === false) {
            flash('error', 'Select an eligible school before starting account registration.');
            redirect('register.php');
        }

        $name = trim((string) ($_POST['full_name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $requestedRoles = $_POST['requested_roles'] ?? [];
        if (!is_array($requestedRoles)) {
            $requestedRoles = [];
        }
        $requestedRoles = array_values(array_unique(array_filter(
            $requestedRoles,
            static fn(mixed $role): bool => is_string($role) && isset($registrationRoles[$role])
        )));
        $requestReason = trim((string) ($_POST['request_reason'] ?? ''));
        $supportingInformation = trim((string) ($_POST['supporting_information'] ?? ''));
        $admissionNumber = trim((string) ($_POST['student_admission_number'] ?? ''));
        $dateOfBirth = trim((string) ($_POST['student_date_of_birth'] ?? ''));
        $errors = [];

        if (preg_match('/^.{2,120}$/us', $name) !== 1) {
            $errors[] = 'Enter a full name between 2 and 120 characters.';
        }
        if (!preg_match('/^[A-Za-z0-9_. -]{3,40}$/', $username)) {
            $errors[] = 'Username must be 3–40 characters and use letters, numbers, spaces, dots, underscores, or hyphens.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($requestedRoles === []) {
            $errors[] = 'Choose at least one supported school role.';
        }
        if (in_array('school_student', $requestedRoles, true) && count($requestedRoles) !== 1) {
            $errors[] = 'Student access must be requested separately from staff or parent roles.';
        }
        if (preg_match('/^.{5,500}$/us', $requestReason) !== 1
            || preg_match('/^.{12,2000}$/us', $supportingInformation) !== 1) {
            $errors[] = 'Provide a request reason and at least 12 characters of supporting information.';
        }
        if (array_intersect($requestedRoles, ['school_headmaster', 'school_assistant_headmaster', 'school_accountant'])
            && strlen($supportingInformation) < 20) {
            $errors[] = 'Sensitive-role requests require at least 20 characters describing supporting eligibility evidence.';
        }
        if (in_array('school_student', $requestedRoles, true)) {
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOfBirth);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($admissionNumber === '' || strlen($admissionNumber) > 50) {
                $errors[] = 'Enter the admission number issued by your school.';
            }
            if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $dateOfBirth
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                $errors[] = 'Enter the date of birth recorded by your school.';
            }
        }
        if (!valid_password($password)) {
            $errors[] = 'Password must be between 12 and 200 characters.';
        }
        if (!hash_equals($password, $confirm)) {
            $errors[] = 'The password confirmation does not match.';
        }

        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            redirect('register.php');
        }

        $duplicateStmt = db()->prepare(
            'SELECT 1 FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(TRIM(email)) = ? LIMIT 1'
        );
        $duplicateStmt->execute([$username, $email]);
        if ($duplicateStmt->fetchColumn()) {
            flash('error', 'That username or email already has an account. Sign in or contact the school administrator for an assignment.');
            redirect('register.php');
        }

        try {
            db()->beginTransaction();
            $lockSchool = db()->prepare(
                "SELECT id FROM schools
                 WHERE id = ? AND deleted_at IS NULL AND status IN ('pending', 'active')
                   AND subscription_status IN ('trial', 'active')
                   AND (subscription_expires_at IS NULL OR subscription_expires_at > UTC_TIMESTAMP())
                 FOR UPDATE"
            );
            $lockSchool->execute([(int) $schoolId]);
            if (!$lockSchool->fetchColumn()) {
                throw new RuntimeException('That school is no longer accepting account applications. Select an eligible school and try again.');
            }
            $createUserStmt = db()->prepare(
                'INSERT INTO users (full_name, username, email, password_hash, status)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $createUserStmt->execute([$name, $username, $email, make_password_hash($password), 'active']);
            $userId = (int) db()->lastInsertId();

            $candidateStudentId = null;
            if (in_array('school_student', $requestedRoles, true)) {
                $candidateStmt = db()->prepare(
                    'SELECT id FROM school_students
                     WHERE school_id = ? AND admission_number = ? AND date_of_birth = ?
                       AND school_status = "active" LIMIT 1'
                );
                $candidateStmt->execute([(int) $schoolId, $admissionNumber, $dateOfBirth]);
                $matchedStudentId = $candidateStmt->fetchColumn();
                if ($matchedStudentId !== false) {
                    $candidateStudentId = (int) $matchedStudentId;
                }
            }

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
            foreach ($requestedRoles as $requestedRole) {
                $requestStmt->execute([(int) $schoolId, $userId, $requestedRole, $requestReason, $supportingInformation]);
                $requestId = (int) db()->lastInsertId();
                $eventStmt->execute([$requestId, (int) $schoolId, $userId, $requestedRole, $userId, $requestReason]);
                audit($userId, 'school_role_requested', 'request_id=' . $requestId . ';school_id=' . (int) $schoolId . ';role=' . $requestedRole);
            }
            if (in_array('school_student', $requestedRoles, true)) {
                $linkStmt = db()->prepare(
                    'INSERT INTO school_student_account_links
                     (school_id, student_id, user_id, requested_admission_number, requested_date_of_birth, status)
                     VALUES (?, ?, ?, ?, ?, "pending")'
                );
                $linkStmt->execute([(int) $schoolId, $candidateStudentId, $userId, $admissionNumber, $dateOfBirth]);
            }
            audit($userId, 'school_registration_requested',
                'school_id=' . (int) $schoolId . ';roles=' . implode(',', $requestedRoles));
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                flash('error', 'That username or email already has an account. Sign in or contact the school administrator for an assignment.');
                redirect('register.php');
            }
            if ($exception instanceof RuntimeException) {
                flash('error', $exception->getMessage());
                redirect('register.php');
            }
            throw $exception;
        }

        set_pending_user($userId);
        unset($_SESSION['school_registration_id']);
        $reviewNoticeStatuses = [];
        foreach ($requestedRoles as $requestedRole) {
            $reviewNoticeStatuses[] = notify_school_role_reviewers((int) $schoolId, $userId, $requestedRole);
        }
        $verificationResult = issue_email_verification_code($userId, $email);
        if ($verificationResult === 'sent') {
            $notice = in_array('delivery_failed', $reviewNoticeStatuses, true)
                ? ' The school review notice could not be emailed; the request is recorded securely.'
                : '';
            flash('success', 'Account created. Verify your email, then the school will review each requested role separately.' . $notice);
        } elseif ($verificationResult === 'rate_limited') {
            flash('error', 'Account created. A verification code was recently sent; wait one minute before requesting another.');
        } else {
            flash('error', 'Account created, but the verification email could not be delivered. Check the configured mail service and request a new code.');
        }
        redirect('verify_email.php');
    }
}

if ($school === null) {
    $schoolListStmt = db()->query(
        "SELECT id, name, short_name, logo_path, region, district, status
         FROM schools
         WHERE deleted_at IS NULL AND status IN ('pending', 'active')
           AND subscription_status IN ('trial','active')
           AND (subscription_expires_at IS NULL OR subscription_expires_at > UTC_TIMESTAMP())
         ORDER BY name"
    );
    $schools = $schoolListStmt->fetchAll();
    $superAdminContact = platform_super_admin_contact_details();
}

render_header('School registration', false);
?>
<section class="auth-card auth-card-wide">
    <p class="eyebrow">SCHOOL PORTAL</p>
    <?php if ($school === null): ?>
        <h1>Register with your school</h1>
        <p class="muted">Select your school and request an available school role. The Proprietor is set up separately by the Platform Super Admin.</p>
        <p class="muted"><a href="proprietor_setup.php">Already designated as a school Proprietor? Set up your account with your OTP.</a></p>
        <?php if ($schools === []): ?>
            <p class="notice notice-error">No schools are currently accepting registrations. Contact support if your school is not listed.</p>
        <?php else: ?>
            <form method="post" action="register.php" class="form-stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="select_school">
                <input type="hidden" name="confirmed_school_id" value="" data-confirmed-school-id>
                <label for="school-search">Search for Your School</label>
                <input id="school-search" type="search" autocomplete="off" placeholder="Type the school name or part of it" aria-controls="school-search-results" aria-describedby="school-search-status">
                <p id="school-search-status" class="muted" role="status" aria-live="polite">Search by school name, short name, region, or district.</p>
                <div id="school-search-results" class="registration-school-results" role="radiogroup" aria-label="Matching schools" data-school-search-results>
                    <?php foreach ($schools as $option): ?>
                        <label class="registration-school-option" data-school-option data-school-search="<?= e(trim(implode(' ', array_filter([(string) $option['name'], (string) $option['short_name'], (string) $option['region'], (string) $option['district'], $option['status'] === 'pending' ? 'setup pending' : 'active'])))) ?>">
                            <input type="radio" name="school_id" value="<?= (int) $option['id'] ?>" required>
                            <?= school_identity_markup($option, true, 'compact') ?>
                            <span class="registration-school-location"><?= e(trim(implode(' · ', array_filter([(string) $option['region'], (string) $option['district']]))) ?: 'School') ?><?php if ($option['status'] === 'pending'): ?> · Setup pending<?php endif; ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="muted" data-school-search-empty hidden>No matching school found. Check the spelling or contact the Super Admin to request school setup.</p>
                <div class="registration-school-confirmation" data-school-confirmation hidden aria-live="polite">
                    <p class="registration-school-confirmation-title">Confirm your school</p>
                    <div class="registration-selected-school">
                        <span data-selected-school-identity></span>
                        <span class="registration-school-location" data-selected-school-location></span>
                    </div>
                    <label class="check-label"><input type="checkbox" data-confirm-school> This is the school I want to register with.</label>
                </div>
                <button class="button button-primary button-wide" type="submit" data-school-continue disabled>Continue</button>
            </form>
        <?php endif; ?>
        <aside class="registration-school-help">
            <h2>IT Support</h2>
            <p>Call or WhatsApp IT Support Manager</p>
            <p>If your school is not listed, contact support to request school registration and setup.</p>
            <div class="registration-contact-grid">
                <section class="registration-contact-group registration-contact-phone">
                    <h3>Phone or WhatsApp</h3>
                    <div class="registration-contact-actions">
                        <a class="registration-contact-link registration-contact-number" href="<?= e($superAdminContact['phone_link']) ?>"><?= e((string) $superAdminContact['phone_numbers'][0]) ?></a>
                        <a class="registration-contact-link registration-contact-icon-link" href="<?= e($superAdminContact['phone_link']) ?>" aria-label="Call IT Support at <?= e((string) $superAdminContact['phone_numbers'][0]) ?>" title="Call IT Support"><span class="registration-contact-icon" aria-hidden="true"><?= sidebar_icon('phone') ?></span></a>
                        <a class="registration-contact-link registration-contact-icon-link is-whatsapp" href="<?= e($superAdminContact['whatsapp_link']) ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp IT Support at <?= e((string) $superAdminContact['phone_numbers'][0]) ?>" title="WhatsApp IT Support"><span class="registration-contact-icon" aria-hidden="true"><?= sidebar_icon('whatsapp') ?></span></a>
                    </div>
                </section>
                <section class="registration-contact-group">
                    <h3>Email</h3>
                    <a class="registration-contact-link is-email" href="<?= e($superAdminContact['email_link']) ?>">
                        <span class="registration-contact-icon" aria-hidden="true"><?= sidebar_icon('email') ?></span>
                        <strong><?= e((string) $superAdminContact['email']) ?></strong>
                    </a>
                </section>
            </div>
        </aside>
    <?php else: ?>
        <h1>Create your account</h1>
        <p class="muted">School: <strong><?= e((string) $school['name']) ?></strong>. School roles require approval after email verification. The school Proprietor is already created during school setup.</p>
        <?php if ($school['status'] === 'pending'): ?>
            <p class="notice">This school is completing setup. The Platform Super Admin must activate the portal before it can be used.</p>
        <?php endif; ?>
        <p><a href="register.php?change_school=1">Choose a different school</a></p>
        <form method="post" action="register.php" class="form-stack" data-loading-form>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_school_account">
            <label for="full_name">Full name</label>
            <input id="full_name" name="full_name" type="text" autocomplete="name" maxlength="120" required>
            <label for="username">Username</label>
            <input id="username" name="username" type="text" autocomplete="username" minlength="3" maxlength="40" pattern="[A-Za-z0-9_. -]+" placeholder="e.g. Ama Mensah" required>
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" autocomplete="email" maxlength="254" required>
            <fieldset class="role-choice-list">
                <legend>School roles requested</legend>
                <small class="muted">Select your role. Each request is reviewed after email verification.</small>
                <?php foreach ($registrationRoles as $role => $label): ?>
                    <label class="check-label">
                        <input type="checkbox" name="requested_roles[]" value="<?= e($role) ?>"
                            <?= $role === 'school_student' ? 'data-student-role-select' : '' ?>>
                        <?= e($label) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <div data-role-request-details>
                <label for="request_reason">Why are you requesting this role?</label>
                <textarea id="request_reason" name="request_reason" rows="3" minlength="5" maxlength="500"></textarea>
                <label for="supporting_information">Supporting information or eligibility evidence</label>
                <textarea id="supporting_information" name="supporting_information" rows="4" minlength="12" maxlength="2000"></textarea>
                <small class="muted">For Headmaster and Accountant requests, describe the evidence or authorized confirmation. A reviewer must independently verify it.</small>
            </div>
            <div data-student-registration-fields hidden>
                <label for="student_admission_number">Student admission number</label>
                <input id="student_admission_number" name="student_admission_number" type="text" maxlength="50" autocomplete="off">
                <label for="student_date_of_birth">Date of birth recorded by the school</label>
                <input id="student_date_of_birth" name="student_date_of_birth" type="date" autocomplete="bday">
                <small class="muted">The school verifies your student record and links it to this account before student access is granted.</small>
            </div>
            <small class="muted">Your account must verify its email. School access remains pending until an authorized school administrator approves the requested role.</small>
            <label for="password">Password <span class="label-hint">12 characters minimum</span></label>
            <div class="input-wrap">
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required>
                <button class="reveal-button" type="button" data-password-toggle="password" aria-label="Show password">Show</button>
            </div>
            <label for="confirm_password">Confirm password</label>
            <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required>
            <button class="button button-primary button-wide" type="submit">
                <span class="button-label">Create account</span>
                <span class="button-loading" aria-hidden="true">Creating…</span>
            </button>
        </form>
    <?php endif; ?>
    <p class="auth-switch">Already registered? <a href="index.php">Sign in</a></p>
</section>
<?php render_footer(); ?>
