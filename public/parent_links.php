<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'parent_links.manage');
$actorId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));
    $note = $note === '' ? null : substr($note, 0, 255);

    try {
        $pdo = db();
        $pdo->beginTransaction();

        if ($action === 'create_link') {
            $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
            $guardianId = filter_var($_POST['guardian_id'] ?? null, FILTER_VALIDATE_INT);
            if ($studentId === false || $studentId < 1 || $guardianId === false || $guardianId < 1) {
                throw new InvalidArgumentException('Choose a valid student and guardian account.');
            }

            $eligibleGuardian = $pdo->prepare(
                'SELECT guardian.id, parent_account.id AS user_id
                 FROM school_guardians guardian
                 JOIN users parent_account
                   ON LOWER(TRIM(parent_account.email)) = LOWER(TRIM(guardian.email))
                      AND parent_account.status = "active"
                      AND parent_account.email_verified_at IS NOT NULL
                 JOIN school_memberships membership
                   ON membership.user_id = parent_account.id AND membership.school_id = guardian.school_id
                      AND membership.role_name = "school_parent" AND membership.status = "active"
                 WHERE guardian.id = ? AND guardian.school_id = ?
                   AND guardian.email IS NOT NULL AND TRIM(guardian.email) <> ""
                   AND EXISTS (
                       SELECT 1 FROM school_students student
                       WHERE student.id = ? AND student.school_id = guardian.school_id
                   )
                 LIMIT 1'
            );
            $eligibleGuardian->execute([$guardianId, $schoolId, $studentId]);
            $eligible = $eligibleGuardian->fetch();
            if (!$eligible) {
                throw new RuntimeException('The guardian email must match an existing, active, email-verified parent account at this school, and the student must belong to this school.');
            }
            $parentUserId = (int) $eligible['user_id'];

            $existingStmt = $pdo->prepare(
                'SELECT id, status FROM school_parent_links
                 WHERE school_id = ? AND student_id = ? AND user_id = ?
                 FOR UPDATE'
            );
            $existingStmt->execute([$schoolId, $studentId, $parentUserId]);
            $existing = $existingStmt->fetch();
            if ($existing && (string) $existing['status'] !== 'revoked') {
                throw new RuntimeException('A pending or verified link already exists for this student and parent.');
            }

            if ($existing) {
                $linkId = (int) $existing['id'];
                $pdo->prepare(
                    'UPDATE school_parent_links
                     SET guardian_id = ?, status = "pending", created_by = ?,
                         verified_by = NULL, verified_at = NULL, revoked_by = NULL, revoked_at = NULL
                     WHERE id = ? AND school_id = ? AND status = "revoked"'
                )->execute([$guardianId, $actorId, $linkId, $schoolId]);
                $auditAction = 'reopened';
                $previousStatus = 'revoked';
            } else {
                $pdo->prepare(
                    'INSERT INTO school_parent_links
                     (school_id, student_id, guardian_id, user_id, status, created_by)
                     VALUES (?, ?, ?, ?, "pending", ?)'
                )->execute([$schoolId, $studentId, $guardianId, $parentUserId, $actorId]);
                $linkId = (int) $pdo->lastInsertId();
                $auditAction = 'created';
                $previousStatus = null;
            }

            $pdo->prepare(
                'INSERT INTO school_parent_link_audit
                 (school_id, link_id, actor_user_id, action, previous_status, new_status, note)
                 VALUES (?, ?, ?, ?, ?, "pending", ?)'
            )->execute([$schoolId, $linkId, $actorId, $auditAction, $previousStatus, $note]);
            audit($actorId, 'school_parent_link_' . $auditAction, 'school_id=' . $schoolId . ';link_id=' . $linkId);
            $pdo->commit();
            flash('success', 'Parent link saved as pending. Verify the relationship before approving access.');
            redirect('parent_links.php');
        }

        if (in_array($action, ['verify_link', 'revoke_link'], true)) {
            $linkId = filter_var($_POST['link_id'] ?? null, FILTER_VALIDATE_INT);
            if ($linkId === false || $linkId < 1) {
                throw new InvalidArgumentException('Choose a valid parent link.');
            }
            $linkStmt = $pdo->prepare(
                'SELECT link.id, link.student_id, link.guardian_id, link.user_id, link.status,
                        guardian.email AS guardian_email,
                        parent_account.email AS parent_email,
                        parent_account.status AS parent_status,
                        parent_account.email_verified_at,
                        membership.id AS membership_id
                 FROM school_parent_links link
                 JOIN school_guardians guardian
                   ON guardian.id = link.guardian_id AND guardian.school_id = link.school_id
                 JOIN school_students student
                   ON student.id = link.student_id AND student.school_id = link.school_id
                 JOIN users parent_account ON parent_account.id = link.user_id
                 LEFT JOIN school_memberships membership
                   ON membership.user_id = link.user_id AND membership.school_id = link.school_id
                      AND membership.role_name = "school_parent" AND membership.status = "active"
                 WHERE link.id = ? AND link.school_id = ?
                 FOR UPDATE'
            );
            $linkStmt->execute([$linkId, $schoolId]);
            $link = $linkStmt->fetch();
            if (!$link) {
                throw new RuntimeException('The parent link is not available in this school.');
            }
            $previousStatus = (string) $link['status'];

            if ($action === 'verify_link') {
                $emailMatches = strtolower(trim((string) $link['guardian_email']))
                    === strtolower(trim((string) $link['parent_email']));
                if ($previousStatus !== 'pending' || !$emailMatches
                    || $link['guardian_email'] === null || trim((string) $link['guardian_email']) === ''
                    || $link['email_verified_at'] === null || $link['parent_status'] !== 'active'
                    || $link['membership_id'] === null) {
                    throw new RuntimeException('This link cannot be approved: it must be pending and its existing parent account must remain active, verified, and email-matched at this school.');
                }
                $pdo->prepare(
                    'UPDATE school_parent_links
                     SET status = "verified", verified_by = ?, verified_at = UTC_TIMESTAMP(),
                         revoked_by = NULL, revoked_at = NULL
                     WHERE id = ? AND school_id = ? AND status = "pending"'
                )->execute([$actorId, $linkId, $schoolId]);
                $newStatus = 'verified';
                $auditAction = 'verified';
            } else {
                if (!in_array($previousStatus, ['pending', 'verified'], true)) {
                    throw new RuntimeException('Only a pending or verified link can be revoked.');
                }
                $pdo->prepare(
                    'UPDATE school_parent_links
                     SET status = "revoked", revoked_by = ?, revoked_at = UTC_TIMESTAMP()
                     WHERE id = ? AND school_id = ? AND status = ?'
                )->execute([$actorId, $linkId, $schoolId, $previousStatus]);
                $newStatus = 'revoked';
                $auditAction = 'revoked';
            }

            $pdo->prepare(
                'INSERT INTO school_parent_link_audit
                 (school_id, link_id, actor_user_id, action, previous_status, new_status, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$schoolId, $linkId, $actorId, $auditAction, $previousStatus, $newStatus, $note]);
            audit($actorId, 'school_parent_link_' . $auditAction, 'school_id=' . $schoolId . ';link_id=' . $linkId);
            $pdo->commit();
            flash('success', $newStatus === 'verified' ? 'Parent link verified and approved.' : 'Parent link revoked.');
            redirect('parent_links.php');
        }

        throw new InvalidArgumentException('Unknown parent-link action.');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', $exception instanceof PDOException
            ? 'The parent-link change could not be saved. Check whether a link already exists, then try again.'
            : $exception->getMessage());
        redirect('parent_links.php');
    }
}

