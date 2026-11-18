<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_login();
$canView = user_has_permission($user, 'jobs.view')
    || user_has_permission($user, 'jobs.manage')
    || user_has_permission($user, 'jobs.review');
$canSubmit = user_has_permission($user, 'jobs.submit');
$canReview = user_has_permission($user, 'jobs.review') || user_has_permission($user, 'jobs.manage');
$canApply = user_has_permission($user, 'jobs.apply');
$memberId = (int) $user['id'];
$activeMember = false;
if ($canSubmit || $canApply) {
    $stmt = db()->prepare(
        "SELECT 1 FROM users u JOIN member_profiles mp ON mp.user_id=u.id
         WHERE u.id=? AND u.status='active' AND u.email_verified_at IS NOT NULL
           AND mp.membership_status='active'"
    );
    $stmt->execute([$memberId]);
    $activeMember = (bool) $stmt->fetchColumn();
}
$canSubmit = $canSubmit && $activeMember;
$canApply = $canApply && $activeMember;
if (!$canView && !$canSubmit) {
    http_response_code(403);
    render_header('Access denied');
    echo '<section class="panel"><h1>Access denied</h1><p>Your account cannot access job opportunities.</p><a class="button" href="dashboard.php">Return to dashboard</a></section>';
    render_footer();
    exit;
}

function job_deadline_utc(string $value): ?string
{
    global $config;
    $timezone = new DateTimeZone((string) ($config['timezone'] ?? 'UTC'));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function store_job_attachment(array $upload): string
{
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($error !== UPLOAD_ERR_OK) {
        error_log('OSAAK job-advertisement upload failed with PHP upload error ' . $error . '.');
        throw new RuntimeException('The attachment upload failed. Check the server upload settings and try again.');
    }
    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    $size = (int) ($upload['size'] ?? 0);
    if (!is_uploaded_file($temporaryPath) || $size < 1 || $size > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('The optional job advertisement must be no larger than 5 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($temporaryPath);
    $extensions = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_string($mime) || !isset($extensions[$mime])) {
        throw new InvalidArgumentException('Upload a PDF, JPEG, PNG, or WebP job advertisement.');
    }
    $relativePath = 'job-advertisements/' . bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
    $destination = APP_ROOT . '/storage/' . $relativePath;
    $directory = dirname($destination);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Private job-advertisement storage could not be created.');
    }
    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('The job advertisement could not be saved.');
    }
    return $relativePath;
}

