<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$admin = require_permission('members.manage');

$importableFields = [
    'phone' => 32,
    'occupation' => 160,
    'employer' => 160,
    'location' => 160,
    'biography' => 2000,
];
$errors = [];
$preview = $_SESSION['member_import_preview'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'preview') {
        unset($_SESSION['member_import_preview']);
        $preview = null;
        $upload = $_FILES['csv_file'] ?? [];
        $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        $temporaryPath = (string) ($upload['tmp_name'] ?? '');
        $fileSize = is_uploaded_file($temporaryPath) ? filesize($temporaryPath) : false;
        if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file($temporaryPath)
            || $fileSize === false || $fileSize < 1 || $fileSize > 2 * 1024 * 1024) {
            $errors[] = 'Choose a valid CSV file no larger than 2 MB.';
        } else {
            $handle = fopen($temporaryPath, 'rb');
            if ($handle === false) {
                throw new RuntimeException('The uploaded CSV file could not be read.');
            }
            $headers = fgetcsv($handle, 0, ',', '"', '\\');
            if ($headers === false) {
                $errors[] = 'The CSV file is empty.';
            } else {
                $headers = array_map(static fn(?string $header): string => strtolower(trim((string) $header)), $headers);
                if (isset($headers[0])) {
                    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
                }
                $allowedHeaders = array_merge(['membership_number'], array_keys($importableFields));
                if (count($headers) !== count(array_unique($headers))
                    || !in_array('membership_number', $headers, true)
                    || array_diff($headers, $allowedHeaders)) {
                    $errors[] = 'Use one membership_number column and only these optional fields: phone, occupation, employer, location, biography.';
                } elseif (count($headers) < 2) {
                    $errors[] = 'Include at least one profile field to update.';
                } else {
                    $seenMembershipNumbers = [];
                    $lookup = db()->prepare(
                        "SELECT mp.user_id,mp.membership_number
                         FROM member_profiles mp JOIN users u ON u.id=mp.user_id
                         WHERE mp.membership_number=? AND mp.membership_status='active'
                           AND u.status='active' AND u.email_verified_at IS NOT NULL"
                    );
                    $rows = [];
                    $dataRowCount = 0;
                    $rowNumber = 1;
                    while (($record = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                        $rowNumber++;
                        if ($record === [null] || count(array_filter($record, static fn(?string $value): bool => trim((string) $value) !== '')) === 0) {
                            continue;
                        }
                        $dataRowCount++;
                        if ($dataRowCount > 500) {
                            $errors[] = 'A CSV import may contain at most 500 data rows.';
                            break;
                        }
                        if (count($record) !== count($headers)) {
                            $errors[] = 'Row ' . $rowNumber . ' has a different number of fields than the header.';
                            continue;
                        }
                        $values = array_combine($headers, array_map(static fn(?string $value): string => trim((string) $value), $record));
                        $membershipNumber = (string) $values['membership_number'];
                        if ($membershipNumber === '' || strlen($membershipNumber) > 32) {
                            $errors[] = 'Row ' . $rowNumber . ' must include a membership number of at most 32 characters.';
                            continue;
                        }
                        if (isset($seenMembershipNumbers[$membershipNumber])) {
                            $errors[] = 'Row ' . $rowNumber . ' repeats a membership number already listed in this file.';
                            continue;
                        }
                        $seenMembershipNumbers[$membershipNumber] = true;

                        $fields = [];
                        $rowInvalid = false;
                        foreach ($importableFields as $field => $maxLength) {
                            if (!in_array($field, $headers, true) || $values[$field] === '') {
                                continue;
                            }
                            if (strlen($values[$field]) > $maxLength || preg_match('//u', $values[$field]) !== 1) {
                                $errors[] = 'Row ' . $rowNumber . ' has an invalid ' . $field . ' value.';
                                $rowInvalid = true;
                                break;
                            }
                            if ($field === 'phone' && preg_match('/^[+0-9() .-]{7,32}$/', $values[$field]) !== 1) {
                                $errors[] = 'Row ' . $rowNumber . ' has an invalid phone number.';
                                $rowInvalid = true;
                                break;
                            }
                            $fields[$field] = $values[$field];
                        }
                        if ($rowInvalid || !$fields) {
                            if (!$rowInvalid) {
                                $errors[] = 'Row ' . $rowNumber . ' contains no profile values to update.';
                            }
                            continue;
                        }
                        $lookup->execute([$membershipNumber]);
                        $member = $lookup->fetch();
                        if (!$member) {
                            $errors[] = 'Row ' . $rowNumber . ' does not match an active, verified member.';
                            continue;
                        }
                        $rows[] = [
                            'user_id' => (int) $member['user_id'],
                            'membership_number' => $membershipNumber,
                            'fields' => $fields,
                        ];
                    }
                }
            }
            fclose($handle);
            if (!$errors && $rows) {
                $preview = [
                    'token' => bin2hex(random_bytes(32)),
                    'expires_at' => time() + 900,
                    'rows' => $rows,
                ];
                $_SESSION['member_import_preview'] = $preview;
                redirect('member_import.php?preview=1');
            } elseif (!$errors) {
                $errors[] = 'The CSV file has no member profile rows to update.';
            }
        }
    } elseif ($action === 'confirm') {
        $postedToken = (string) ($_POST['preview_token'] ?? '');
        if (!is_array($preview) || !isset($preview['token'], $preview['expires_at'], $preview['rows'])
            || (int) $preview['expires_at'] < time() || !hash_equals((string) $preview['token'], $postedToken)) {
            unset($_SESSION['member_import_preview']);
            $preview = null;
            $errors[] = 'The import preview expired or changed. Upload the CSV again.';
        } else {
            try {
                db()->beginTransaction();
                $checkMember = db()->prepare(
                    "SELECT mp.membership_number
                     FROM member_profiles mp JOIN users u ON u.id=mp.user_id
                     WHERE mp.user_id=? AND mp.membership_status='active'
                       AND u.status='active' AND u.email_verified_at IS NOT NULL
                     FOR UPDATE"
                );
                foreach ($preview['rows'] as $row) {
                    $checkMember->execute([(int) $row['user_id']]);
                    if ($checkMember->fetchColumn() !== $row['membership_number']) {
                        throw new InvalidArgumentException('A member changed after preview. No import changes were saved; preview the file again.');
                    }
                    $sets = [];
                    $values = [];
                    foreach ($row['fields'] as $field => $value) {
                        if (!array_key_exists($field, $importableFields)) {
                            throw new InvalidArgumentException('The preview contains an unsupported member profile field.');
                        }
                        $sets[] = $field . ' = ?';
                        $values[] = $value;
                    }
                    $values[] = (int) $row['user_id'];
                    $stmt = db()->prepare('UPDATE member_profiles SET ' . implode(', ', $sets) . ' WHERE user_id = ?');
                    $stmt->execute($values);
                }
                db()->commit();
            } catch (InvalidArgumentException $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                unset($_SESSION['member_import_preview']);
                $preview = null;
                $errors[] = $exception->getMessage();
            } catch (PDOException $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
            if (!$errors) {
                $updatedCount = count($preview['rows']);
                unset($_SESSION['member_import_preview']);
                audit((int) $admin['id'], 'member_profile_csv_imported', 'member_count=' . $updatedCount);
                flash('success', $updatedCount . ' member profiles were updated.');
                redirect('member_import.php');
            }
        }
    } else {
        $errors[] = 'Choose a valid import action.';
    }
}

