<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
$role = school_user_role_for_school((int) $user['id'], $schoolId);
if (!in_array($role, ['school_headmaster', 'school_proprietor'], true)) {
    http_response_code(403);
    exit('Only an authorized Headmaster or Proprietor can issue completion certificates.');
}
require_school_permission($schoolId, 'students.lifecycle.manage');

$schoolStmt = db()->prepare(
    'SELECT id, name, address, region, district, contact_email, contact_phone,
            logo_path, require_proprietor_certificate_signature
     FROM schools WHERE id = ? LIMIT 1'
);
$schoolStmt->execute([$schoolId]);
$school = $schoolStmt->fetch();
if (!$school) {
    http_response_code(404);
    exit('School not found.');
}

$headmastersStmt = db()->prepare(
    'SELECT sm.user_id, u.full_name FROM school_memberships sm
     JOIN users u ON u.id = sm.user_id
     WHERE sm.school_id = ? AND sm.role_name = "school_headmaster"
       AND sm.status = "active" AND u.status = "active"
     ORDER BY u.full_name'
);
$headmastersStmt->execute([$schoolId]);
$headmasters = $headmastersStmt->fetchAll();
$proprietorsStmt = db()->prepare(
    'SELECT sm.user_id, u.full_name FROM school_memberships sm
     JOIN users u ON u.id = sm.user_id
     WHERE sm.school_id = ? AND sm.role_name = "school_proprietor"
       AND sm.status = "active" AND u.status = "active"
     ORDER BY u.full_name'
);
$proprietorsStmt->execute([$schoolId]);
$proprietors = $proprietorsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    db()->beginTransaction();
    try {
        if ($action === 'revoke_certificate') {
            $certificateId = filter_var($_POST['certificate_id'] ?? null, FILTER_VALIDATE_INT);
            if ($certificateId === false || $certificateId < 1
                || preg_match('/^.{5,1000}$/us', $reason) !== 1) {
                throw new InvalidArgumentException('Select an issued certificate and provide a revocation reason of 5 to 1000 characters.');
            }
            $certificateStmt = db()->prepare(
                'SELECT id, student_id, graduation_id FROM school_completion_certificates
                 WHERE id = ? AND school_id = ? AND status = "issued" FOR UPDATE'
            );
            $certificateStmt->execute([$certificateId, $schoolId]);
            $certificate = $certificateStmt->fetch();
            if (!$certificate) {
                throw new RuntimeException('Only an active certificate from this school can be revoked.');
            }
            db()->prepare(
                'UPDATE school_completion_certificates
                 SET status = "revoked", status_changed_by = ?, status_changed_at = UTC_TIMESTAMP(),
                     status_reason = ? WHERE id = ? AND school_id = ? AND status = "issued"'
            )->execute([(int) $user['id'], $reason, $certificateId, $schoolId]);
            $eventType = 'certificate_revoked';
            $eventStmt = db()->prepare(
                'INSERT INTO student_graduation_events
                 (school_id, student_id, graduation_id, certificate_id, actor_user_id, event_type, reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $eventStmt->execute([$schoolId, (int) $certificate['student_id'], (int) $certificate['graduation_id'],
                $certificateId, (int) $user['id'], $eventType, $reason]);
            audit((int) $user['id'], $eventType,
                'school_id=' . $schoolId . ';student_id=' . (int) $certificate['student_id']
                    . ';certificate_id=' . $certificateId . ';reason=' . $reason);
            db()->commit();
            flash('success', 'Certificate revoked. The graduation and historical student record remain intact.');
            redirect('graduation_certificates.php');
        }

        if (!in_array($action, ['issue_certificate', 'replace_certificate'], true)) {
            throw new InvalidArgumentException('Unsupported certificate action.');
        }
        $graduationId = filter_var($_POST['graduation_id'] ?? null, FILTER_VALIDATE_INT);
        $headmasterId = filter_var($_POST['headmaster_user_id'] ?? null, FILTER_VALIDATE_INT);
        $proprietorId = filter_var($_POST['proprietor_user_id'] ?? null, FILTER_VALIDATE_INT);
        $previousCertificateId = filter_var($_POST['previous_certificate_id'] ?? null, FILTER_VALIDATE_INT);
        if ($graduationId === false || $graduationId < 1 || $headmasterId === false || $headmasterId < 1
            || ($action === 'replace_certificate' && ($previousCertificateId === false || $previousCertificateId < 1
                || preg_match('/^.{5,1000}$/us', $reason) !== 1))) {
            throw new InvalidArgumentException('Choose an approved graduation, verified Headmaster, and provide the required replacement reason.');
        }
        if (!in_array($headmasterId, array_map(static fn(array $item): int => (int) $item['user_id'], $headmasters), true)) {
            throw new InvalidArgumentException('Select an active Headmaster assigned to this school.');
        }
        $proprietorName = null;
        if ((int) $school['require_proprietor_certificate_signature'] === 1) {
            if ($proprietorId === false || $proprietorId < 1
                || !in_array($proprietorId, array_map(static fn(array $item): int => (int) $item['user_id'], $proprietors), true)) {
                throw new InvalidArgumentException('School policy requires the signature of an active, verified Proprietor.');
            }
            foreach ($proprietors as $proprietor) {
                if ((int) $proprietor['user_id'] === $proprietorId) {
                    $proprietorName = (string) $proprietor['full_name'];
                    break;
                }
            }
        }

        $graduationStmt = db()->prepare(
            'SELECT g.id, g.student_id, g.academic_year, g.completion_program, g.graduation_date,
                    ss.full_name, ss.admission_number
             FROM student_graduations g
             JOIN school_students ss ON ss.id = g.student_id AND ss.school_id = g.school_id
             WHERE g.id = ? AND g.school_id = ? AND g.status = "approved"
               AND ss.school_status = "graduated" FOR UPDATE'
        );
        $graduationStmt->execute([$graduationId, $schoolId]);
        $graduation = $graduationStmt->fetch();
        if (!$graduation) {
            throw new RuntimeException('Certificates can only be issued for a currently approved graduation at this school.');
        }

        if ($action === 'issue_certificate') {
            $activeCertStmt = db()->prepare(
                'SELECT id FROM school_completion_certificates
                 WHERE graduation_id = ? AND status = "issued" LIMIT 1 FOR UPDATE'
            );
            $activeCertStmt->execute([$graduationId]);
            if ($activeCertStmt->fetchColumn()) {
                throw new RuntimeException('An issued certificate already exists. Use the authorized replacement action instead.');
            }
        } else {
            $previousStmt = db()->prepare(
                'SELECT id, student_id FROM school_completion_certificates
                 WHERE id = ? AND graduation_id = ? AND school_id = ? AND status = "issued" FOR UPDATE'
            );
            $previousStmt->execute([$previousCertificateId, $graduationId, $schoolId]);
            $previous = $previousStmt->fetch();
            if (!$previous) {
                throw new RuntimeException('Only the currently issued certificate for this graduation can be replaced.');
            }
            db()->prepare(
                'UPDATE school_completion_certificates
                 SET status = "replaced", status_changed_by = ?, status_changed_at = UTC_TIMESTAMP(),
                     status_reason = ? WHERE id = ? AND status = "issued"'
            )->execute([(int) $user['id'], $reason, (int) $previousCertificateId]);
        }

        $headmasterName = '';
        foreach ($headmasters as $headmaster) {
            if ((int) $headmaster['user_id'] === $headmasterId) {
                $headmasterName = (string) $headmaster['full_name'];
                break;
            }
        }
        $schoolContactParts = array_filter([
            (string) ($school['contact_email'] ?? ''),
            (string) ($school['contact_phone'] ?? ''),
        ], static fn(string $value): bool => $value !== '');
        $schoolAddress = implode(', ', array_filter([
            (string) ($school['address'] ?? ''),
            (string) ($school['district'] ?? ''),
            (string) ($school['region'] ?? ''),
        ], static fn(string $value): bool => $value !== ''));
        $identifier = trim((string) ($graduation['admission_number'] ?? ''));
        if ($identifier === '') {
            $identifier = (string) $graduation['student_id'];
        }
        $insertStmt = db()->prepare(
            'INSERT INTO school_completion_certificates
             (school_id, student_id, graduation_id, status, replaces_certificate_id,
              student_name, student_identifier, academic_year, completion_date, date_issued,
              school_name, school_address, school_contact, school_logo_path,
              headmaster_name, proprietor_name, issued_by)
             VALUES (?, ?, ?, "issued", ?, ?, ?, ?, ?, CURRENT_DATE(), ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertStmt->execute([
            $schoolId, (int) $graduation['student_id'], $graduationId,
            $action === 'replace_certificate' ? (int) $previousCertificateId : null,
            (string) $graduation['full_name'], $identifier, (string) $graduation['academic_year'],
            (string) $graduation['graduation_date'], strtoupper((string) $school['name']),
            $schoolAddress !== '' ? $schoolAddress : null,
            $schoolContactParts !== [] ? implode(' · ', $schoolContactParts) : null,
            is_string($school['logo_path']) ? $school['logo_path'] : null,
            $headmasterName, $proprietorName, (int) $user['id'],
        ]);
        $certificateId = (int) db()->lastInsertId();
        $eventType = $action === 'replace_certificate' ? 'certificate_replaced' : 'certificate_issued';
        $eventReason = $action === 'replace_certificate' ? $reason : 'Certificate of Completion issued after approved graduation.';
        $eventStmt = db()->prepare(
            'INSERT INTO student_graduation_events
             (school_id, student_id, graduation_id, certificate_id, actor_user_id, event_type, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $eventStmt->execute([
            $schoolId, (int) $graduation['student_id'], $graduationId, $certificateId,
            (int) $user['id'], $eventType, $eventReason,
        ]);
        audit((int) $user['id'], $eventType,
            'school_id=' . $schoolId . ';student_id=' . (int) $graduation['student_id']
                . ';certificate_id=' . $certificateId . ';reason=' . $eventReason);
        db()->commit();
        flash('success', $action === 'replace_certificate'
            ? 'Replacement certificate issued. The previous version remains in the audit history.'
            : 'Certificate issued. Open it to preview, print, or save as PDF.');
        redirect('school_certificate.php?id=' . $certificateId);
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $exception;
    }
    redirect('graduation_certificates.php');
}

$graduatesStmt = db()->prepare(
    'SELECT g.id AS graduation_id, g.student_id, g.academic_year, g.completion_program, g.graduation_date,
            ss.full_name, ss.admission_number,
            (SELECT c.id FROM school_completion_certificates c
             WHERE c.graduation_id = g.id AND c.status = "issued"
             ORDER BY c.issued_at DESC LIMIT 1) AS active_certificate_id
     FROM student_graduations g
     JOIN school_students ss ON ss.id = g.student_id AND ss.school_id = g.school_id
     WHERE g.school_id = ? AND g.status = "approved" AND ss.school_status = "graduated"
     ORDER BY g.graduation_date DESC, ss.full_name'
);
$graduatesStmt->execute([$schoolId]);
$graduates = $graduatesStmt->fetchAll();
$historyStmt = db()->prepare(
    'SELECT c.id, c.student_id, c.status, c.student_name, c.student_identifier,
            c.academic_year, c.date_issued, c.status_reason, c.issued_at
     FROM school_completion_certificates c
     WHERE c.school_id = ? ORDER BY c.issued_at DESC'
);
$historyStmt->execute([$schoolId]);
$certificateHistory = $historyStmt->fetchAll();
$certificateStatusChart = [];
foreach ($certificateHistory as $certificate) {
    $statusLabel = ucfirst((string) $certificate['status']);
    $certificateStatusChart[$statusLabel] = ($certificateStatusChart[$statusLabel] ?? 0) + 1;
}
$requireProprietor = (int) $school['require_proprietor_certificate_signature'] === 1;

render_header('Graduation certificates');
?>
<section class="panel">
    <p class="eyebrow">FORMER STUDENTS</p>
    <h1>Certificates of Completion</h1>
    <p class="muted">Issue certificates only after graduation approval. The print view supports landscape printing or saving as PDF; no certificate number, verification code, or QR code is included.</p>
    <p><a class="button button-secondary" href="graduation.php">Graduation approvals</a></p>
</section>

<section class="panel spaced-top" aria-label="Certificate status summary">
    <h2>Certificate history by status</h2>
    <p class="muted">Issued, replaced, and revoked certificates retained in this school’s history.</p>
    <?php render_dashboard_bar_chart('Completion certificates by status', $certificateStatusChart, 'certificates'); ?>
</section>

<section class="panel" style="margin-top:1.5rem;">
    <h2>Graduated students</h2>
    <?php if ($graduates === []): ?><p class="muted">No approved graduates are available for certificate issuance.</p>
    <?php else: ?><div class="table-scroll"><table><thead><tr><th>Graduate</th><th>Programme</th><th>Academic year</th><th>Completion date</th><th>Certificate</th></tr></thead><tbody>
        <?php foreach ($graduates as $graduate): ?><tr>
            <td><?= e((string) $graduate['full_name']) ?><br><small>Student ID: <?= e((string) ($graduate['admission_number'] ?: $graduate['student_id'])) ?></small></td>
            <td><?= e((string) $graduate['completion_program']) ?></td>
            <td><?= e(school_academic_year_label((string) $graduate['academic_year'])) ?></td>
            <td><?= e((string) $graduate['graduation_date']) ?></td>
            <td>
                <?php if ($graduate['active_certificate_id'] !== null): ?>
                    <a class="button button-secondary" href="school_certificate.php?id=<?= (int) $graduate['active_certificate_id'] ?>">Preview / print / save PDF</a>
                    <details class="certificate-action-details">
                        <summary>Replace certificate</summary>
                        <form method="post" class="form-stack">
                            <?= csrf_field() ?><input type="hidden" name="action" value="replace_certificate">
                            <input type="hidden" name="graduation_id" value="<?= (int) $graduate['graduation_id'] ?>">
                            <input type="hidden" name="previous_certificate_id" value="<?= (int) $graduate['active_certificate_id'] ?>">
                            <label>Headmaster signing the replacement
                                <select name="headmaster_user_id" required><option value="">Choose Headmaster</option>
                                    <?php foreach ($headmasters as $headmaster): ?><option value="<?= (int) $headmaster['user_id'] ?>"><?= e((string) $headmaster['full_name']) ?></option><?php endforeach; ?>
                                </select>
                            </label>
                            <?php if ($requireProprietor): ?><label>Proprietor signature is required
                                <select name="proprietor_user_id" required><option value="">Choose Proprietor</option>
                                    <?php foreach ($proprietors as $proprietor): ?><option value="<?= (int) $proprietor['user_id'] ?>"><?= e((string) $proprietor['full_name']) ?></option><?php endforeach; ?>
                                </select>
                            </label><?php endif; ?>
                            <label>Replacement reason<textarea name="reason" minlength="5" maxlength="1000" required></textarea></label>
                            <button class="button button-primary" type="submit">Issue replacement</button>
                        </form>
                    </details>
                    <form method="post" class="certificate-revoke-form" onsubmit="return confirm('Revoke this certificate? The graduation record will remain intact.');">
                        <?= csrf_field() ?><input type="hidden" name="action" value="revoke_certificate">
                        <input type="hidden" name="certificate_id" value="<?= (int) $graduate['active_certificate_id'] ?>">
                        <label>Revocation reason<input name="reason" minlength="5" maxlength="1000" required></label>
                        <button class="button button-quiet" type="submit">Revoke certificate</button>
                    </form>
                <?php else: ?>
                    <form method="post" class="form-stack">
                        <?= csrf_field() ?><input type="hidden" name="action" value="issue_certificate">
                        <input type="hidden" name="graduation_id" value="<?= (int) $graduate['graduation_id'] ?>">
                        <label>Headmaster signing the certificate
                            <select name="headmaster_user_id" required><option value="">Choose Headmaster</option>
                                <?php foreach ($headmasters as $headmaster): ?><option value="<?= (int) $headmaster['user_id'] ?>"><?= e((string) $headmaster['full_name']) ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <?php if ($requireProprietor): ?><label>Proprietor signature is required
                            <select name="proprietor_user_id" required><option value="">Choose Proprietor</option>
                                <?php foreach ($proprietors as $proprietor): ?><option value="<?= (int) $proprietor['user_id'] ?>"><?= e((string) $proprietor['full_name']) ?></option><?php endforeach; ?>
                            </select>
                        </label><?php endif; ?>
                        <button class="button button-primary" type="submit">Issue certificate</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>

<section class="panel" style="margin-top:1.5rem;">
    <h2>Certificate history</h2>
    <?php if ($certificateHistory === []): ?><p class="muted">No certificates have been issued.</p>
    <?php else: ?><div class="table-scroll"><table><thead><tr><th>Student</th><th>Academic year</th><th>Date issued</th><th>Status</th><th>Reason</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($certificateHistory as $certificate): ?><tr>
            <td><?= e((string) $certificate['student_name']) ?><br><small><?= e((string) $certificate['student_identifier']) ?></small></td>
            <td><?= e(school_academic_year_label((string) $certificate['academic_year'])) ?></td>
            <td><?= e((string) $certificate['date_issued']) ?></td>
            <td><?= e(ucfirst((string) $certificate['status'])) ?></td>
            <td><?= e((string) ($certificate['status_reason'] ?: '—')) ?></td>
            <td><?php if ($certificate['status'] === 'issued'): ?><a href="school_certificate.php?id=<?= (int) $certificate['id'] ?>">Preview / print</a><?php else: ?>Not available for printing<?php endif; ?></td>
        </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<?php render_footer(); ?>