function job_submission_fields(): array
{
    $title = trim((string) ($_POST['title'] ?? ''));
    $organisation = trim((string) ($_POST['organisation'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));
    $employmentType = (string) ($_POST['employment_type'] ?? '');
    $industry = trim((string) ($_POST['industry'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $qualifications = trim((string) ($_POST['qualifications'] ?? ''));
    $url = trim((string) ($_POST['application_url'] ?? ''));
    $applicationEmail = strtolower(trim((string) ($_POST['application_email'] ?? '')));
    $instructions = trim((string) ($_POST['application_instructions'] ?? ''));
    $deadlineInput = trim((string) ($_POST['closes_at'] ?? ''));
    $closesAt = $deadlineInput !== '' ? job_deadline_utc($deadlineInput) : null;
    $validUrl = $url === '' || (
        filter_var($url, FILTER_VALIDATE_URL) !== false
        && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
    );
    if (preg_match('/^.{2,160}$/us', $title) !== 1
        || preg_match('/^.{2,160}$/us', $organisation) !== 1
        || strlen($location) > 160
        || !in_array($employmentType, ['full_time','part_time','contract','internship','temporary','other'], true)
        || strlen($industry) > 120
        || strlen($description) < 10 || strlen($description) > 30000
        || strlen($qualifications) > 30000
        || !$validUrl || strlen($url) > 1000
        || ($applicationEmail !== '' && (!filter_var($applicationEmail, FILTER_VALIDATE_EMAIL) || strlen($applicationEmail) > 254))
        || ($url === '' && $applicationEmail === '' && $instructions === '')
        || strlen($instructions) > 20000
        || ($deadlineInput !== '' && !$closesAt)) {
        throw new InvalidArgumentException('Check the job details, employment type, application contact, and deadline.');
    }
    return [
        $title, $organisation, $location !== '' ? $location : null, $employmentType,
        $industry !== '' ? $industry : null, $description,
        $qualifications !== '' ? $qualifications : null,
        $url !== '' ? $url : null, $applicationEmail !== '' ? $applicationEmail : null,
        $instructions !== '' ? $instructions : null, $closesAt,
    ];
}

function redirect_jobs(string $error = ''): never
{
    if ($error !== '') {
        flash('error', $error);
    }
    redirect('jobs.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $newAttachment = '';
    try {
        if ($action === 'submit_job' && $canSubmit) {
            $fields = job_submission_fields();
            $newAttachment = store_job_attachment($_FILES['attachment'] ?? []);
            $stmt = db()->prepare(
                "INSERT INTO association_jobs
                 (title,organisation,location,employment_type,industry,description,qualifications,
                  application_url,application_email,application_instructions,closes_at,status,created_by,
                  submission_kind,review_status,attachment_path,created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,'draft',?,'member','pending',?,UTC_TIMESTAMP())"
            );
            $stmt->execute([...$fields, $memberId, $newAttachment !== '' ? $newAttachment : null]);
            $jobId = (int) db()->lastInsertId();
            audit($memberId, 'member_job_submitted', 'job_id=' . $jobId);
            flash('success', 'Your opportunity was submitted and is pending review.');
        } elseif ($action === 'create_official_job' && user_has_permission($user, 'jobs.manage')) {
            $fields = job_submission_fields();
            $status = (string) ($_POST['status'] ?? 'draft');
            if (!in_array($status, ['draft','published'], true)) {
                throw new InvalidArgumentException('Choose a valid official opportunity status.');
            }
            $stmt = db()->prepare(
                "INSERT INTO association_jobs
                 (title,organisation,location,employment_type,industry,description,qualifications,
                  application_url,application_email,application_instructions,closes_at,status,created_by,
                  submission_kind,review_status,created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'association',?,UTC_TIMESTAMP())"
            );
            $stmt->execute([...$fields, $status, $memberId, $status === 'published' ? 'approved' : 'not_required']);
            audit($memberId, 'job_post_created', 'job_id=' . (int) db()->lastInsertId());
            flash('success', 'Official association opportunity saved.');
        } elseif ($action === 'edit_official_job' && user_has_permission($user, 'jobs.manage')) {
            $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT);
            $fields = job_submission_fields();
            if (!$jobId) {
                throw new InvalidArgumentException('Choose a valid official opportunity to edit.');
            }
            $stmt = db()->prepare(
                "UPDATE association_jobs SET title=?,organisation=?,location=?,employment_type=?,industry=?,
                    description=?,qualifications=?,application_url=?,application_email=?,application_instructions=?,
                    closes_at=? WHERE id=? AND submission_kind='association'"
            );
            $stmt->execute([...$fields, (int) $jobId]);
            if (!$stmt->rowCount()) {
                $stmt = db()->prepare("SELECT 1 FROM association_jobs WHERE id=? AND submission_kind='association'");
                $stmt->execute([(int) $jobId]);
                if (!$stmt->fetchColumn()) {
                    throw new InvalidArgumentException('That official association opportunity was not found.');
                }
            }
            audit($memberId, 'job_post_updated', 'job_id=' . (int) $jobId);
            flash('success', 'Official opportunity details updated.');
        } elseif ($action === 'official_job_status' && user_has_permission($user, 'jobs.manage')) {
            $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            if (!$jobId || !in_array($status, ['draft','published','closed','archived'], true)) {
                throw new InvalidArgumentException('Choose a valid official opportunity status.');
            }
            $stmt = db()->prepare(
                "UPDATE association_jobs
                 SET status=?,review_status=CASE WHEN ?='published' THEN 'approved' ELSE 'not_required' END
                 WHERE id=? AND submission_kind='association'"
            );
            $stmt->execute([$status, $status, (int) $jobId]);
            if (!$stmt->rowCount()) {
                throw new InvalidArgumentException('That official association opportunity was not found.');
            }
            audit($memberId, 'job_post_status_changed', 'job_id=' . (int) $jobId . '; status=' . $status);
            flash('success', 'Official opportunity status updated.');
        } elseif ($action === 'edit_job' && $canSubmit) {
            $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$jobId) {
                throw new InvalidArgumentException('Choose a valid opportunity to edit.');
            }
            $stmt = db()->prepare(
                "SELECT attachment_path,review_status FROM association_jobs
                 WHERE id=? AND created_by=? AND submission_kind='member'
                   AND review_status IN ('pending','approved')"
            );
            $stmt->execute([(int) $jobId, $memberId]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw new InvalidArgumentException('Only your pending or approved submissions can be edited.');
            }
            $fields = job_submission_fields();
            $newAttachment = store_job_attachment($_FILES['attachment'] ?? []);
            $attachment = $newAttachment !== '' ? $newAttachment : $existing['attachment_path'];
            $stmt = db()->prepare(
                "UPDATE association_jobs
                 SET title=?,organisation=?,location=?,employment_type=?,industry=?,description=?,
                     qualifications=?,application_url=?,application_email=?,application_instructions=?,
                     closes_at=?,status='draft',review_status='pending',review_note=NULL,
                     reviewed_by=NULL,reviewed_at=NULL,attachment_path=?
                 WHERE id=? AND created_by=? AND submission_kind='member'
                   AND review_status IN ('pending','approved')"
            );
            $stmt->execute([...$fields, $attachment, (int) $jobId, $memberId]);
            if ($newAttachment !== '' && $existing['attachment_path']) {
                $oldPath = APP_ROOT . '/storage/' . $existing['attachment_path'];
                if (is_file($oldPath) && !unlink($oldPath)) {
                    error_log('OSAAK could not remove a replaced job advertisement.');
                }
            }
            audit($memberId, 'member_job_updated', 'job_id=' . (int) $jobId . '; review_status=pending');
            flash('success', 'Your changes were saved and returned to Pending Review.');
        } elseif ($action === 'withdraw_job' && $canSubmit) {
            $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT);
            $stmt = db()->prepare(
                "UPDATE association_jobs SET status='archived',review_status='withdrawn'
                 WHERE id=? AND created_by=? AND submission_kind='member' AND review_status='pending'"
            );
            $stmt->execute([(int) $jobId, $memberId]);
            if (!$jobId || !$stmt->rowCount()) {
                throw new InvalidArgumentException('Only your pending submissions can be withdrawn.');
            }
            audit($memberId, 'member_job_withdrawn', 'job_id=' . (int) $jobId);
            flash('success', 'Your pending opportunity was withdrawn.');
        } elseif ($action === 'review_job' && $canReview) {
            $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT);
            $decision = (string) ($_POST['decision'] ?? '');
            $reason = trim((string) ($_POST['review_note'] ?? ''));
            if (!$jobId || !in_array($decision, ['approve','reject','remove'], true)
                || strlen($reason) > 1000 || ($decision === 'reject' && $reason === '')) {
                throw new InvalidArgumentException('Choose a review decision and provide a reason when rejecting an opportunity.');
            }
            $status = $decision === 'approve' ? 'published' : 'archived';
            $reviewStatus = ['approve' => 'approved', 'reject' => 'rejected', 'remove' => 'removed'][$decision];
            $note = $decision === 'reject' ? $reason : ($reason !== '' ? $reason : null);
            $stmt = db()->prepare(
                "UPDATE association_jobs
                 SET status=?,review_status=?,review_note=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP()
                 WHERE id=? AND submission_kind='member' AND review_status IN ('pending','approved')"
            );
            $stmt->execute([$status, $reviewStatus, $note, $memberId, (int) $jobId]);
            if (!$stmt->rowCount()) {
                throw new InvalidArgumentException('That member-submitted opportunity is no longer available for review.');
            }
            audit($memberId, 'member_job_reviewed', 'job_id=' . (int) $jobId . '; decision=' . $decision);
            flash('success', 'Opportunity review saved.');
        } elseif ($action === 'apply_job' && $canApply) {
            $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT);
            $coverNote = trim((string) ($_POST['cover_note'] ?? ''));
            if (!$jobId || strlen($coverNote) > 3000) {
                throw new InvalidArgumentException('Check the opportunity and application note.');
            }
            $stmt = db()->prepare(
                "SELECT id FROM association_jobs WHERE id=? AND status='published'
                   AND review_status='approved'
                   AND (closes_at IS NULL OR closes_at>UTC_TIMESTAMP())"
            );
            $stmt->execute([(int) $jobId]);
            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException('That opportunity is no longer accepting applications.');
            }
            $stmt = db()->prepare('INSERT INTO job_applications (job_id,user_id,cover_note) VALUES (?,?,?)');
            $stmt->execute([(int) $jobId, $memberId, $coverNote !== '' ? $coverNote : null]);
            audit($memberId, 'job_application_submitted', 'job_id=' . (int) $jobId);
            flash('success', 'Your application was submitted.');
        } elseif ($action === 'update_application' && $canReview) {
            $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT);
            $applicantId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            if (!$jobId || !$applicantId || !in_array($status, ['reviewing','shortlisted','declined'], true)) {
                throw new InvalidArgumentException('Choose a valid application and review status.');
            }
            $stmt = db()->prepare(
                "UPDATE job_applications SET status=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP()
                 WHERE job_id=? AND user_id=? AND status<>'withdrawn'"
            );
            $stmt->execute([$status, $memberId, (int) $jobId, (int) $applicantId]);
            if (!$stmt->rowCount()) {
                $stmt = db()->prepare(
                    "SELECT 1 FROM job_applications
                     WHERE job_id=? AND user_id=? AND status<>'withdrawn'"
                );
                $stmt->execute([(int) $jobId, (int) $applicantId]);
                if (!$stmt->fetchColumn()) {
                    throw new InvalidArgumentException('That application was not found or has been withdrawn.');
                }
            }
            audit($memberId, 'job_application_reviewed', 'job_id=' . (int) $jobId . '; user_id=' . (int) $applicantId);
            flash('success', 'Application review saved.');
        } else {
            throw new InvalidArgumentException('Your account cannot perform that job opportunity action.');
        }
        redirect('jobs.php');
    } catch (InvalidArgumentException $exception) {
        if ($newAttachment !== '') {
            $path = APP_ROOT . '/storage/' . $newAttachment;
            if (is_file($path) && !unlink($path)) {
                error_log('OSAAK could not clean up a rejected job advertisement upload.');
            }
        }
        redirect_jobs($exception->getMessage());
    } catch (PDOException $exception) {
        if ($newAttachment !== '') {
            $path = APP_ROOT . '/storage/' . $newAttachment;
            if (is_file($path) && !unlink($path)) {
                error_log('OSAAK could not clean up a failed job advertisement upload.');
            }
        }
        if ($exception->getCode() === '23000') {
            redirect_jobs('That application already exists or the selected record is no longer available.');
        }
        throw $exception;
    }
}