if (isset($_GET['preview']) && $_GET['preview'] !== '1') {
    unset($_SESSION['member_import_preview']);
    $preview = null;
}
if (is_array($preview) && (int) ($preview['expires_at'] ?? 0) < time()) {
    unset($_SESSION['member_import_preview']);
    $preview = null;
}

render_header('Import member profile CSV');
page_title('MEMBER ADMINISTRATION', 'Import member profile data', 'Preview and validate changes before saving them. Only existing, active, email-verified members can be updated.');
?>
<section class="panel module-form-panel">
    <h2>Upload CSV for preview</h2>
    <p class="muted">Required column: <code>membership_number</code>. Optional columns: <code>phone</code>, <code>occupation</code>, <code>employer</code>, <code>location</code>, <code>biography</code>. Blank cells leave existing values unchanged. Account emails, passwords, roles, dates of birth, and membership statuses cannot be imported.</p>
    <form method="post" enctype="multipart/form-data" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="preview"><label for="csv-file">Member profile CSV</label><input id="csv-file" name="csv_file" type="file" accept=".csv,text/csv" required><small class="muted">Maximum 2 MB and 500 data rows.</small><button class="button button-primary" type="submit">Validate and preview</button></form>
</section>
<?php if ($errors): ?><section class="panel top-gap" role="alert"><h2>Import needs correction</h2><ul><?php foreach (array_slice($errors, 0, 100) as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<?php if (is_array($preview) && isset($preview['rows']) && ($_GET['preview'] ?? '') === '1'): ?>
<section class="panel table-panel module-table top-gap"><h2>Preview · <?= count($preview['rows']) ?> member(s)</h2><p class="muted">Review these changes. This preview expires in 15 minutes.</p><div class="table-scroll"><table><thead><tr><th>Membership number</th><th>Fields to update</th></tr></thead><tbody>
<?php foreach ($preview['rows'] as $row): ?><tr><td><?= e($row['membership_number']) ?></td><td><?php foreach ($row['fields'] as $field => $value): ?><strong><?= e(ucfirst($field)) ?>:</strong> <?= e($value) ?><br><?php endforeach; ?></td></tr><?php endforeach; ?>
</tbody></table></div><form method="post" class="top-gap"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="preview_token" value="<?= e($preview['token']) ?>"><button class="button button-primary" type="submit">Confirm and import</button></form></section>
<?php endif; ?>
<?php render_footer(); ?>
