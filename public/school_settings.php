<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/image_uploads.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
if (!school_has_permission($user, $schoolId, 'school.settings.edit')
    && !school_has_permission($user, $schoolId, 'school.branding.manage')) {
    http_response_code(403);
    exit('You are not allowed to manage this school’s settings or branding.');
}
$schoolRole = school_user_role_for_school((int) $user['id'], $schoolId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_school_logo') {
        require_school_permission($schoolId, 'school.branding.manage');
        if ($schoolRole === null) {
            http_response_code(403);
            exit('An active school role is required to update this school logo.');
        }
        $oldLogoStmt = db()->prepare('SELECT logo_path FROM schools WHERE id = ? LIMIT 1');
        $oldLogoStmt->execute([$schoolId]);
        $oldLogo = $oldLogoStmt->fetchColumn();
        $removeLogo = ($_POST['remove_school_logo'] ?? '') === '1';
        $upload = $_FILES['school_logo'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        $newLogo = null;
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $newLogo = store_uploaded_image($upload, 'school-logos', 2097152);
            } catch (InvalidArgumentException | RuntimeException $exception) {
                flash('error', $exception->getMessage());
                redirect('school_settings.php');
            }
        } elseif (!$removeLogo) {
            flash('error', 'Choose a school logo to upload, or select the remove option.');
            redirect('school_settings.php');
        }
        $logoPath = $newLogo['path'] ?? ($removeLogo ? null : (is_string($oldLogo) ? $oldLogo : null));
        try {
            $stmt = db()->prepare('UPDATE schools SET logo_path = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->execute([$logoPath, $schoolId]);
        } catch (Throwable $exception) {
            if ($newLogo !== null) {
                remove_stored_image($newLogo['path'], 'school-logos');
            }
            throw $exception;
        }
        if (is_string($oldLogo) && $oldLogo !== $logoPath) {
            $certificateLogoStmt = db()->prepare(
                'SELECT 1 FROM school_completion_certificates WHERE school_id = ? AND school_logo_path = ? LIMIT 1'
            );
            $certificateLogoStmt->execute([$schoolId, $oldLogo]);
            if (!$certificateLogoStmt->fetchColumn()) {
                remove_stored_image($oldLogo, 'school-logos');
            }
        }
        audit((int) $user['id'], $removeLogo ? 'school_logo_removed' : 'school_logo_updated', 'school_id=' . $schoolId);
        flash('success', $removeLogo ? 'School logo removed.' : 'School logo updated.');
        redirect('school_settings.php');
    }
    if ($action === 'save_school_settings') {
        require_school_permission($schoolId, 'school.settings.edit');
        $updates = [
            'name' => trim((string) ($_POST['school_name'] ?? '')),
            'short_name' => trim((string) ($_POST['short_name'] ?? '')),
            'admission_abbreviation' => strtoupper(trim((string) ($_POST['admission_abbreviation'] ?? ''))),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'region' => trim((string) ($_POST['region'] ?? '')),
            'district' => trim((string) ($_POST['district'] ?? '')),
            'contact_email' => strtolower(trim((string) ($_POST['contact_email'] ?? ''))),
            'contact_phone' => trim((string) ($_POST['contact_phone'] ?? '')),
            'proprietor_name' => trim((string) ($_POST['proprietor_name'] ?? '')),
            'require_proprietor_certificate_signature' => ($_POST['require_proprietor_certificate_signature'] ?? '') === '1' ? 1 : 0,
        ];
        if ($updates['name'] === '' || $updates['short_name'] === '') {
            flash('error', 'School name and short name are required.');
            redirect('school_settings.php');
        }
        if ($updates['admission_abbreviation'] !== ''
            && preg_match('/^[A-Z0-9]{1,20}$/', $updates['admission_abbreviation']) !== 1) {
            flash('error', 'The admission abbreviation must contain 1–20 letters or numbers, without spaces or punctuation.');
            redirect('school_settings.php');
        }
        $stmt = db()->prepare(
            'UPDATE schools SET name = ?, short_name = ?, admission_abbreviation = ?, address = ?, region = ?, district = ?, contact_email = ?, contact_phone = ?, proprietor_name = ?, require_proprietor_certificate_signature = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([
            $updates['name'],
            $updates['short_name'],
            $updates['admission_abbreviation'] === '' ? null : $updates['admission_abbreviation'],
            $updates['address'] === '' ? null : $updates['address'],
            $updates['region'] === '' ? null : $updates['region'],
            $updates['district'] === '' ? null : $updates['district'],
            $updates['contact_email'] === '' ? null : $updates['contact_email'],
            $updates['contact_phone'] === '' ? null : $updates['contact_phone'],
            $updates['proprietor_name'] === '' ? null : $updates['proprietor_name'],
            $updates['require_proprietor_certificate_signature'],
            $schoolId,
        ]);
        audit((int) $user['id'], 'school_admission_abbreviation_updated', 'school_id=' . $schoolId . ';configured=' . ($updates['admission_abbreviation'] !== '' ? 'yes' : 'no'));
        flash('success', 'School information updated.');
        redirect('school_settings.php');
    }
}