$now = new DateTimeImmutable('now', new DateTimeZone((string) ($config['timezone'] ?? 'UTC')));
$activeJobs = [];
if ($canView) {
    $stmt = db()->query(
        "SELECT j.*,u.full_name AS submitter_name
         FROM association_jobs j JOIN users u ON u.id=j.created_by
         WHERE j.status='published' AND j.review_status='approved'
           AND (j.closes_at IS NULL OR j.closes_at>UTC_TIMESTAMP())
         ORDER BY j.created_at DESC,j.id DESC LIMIT 200"
    );
    $activeJobs = $stmt->fetchAll();
    if ($activeJobs && $activeMember) {
        $markRead = db()->prepare('INSERT IGNORE INTO job_opportunity_reads (user_id,job_id) VALUES (?,?)');
        foreach ($activeJobs as $job) {
            $markRead->execute([$memberId, (int) $job['id']]);
        }
    }
}

$ownJobs = [];
if ($canSubmit) {
    $stmt = db()->prepare(
        "SELECT j.* FROM association_jobs j
         WHERE j.created_by=? AND j.submission_kind='member'
         ORDER BY j.created_at DESC,j.id DESC LIMIT 100"
    );
    $stmt->execute([$memberId]);
    $ownJobs = $stmt->fetchAll();
}
$reviewJobs = [];
$applications = [];
$officialJobs = [];
if ($canReview) {
    $reviewJobs = db()->query(
        "SELECT j.*,u.full_name AS submitter_name
         FROM association_jobs j JOIN users u ON u.id=j.created_by
         WHERE j.submission_kind='member' AND j.review_status IN ('pending','approved')
         ORDER BY (j.review_status='pending') DESC,j.created_at DESC,j.id DESC LIMIT 200"
    )->fetchAll();
    $applications = db()->query(
        "SELECT a.*,u.full_name,j.title AS job_title
         FROM job_applications a JOIN users u ON u.id=a.user_id
         JOIN association_jobs j ON j.id=a.job_id
         ORDER BY a.applied_at DESC LIMIT 300"
    )->fetchAll();
}
$canManageOfficial = user_has_permission($user, 'jobs.manage');
if ($canManageOfficial) {
    $officialJobs = db()->query(
        "SELECT * FROM association_jobs WHERE submission_kind='association'
         ORDER BY created_at DESC,id DESC LIMIT 200"
    )->fetchAll();
}
$myApplications = [];
if ($canApply) {
    $stmt = db()->prepare(
        'SELECT a.*,j.title AS job_title FROM job_applications a
         JOIN association_jobs j ON j.id=a.job_id WHERE a.user_id=? ORDER BY a.applied_at DESC LIMIT 100'
    );
    $stmt->execute([$memberId]);
    $myApplications = $stmt->fetchAll();
}

