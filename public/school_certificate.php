<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'students.lifecycle.manage');

$certificateId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($certificateId === false || $certificateId < 1) {
    http_response_code(404);
    exit('Certificate not found.');
}
$certificateStmt = db()->prepare(
    'SELECT certificate.id, certificate.school_id, certificate.student_id,
            certificate.graduation_id, certificate.status, certificate.student_name,
            certificate.student_identifier, certificate.academic_year, certificate.completion_date,
            certificate.date_issued, certificate.school_name, certificate.school_address,
            certificate.school_contact, certificate.school_logo_path, certificate.headmaster_name,
            certificate.proprietor_name, graduation.completion_program
     FROM school_completion_certificates certificate
     JOIN student_graduations graduation
       ON graduation.id = certificate.graduation_id AND graduation.school_id = certificate.school_id
     WHERE certificate.id = ? AND certificate.school_id = ? AND certificate.status = "issued" LIMIT 1'
);
$certificateStmt->execute([$certificateId, $schoolId]);
$certificate = $certificateStmt->fetch();
if (!$certificate) {
    http_response_code(404);
    exit('This certificate is unavailable, replaced, or revoked.');
}

if (active_it_support_context($user) === null) {
    $eventStmt = db()->prepare(
        'INSERT INTO student_graduation_events
         (school_id, student_id, graduation_id, certificate_id, actor_user_id, event_type, reason)
         VALUES (?, ?, ?, ?, ?, "certificate_reprinted", ?)'
    );
    $eventStmt->execute([
        $schoolId, (int) $certificate['student_id'], (int) $certificate['graduation_id'],
        $certificateId, (int) $user['id'], 'Authorized certificate preview/print opened.',
    ]);
    audit((int) $user['id'], 'student_certificate_reprinted',
        'school_id=' . $schoolId . ';student_id=' . (int) $certificate['student_id']
            . ';certificate_id=' . $certificateId);
}

render_header('Certificate of Completion');
?>
<link rel="stylesheet" href="assets/css/certificate.css?v=2">
<section class="certificate-toolbar no-print">
    <a class="button button-secondary" href="graduation_certificates.php">Back to certificates</a>
    <p>Use your browser’s print dialog to print in landscape or choose <strong>Save as PDF</strong>.</p>
    <button class="button button-primary" type="button" data-print-button>Print / Save as PDF</button>
</section>
<article class="completion-certificate" aria-label="Certificate of Completion">
    <div class="certificate-inner-border">
        <div class="certificate-school">
            <?php if (is_string($certificate['school_logo_path']) && $certificate['school_logo_path'] !== ''): ?>
                <img class="certificate-logo" src="school_certificate_logo.php?id=<?= (int) $certificate['id'] ?>" alt="<?= e((string) $certificate['school_name']) ?> logo">
            <?php endif; ?>
            <div class="certificate-school-copy">
                <h1><?= e(strtoupper((string) $certificate['school_name'])) ?></h1>
                <?php if ($certificate['school_address']): ?><p><?= e((string) $certificate['school_address']) ?></p><?php endif; ?>
                <?php if ($certificate['school_contact']): ?><p><?= e((string) $certificate['school_contact']) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="certificate-rule" aria-hidden="true"></div>
        <p class="certificate-eyebrow">SCHOOL PROGRAMME</p>
        <h2>CERTIFICATE OF COMPLETION</h2>
        <p class="certificate-intro">This certificate is proudly presented to</p>
        <p class="certificate-student-name"><?= e((string) $certificate['student_name']) ?></p>
        <p class="certificate-student-id">Student ID: <strong><?= e((string) $certificate['student_identifier']) ?></strong></p>
        <p class="certificate-statement">
            in recognition of successfully completing the school’s <?= e((string) $certificate['completion_program']) ?> programme
            during the <strong><?= e(school_academic_year_label((string) $certificate['academic_year'])) ?></strong>
            academic year.
        </p>
        <p class="certificate-completion">Completion date: <strong><?= e(date('F j, Y', strtotime((string) $certificate['completion_date']))) ?></strong></p>
        <div class="certificate-signatures">
            <div class="certificate-signature">
                <span class="signature-space"></span>
                <strong><?= e((string) $certificate['headmaster_name']) ?></strong>
                <span>Headmaster</span>
            </div>
            <?php if ($certificate['proprietor_name']): ?>
                <div class="certificate-signature">
                    <span class="signature-space"></span>
                    <strong><?= e((string) $certificate['proprietor_name']) ?></strong>
                    <span>Proprietor</span>
                </div>
            <?php endif; ?>
            <div class="certificate-issued-date">
                <span>Date Issued</span>
                <strong><?= e(date('F j, Y', strtotime((string) $certificate['date_issued']))) ?></strong>
            </div>
        </div>
        <div class="certificate-footer">
            <span>School-issued Certificate of Completion</span>
            <span>Presented in recognition of learning and achievement</span>
        </div>
        <div class="certificate-stamp" aria-label="Space for school stamp">SCHOOL STAMP</div>
    </div>
</article>
<?php render_footer(); ?>
