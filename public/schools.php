<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_login();
$platformAdmin = platform_super_admin_user($user);
$memberships = school_user_memberships((int) $user['id']);
$activeSchoolId = active_school_id();
$proprietorEmailAvailable = school_has_proprietor_email_column();
$proprietorPhoneAvailable = school_has_proprietor_phone_column();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'select_school') {
        if ($platformAdmin) {
            flash('error', 'Use IT Support Mode to open a school portal.');
            redirect('platform_admin.php');
        }
        $schoolId = filter_var($_POST['school_id'] ?? null, FILTER_VALIDATE_INT);
        if ($schoolId === false || $schoolId < 1) {
            flash('error', 'Choose a valid school.');
            redirect('schools.php');
        }
        $roleName = trim((string) ($_POST['role_name'] ?? ''));
        set_active_school($schoolId, $roleName !== '' ? $roleName : null);
        if (active_school_id() !== $schoolId) {
            audit((int) $user['id'], 'school_selection_denied', 'requested_school_id=' . $schoolId);
            flash('error', 'School selection was denied. Your account must have active access to exactly one school.');
            redirect('schools.php');
        }
        flash('success', 'School workspace and approved role selected.');
        redirect((string) ($_SESSION['active_school_role'] ?? '') === 'school_student'
            ? 'student_dashboard.php'
            : 'school_dashboard.php');
    }

    if ($action === 'create_school') {
        if (!$platformAdmin) {
            http_response_code(403);
            exit('Only the platform super admin can create new schools.');
        }
        if (!$proprietorEmailAvailable || !$proprietorPhoneAvailable) {
            flash('error', 'Apply database migrations 093_designated_proprietor_email.sql and 094_school_proprietor_phone.sql before setting up the school and its Proprietor.');
            redirect('schools.php');
        }

        $name = trim((string) ($_POST['school_name'] ?? ''));
        $shortName = trim((string) ($_POST['short_name'] ?? ''));
        $region = trim((string) ($_POST['region'] ?? ''));
        $district = trim((string) ($_POST['district'] ?? ''));
        $contactEmail = strtolower(trim((string) ($_POST['contact_email'] ?? '')));
        $proprietorName = trim((string) ($_POST['proprietor_name'] ?? ''));
        $proprietorEmail = strtolower(trim((string) ($_POST['proprietor_email'] ?? '')));
        $proprietorPhone = trim((string) ($_POST['proprietor_phone'] ?? ''));
        $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));

        if ($name === '' || strlen($name) > 180) {
            flash('error', 'The school name must be between 1 and 180 characters.');
            redirect('schools.php');
        }
        if ($shortName === '' || strlen($shortName) > 40) {
            flash('error', 'The short school name must be between 1 and 40 characters.');
            redirect('schools.php');
        }
        if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL) && $contactEmail !== '') {
            flash('error', 'Enter a valid contact email or leave it blank.');
            redirect('schools.php');
        }
        if (!filter_var($proprietorEmail, FILTER_VALIDATE_EMAIL) || strlen($proprietorEmail) > 254) {
            flash('error', 'Enter the designated Proprietor’s valid email address.');
            redirect('schools.php');
        }
        if (preg_match('/^.{2,120}$/us', $proprietorName) !== 1) {
            flash('error', 'Enter the Proprietor’s full name (2 to 120 characters).');
            redirect('schools.php');
        }
        if ($proprietorPhone === '' || strlen($proprietorPhone) > 32) {
            flash('error', 'Enter the Proprietor’s phone number (up to 32 characters).');
            redirect('schools.php');
        }

        if (strlen($shortName) > 0 && preg_match('/^[A-Za-z0-9_-]+$/', $shortName) !== 1) {
            flash('error', 'The short name may contain letters, numbers, underscores, or hyphens only.');
            redirect('schools.php');
        }

        $proprietorSetup = null;
        try {
            db()->beginTransaction();
            $stmt = db()->prepare(
                'INSERT INTO schools (name, short_name, school_type, address, region, district, contact_email, proprietor_name, proprietor_email, proprietor_phone, contact_phone, status, subscription_status, annual_fee)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00)'
            );
            $stmt->execute([
                $name,
                $shortName,
                'basic_school',
                trim((string) ($_POST['address'] ?? '')),
                $region,
                $district,
                $contactEmail === '' ? null : $contactEmail,
                $proprietorName,
                $proprietorEmail,
                $proprietorPhone,
                $contactPhone === '' ? null : $contactPhone,
                'pending',
                'trial',
            ]);
            $schoolId = (int) db()->lastInsertId();

            $resultSettingsStmt = db()->prepare(
                'INSERT INTO school_result_settings (school_id, ranking_policy, class_score_weight) VALUES (?, "competition", 30.00)'
            );
            $resultSettingsStmt->execute([$schoolId]);
            school_insert_grade_rules(db(), $schoolId, [
                ['A', 90.00, 1, 'Excellent'],
                ['B', 80.00, 2, 'Very good'],
                ['C', 70.00, 3, 'Good'],
                ['D', 60.00, 4, 'Credit'],
                ['E', 50.00, 5, 'Pass'],
                ['F', 0.00, 6, 'Needs improvement'],
            ]);

            $proprietorSetup = school_designate_proprietor_account(
                db(),
                $schoolId,
                $proprietorName,
                $proprietorEmail,
                $proprietorPhone,
                (int) $user['id']
            );

            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('Failed to create school: ' . $exception->getMessage());
            if ($exception instanceof PDOException && ($exception->errorInfo[1] ?? null) === 1062) {
                flash('error', 'That short school name is already in use. Choose a different short name and try again.');
            } elseif ($exception instanceof RuntimeException) {
                flash('error', $exception->getMessage());
            } elseif ($exception instanceof PDOException
                && in_array((int) ($exception->errorInfo[1] ?? 0), [1054, 1146], true)) {
                flash('error', 'The school portal database setup is incomplete. Apply the pending school migrations, then try again.');
            } else {
                flash('error', 'The school could not be created. Please check the school details and server configuration, then try again.');
            }
            redirect('schools.php');
        }

        flash(
            'success',
            $proprietorSetup['activated']
                ? 'School portal created and the existing verified account is now its Proprietor. The school remains unavailable until you activate it.'
                : 'School portal and initial Proprietor account created. A verification code was sent to the Proprietor’s email; they must enter it and set a password before accessing the portal.'
        );
        if ($proprietorSetup['needs_setup']) {
            $verificationResult = issue_email_verification_code(
                (int) $proprietorSetup['user_id'],
                $proprietorEmail,
                'proprietor_setup'
            );
            if ($verificationResult !== 'sent') {
                flash('error', 'The school and pending Proprietor account were created, but the verification code could not be sent. The Proprietor can request a new code from the account setup page.');
            }
        }
        redirect('schools.php');
    }

    if ($action === 'set_first_proprietor_email') {
        if (!$platformAdmin) {
            http_response_code(403);
            exit('Only the platform super admin can designate a school proprietor.');
        }
        if (!$proprietorEmailAvailable || !$proprietorPhoneAvailable) {
            flash('error', 'Apply database migrations 093_designated_proprietor_email.sql and 094_school_proprietor_phone.sql before designating a school Proprietor.');
            redirect('schools.php');
        }

        $schoolId = filter_var($_POST['school_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($_POST['proprietor_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['proprietor_email'] ?? '')));
        $phone = trim((string) ($_POST['proprietor_phone'] ?? ''));
        if ($schoolId === false || $schoolId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 254 || preg_match('/^.{2,120}$/us', $name) !== 1
            || $phone === '' || strlen($phone) > 32) {
            flash('error', 'Choose a valid school and enter the Proprietor’s name, email, and phone number.');
            redirect('schools.php');
        }

        $proprietorSetup = null;
        try {
            db()->beginTransaction();
            $proprietorSetup = school_designate_proprietor_account(db(), $schoolId, $name, $email, $phone, (int) $user['id']);
            if (db()->query("SHOW TABLES LIKE 'school_proprietor_invitations'")->fetchColumn()) {
                db()->prepare(
                    'UPDATE school_proprietor_invitations
                     SET revoked_at = UTC_TIMESTAMP()
                     WHERE school_id = ? AND revoked_at IS NULL AND redeemed_at IS NULL'
                )->execute([$schoolId]);
            }
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                flash('error', $exception->getMessage());
                redirect('schools.php');
            }
            error_log('Failed to designate school proprietor: ' . $exception->getMessage());
            flash('error', 'The Proprietor email could not be saved.');
            redirect('schools.php');
        }

        if ($proprietorSetup['needs_setup']) {
            $verificationResult = issue_email_verification_code(
                (int) $proprietorSetup['user_id'],
                $email,
                'proprietor_setup'
            );
            if ($verificationResult !== 'sent') {
                flash('error', 'The Proprietor account is pending, but the verification code could not be sent. They can request a new code from the account setup page.');
                redirect('schools.php');
            }
        }
        flash('success', $proprietorSetup['activated']
            ? 'The existing verified account has been added as this school’s Proprietor.'
            : 'The Proprietor account has been set up. A verification code was sent; the Proprietor must verify it and set a password.');
        redirect('schools.php');
    }
}

