<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/image_uploads.php';
$user = require_login();
$stmt = db()->prepare(
    'SELECT phone, date_of_birth, profile_picture_path, occupation,
            employer, location, biography, membership_number, membership_category,
            membership_status, directory_visible, graduation_year,
            membership_id_review_required
     FROM member_profiles WHERE user_id = ?'
);
$stmt->execute([(int) $user['id']]);
$memberProfile = $stmt->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'profile_picture') {
        $oldPictures = array_values(array_unique(array_filter([
            $user['account_profile_picture_path'] ?? null,
            $memberProfile['profile_picture_path'] ?? null,
        ], static fn($path): bool => is_string($path) && $path !== '')));
        $removePicture = ($_POST['remove_profile_picture'] ?? '') === '1';
        $newPicture = null;
        $upload = $_FILES['profile_picture'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $newPicture = store_uploaded_image($upload, 'profile-pictures', 2097152);
            } catch (InvalidArgumentException | RuntimeException $exception) {
                flash('error', $exception->getMessage());
                redirect('profile.php');
            }
        } elseif (!$removePicture) {
            flash('error', 'Choose a profile photo to upload, or select the remove option.');
            redirect('profile.php');
        }
        $picturePath = $newPicture['path'] ?? ($removePicture ? null : ($user['profile_picture_path'] ?? null));
        try {
            db()->beginTransaction();
            $stmt = db()->prepare('UPDATE users SET profile_picture_path = ? WHERE id = ?');
            $stmt->execute([$picturePath, (int) $user['id']]);
            if ($memberProfile !== null) {
                $stmt = db()->prepare('UPDATE member_profiles SET profile_picture_path = ? WHERE user_id = ?');
                $stmt->execute([$picturePath, (int) $user['id']]);
            }
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if ($newPicture !== null) {
                remove_stored_image($newPicture['path'], 'profile-pictures');
            }
            throw $exception;
        }
        foreach ($oldPictures as $oldPicture) {
            if ($oldPicture !== $picturePath) {
                remove_stored_image($oldPicture, 'profile-pictures');
            }
        }
        audit((int) $user['id'], $removePicture ? 'profile_picture_removed' : 'profile_picture_updated');
        flash('success', $removePicture ? 'Your profile photo was removed.' : 'Your profile photo was updated.');
        redirect(role_home($user));
    }
    if ($action === 'profile') {
        $name = trim((string) ($_POST['full_name'] ?? ''));
        if (preg_match('/^.{2,120}$/us', $name) !== 1) {
            flash('error', 'Full name must be between 2 and 120 characters.');
            redirect('profile.php');
        }
        if ($memberProfile === null) {
            $stmt = db()->prepare('UPDATE users SET full_name = ? WHERE id = ?');
            $stmt->execute([$name, (int) $user['id']]);
            audit((int) $user['id'], 'profile_updated');
            flash('success', 'Your profile has been updated.');
            redirect('profile.php');
        }
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $occupation = trim((string) ($_POST['occupation'] ?? ''));
        $employer = trim((string) ($_POST['employer'] ?? ''));
        $location = trim((string) ($_POST['location'] ?? ''));
        $biography = trim((string) ($_POST['biography'] ?? ''));
        $graduationYearInput = trim((string) ($_POST['graduation_year'] ?? ''));
        $graduationYear = $memberProfile['graduation_year'] === null
            ? null
            : (int) $memberProfile['graduation_year'];
        $membershipIdReviewRequired = (bool) $memberProfile['membership_id_review_required'];
        if ($graduationYearInput !== '') {
            $submittedGraduationYear = filter_var($graduationYearInput, FILTER_VALIDATE_INT);
            if ($submittedGraduationYear === false
                || $submittedGraduationYear < 1900
                || $submittedGraduationYear > (int) gmdate('Y')
                || ($graduationYear !== null && $submittedGraduationYear !== $graduationYear)) {
                flash('error', 'Enter a valid graduation year. Once set, it cannot be changed through profile settings.');
                redirect('profile.php');
            }
            $graduationYear = (int) $submittedGraduationYear;
            if ($memberProfile['graduation_year'] === null) {
                $membershipIdReviewRequired = true;
            }
        }
        $directoryVisible = ($_POST['directory_visible'] ?? '') === '1';
        if (strlen($phone) > 32 || preg_match('/^[+0-9() .-]{7,32}$/', $phone) !== 1
            || strlen($occupation) > 160 || strlen($employer) > 160 || strlen($location) > 160 || strlen($biography) > 2000) {
            flash('error', 'Check the phone number and profile field lengths, then try again.');
            redirect('profile.php');
        }
        try {
            db()->beginTransaction();
            $stmt = db()->prepare('UPDATE users SET full_name = ? WHERE id = ?');
            $stmt->execute([$name, (int) $user['id']]);
            $stmt = db()->prepare(
                'UPDATE member_profiles
                 SET phone = ?, occupation = ?, employer = ?, location = ?, biography = ?,
                     directory_visible = ?, graduation_year = ?,
                     membership_id_review_required = ?
                 WHERE user_id = ?'
            );
            $stmt->execute([
                $phone,
                $occupation !== '' ? $occupation : null,
                $employer !== '' ? $employer : null,
                $location !== '' ? $location : null,
                $biography !== '' ? $biography : null,
                $directoryVisible ? 1 : 0,
                $graduationYear,
                $membershipIdReviewRequired ? 1 : 0,
                (int) $user['id'],
            ]);
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $exception;
        }
        audit((int) $user['id'], 'profile_updated');
        if ($memberProfile['graduation_year'] === null && $graduationYear !== null) {
            audit((int) $user['id'], 'graduation_year_recorded', 'year=' . $graduationYear);
        }
        flash('success', 'Your profile has been updated.');
        redirect('profile.php');
    }
    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $password = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        $hash = (string) $stmt->fetchColumn();
        if (!verify_password($current, $hash) || !valid_password($password) || !hash_equals($password, $confirm)) {
            flash('error', 'Current password is incorrect, or the new password is too short or does not match.');
            redirect('profile.php');
        }
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([make_password_hash($password), (int) $user['id']]);
        $stmt = db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
        $stmt->execute([(int) $user['id']]);
        audit((int) $user['id'], 'password_changed');
        flash('success', 'Password changed. Other remembered browsers have been signed out.');
        redirect('profile.php');
    }
    flash('error', 'Unknown profile action.');
    redirect('profile.php');
}

