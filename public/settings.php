<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'school.settings.edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_school_setting') {
        $key = trim((string) ($_POST['setting_key'] ?? ''));
        $value = trim((string) ($_POST['setting_value'] ?? ''));
        if ($key === '' || strlen($key) > 80) {
            flash('error', 'Choose a valid setting key.');
            redirect('settings.php');
        }

        $stmt = db()->prepare(
            'INSERT INTO school_settings (school_id, setting_key, setting_value, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()'
        );
        $stmt->execute([$schoolId, $key, $value === '' ? null : $value, (int) $user['id']]);
        flash('success', 'School setting saved.');
        redirect('settings.php');
    }
}

$settingsStmt = db()->prepare(
    'SELECT setting_key, setting_value FROM school_settings WHERE school_id = ? ORDER BY setting_key ASC'
);
$settingsStmt->execute([$schoolId]);
$settings = $settingsStmt->fetchAll();

render_header('School settings');
?>
<section class="panel">
    <p class="eyebrow">CONFIG</p>
    <h1>School configuration</h1>
</section>

<section class="panel" style="margin-top:1.5rem;">
    <h2>Add or edit setting</h2>
    <form method="post" action="settings.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_school_setting">
        <div class="form-grid two-columns">
            <div><label for="setting_key">Setting key</label><input id="setting_key" name="setting_key" type="text" maxlength="80" required></div>
            <div><label for="setting_value">Setting value</label><input id="setting_value" name="setting_value" type="text" maxlength="255"></div>
        </div>
        <button class="button button-primary" type="submit">Save setting</button>
    </form>
</section>

<section class="panel" style="margin-top:1.5rem;">
    <h2>Saved values</h2>
    <?php if ($settings === []): ?>
        <p class="muted">No custom school settings have been saved yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Key</th><th>Value</th></tr>
            </thead>
            <tbody>
                <?php foreach ($settings as $setting): ?>
                    <tr>
                        <td><?= e((string) $setting['setting_key']) ?></td>
                        <td><?= e((string) ($setting['setting_value'] ?: '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