render_header('School management');
render_dashboard_profile_card($user);
$superAdminContact = platform_super_admin_contact_details();
$bootstrapSchools = [];
if ($platformAdmin) {
    $proprietorEmailColumn = $proprietorEmailAvailable ? 'school.proprietor_email' : 'NULL AS proprietor_email';
    $proprietorPhoneColumn = $proprietorPhoneAvailable ? 'school.proprietor_phone' : 'NULL AS proprietor_phone';
    $bootstrapSchoolsStmt = db()->query(
        'SELECT school.id, school.name, school.status, school.proprietor_name, '
        . $proprietorEmailColumn . ', ' . $proprietorPhoneColumn . '
         FROM schools school
         WHERE school.deleted_at IS NULL AND school.status IN ("pending", "active")
           AND school.subscription_status IN ("trial", "active")
           AND (school.subscription_expires_at IS NULL OR school.subscription_expires_at > UTC_TIMESTAMP())
           AND NOT EXISTS (
               SELECT 1 FROM school_memberships membership
               WHERE membership.school_id = school.id AND membership.status = "active"
                 AND membership.role_name IN ("school_proprietor", "school_headmaster")
           )
         ORDER BY school.name'
    );
    $bootstrapSchools = $bootstrapSchoolsStmt->fetchAll();
}
?>
<div class="spaced-top"><?php render_it_support_contact_section($superAdminContact); ?></div>
<section class="panel">
    <div class="toolbar-row">
        <div>
            <p class="eyebrow">MULTI-TENANT SCHOOL PORTAL</p>
            <h1>School workspaces</h1>
        </div>
    </div>
