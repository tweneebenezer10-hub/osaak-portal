<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/image_uploads.php';
$operator = require_permission('system.settings.manage');

$stmt = db()->prepare("SELECT setting_value FROM association_settings WHERE setting_key='site_logo_path' LIMIT 1");
$stmt->execute();
$logoPath = $stmt->fetchColumn() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $upload = $_FILES['site_logo'] ?? ['error' => UPLOAD_ERR_NO_FILE];
    $newLogo = null;
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $newLogo = store_uploaded_image($upload, 'branding', 2097152);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            flash('error', $exception->getMessage());
            redirect('branding.php');
        }
    }
    $removeLogo = ($_POST['remove_logo'] ?? '') === '1' && $newLogo === null;
    if ($newLogo === null && !$removeLogo) {
        flash('error', 'Choose a logo image, or select the remove option to clear the current logo.');
        redirect('branding.php');
    }
    $nextPath = $newLogo['path'] ?? null;
    try {
        db()->beginTransaction();
        $stmt = db()->prepare(
            "INSERT INTO association_settings (setting_key,setting_value,updated_by)
             VALUES ('site_logo_path',?,?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                 updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()"
        );
        $stmt->execute([$nextPath, (int) $operator['id']]);
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if ($newLogo !== null) {
            remove_stored_image($newLogo['path'], 'branding');
        }
        throw $exception;
    }
    remove_stored_image($logoPath, 'branding');
    audit((int) $operator['id'], $nextPath === null ? 'site_logo_removed' : 'site_logo_updated');
    flash('success', $nextPath === null ? 'Website logo removed.' : 'Website logo updated.');
    redirect('branding.php');
}

render_header('Website branding');
page_title('TECHNICAL SETTINGS', 'Website branding', 'Upload the association logo shown on sign-in and portal pages.');
?>
<section class="panel module-form-panel">
    <h2>Association logo</h2>
    <?php if ($logoPath): ?><div class="branding-logo-preview"><img src="branding_logo.php" alt="Current association website logo"></div><?php else: ?><p class="muted">No custom logo is set. The portal displays the default OSA mark.</p><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="form-stack"><?= csrf_field() ?>
        <label for="site_logo">Logo image</label><input id="site_logo" name="site_logo" type="file" accept="image/jpeg,image/png,image/webp">
        <p class="help-text">JPEG, PNG, or WebP; maximum 2 MB and 5000 × 5000 pixels. The file is stored outside the public web directory.</p>
        <?php if ($logoPath): ?><label class="check-label"><input type="checkbox" name="remove_logo" value="1"> Remove the current logo</label><?php endif; ?>
        <button class="button button-primary" type="submit">Save website logo</button>
    </form>
</section>
<?php render_footer(); ?>
