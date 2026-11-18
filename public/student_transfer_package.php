<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_school_context();
$school = active_school_context();
$schoolId = (int) $school['id'];
require_school_permission($schoolId, 'students.lifecycle.manage');

$transferId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($transferId === false || $transferId < 1) {
    http_response_code(404);
    exit('Released record package not found.');
}
$stmt = db()->prepare(
    "SELECT package_json FROM student_record_transfer_requests
     WHERE id = ? AND target_school_id = ? AND status = 'released' LIMIT 1"
);
$stmt->execute([$transferId, $schoolId]);
$packageJson = $stmt->fetchColumn();
if (!is_string($packageJson) || $packageJson === '') {
    http_response_code(404);
    exit('Released record package not found.');
}
try {
    $package = json_decode($packageJson, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    error_log('Student record transfer package ' . $transferId . ' contains invalid JSON.');
    http_response_code(500);
    exit('The released record package could not be read. Contact the source school.');
}
if (!is_array($package) || !isset($package['student'], $package['source_school_id'])) {
    http_response_code(500);
    exit('The released record package is incomplete. Contact the source school.');
}

if (($_GET['format'] ?? '') === 'json') {
    audit((int) $user['id'], 'student_record_transfer_package_downloaded', 'transfer_id=' . $transferId . ';target_school_id=' . $schoolId);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="student-record-transfer-' . $transferId . '.json"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

render_header('Released student record package');
$student = $package['student'];
?>
<section class="panel">
    <p class="eyebrow">AUTHORIZED SCHOOL TRANSFER</p>
    <h1>Released student record package</h1>
    <p>This package was released by the source school after consent and destination-school approval. The source school's records remain intact.</p>
    <dl class="lifecycle-details-grid">
        <dt>Student</dt><dd><?= e((string) ($student['full_name'] ?? '—')) ?></dd>
        <dt>Admission number</dt><dd><?= e((string) ($student['admission_number'] ?? '—')) ?></dd>
        <dt>Source school ID</dt><dd><?= (int) $package['source_school_id'] ?></dd>
        <dt>Attendance records</dt><dd><?= count($package['attendance_history'] ?? []) ?></dd>
        <dt>Assessment results</dt><dd><?= count($package['assessment_results'] ?? []) ?></dd>
        <dt>Invoices</dt><dd><?= count($package['invoices'] ?? []) ?></dd>
        <dt>Payment records</dt><dd><?= count($package['payment_history'] ?? []) ?></dd>
    </dl>
    <a class="button button-primary" href="student_transfer_package.php?id=<?= $transferId ?>&amp;format=json">Download authorized record package</a>
    <a class="button button-secondary" href="former_students.php">Back to former students &amp; transfers</a>
</section>
<?php render_footer(); ?>