</section>

<?php if ($platformAdmin): ?>
<section class="report-action-grid" aria-label="School management actions">
    <article class="report-action-card">
    <span class="report-action-icon" aria-hidden="true"><?= sidebar_icon('school') ?></span>
    <div><h2>Create a School</h2><p>Create the school portal and its first Proprietor account for platform approval and onboarding.</p></div>
    <a class="button button-primary" href="#school-create-form" data-action-open="school-create-form">Create School</a>
    </article>
</section>
<?php if (!$proprietorEmailAvailable || !$proprietorPhoneAvailable): ?>
    <p class="notice notice-warning">Apply database migrations 093_designated_proprietor_email.sql and 094_school_proprietor_phone.sql before setting up schools with their first Proprietor.</p>
<?php endif; ?>
<section id="school-create-form" class="panel toggle-reveal panel-top-gap" data-action-view="school-create-form" hidden>
    <h2>Create a new school</h2>
    <form method="post" action="schools.php" class="form-stack" data-loading-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_school">
        <div class="form-grid two-columns">
            <div>
                <label for="school_name">School name</label>
                <input id="school_name" name="school_name" type="text" maxlength="180" required>
            </div>
            <div>
                <label for="short_name">Short name</label>
                <input id="short_name" name="short_name" type="text" maxlength="40" required>
            </div>
            <div>
                <label for="region">Region</label>
                <input id="region" name="region" type="text" maxlength="80">
            </div>
            <div>
                <label for="district">District</label>
                <input id="district" name="district" type="text" maxlength="80">
            </div>
            <div>
                <label for="contact_email">Contact email</label>
                <input id="contact_email" name="contact_email" type="email" maxlength="254">
            </div>
            <div>
                <label for="proprietor_name">First Proprietor’s full name</label>
                <input id="proprietor_name" name="proprietor_name" type="text" maxlength="120" <?= $proprietorEmailAvailable && $proprietorPhoneAvailable ? 'required' : 'disabled' ?>>
            </div>
            <div>
                <label for="proprietor_email">First Proprietor’s email</label>
                <input id="proprietor_email" name="proprietor_email" type="email" maxlength="254" <?= $proprietorEmailAvailable && $proprietorPhoneAvailable ? 'required' : 'disabled' ?>>
            </div>
            <div>
                <label for="proprietor_phone">First Proprietor’s phone number</label>
                <input id="proprietor_phone" name="proprietor_phone" type="tel" maxlength="32" <?= $proprietorEmailAvailable && $proprietorPhoneAvailable ? 'required' : 'disabled' ?>>
                <small class="muted">A verification code will be sent to the Proprietor’s email. They enter that code and set their own password.</small>
            </div>
            <div>
                <label for="contact_phone">Contact phone</label>
                <input id="contact_phone" name="contact_phone" type="tel" maxlength="32">
            </div>
            <div class="span-full">
                <label for="address">Address</label>
                <textarea id="address" name="address" rows="3" maxlength="255"></textarea>
            </div>
        </div>
        <button class="button button-primary" type="submit">Create school</button>
    </form>