render_header('Jobs and opportunities');
page_title('ASSOCIATION PROGRAMMES', 'Jobs and opportunities', 'Member-shared vacancies are reviewed before appearing in active listings.');
?>
<?php if ($canSubmit): ?>
<section class="panel module-form-panel">
    <div class="panel-heading"><div><h2>Share a job opportunity</h2><p class="muted">Your submission will remain private to you and reviewers until approved.</p></div><button class="button button-primary" type="button" data-toggle-job-form>Post a Job Opportunity</button></div>
    <form method="post" enctype="multipart/form-data" class="module-form" data-job-form hidden>
        <?= csrf_field() ?><input type="hidden" name="action" value="submit_job">
        <label>Job title<input name="title" maxlength="160" required></label>
        <label>Company or organisation<input name="organisation" maxlength="160" required></label>
        <label>Job location<input name="location" maxlength="160"></label>
        <label>Employment type<select name="employment_type" required><option value="full_time">Full-time</option><option value="part_time">Part-time</option><option value="contract">Contract</option><option value="internship">Internship</option><option value="temporary">Temporary</option><option value="other">Other</option></select></label>
        <label>Industry or category<input name="industry" maxlength="120"></label>
        <label>Application deadline<input name="closes_at" type="datetime-local"></label>
        <label class="span-all">Job description and responsibilities<textarea name="description" rows="5" minlength="10" maxlength="30000" required></textarea></label>
        <label class="span-all">Qualifications and experience required<textarea name="qualifications" rows="3" maxlength="30000"></textarea></label>
        <label class="span-all">Application instructions<textarea name="application_instructions" rows="3" maxlength="20000"></textarea></label>
        <label>Application link<input name="application_url" type="url" maxlength="1000"></label>
        <label>Application email<input name="application_email" type="email" maxlength="254"></label>
        <label class="span-all">Optional job advertisement (PDF, JPEG, PNG, or WebP; max 5 MB)<input name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"></label>
        <small class="muted span-all">Provide an application link, email address, or other application instructions/contact.</small>
        <button class="button button-primary" type="submit">Submit for review</button>
    </form>