$eligibleStmt = db()->prepare(
    'SELECT guardian.id AS guardian_id, guardian.full_name AS guardian_name,
            guardian.email, parent_account.full_name AS parent_name
     FROM school_guardians guardian
     JOIN users parent_account
       ON LOWER(TRIM(parent_account.email)) = LOWER(TRIM(guardian.email))
          AND parent_account.status = "active"
          AND parent_account.email_verified_at IS NOT NULL
     JOIN school_memberships membership
       ON membership.user_id = parent_account.id AND membership.school_id = guardian.school_id
          AND membership.role_name = "school_parent" AND membership.status = "active"
     WHERE guardian.school_id = ? AND guardian.email IS NOT NULL AND TRIM(guardian.email) <> ""
     ORDER BY guardian.full_name, guardian.email'
);
$eligibleStmt->execute([$schoolId]);
$eligibleGuardians = $eligibleStmt->fetchAll();
$studentStmt = db()->prepare(
    'SELECT id, full_name, admission_number, school_status
     FROM school_students WHERE school_id = ? ORDER BY full_name'
);
$studentStmt->execute([$schoolId]);
$schoolStudents = $studentStmt->fetchAll();
$linksStmt = db()->prepare(
    'SELECT link.id, link.student_id, link.guardian_id, link.user_id, link.status,
            link.created_at, link.verified_at, link.revoked_at,
            student.full_name AS student_name, student.admission_number,
            guardian.full_name AS guardian_name, guardian.email AS guardian_email,
            parent_account.full_name AS parent_name, parent_account.email AS parent_email,
            creator.full_name AS created_by_name, verifier.full_name AS verified_by_name,
            revoker.full_name AS revoked_by_name
     FROM school_parent_links link
     JOIN school_students student
       ON student.id = link.student_id AND student.school_id = link.school_id
     JOIN school_guardians guardian
       ON guardian.id = link.guardian_id AND guardian.school_id = link.school_id
     JOIN users parent_account ON parent_account.id = link.user_id
     LEFT JOIN users creator ON creator.id = link.created_by
     LEFT JOIN users verifier ON verifier.id = link.verified_by
     LEFT JOIN users revoker ON revoker.id = link.revoked_by
     WHERE link.school_id = ? ORDER BY link.updated_at DESC, link.id DESC'
);
$linksStmt->execute([$schoolId]);
$links = $linksStmt->fetchAll();
$historyStmt = db()->prepare(
    'SELECT history.action, history.previous_status, history.new_status, history.note, history.created_at,
            actor.full_name AS actor_name, history.link_id
     FROM school_parent_link_audit history
     LEFT JOIN users actor ON actor.id = history.actor_user_id
     WHERE history.school_id = ? ORDER BY history.created_at DESC, history.id DESC LIMIT 100'
);
$historyStmt->execute([$schoolId]);
$history = $historyStmt->fetchAll();