</section>
<?php endif; ?>

<?php if ($platformAdmin): ?>
<section class="panel spaced-top" aria-labelledby="first-proprietor-heading">
    <p class="eyebrow">SCHOOL ONBOARDING</p>
    <h2 id="first-proprietor-heading">Designate a school’s first Proprietor</h2>
    <p class="muted">Enter the email address that should receive Proprietor access after verification. Only the Platform Super Admin can create and activate a school portal.</p>
    <?php if ($bootstrapSchools === []): ?>
        <p class="muted">All available schools already have an active school authority.</p>
    <?php else: ?>
        <div class="table-scroll"><table>
            <thead><tr><th>School</th><th>Status</th><th>Proprietor setup</th></tr></thead>
            <tbody>
                <?php foreach ($bootstrapSchools as $bootstrapSchool): ?>
                    <tr>
                        <td><?= e((string) $bootstrapSchool['name']) ?></td>
                        <td><?= e(ucfirst((string) $bootstrapSchool['status'])) ?></td>
                        <td>
                            <form method="post" action="schools.php" class="proprietor-invitation-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_first_proprietor_email">
                                <input type="hidden" name="school_id" value="<?= (int) $bootstrapSchool['id'] ?>">
                                <label class="sr-only" for="proprietor-name-<?= (int) $bootstrapSchool['id'] ?>">Proprietor name for <?= e((string) $bootstrapSchool['name']) ?></label>
                                <input id="proprietor-name-<?= (int) $bootstrapSchool['id'] ?>" name="proprietor_name" type="text" maxlength="120" value="<?= e((string) ($bootstrapSchool['proprietor_name'] ?? '')) ?>" placeholder="Full name" required>
                                <label class="sr-only" for="proprietor-email-<?= (int) $bootstrapSchool['id'] ?>">Proprietor email for <?= e((string) $bootstrapSchool['name']) ?></label>
                                <input id="proprietor-email-<?= (int) $bootstrapSchool['id'] ?>" name="proprietor_email" type="email" maxlength="254" value="<?= e((string) ($bootstrapSchool['proprietor_email'] ?? '')) ?>" placeholder="Email address" required>
                                <label class="sr-only" for="proprietor-phone-<?= (int) $bootstrapSchool['id'] ?>">Proprietor phone for <?= e((string) $bootstrapSchool['name']) ?></label>
                                <input id="proprietor-phone-<?= (int) $bootstrapSchool['id'] ?>" name="proprietor_phone" type="tel" maxlength="32" value="<?= e((string) ($bootstrapSchool['proprietor_phone'] ?? '')) ?>" placeholder="Phone number" required>
                                <button class="button button-primary" type="submit">Set up</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="dashboard-grid panel-top-gap">
    <?php if ($memberships === []): ?>
        <article class="panel">
            <h2>No school assigned</h2>
                <p class="muted">Your account is not linked to a school yet. Select your school and submit a role request for approval, or register using the email designated as its first Proprietor.</p>
                <?php if (!$platformAdmin): ?><p><a class="button button-primary" href="school_join.php">Select school and request access</a></p><?php endif; ?>
        </article>
    <?php else: ?>
        <?php $activeSchoolIds = array_values(array_unique(array_column(
            array_filter($memberships, static fn(array $membership): bool => $membership['status'] === 'active'),
            'school_id'
        ))); ?>
        <?php foreach ($memberships as $membership): ?>
            <article class="panel school-workspace-card">
                <p class="eyebrow"><?= e($membership['status']) ?> · <?= e($membership['school_status']) ?></p>
                <?= school_identity_markup([
                    'id' => $membership['school_id'],
                    'name' => $membership['name'],
                    'short_name' => $membership['short_name'],
                    'logo_path' => $membership['logo_path'],
                ]) ?>
                <p class="muted">Role: <?= e($membership['role_name']) ?></p>
                <p class="muted">Region: <?= e($membership['region'] ?: 'Not set') ?> · District: <?= e($membership['district'] ?: 'Not set') ?></p>
                <?php if ($membership['status'] === 'pending'): ?>
                    <?php if ($membership['role_name'] === 'school_proprietor'): ?>
                        <p class="muted">Verify your email to activate Proprietor access. The Platform Super Admin must activate the school portal before it can be used.</p>
                    <?php else: ?>
                        <p class="muted">Your email and school-role request must be approved before access is granted.</p>
                    <?php endif; ?>
                <?php elseif ($membership['school_status'] !== 'active'): ?>
                    <p class="muted">Your role is active. The Platform Super Admin must activate the school portal before it can be used.</p>
                <?php elseif ($membership['status'] === 'active' && count($activeSchoolIds) === 1): ?>
                    <form method="post" action="schools.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="select_school">
                        <input type="hidden" name="school_id" value="<?= (int) $membership['school_id'] ?>">
                        <input type="hidden" name="role_name" value="<?= e($membership['role_name']) ?>">
                        <button class="button button-primary" type="submit" <?= $activeSchoolId === (int) $membership['school_id'] && ($_SESSION['active_school_role'] ?? '') === $membership['role_name'] ? 'disabled' : '' ?>>
                            <?= $activeSchoolId === (int) $membership['school_id'] && ($_SESSION['active_school_role'] ?? '') === $membership['role_name'] ? 'Current role' : 'Open as ' . e(role_display_name($membership['role_name'])) ?>
                        </button>
                    </form>
                <?php elseif ($membership['status'] === 'active'): ?>
                    <p class="muted">Access is locked until the platform administrator resolves multiple school assignments.</p>
                <?php endif; ?>
                <?php if ($membership['status'] !== 'active' || count($activeSchoolIds) === 1): ?>
                    <?php if (!$platformAdmin): ?><p><a class="button button-quiet" href="school_join.php<?= $membership['status'] === 'pending' ? '' : '' ?>">Request another role</a></p><?php endif; ?>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php