</section>
<?php endif; ?>
<?php if ($canManageOfficial): ?>
<section class="panel module-form-panel top-gap">
    <h2>Post an official association opportunity</h2>
    <p class="muted">Use this form only for opportunities formally issued by the association. Member-shared vacancies must use the review form above.</p>
    <form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="action" value="create_official_job">
        <label>Job title<input name="title" maxlength="160" required></label><label>Company or organisation<input name="organisation" maxlength="160" required></label><label>Location<input name="location" maxlength="160"></label>
        <label>Employment type<select name="employment_type"><option value="full_time">Full-time</option><option value="part_time">Part-time</option><option value="contract">Contract</option><option value="internship">Internship</option><option value="temporary">Temporary</option><option value="other">Other</option></select></label>
        <label>Industry or category<input name="industry" maxlength="120"></label><label>Application deadline<input name="closes_at" type="datetime-local"></label>
        <label>Application link<input name="application_url" type="url" maxlength="1000"></label><label>Application email<input name="application_email" type="email" maxlength="254"></label><label>Publication<select name="status"><option value="draft">Draft</option><option value="published">Publish</option></select></label>
        <label class="span-all">Description<textarea name="description" rows="4" minlength="10" maxlength="30000" required></textarea></label><label class="span-all">Qualifications and experience<textarea name="qualifications" rows="3" maxlength="30000"></textarea></label>
        <label class="span-all">Application instructions/contact<textarea name="application_instructions" rows="3" maxlength="20000"></textarea></label><button class="button button-primary" type="submit">Save official opportunity</button>
    </form>
