<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$operator = require_permission('system.settings.manage');

$mail = $config['mail'] ?? [];
$mailConfigured = !empty($mail['host']) && !empty($mail['username'])
    && !empty($mail['password']) && !empty($mail['from_email']);
$appScheme = strtolower((string) parse_url((string) ($config['app_url'] ?? ''), PHP_URL_SCHEME));
$requiredTables = [
    'users', 'roles', 'user_roles', 'permissions', 'role_permissions', 'security_logs',
    'member_profiles',
    'association_events', 'event_registrations', 'association_announcements',
    'membership_dues', 'payment_records', 'association_meetings', 'meeting_attendance',
    'association_documents', 'association_settings', 'association_chapters',
    'association_committees', 'committee_members', 'association_elections',
    'election_candidates', 'election_voters', 'election_votes', 'welfare_cases',
    'mentorship_profiles', 'association_jobs', 'job_applications',
    'support_tickets', 'support_ticket_replies', 'support_ticket_attachments', 'support_ticket_history', 'payment_adjustments',
    'member_role_requests', 'association_news', 'membership_due_adjustments',
    'association_notifications', 'user_notification_preferences',
    'membership_id_sequences',
    'dues_reminder_runs', 'member_role_history',
];
$placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
$stmt = db()->prepare(
    'SELECT table_name FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name IN (' . $placeholders . ')'
);
$stmt->execute($requiredTables);
$availableTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
$missingTables = array_values(array_diff($requiredTables, $availableTables));
$requiredProfileColumns = [
    'requested_role', 'profile_picture_path', 'date_of_birth',
    'graduation_year', 'membership_number', 'membership_id_review_required',
];
$columnPlaceholders = implode(',', array_fill(0, count($requiredProfileColumns), '?'));
$stmt = db()->prepare(
    'SELECT column_name FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name=\'member_profiles\'
       AND column_name IN (' . $columnPlaceholders . ')'
);
$stmt->execute($requiredProfileColumns);
$availableProfileColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
$missingProfileColumns = array_values(array_diff($requiredProfileColumns, $availableProfileColumns));
$storageRoot = APP_ROOT . '/storage';
$storageWritable = is_dir($storageRoot) ? is_writable($storageRoot) : is_writable(dirname($storageRoot));
$displayErrors = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN);
$databaseUser = strtolower((string) ($config['db']['user'] ?? ''));
$checks = [
    ['PHP runtime', version_compare(PHP_VERSION, '8.1.0', '>='), 'Requires PHP 8.1 or newer.'],
    ['MySQL PDO driver', extension_loaded('pdo_mysql'), 'Required by the application database layer.'],
    ['OpenSSL', extension_loaded('openssl'), 'Required for SMTP TLS and cryptographic operations.'],
    ['File information', extension_loaded('fileinfo'), 'Required to inspect uploaded document types.'],
    ['Composer dependencies', is_file(APP_ROOT . '/vendor/autoload.php'), 'Required for PHPMailer.'],
    ['Private storage protection', is_file(APP_ROOT . '/storage/.htaccess') && $storageWritable, 'Storage must be writable by PHP and blocked from direct web requests.'],
    ['Database migrations', $missingTables === [], $missingTables ? 'Missing tables: ' . implode(', ', $missingTables) : 'Required association tables are present.'],
    ['Member profile migration', $missingProfileColumns === [], $missingProfileColumns ? 'Missing member profile columns: ' . implode(', ', $missingProfileColumns) : 'Requested roles, private profile pictures, dates of birth, and graduation-year membership IDs are available.'],
    ['Mail configuration', $mailConfigured, 'SMTP settings are checked without displaying credentials; sending still requires a real delivery test.'],
    ['HTTPS URL', $appScheme === 'https', 'Use HTTPS for non-local deployments; the current XAMPP HTTP URL is suitable only for local testing.'],
    ['Database account', $databaseUser !== 'root' && $databaseUser !== '', 'Use a dedicated least-privilege MySQL account outside local development.'],
    ['PHP error display', !$displayErrors, 'Disable display_errors in production and log errors to a protected server log.'],
];
render_header('System health');
page_title('OPERATIONS', 'System health and deployment checks', 'Read-only configuration checks. No passwords, app keys, or SMTP secrets are displayed.');
?>
<section class="panel health-summary"><p><strong>Runtime:</strong> PHP <?= e(PHP_VERSION) ?> · XAMPP maintenance checks</p><p class="muted">A passing configuration check does not confirm email inbox delivery, backup recoverability, or production readiness. Test these separately before deployment.</p></section>
<section class="panel table-panel module-table"><div class="table-scroll"><table><thead><tr><th>Check</th><th>Result</th><th>Details</th></tr></thead><tbody>
<?php foreach ($checks as [$label, $passed, $details]): ?>
    <tr><td><strong><?= e($label) ?></strong></td><td><span class="status-pill <?= $passed ? 'status-good' : '' ?>"><?= $passed ? 'Pass' : 'Action required' ?></span></td><td><?= e($details) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
<section class="panel top-gap"><h2>Operational safeguards</h2><ul><li>Back up the database before applying migrations and before any authorized data cleanup.</li><li>Keep local configuration and the private storage directory outside the public web root where possible.</li><li>Use a least-privileged database login and rotate any credentials that may have been shared.</li><li>For production, enable HTTPS, secure cookie settings, protected error logs, and a tested restore procedure.</li><li>Payment records are manually entered association ledger records; no payment processor is connected.</li></ul></section>
<?php render_footer(); ?>