$hasActiveMembership = count(array_filter($memberships, static fn(array $membership): bool => $membership['status'] === 'active')) > 0;
$hasPendingMembership = count(array_filter($memberships, static fn(array $membership): bool => $membership['status'] === 'pending')) > 0;
?>
<?php if (!$platformAdmin && !$hasActiveMembership && !$hasPendingMembership && $memberships !== []): ?>
    <section class="panel panel-top-gap">
        <h2>No active school assignment</h2>
        <p class="muted">Your previous assignment is inactive. Select a school and submit a new request, or contact the platform administrator if you need a transfer.</p>
        <a class="button button-primary" href="school_join.php">Select school and request access</a>
    </section>
<?php endif; ?>

<?php if ($activeSchoolId !== null): ?>
    <?php $context = active_school_context(); ?>
    <?php if ($context): ?>
        <?php $canEditSchoolSettings = school_has_permission($user, (int) $context['id'], 'school.settings.edit')
            || school_has_permission($user, (int) $context['id'], 'school.branding.manage'); ?>
        <section class="panel school-workspace-card panel-top-gap">
            <p class="eyebrow">ACTIVE SCHOOL</p>
            <?= school_identity_markup($context) ?>
            <p class="muted">Type: <?= e((string) $context['school_type']) ?></p>
            <p class="muted">Address: <?= e((string) ($context['address'] ?: 'Not set')) ?> · Contact: <?= e((string) ($context['contact_email'] ?: 'Not set')) ?><?= $context['contact_phone'] ? ' / ' . e((string) $context['contact_phone']) : '' ?></p>
            <p class="muted">Subscription: <?= e((string) $context['subscription_status']) ?> · Expiry: <?= e((string) ($context['subscription_expires_at'] ?: 'Not set')) ?></p>
            <p><a class="button button-primary" href="school_dashboard.php">Open school dashboard</a><?php if ($canEditSchoolSettings): ?> <a class="button button-quiet" href="school_settings.php">School settings</a><?php endif; ?></p>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php render_footer(); ?>