$settingsStmt = db()->prepare(
    'SELECT id, name, short_name, admission_abbreviation, logo_path, address, region, district, contact_email, contact_phone, proprietor_name,
            require_proprietor_certificate_signature, status, subscription_status, annual_fee, subscription_expires_at
     FROM schools WHERE id = ? LIMIT 1'
);
$settingsStmt->execute([$schoolId]);
$settings = $settingsStmt->fetch();
$suggestedAdmissionAbbreviation = school_suggested_admission_abbreviation((string) $settings['name']);

render_header('School settings');
?>
<section class="school-identity-banner">
    <?= school_identity_markup($settings) ?>
    <h1>School profile and settings</h1>
</section>

<?php if (school_has_permission($user, $schoolId, 'school.branding.manage')): ?>
<section class="panel" style="margin-top:1.5rem;">
    <h2>School logo</h2>
    <div class="school-logo-editor">
        <div class="school-logo-preview-frame">
            <img id="school-logo-preview" class="school-settings-logo" src="<?= !empty($settings['logo_path']) && preg_match('#^school-logos/[a-f0-9]{48}\.(?:jpg|png|webp)$#', (string) $settings['logo_path']) === 1 ? 'school_logo.php' : 'assets/images/connezer-brand.png' ?>" alt="<?= !empty($settings['logo_path']) ? e((string) $settings['name']) . ' logo' : 'Default school logo preview' ?>" data-image-preview>
        </div>
        <div>
            <p class="muted">Upload or replace this school’s logo. JPEG, PNG, or WebP; maximum 2 MB and 5000 × 5000 pixels. It is stored on this school record only.</p>
            <form method="post" action="school_settings.php" class="form-stack" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_school_logo">
        <label for="school_logo">Choose school logo</label>
        <input id="school_logo" name="school_logo" type="file" accept="image/jpeg,image/png,image/webp" data-image-preview-input data-preview-target="school-logo-preview" <?= empty($settings['logo_path']) ? 'required' : '' ?>>
        <?php if (!empty($settings['logo_path'])): ?><label class="check-label"><input type="checkbox" name="remove_school_logo" value="1"> Remove current logo</label><?php endif; ?>
        <button class="button button-primary" type="submit"><?= empty($settings['logo_path']) ? 'Upload logo' : 'Save logo changes' ?></button>
            </form>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($schoolRole === 'school_proprietor'): ?>
<section class="panel" style="margin-top:1.5rem;">
    <form method="post" action="school_settings.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_school_settings">
        <div class="form-grid two-columns">
            <div><label for="school_name">School name</label><input id="school_name" name="school_name" type="text" value="<?= e((string) $settings['name']) ?>" required></div>
            <div><label for="short_name">Short name</label><input id="short_name" name="short_name" type="text" value="<?= e((string) $settings['short_name']) ?>" required></div>
            <div><label for="admission_abbreviation">Admission abbreviation <span class="label-hint">(optional)</span></label><input id="admission_abbreviation" name="admission_abbreviation" type="text" maxlength="20" pattern="[A-Za-z0-9]{1,20}" value="<?= e((string) ($settings['admission_abbreviation'] ?? '')) ?>" placeholder="<?= e($suggestedAdmissionAbbreviation) ?>"><small class="muted">Suggested from the school name: <?= e($suggestedAdmissionAbbreviation) ?>. Leave blank to use the school’s short name. Changing this only affects future admissions; issued numbers remain unchanged.</small></div>
            <div><label for="region">Region</label><input id="region" name="region" type="text" value="<?= e((string) ($settings['region'] ?: '')) ?>"></div>
            <div><label for="district">District</label><input id="district" name="district" type="text" value="<?= e((string) ($settings['district'] ?: '')) ?>"></div>
            <div><label for="contact_email">Contact email</label><input id="contact_email" name="contact_email" type="email" value="<?= e((string) ($settings['contact_email'] ?: '')) ?>"></div>
            <div><label for="contact_phone">Contact phone</label><input id="contact_phone" name="contact_phone" type="tel" value="<?= e((string) ($settings['contact_phone'] ?: '')) ?>"></div>
            <div><label for="proprietor_name">Proprietor</label><input id="proprietor_name" name="proprietor_name" type="text" value="<?= e((string) ($settings['proprietor_name'] ?: '')) ?>"></div>
            <div><label class="check-label"><input type="checkbox" name="require_proprietor_certificate_signature" value="1" <?= (int) $settings['require_proprietor_certificate_signature'] === 1 ? 'checked' : '' ?>> Require a verified Proprietor signature on Certificates of Completion</label></div>
            <div style="grid-column:1 / -1;"><label for="address">Address</label><textarea id="address" name="address" rows="4"><?= e((string) ($settings['address'] ?: '')) ?></textarea></div>
        </div>
        <button class="button button-primary" type="submit">Save settings</button>
    </form>
</section>
<?php endif; ?>
<?php render_footer(); ?>