</section>
<?php endif; ?>

<section class="job-list-section">
    <h2>Active opportunities</h2>
    <?php if (!$activeJobs): ?><article class="panel"><p class="muted">There are no active approved opportunities right now.</p></article><?php else: ?>
    <div class="association-card-grid">
        <?php foreach ($activeJobs as $job): ?>
        <article class="panel association-card job-card">
            <div class="association-card-heading"><h3><?= e($job['title']) ?></h3><span class="status-pill">Active</span></div>
            <p><strong><?= e($job['organisation']) ?></strong><?= $job['location'] ? ' · ' . e($job['location']) : '' ?> · <?= e(ucwords(str_replace('_', ' ', (string) $job['employment_type']))) ?></p>
            <?php if ($job['industry']): ?><p class="muted"><?= e($job['industry']) ?></p><?php endif; ?>
            <p class="module-body"><?= nl2br(e($job['description'])) ?></p>
            <?php if ($job['qualifications']): ?><p><strong>Qualifications and experience</strong><br><?= nl2br(e($job['qualifications'])) ?></p><?php endif; ?>
            <?php if ($job['application_instructions']): ?><p><strong>How to apply</strong><br><?= nl2br(e($job['application_instructions'])) ?></p><?php endif; ?>
            <?php if ($job['application_url']): ?><p><a href="<?= e($job['application_url']) ?>" target="_blank" rel="noopener noreferrer">Application link</a></p><?php endif; ?>
            <?php if ($job['application_email']): ?><p><a href="mailto:<?= e($job['application_email']) ?>"><?= e($job['application_email']) ?></a></p><?php endif; ?>
            <?php if ($job['closes_at']): ?><p class="muted">Deadline: <?= e((new DateTimeImmutable($job['closes_at'], new DateTimeZone('UTC')))->setTimezone($now->getTimezone())->format('j F Y, H:i')) ?> <?= e($now->getTimezone()->getName()) ?></p><?php endif; ?>
            <?php if ($job['attachment_path']): ?><p><a href="job_attachment.php?id=<?= (int) $job['id'] ?>">View job advertisement</a></p><?php endif; ?>
            <p class="muted">Shared by <?= e($job['submitter_name']) ?></p>
            <?php if ($canApply): ?><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="apply_job"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><label>Application note<textarea name="cover_note" rows="2" maxlength="3000"></textarea></label><button class="button button-small" type="submit">Record application</button></form><?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<?php if ($ownJobs): ?>
