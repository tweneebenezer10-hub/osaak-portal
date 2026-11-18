<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/school_results.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
$role = (string) ($user['active_school_role'] ?? school_user_role_for_school((int) $user['id'], $schoolId));
if (in_array($role, ['school_student', 'school_parent'], true)) {
    http_response_code(403);
    exit('Sample report previews are available to authorized school staff only.');
}
require_school_permission($schoolId, 'report_cards.view');

$sampleSubjects = [
    ['name' => 'English Language', 'class' => 20, 'exam' => 50],
    ['name' => 'Mathematics', 'class' => 25, 'exam' => 55],
    ['name' => 'Science', 'class' => 18, 'exam' => 52],
    ['name' => 'Social Studies', 'class' => 28, 'exam' => 60],
    ['name' => 'Computing', 'class' => 22, 'exam' => 48],
];
foreach ($sampleSubjects as &$subject) {
    $subject['total'] = (float) $subject['class'] + (float) $subject['exam'];
}
unset($subject);
$schoolContact = trim((string) ($school['contact_phone'] ?? '')
    . (!empty($school['contact_email'])
        ? (!empty($school['contact_phone']) ? ' · ' : '') . $school['contact_email']
        : ''));
$schoolLogoPath = (string) ($school['logo_path'] ?? '');
$schoolLogoUrl = $schoolId > 0
    && preg_match('#^school-logos/[a-f0-9]{48}\.(?:jpg|png|webp)$#', $schoolLogoPath) === 1
    ? 'school_logo.php?school_id=' . $schoolId
    : null;
$formatSampleScore = static fn(float $score): string => rtrim(rtrim(number_format($score, 2, '.', ''), '0'), '.');

render_header('Sample report card');
?>
<section class="panel report-card-page">
    <p class="eyebrow">SAMPLE PREVIEW</p>
    <h1>End-of-term report preview</h1>
    <p class="muted">Fictional student and marks for layout preview only. This is not saved or an official student report.</p>
    <div class="attendance-tools"><a class="button" href="report_cards.php">Back to report cards</a><button class="button button-primary" type="button" data-print-button>Print / Save as PDF</button></div>
    <section class="report-card-output terminal-report-card">
        <header class="terminal-report-header">
            <div class="terminal-school-identity-row">
                <div class="terminal-school-logo-slot"><?php if ($schoolLogoUrl !== null): ?><img class="terminal-school-logo" src="<?= e($schoolLogoUrl) ?>" alt=""><?php endif; ?></div>
                <div class="terminal-school-name-address">
                    <strong><?= e(strtoupper((string) ($school['name'] ?? 'School'))) ?></strong>
                    <?php if (!empty($school['address'])): ?><span><?= e((string) $school['address']) ?></span><?php endif; ?>
                    <?php if ($schoolContact !== ''): ?><span><?= e($schoolContact) ?></span><?php endif; ?>
                </div>
                <div class="terminal-school-logo-slot"><?php if ($schoolLogoUrl !== null): ?><img class="terminal-school-logo" src="<?= e($schoolLogoUrl) ?>" alt=""><?php endif; ?></div>
            </div>
            <h1 class="terminal-report-title">END OF TERM REPORT</h1>
            <div class="terminal-report-year">2025/2026 ACADEMIC YEAR</div>
        </header>
        <p class="sample-report-watermark">SAMPLE ONLY · NOT OFFICIAL</p>
        <section class="report-card-section">
            <div class="terminal-metadata-grid">
                <div><span>Student Name</span><strong>Sample Student</strong></div>
                <div><span>Admission Number</span><strong>DEMO-0001</strong></div>
                <div><span>Class</span><strong>Primary 4</strong></div>
                <div><span>Term</span><strong>First Term</strong></div>
                <div><span>Vacation Date</span><strong>Sample date</strong></div>
                <div><span>Next Term Begins</span><strong>Sample date</strong></div>
            </div>
        </section>
        <section class="report-card-section">
            <table class="terminal-mark-table">
                <colgroup>
                    <col class="terminal-mark-subject">
                    <col class="terminal-mark-class-score">
                    <col class="terminal-mark-exam-score">
                    <col class="terminal-mark-total">
                    <col class="terminal-mark-grade">
                    <col class="terminal-mark-position">
                    <col class="terminal-mark-remarks">
                </colgroup>
                <thead><tr><th>SUBJECT</th><th>CLASS (30%)</th><th>EXAMS (70%)</th><th>TOTAL (100%)</th><th>GRADE</th><th>POSITION</th><th>REMARKS</th></tr></thead>
                <tbody><?php foreach ($sampleSubjects as $subject): ?><tr>
                    <td><?= e((string) $subject['name']) ?></td>
                    <td><?= e($formatSampleScore((float) $subject['class'])) ?></td>
                    <td><?= e($formatSampleScore((float) $subject['exam'])) ?></td>
                    <td><?= e($formatSampleScore((float) $subject['total'])) ?></td>
                    <td>A</td>
                    <td>1st</td>
                    <td>Excellent</td>
                </tr><?php endforeach; ?></tbody>
            </table>
        </section>
        <section class="terminal-summary-strip">
            <span><strong>Overall Class Position:</strong> 3rd (sample)</span>
            <span><strong>Attendance:</strong> 58 / 60 days (sample)</span>
        </section>
        <section class="terminal-behaviour-grid report-card-section">
            <div><strong>Conduct</strong><span>Good (sample)</span></div>
            <div><strong>Interest</strong><span>High (sample)</span></div>
            <div><strong>Attitude</strong><span>Positive (sample)</span></div>
        </section>
        <section class="terminal-comments-grid report-card-section">
            <div><h2>CLASS TEACHER’S REMARKS</h2><p>Consistent effort and good progress this term. (Sample)</p></div>
            <div><h2>HEAD TEACHER’S REMARKS</h2><p>Keep up the good work. (Sample)</p></div>
        </section>
        <section class="terminal-signatures report-card-section">
            <div><span>CLASS TEACHER’S SIGNATURE</span><strong>&nbsp;</strong><div class="signature-box"></div><span>Date: ____________________</span></div>
            <div><span>HEAD TEACHER’S SIGNATURE</span><strong>&nbsp;</strong><div class="signature-box"></div><span>Date: ____________________</span></div>
            <div><span>SCHOOL STAMP</span><strong>&nbsp;</strong><div class="signature-box"></div></div>
        </section>
    </section>
</section>
<?php render_footer(); ?>