render_header('Parent / guardian links');
?>
<section class="panel">
    <p class="eyebrow">STUDENT ACCESS</p>
    <h1>Parent / guardian links</h1>
    <p class="muted">Create pending links only for an existing student and an existing active parent account whose verified email matches the school's guardian email. Confirm the family relationship outside this form before approval. Admission numbers are not proof of identity.</p>
</section>

<section class="panel" style="margin-top:1.5rem;">
    <h2>Request a parent link</h2>
    <?php if ($schoolStudents === [] || $eligibleGuardians === []): ?>
        <p class="muted">A student and a matching active, email-verified school parent account are required before a link can be requested.</p>
    <?php else: ?>
    <form method="post" action="parent_links.php" class="form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_link">
        <div class="form-grid two-columns">
            <div><label for="student_id">Student</label><select id="student_id" name="student_id" required><option value="">Select student</option>
                <?php foreach ($schoolStudents as $student): ?>
                    <option value="<?= (int) $student['id'] ?>"><?= e((string) $student['full_name']) ?><?= $student['admission_number'] ? ' · ' . e((string) $student['admission_number']) : '' ?><?= $student['school_status'] !== 'active' ? ' · ' . e((string) $student['school_status']) : '' ?></option>
                <?php endforeach; ?>
            </select></div>
            <div><label for="guardian_id">Guardian record / matching account</label><select id="guardian_id" name="guardian_id" required><option value="">Select guardian</option>
                <?php foreach ($eligibleGuardians as $guardian): ?>
                    <option value="<?= (int) $guardian['guardian_id'] ?>"><?= e((string) $guardian['guardian_name']) ?> — <?= e((string) $guardian['email']) ?> (<?= e((string) $guardian['parent_name']) ?>)</option>
                <?php endforeach; ?>
            </select></div>
        </div>
        <label for="note">Verification note (optional)</label>
        <input id="note" name="note" maxlength="255">
        <button class="button button-primary" type="submit">Create pending link</button>
    </form>
    <?php endif; ?>
