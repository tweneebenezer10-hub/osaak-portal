<?php
declare(strict_types=1);

/**
 * Shared Attendance Device Management POST handler and UI renderer
 * for Headmaster & Proprietor dashboards and staff_attendance.php.
 */
function handle_attendance_device_post(
    PDO $pdo,
    int $schoolId,
    int $userId,
    array $user,
    DateTimeZone $timezone,
    bool $kioskConfigured,
    string $redirectUrl
): bool {
    $action = (string) ($_POST['action'] ?? '');
    $deviceActions = [
        'register_kiosk_device',
        'rename_kiosk_device',
        'activate_kiosk_device',
        'deactivate_kiosk_device',
        'revoke_kiosk_device',
        'replace_kiosk_device',
    ];
    if (!in_array($action, $deviceActions, true)) {
        return false;
    }

    if (!school_staff_attendance_can_manage_devices($user, $schoolId)) {
        http_response_code(403);
        exit('Only the Headmaster or Proprietor can manage attendance devices.');
    }

    if ($action === 'register_kiosk_device') {
        if (!$kioskConfigured) {
            throw new RuntimeException('Save a schedule for every active staff category before registering an attendance device.');
        }
        $reg = school_staff_attendance_register_device(
            $pdo,
            $schoolId,
            $userId,
            (string) ($_POST['device_name'] ?? ''),
            (string) ($_POST['device_location'] ?? 'Main Office'),
            $timezone,
            true
        );
        $_SESSION['kiosk_latest_enrollment'][$schoolId] = [
            'device_id' => $reg['device_id'],
            'device_name' => trim((string) ($_POST['device_name'] ?? '')),
            'enrollment_code' => $reg['enrollment_code'],
            'expires_at' => $reg['enrollment_expires_at'],
        ];
        flash('success', 'Attendance device registered. Pair the Local Device Agent with enrollment code ' . $reg['enrollment_code'] . ' to authorise all browsers on this physical computer.');
    } elseif ($action === 'rename_kiosk_device') {
        $deviceId = filter_var($_POST['device_id'] ?? null, FILTER_VALIDATE_INT);
        $deviceName = trim((string) ($_POST['device_name'] ?? ''));
        $deviceLocation = trim((string) ($_POST['device_location'] ?? 'Main Office'));
        if ($deviceId === false || $deviceId < 1 || $deviceName === '' || mb_strlen($deviceName) > 100) {
            throw new RuntimeException('Enter a valid device name of up to 100 characters.');
        }
        $nowStr = (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
        $pdo->beginTransaction();
        if (school_staff_attendance_device_extended_columns_ready($pdo)) {
            $stmt = $pdo->prepare(
                'UPDATE school_staff_attendance_kiosk_devices
                 SET device_name = ?, device_location = ?
                 WHERE id = ? AND school_id = ? AND status <> "revoked"'
            );
            $stmt->execute([$deviceName, $deviceLocation !== '' ? $deviceLocation : 'Main Office', (int) $deviceId, $schoolId]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE school_staff_attendance_kiosk_devices
                 SET device_name = ?
                 WHERE id = ? AND school_id = ? AND deactivated_at IS NULL'
            );
            $stmt->execute([$deviceName, (int) $deviceId, $schoolId]);
        }
        if ($stmt->rowCount() < 1) {
            $pdo->rollBack();
            throw new RuntimeException('The device could not be updated or has been revoked.');
        }
        school_staff_attendance_security_event($pdo, $schoolId, 'device_renamed', (int) $deviceId, null, $userId, null, $deviceName . ' (' . $deviceLocation . ')', $nowStr);
        audit($userId, 'school_staff_attendance_kiosk_device_renamed', 'school_id=' . $schoolId . ';device_id=' . (int) $deviceId);
        $pdo->commit();
        flash('success', 'Attendance device details updated.');
    } elseif ($action === 'activate_kiosk_device') {
        $deviceId = filter_var($_POST['device_id'] ?? null, FILTER_VALIDATE_INT);
        if ($deviceId === false || $deviceId < 1) {
            throw new RuntimeException('Select a valid registered device.');
        }
        school_staff_attendance_set_device_status($pdo, $schoolId, (int) $deviceId, $userId, 'active', $timezone);
        flash('success', 'Attendance device activated.');
    } elseif ($action === 'deactivate_kiosk_device') {
        $deviceId = filter_var($_POST['device_id'] ?? null, FILTER_VALIDATE_INT);
        if ($deviceId === false || $deviceId < 1) {
            throw new RuntimeException('Select a valid registered device.');
        }
        school_staff_attendance_set_device_status($pdo, $schoolId, (int) $deviceId, $userId, 'inactive', $timezone);
        flash('success', 'Attendance device temporarily deactivated.');
    } elseif ($action === 'revoke_kiosk_device') {
        $deviceId = filter_var($_POST['device_id'] ?? null, FILTER_VALIDATE_INT);
        if ($deviceId === false || $deviceId < 1) {
            throw new RuntimeException('Select a valid registered device.');
        }
        school_staff_attendance_set_device_status($pdo, $schoolId, (int) $deviceId, $userId, 'revoked', $timezone);
        flash('success', 'Attendance device authorisation permanently revoked.');
    } elseif ($action === 'replace_kiosk_device') {
        $oldDeviceId = filter_var($_POST['device_id'] ?? null, FILTER_VALIDATE_INT);
        if ($oldDeviceId === false || $oldDeviceId < 1) {
            throw new RuntimeException('Select a valid device to replace.');
        }
        $newName = trim((string) ($_POST['device_name'] ?? ''));
        $newLocation = trim((string) ($_POST['device_location'] ?? 'Main Office'));
        $reg = school_staff_attendance_replace_device(
            $pdo,
            $schoolId,
            (int) $oldDeviceId,
            $userId,
            $newName,
            $newLocation,
            $timezone,
            true
        );
        $_SESSION['kiosk_latest_enrollment'][$schoolId] = [
            'device_id' => $reg['device_id'],
            'device_name' => $newName,
            'enrollment_code' => $reg['enrollment_code'],
            'expires_at' => $reg['enrollment_expires_at'],
        ];
        flash('success', 'Old device revoked and replacement registered. Pair the new physical PC using enrollment code ' . $reg['enrollment_code'] . '.');
    }

    redirect($redirectUrl);
}

function render_attendance_device_management(
    PDO $pdo,
    int $schoolId,
    array $user,
    DateTimeZone $timezone,
    bool $kioskConfigured,
    string $formAction,
    string $returnView = ''
): void {
    if (!school_staff_attendance_can_manage_devices($user, $schoolId)) {
        return;
    }

    $devices = school_staff_attendance_list_devices($pdo, $schoolId);
    $history = school_staff_attendance_device_history($pdo, $schoolId, 25);
    $currentDevice = school_staff_attendance_kiosk_device($pdo, $schoolId);
    $activeCount = count(array_filter($devices, static fn(array $d): bool => ($d['status'] ?? '') === 'active' && $d['deactivated_at'] === null));
    $latestEnrollment = $_SESSION['kiosk_latest_enrollment'][$schoolId] ?? null;
    $formatTs = static function (?string $val) use ($timezone): string {
        if (!$val) {
            return 'Never';
        }
        return (new DateTimeImmutable($val, $timezone))->format('j M Y, g:i A');
    };
    ?>
    <section class="panel spaced-top" id="attendance-device-management" aria-labelledby="attendance-device-mgmt-heading">
        <p class="eyebrow">PHYSICAL ATTENDANCE COMPUTERS</p>
        <h2 id="attendance-device-mgmt-heading">Attendance Device Management</h2>
        <p class="muted">Each school maintains an independent attendance-computer registry (no global one-PC limit). Only Headmasters and Proprietors can register, activate, deactivate, revoke, or replace attendance computers. Pair each physical PC with the <strong>Connezer Local Device Agent</strong> (<code>127.0.0.1:47832</code>) so Chrome, Edge, and Firefox on that computer share verified hardware-key authorisation.</p>
        <p><strong>Active Devices:</strong> <?= $activeCount ?> · <strong>This Computer/Browser:</strong> <?= $currentDevice ? e((string) $currentDevice['device_name']) . ' (Authorised)' : 'Not authorised for this school' ?></p>

        <?php if (is_array($latestEnrollment)): ?>
            <div class="notice notice-success" role="status">
                <strong>Pair Physical PC (<?= e((string) $latestEnrollment['device_name']) ?>):</strong>
                One-time Enrollment Code: <code><?= e((string) $latestEnrollment['enrollment_code']) ?></code>
                (expires <?= e((string) $latestEnrollment['expires_at']) ?> UTC).
                <button class="button button-small" type="button" data-pair-agent-button data-pair-school-id="<?= $schoolId ?>" data-pair-code="<?= e((string) $latestEnrollment['enrollment_code']) ?>">Pair Local Device Agent on this PC</button>
                <span class="muted" data-pair-result></span>
            </div>
        <?php endif; ?>

        <h3>Register New Attendance Device</h3>
        <?php if ($kioskConfigured): ?>
            <form method="post" action="<?= e($formAction) ?>" class="form-grid two-columns">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="register_kiosk_device">
                <?php if ($returnView !== ''): ?><input type="hidden" name="return_view" value="<?= e($returnView) ?>"><?php endif; ?>
                <div>
                    <label for="reg-device-name-<?= e($formAction) ?>">Device Name</label>
                    <input id="reg-device-name-<?= e($formAction) ?>" name="device_name" type="text" maxlength="100" required placeholder="e.g. Reception PC 1">
                </div>
                <div>
                    <label for="reg-device-loc-<?= e($formAction) ?>">Device Location</label>
                    <input id="reg-device-loc-<?= e($formAction) ?>" name="device_location" type="text" maxlength="120" required placeholder="e.g. Main Gate Security Desk">
                </div>
                <div class="span-full">
                    <button class="button button-primary" type="submit">Register New Attendance Device</button>
                </div>
            </form>
        <?php else: ?>
            <p class="notice">Configure reporting schedules for all active staff categories in <a href="staff_attendance.php#reporting-schedules">Staff attendance &amp; punctuality</a> before registering attendance devices.</p>
        <?php endif; ?>

        <h3 class="spaced-top">Registered Devices</h3>
        <?php if ($devices === []): ?>
            <p class="muted">No attendance computers are registered for this school yet.</p>
        <?php else: ?>
            <div class="table-scroll"><table>
                <thead>
                    <tr>
                        <th>Device Name</th>
                        <th>Device Location</th>
                        <th>Registration Date</th>
                        <th>Last Seen</th>
                        <th>Device Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($devices as $dev): ?>
                    <?php
                    $status = (string) ($dev['status'] ?? ($dev['deactivated_at'] === null ? 'active' : 'revoked'));
                    $authModeLabel = ($dev['auth_mode'] ?? 'browser_credential') === 'local_agent' ? 'Local Agent (All Browsers)' : 'Browser Credential';
                    ?>
                    <tr>
                        <td><strong><?= e((string) $dev['device_name']) ?></strong><br><small class="muted"><?= e($authModeLabel) ?><?= !empty($dev['device_uid']) ? ' · UID ' . e(substr((string) $dev['device_uid'], 0, 10)) . '…' : '' ?></small></td>
                        <td><?= e((string) ($dev['device_location'] ?? 'Main Office')) ?></td>
                        <td><?= e($formatTs((string) $dev['created_at'])) ?><br><small class="muted">By <?= e((string) $dev['registered_by_name']) ?></small></td>
                        <td><?= e($formatTs($dev['last_used_at'] ? (string) $dev['last_used_at'] : null)) ?></td>
                        <td>
                            <?php if ($status === 'active'): ?>
                                <strong>Active</strong>
                            <?php elseif ($status === 'inactive'): ?>
                                <span>Inactive (Deactivated)</span>
                            <?php else: ?>
                                <span>Revoked<?= !empty($dev['replaced_by_device_id']) ? ' (Replaced by #' . (int) $dev['replaced_by_device_id'] . ')' : '' ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($status !== 'revoked'): ?>
                                <div class="staff-kiosk-device-actions">
                                    <?php if ($status === 'active'): ?>
                                        <form method="post" action="<?= e($formAction) ?>">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="deactivate_kiosk_device">
                                            <?php if ($returnView !== ''): ?><input type="hidden" name="return_view" value="<?= e($returnView) ?>"><?php endif; ?>
                                            <input type="hidden" name="device_id" value="<?= (int) $dev['id'] ?>">
                                            <button class="button button-small" type="submit">Deactivate</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?= e($formAction) ?>">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="activate_kiosk_device">
                                            <?php if ($returnView !== ''): ?><input type="hidden" name="return_view" value="<?= e($returnView) ?>"><?php endif; ?>
                                            <input type="hidden" name="device_id" value="<?= (int) $dev['id'] ?>">
                                            <button class="button button-small button-primary" type="submit">Activate</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e($formAction) ?>" onsubmit="return confirm('Permanently revoke this attendance device? It cannot be reactivated.');">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="revoke_kiosk_device">
                                        <?php if ($returnView !== ''): ?><input type="hidden" name="return_view" value="<?= e($returnView) ?>"><?php endif; ?>
                                        <input type="hidden" name="device_id" value="<?= (int) $dev['id'] ?>">
                                        <button class="button button-small button-danger" type="submit">Revoke Device</button>
                                    </form>
                                </div>
                                <details class="spaced-top">
                                    <summary>Replace / Rename</summary>
                                    <form method="post" action="<?= e($formAction) ?>" class="form-stack">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="rename_kiosk_device">
                                        <?php if ($returnView !== ''): ?><input type="hidden" name="return_view" value="<?= e($returnView) ?>"><?php endif; ?>
                                        <input type="hidden" name="device_id" value="<?= (int) $dev['id'] ?>">
                                        <input type="text" name="device_name" maxlength="100" value="<?= e((string) $dev['device_name']) ?>" required>
                                        <input type="text" name="device_location" maxlength="120" value="<?= e((string) ($dev['device_location'] ?? 'Main Office')) ?>" required>
                                        <button class="button button-small" type="submit">Save Name &amp; Location</button>
                                    </form>
                                    <form method="post" action="<?= e($formAction) ?>" class="form-stack spaced-top" onsubmit="return confirm('Revoke the old device and issue a replacement?');">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="replace_kiosk_device">
                                        <?php if ($returnView !== ''): ?><input type="hidden" name="return_view" value="<?= e($returnView) ?>"><?php endif; ?>
                                        <input type="hidden" name="device_id" value="<?= (int) $dev['id'] ?>">
                                        <input type="text" name="device_name" maxlength="100" placeholder="Replacement PC name" required>
                                        <input type="text" name="device_location" maxlength="120" value="<?= e((string) ($dev['device_location'] ?? 'Main Office')) ?>" required>
                                        <button class="button button-small" type="submit">Replace Device</button>
                                    </form>
                                </details>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>

        <h3 class="spaced-top">Device Registration History</h3>
        <?php if ($history === []): ?>
            <p class="muted">No device registration events recorded yet.</p>
        <?php else: ?>
            <div class="table-scroll"><table>
                <thead><tr><th>Date &amp; Time</th><th>Event</th><th>Device</th><th>Actor</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ($history as $ev): ?>
                    <tr>
                        <td><?= e($formatTs((string) $ev['occurred_at'])) ?></td>
                        <td><?= e(ucwords(str_replace('_', ' ', (string) $ev['event_type']))) ?></td>
                        <td><?= e((string) ($ev['device_name'] ?? 'Unregistered')) ?></td>
                        <td><?= e((string) ($ev['actor_name'] ?? 'System / Kiosk')) ?></td>
                        <td><?= e((string) ($ev['reason'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
        <script src="assets/js/kiosk_agent.js?v=1" defer></script>
    </section>
    <?php
}