render_header('Profile');
page_title('ACCOUNT SETTINGS', 'Your profile', 'Only permitted personal information is editable here.');
?>
<section class="settings-grid">
    <article class="panel">
        <h2>Profile photo</h2>
        <div class="profile-picture-editor">
            <?= profile_avatar_button($user, 'profile-avatar-trigger-large') ?>
            <div><strong><?= e((string) $user['full_name']) ?></strong><span class="muted">Select your photo to view or edit it.</span></div>
        </div>
    </article>
    <article class="panel">
        <h2><?= $memberProfile === null ? 'Account profile' : 'Member profile' ?></h2>
        <form method="post" action="profile.php" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
            <label for="full_name">Full name</label><input id="full_name" name="full_name" value="<?= e($user['full_name']) ?>" maxlength="120" required>
        <label>Email address</label><input value="<?= e($user['email']) ?>" readonly aria-describedby="email-help"><p class="help-text" id="email-help">Email changes are disabled in this project so account recovery stays tied to a verified address.</p>
        <label>Username</label><input value="<?= e($user['username']) ?>" readonly>
        <?php if ($memberProfile !== null): ?>
        <label for="membership_number">Membership number</label><input id="membership_number" value="<?= e($memberProfile['membership_number']) ?>" readonly placeholder="Assigned at registration">
        <?php if ($memberProfile['membership_id_review_required']): ?><p class="notice notice-error" role="status">Your existing membership ID is preserved. The association will review your ID and graduation-year record.</p><?php endif; ?>
        <label for="graduation_year">Graduation year</label><input id="graduation_year" name="graduation_year" type="number" min="1900" max="<?= (int) gmdate('Y') ?>" step="1" value="<?= e($memberProfile['graduation_year'] === null ? '' : (string) $memberProfile['graduation_year']) ?>" <?= $memberProfile['graduation_year'] !== null ? 'readonly' : '' ?>><small class="muted"><?= $memberProfile['graduation_year'] === null ? 'Enter your graduation year. Any existing membership ID will be preserved; mismatches are flagged for association review.' : 'Your graduation year is saved and cannot be changed here.' ?></small>
        <label for="membership_status">Membership status</label><input id="membership_status" value="<?= e(ucfirst((string) $memberProfile['membership_status'])) ?>" readonly>
        <label for="date_of_birth">Date of birth</label><input id="date_of_birth" value="<?= e($memberProfile['date_of_birth']) ?>" readonly><small class="muted">Your date of birth is private and is not shown in the member directory.</small>
        <label for="phone">Phone number</label><input id="phone" name="phone" type="tel" value="<?= e($memberProfile['phone']) ?>" maxlength="32" required>
        <label for="occupation">Occupation</label><input id="occupation" name="occupation" value="<?= e($memberProfile['occupation']) ?>" maxlength="160">
        <label for="employer">Employer or business</label><input id="employer" name="employer" value="<?= e($memberProfile['employer']) ?>" maxlength="160">
        <label for="location">Town or region</label><input id="location" name="location" value="<?= e($memberProfile['location']) ?>" maxlength="160">
        <label for="biography">Professional biography</label><textarea id="biography" name="biography" rows="4" maxlength="2000"><?= e($memberProfile['biography']) ?></textarea>
        <label class="check-label"><input type="checkbox" name="directory_visible" value="1" <?= $memberProfile['directory_visible'] ? 'checked' : '' ?>> Show my professional profile in the member directory</label>
        <small class="muted">Your phone number appears in the directory only when you opt in to showing your profile.</small>
        <?php endif; ?>
            <button class="button button-primary" type="submit">Save profile</button>
        </form>
    </article>
    <article class="panel">
        <h2>Change password</h2>
        <form method="post" action="profile.php" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="password">
            <label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            <label for="new_password">New password <span class="label-hint">12 characters minimum</span></label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required>
            <label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required>
            <button class="button button-primary" type="submit">Update password</button>
        </form>
    </article>
</section>
<?php render_footer(); ?>