</section>

<section class="panel" style="margin-top:1.5rem;">
    <h2>Links at this school</h2>
    <?php if ($links === []): ?><p class="muted">No parent links have been recorded.</p><?php else: ?>
    <div class="table-scroll"><table><thead><tr><th>Student</th><th>Guardian / account</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($links as $link): ?>
        <tr>
            <td><?= e((string) $link['student_name']) ?><?= $link['admission_number'] ? ' · ' . e((string) $link['admission_number']) : '' ?></td>
            <td><?= e((string) $link['guardian_name']) ?> · <?= e((string) $link['guardian_email']) ?><br><span class="muted">Account: <?= e((string) $link['parent_name']) ?> (<?= e((string) $link['parent_email']) ?>)</span></td>
            <td><?= e(ucfirst((string) $link['status'])) ?>
                <?php if ($link['status'] === 'verified'): ?><br><span class="muted">Approved by <?= e((string) ($link['verified_by_name'] ?? 'former administrator')) ?> · <?= e((string) $link['verified_at']) ?></span><?php endif; ?>
                <?php if ($link['status'] === 'revoked'): ?><br><span class="muted">Revoked by <?= e((string) ($link['revoked_by_name'] ?? 'former administrator')) ?> · <?= e((string) $link['revoked_at']) ?></span><?php endif; ?>
            </td>
            <td>
                <?php if ($link['status'] === 'pending'): ?>
                <form method="post" action="parent_links.php" class="form-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="verify_link"><input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                    <input name="note" maxlength="255" placeholder="Verification note (optional)">
                    <button class="button button-primary" type="submit">Verify and approve</button>
                </form>
                <?php endif; ?>
                <?php if (in_array($link['status'], ['pending', 'verified'], true)): ?>
                <form method="post" action="parent_links.php" class="top-gap">
                    <?= csrf_field() ?><input type="hidden" name="action" value="revoke_link"><input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                    <input name="note" maxlength="255" placeholder="Revocation note (optional)">
                    <button class="button" type="submit">Revoke</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>

<section class="panel" style="margin-top:1.5rem;">
    <h2>Recent link audit history</h2>
    <?php if ($history === []): ?><p class="muted">No link changes have been recorded.</p><?php else: ?>
    <div class="table-scroll"><table><thead><tr><th>When</th><th>Link</th><th>Action</th><th>Status change</th><th>By</th><th>Note</th></tr></thead><tbody>
        <?php foreach ($history as $entry): ?><tr>
            <td><?= e((string) $entry['created_at']) ?></td><td>#<?= (int) $entry['link_id'] ?></td>
            <td><?= e(ucfirst((string) $entry['action'])) ?></td>
            <td><?= e((string) ($entry['previous_status'] ?? '—')) ?> → <?= e((string) $entry['new_status']) ?></td>
            <td><?= e((string) ($entry['actor_name'] ?? 'Former administrator')) ?></td>
            <td><?= e((string) ($entry['note'] ?? '—')) ?></td>
        </tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
