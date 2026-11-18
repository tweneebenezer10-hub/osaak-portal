<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$operator = require_permission('system.settings.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        try {
            $pointId = create_config_restore_point($name, $description !== '' ? $description : null, (int) $operator['id']);
            audit((int) $operator['id'], 'config_restore_point_created', 'restore_point_id=' . $pointId . '; name=' . $name);
            flash('success', 'Configuration restore point created successfully.');
        } catch (InvalidArgumentException $exception) {
            flash('error', $exception->getMessage());
        }
        redirect('config_restore.php');
    }
    if ($action === 'restore') {
        $pointId = filter_var($_POST['restore_point_id'] ?? null, FILTER_VALIDATE_INT);
        if ($pointId === false || $pointId === null) {
            flash('error', 'Select a valid configuration restore point.');
            redirect('config_restore.php');
        }
        try {
            $result = restore_config_point((int) $pointId, (int) $operator['id']);
            if ($result['changed'] === []) {
                flash('success', 'The selected configuration already matches the restore point; no values were changed.');
            } else {
                flash('success', 'Configuration restored: ' . implode(', ', $result['changed']) . '.');
            }
        } catch (Throwable $exception) {
            flash('error', 'The restore point could not be applied. ' . $exception->getMessage());
        }
        redirect('config_restore.php');
    }
}

$points = db()->query(
    'SELECT id, name, description, created_by, created_at, snapshot_json
     FROM config_restore_points
     ORDER BY created_at DESC LIMIT 25'
)->fetchAll();

render_header('Configuration restore points');
page_title('TECHNICAL SETTINGS', 'Configuration restore points', 'Create a safe snapshot of non-sensitive system settings and restore it without changing member or dues data.');
?>
<section class="panel module-form-panel">
    <h2>Create a restore point</h2>
    <p class="muted">Only approved configuration keys are captured. Secret values such as database passwords, SMTP passwords, and the app pepper are excluded.</p>
    <form method="post" class="form-stack" data-loading-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <label for="restore_name">Restore point name</label>
        <input id="restore_name" name="name" type="text" maxlength="120" placeholder="Before theme refresh" required>
        <label for="restore_desc">Description</label>
        <textarea id="restore_desc" name="description" rows="3" maxlength="500" placeholder="Optional notes about what changed"></textarea>
        <button class="button button-primary" type="submit">Create snapshot</button>
    </form>
</section>
<section class="panel top-gap table-panel">
    <h2>Saved restore points</h2>
    <?php if (!$points): ?>
        <p class="muted">No configuration snapshots have been created yet.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Created</th>
                        <th>Keys</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($points as $point): ?>
                        <?php $snapshot = json_decode((string) $point['snapshot_json'], true); $snapshotKeys = is_array($snapshot) ? array_keys($snapshot) : []; ?>
                        <tr>
                            <td><strong><?= e((string) $point['name']) ?></strong></td>
                            <td><?= e((string) ($point['description'] ?? '')) ?: '<span class="muted">No description</span>' ?></td>
                            <td><?= e((string) $point['created_at']) ?></td>
                            <td><?= e(implode(', ', $snapshotKeys)) ?></td>
                            <td>
                                <form method="post" class="inline-form" data-loading-form>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="restore_point_id" value="<?= (int) $point['id'] ?>">
                                    <button class="button button-quiet button-small" type="submit" onclick="return confirm('Restore this configuration snapshot? It only updates approved settings and keeps operational data unchanged.');">Restore</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