<section class="panel top-gap">
    <h2>My submissions</h2>
    <div class="table-scroll"><table><thead><tr><th>Opportunity</th><th>Submitted</th><th>Status</th><th>Review note</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($ownJobs as $job): ?>
    <?php $expired = $job['closes_at'] !== null && strtotime($job['closes_at'] . ' UTC') <= time(); ?>
    <tr><td><?= e($job['title']) ?> · <?= e($job['organisation']) ?><?= $expired ? '<br><span class="muted">Expired vacancy</span>' : '' ?></td><td><?= e((string) $job['created_at']) ?> UTC</td><td><?= e(ucwords(str_replace('_', ' ', (string) $job['review_status']))) ?></td><td><?= e((string) ($job['review_note'] ?? '—')) ?></td><td>
        <?php if ($job['review_status'] === 'pending' || $job['review_status'] === 'approved'): ?>
        <details><summary>Edit</summary><form method="post" enctype="multipart/form-data" class="form-stack top-gap"><?= csrf_field() ?><input type="hidden" name="action" value="edit_job"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
            <label>Job title<input name="title" maxlength="160" value="<?= e($job['title']) ?>" required></label><label>Company or organisation<input name="organisation" maxlength="160" value="<?= e($job['organisation']) ?>" required></label><label>Location<input name="location" maxlength="160" value="<?= e((string) $job['location']) ?>"></label>
            <label>Employment type<select name="employment_type"><?php foreach (['full_time'=>'Full-time','part_time'=>'Part-time','contract'=>'Contract','internship'=>'Internship','temporary'=>'Temporary','other'=>'Other'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $job['employment_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <label>Industry or category<input name="industry" maxlength="120" value="<?= e((string) $job['industry']) ?>"></label><label>Application deadline<input name="closes_at" type="datetime-local" value="<?= $job['closes_at'] ? e((new DateTimeImmutable($job['closes_at'], new DateTimeZone('UTC')))->setTimezone($now->getTimezone())->format('Y-m-d\TH:i')) : '' ?>"></label>
            <label>Job description<textarea name="description" rows="4" minlength="10" maxlength="30000" required><?= e($job['description']) ?></textarea></label><label>Qualifications and experience<textarea name="qualifications" rows="3" maxlength="30000"><?= e((string) $job['qualifications']) ?></textarea></label>
            <label>Application instructions/contact<textarea name="application_instructions" rows="3" maxlength="20000"><?= e((string) $job['application_instructions']) ?></textarea></label><label>Application link<input name="application_url" type="url" maxlength="1000" value="<?= e((string) $job['application_url']) ?>"></label><label>Application email<input name="application_email" type="email" maxlength="254" value="<?= e((string) $job['application_email']) ?>"></label>
            <label>Replace advertisement<input name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"></label><button class="button button-small" type="submit">Save changes for review</button>
        </form></details>
        <?php endif; ?>
        <?php if ($job['review_status'] === 'pending'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="withdraw_job"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><button class="button button-quiet button-small" type="submit">Withdraw</button></form><?php endif; ?>
        </td></tr>
    <?php endforeach; ?></tbody></table></div>
</section>
<?php endif; ?>

<?php if ($canReview): ?>
<section class="panel top-gap">
    <h2>Member submissions · review queue</h2>
    <?php if (!$reviewJobs): ?><p class="muted">There are no member submissions awaiting review.</p><?php else: ?>
    <div class="association-card-grid"><?php foreach ($reviewJobs as $job): ?>
        <?php $expired = $job['closes_at'] !== null && strtotime($job['closes_at'] . ' UTC') <= time(); ?>
        <article class="panel association-card job-card"><div class="association-card-heading"><h3><?= e($job['title']) ?></h3><span class="status-pill"><?= e(ucwords(str_replace('_', ' ', (string) $job['review_status']))) ?></span></div>
            <p><strong><?= e($job['organisation']) ?></strong><?= $job['location'] ? ' · ' . e($job['location']) : '' ?><?= $job['industry'] ? ' · ' . e($job['industry']) : '' ?></p>
            <p class="muted">Submitted by <?= e($job['submitter_name']) ?> · <?= e((string) $job['created_at']) ?> UTC<?= $expired ? ' · Expired vacancy' : '' ?></p>
            <p class="module-body"><?= nl2br(e($job['description'])) ?></p>
            <?php if ($job['qualifications']): ?><p><strong>Qualifications</strong><br><?= nl2br(e($job['qualifications'])) ?></p><?php endif; ?>
            <?php if ($job['application_instructions']): ?><p><strong>Application instructions</strong><br><?= nl2br(e($job['application_instructions'])) ?></p><?php endif; ?>
            <?php if ($job['application_url']): ?><p><?= e($job['application_url']) ?></p><?php endif; ?><?php if ($job['application_email']): ?><p><?= e($job['application_email']) ?></p><?php endif; ?>
            <?php if ($job['attachment_path']): ?><p><a href="job_attachment.php?id=<?= (int) $job['id'] ?>">View attached advertisement</a></p><?php endif; ?>
            <?php if ($job['review_status'] === 'pending'): ?><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="review_job"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><label>Rejection reason (required to reject)<textarea name="review_note" rows="2" maxlength="1000"></textarea></label><div class="inline-form"><button class="button button-primary button-small" name="decision" value="approve" type="submit">Approve</button><button class="button button-quiet button-small" name="decision" value="reject" type="submit">Reject</button><button class="button button-quiet button-small" name="decision" value="remove" type="submit">Remove</button></div></form>
            <?php else: ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="review_job"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><input type="hidden" name="decision" value="remove"><button class="button button-quiet button-small" type="submit">Remove</button></form><?php endif; ?>
        </article>
    <?php endforeach; ?></div><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($officialJobs): ?>
<section class="panel top-gap"><h2>Official association opportunities</h2><div class="table-scroll"><table><thead><tr><th>Opportunity</th><th>Status</th><th>Publication controls</th><th>Edit details</th></tr></thead><tbody>
<?php foreach ($officialJobs as $job): ?><tr><td><?= e($job['title']) ?> · <?= e($job['organisation']) ?></td><td><?= e(ucfirst((string) $job['status'])) ?><?= $job['closes_at'] && strtotime($job['closes_at'] . ' UTC') <= time() ? '<br><span class="muted">Expired</span>' : '' ?></td><td><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="official_job_status"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><select name="status"><?php foreach (['draft','published','closed','archived'] as $status): ?><option value="<?= e($status) ?>" <?= $job['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select><button class="button button-small" type="submit">Save</button></form></td>
<td><details><summary>Edit</summary><form method="post" class="form-stack top-gap"><?= csrf_field() ?><input type="hidden" name="action" value="edit_official_job"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
<label>Job title<input name="title" maxlength="160" value="<?= e($job['title']) ?>" required></label><label>Organisation<input name="organisation" maxlength="160" value="<?= e($job['organisation']) ?>" required></label><label>Location<input name="location" maxlength="160" value="<?= e((string) $job['location']) ?>"></label>
<label>Employment type<select name="employment_type"><?php foreach (['full_time'=>'Full-time','part_time'=>'Part-time','contract'=>'Contract','internship'=>'Internship','temporary'=>'Temporary','other'=>'Other'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $job['employment_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label>Industry<input name="industry" maxlength="120" value="<?= e((string) $job['industry']) ?>"></label><label>Deadline<input name="closes_at" type="datetime-local" value="<?= $job['closes_at'] ? e((new DateTimeImmutable($job['closes_at'], new DateTimeZone('UTC')))->setTimezone($now->getTimezone())->format('Y-m-d\TH:i')) : '' ?>"></label>
<label>Description<textarea name="description" rows="4" minlength="10" maxlength="30000" required><?= e($job['description']) ?></textarea></label><label>Qualifications<textarea name="qualifications" rows="3" maxlength="30000"><?= e((string) $job['qualifications']) ?></textarea></label>
<label>Application instructions<textarea name="application_instructions" rows="3" maxlength="20000"><?= e((string) $job['application_instructions']) ?></textarea></label><label>Application link<input name="application_url" type="url" maxlength="1000" value="<?= e((string) $job['application_url']) ?>"></label><label>Application email<input name="application_email" type="email" maxlength="254" value="<?= e((string) $job['application_email']) ?>"></label><button class="button button-small" type="submit">Save details</button></form></details></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

<?php if ($myApplications): ?><section class="panel top-gap"><h2>My job applications</h2><ul class="activity-list"><?php foreach ($myApplications as $application): ?><li><strong><?= e($application['job_title']) ?></strong><span><?= e(ucfirst((string) $application['status'])) ?> · <?= e((string) $application['applied_at']) ?> UTC</span></li><?php endforeach; ?></ul></section><?php endif; ?>
<?php if ($canReview && $applications): ?><section class="panel top-gap"><h2>Job applications</h2><div class="table-scroll"><table><thead><tr><th>Opportunity</th><th>Applicant</th><th>Applied</th><th>Review status</th><th>Update</th></tr></thead><tbody><?php foreach ($applications as $application): ?><tr><td><?= e($application['job_title']) ?></td><td><?= e($application['full_name']) ?><br><?= e((string) ($application['cover_note'] ?? '')) ?></td><td><?= e((string) $application['applied_at']) ?> UTC</td><td><?= e(ucfirst((string) $application['status'])) ?></td><td><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="update_application"><input type="hidden" name="job_id" value="<?= (int) $application['job_id'] ?>"><input type="hidden" name="user_id" value="<?= (int) $application['user_id'] ?>"><select name="status"><option value="reviewing">Reviewing</option><option value="shortlisted">Shortlisted</option><option value="declined">Declined</option></select><button class="button button-small" type="submit">Save</button></form></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<script src="assets/js/jobs.js?v=1" defer></script>
<?php render_footer(); ?>
