<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$user = require_login();
$requestedModule = (string) ($_GET['module'] ?? $_POST['module'] ?? '');
if ($requestedModule === 'mentorship') {
    require __DIR__ . '/mentorship.php';
    exit;
}
if ($requestedModule === 'jobs') {
    require __DIR__ . '/jobs.php';
    exit;
}

$communityModules = [
    'chapters' => ['title' => 'Chapters', 'view' => 'chapters.view', 'manage' => 'chapters.manage'],
    'committees' => ['title' => 'Committees', 'view' => 'committees.view', 'manage' => 'committees.manage'],
    'elections' => ['title' => 'Elections', 'view' => 'elections.view', 'manage' => 'elections.manage'],
    'welfare' => ['title' => 'Welfare support', 'view' => 'welfare.submit', 'manage' => 'welfare.manage'],
    'mentorship' => ['title' => 'Mentorship', 'view' => 'mentorship.view', 'manage' => 'mentorship.manage'],
    'jobs' => ['title' => 'Jobs and opportunities', 'view' => 'jobs.view', 'manage' => 'jobs.manage'],
];
$module = (string) ($_GET['module'] ?? $_POST['module'] ?? 'chapters');
if (!isset($communityModules[$module])) {
    http_response_code(404);
    exit('Community programme not found.');
}
$definition = $communityModules[$module];
$canManage = user_has_permission($user, $definition['manage']);
$canView = user_has_permission($user, $definition['view']) || $canManage
    || ($module === 'elections' && user_has_permission($user, 'elections.vote'));
if (!$canView) {
    http_response_code(403);
    render_header('Access denied');
    echo '<section class="panel"><h1>Access denied</h1><p>Your account does not have permission to view this programme.</p><a class="button" href="dashboard.php">Return to dashboard</a></section>';
    render_footer();
    exit;
}

function community_datetime(string $value): ?string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $date->format('Y-m-d H:i:s');
}

function community_active_members(): array
{
    return db()->query(
        "SELECT u.id,u.full_name,mp.membership_number
         FROM users u JOIN member_profiles mp ON mp.user_id=u.id
         WHERE u.status='active' AND u.email_verified_at IS NOT NULL AND mp.membership_status='active'
         ORDER BY u.full_name LIMIT 500"
    )->fetchAll();
}

function community_member_is_active(int $memberId): bool
{
    $stmt = db()->prepare(
        "SELECT 1 FROM users u JOIN member_profiles mp ON mp.user_id=u.id
         WHERE u.id=? AND u.status='active' AND u.email_verified_at IS NOT NULL
           AND mp.membership_status='active'"
    );
    $stmt->execute([$memberId]);
    return (bool) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $fail = static function (string $message): never {
        flash('error', $message);
        redirect('community.php?module=' . rawurlencode((string) ($_POST['module'] ?? 'chapters')));
    };
    try {
        if ($module === 'chapters' && $action === 'create_chapter' && $canManage) {
            $name = trim((string) ($_POST['name'] ?? ''));
            $region = trim((string) ($_POST['region'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $coordinator = filter_var($_POST['coordinator_user_id'] ?? null, FILTER_VALIDATE_INT);
            if (preg_match('/^.{2,120}$/us', $name) !== 1 || strlen($region) > 120 || strlen($location) > 200
                || ($coordinator && !community_member_is_active((int) $coordinator))) {
                throw new InvalidArgumentException('Check the chapter name, region, location, and coordinator.');
            }
            $stmt = db()->prepare('INSERT INTO association_chapters (name,region,location,coordinator_user_id,created_by) VALUES (?,?,?,?,?)');
            $stmt->execute([$name, $region !== '' ? $region : null, $location !== '' ? $location : null, $coordinator ?: null, (int) $user['id']]);
            audit((int) $user['id'], 'chapter_created', 'chapter_id=' . (int) db()->lastInsertId());
            flash('success', 'Chapter created.');
        } elseif ($module === 'chapters' && $action === 'update_chapter' && $canManage) {
            $chapterId = filter_var($_POST['chapter_id'] ?? null, FILTER_VALIDATE_INT);
            $coordinator = filter_var($_POST['coordinator_user_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            if (!$chapterId || !in_array($status, ['active', 'archived'], true)
                || ($coordinator && !community_member_is_active((int) $coordinator))) {
                throw new InvalidArgumentException('Choose a valid chapter, coordinator, and status.');
            }
            $stmt = db()->prepare('UPDATE association_chapters SET coordinator_user_id=?,status=? WHERE id=?');
            $stmt->execute([$coordinator ?: null, $status, (int) $chapterId]);
            audit((int) $user['id'], 'chapter_updated', 'chapter_id=' . (int) $chapterId . '; status=' . $status);
            flash('success', 'Chapter details updated.');
        } elseif ($module === 'committees' && $action === 'create_committee' && $canManage) {
            $name = trim((string) ($_POST['name'] ?? ''));
            $mandate = trim((string) ($_POST['mandate'] ?? ''));
            $chairId = filter_var($_POST['chair_user_id'] ?? null, FILTER_VALIDATE_INT);
            if (preg_match('/^.{2,120}$/us', $name) !== 1 || strlen($mandate) > 1000
                || ($chairId && !community_member_is_active((int) $chairId))) {
                throw new InvalidArgumentException('Check the committee name, mandate, and chairperson.');
            }
            $stmt = db()->prepare('INSERT INTO association_committees (name,mandate,chair_user_id,created_by) VALUES (?,?,?,?)');
            $stmt->execute([$name, $mandate !== '' ? $mandate : null, $chairId ?: null, (int) $user['id']]);
            $committeeId = (int) db()->lastInsertId();
            if ($chairId) {
                $stmt = db()->prepare("INSERT IGNORE INTO committee_members (committee_id,user_id,committee_role,assigned_by) VALUES (?,?,'Chairperson',?)");
                $stmt->execute([$committeeId, (int) $chairId, (int) $user['id']]);
            }
            audit((int) $user['id'], 'committee_created', 'committee_id=' . $committeeId);
            flash('success', 'Committee created.');
        } elseif ($module === 'committees' && $action === 'update_committee' && $canManage) {
            $committeeId = filter_var($_POST['committee_id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string) ($_POST['name'] ?? ''));
            $mandate = trim((string) ($_POST['mandate'] ?? ''));
            $chairId = filter_var($_POST['chair_user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$committeeId || preg_match('/^.{2,120}$/us', $name) !== 1 || strlen($mandate) > 1000
                || ($chairId && !community_member_is_active((int) $chairId))) {
                throw new InvalidArgumentException('Check the committee name, mandate, and chairperson.');
            }
            db()->beginTransaction();
            try {
                $stmt = db()->prepare('SELECT chair_user_id FROM association_committees WHERE id=? FOR UPDATE');
                $stmt->execute([(int) $committeeId]);
                $oldChairId = $stmt->fetchColumn();
                if ($oldChairId === false) {
                    throw new InvalidArgumentException('That committee no longer exists.');
                }
                $stmt = db()->prepare('UPDATE association_committees SET name=?,mandate=?,chair_user_id=? WHERE id=?');
                $stmt->execute([$name,$mandate !== '' ? $mandate : null,$chairId ?: null,(int) $committeeId]);
                if ($oldChairId && (int) $oldChairId !== (int) $chairId) {
                    $stmt = db()->prepare(
                        "UPDATE committee_members SET committee_role='Member'
                         WHERE committee_id=? AND user_id=? AND committee_role='Chairperson'"
                    );
                    $stmt->execute([(int) $committeeId,(int) $oldChairId]);
                }
                if ($chairId) {
                    $stmt = db()->prepare(
                        "INSERT INTO committee_members (committee_id,user_id,committee_role,assigned_by)
                         VALUES (?,?,'Chairperson',?)
                         ON DUPLICATE KEY UPDATE
                            committee_role=IF(committee_role='Member','Chairperson',committee_role),
                            assigned_by=VALUES(assigned_by)"
                    );
                    $stmt->execute([(int) $committeeId,(int) $chairId,(int) $user['id']]);
                }
                audit((int) $user['id'], 'committee_updated', 'committee_id=' . (int) $committeeId);
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
            flash('success', 'Committee details updated.');
        } elseif ($module === 'committees' && $action === 'assign_committee_member' && $canManage) {
            $committeeId = filter_var($_POST['committee_id'] ?? null, FILTER_VALIDATE_INT);
            $memberId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $role = trim((string) ($_POST['committee_role'] ?? 'Member'));
            if (!$committeeId || !$memberId || strlen($role) < 2 || strlen($role) > 80 || !community_member_is_active((int) $memberId)) {
                throw new InvalidArgumentException('Select an active member and a valid committee role.');
            }
            $stmt = db()->prepare("SELECT id FROM association_committees WHERE id=? AND status='active'");
            $stmt->execute([(int) $committeeId]);
            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException('That committee is not active.');
            }
            $stmt = db()->prepare('INSERT INTO committee_members (committee_id,user_id,committee_role,assigned_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE committee_role=VALUES(committee_role),assigned_by=VALUES(assigned_by)');
            $stmt->execute([(int) $committeeId, (int) $memberId, $role, (int) $user['id']]);
            audit((int) $user['id'], 'committee_member_assigned', 'committee_id=' . (int) $committeeId . '; user_id=' . (int) $memberId);
            flash('success', 'Committee assignment saved.');
        } elseif ($module === 'committees' && $action === 'remove_committee_member' && $canManage) {
            $committeeId = filter_var($_POST['committee_id'] ?? null, FILTER_VALIDATE_INT);
            $memberId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$committeeId || !$memberId) {
                throw new InvalidArgumentException('Select a committee member to remove.');
            }
            db()->beginTransaction();
            $stmt = db()->prepare('DELETE FROM committee_members WHERE committee_id=? AND user_id=?');
            $stmt->execute([(int) $committeeId, (int) $memberId]);
            $stmt = db()->prepare('UPDATE association_committees SET chair_user_id=NULL WHERE id=? AND chair_user_id=?');
            $stmt->execute([(int) $committeeId, (int) $memberId]);
            db()->commit();
            audit((int) $user['id'], 'committee_member_removed', 'committee_id=' . (int) $committeeId . '; user_id=' . (int) $memberId);
            flash('success', 'Committee assignment removed.');
        } elseif ($module === 'committees' && $action === 'committee_status' && $canManage) {
            $committeeId = filter_var($_POST['committee_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            if (!$committeeId || !in_array($status, ['active', 'archived'], true)) {
                throw new InvalidArgumentException('Choose a valid committee and status.');
            }
            $stmt = db()->prepare('UPDATE association_committees SET status=? WHERE id=?');
            $stmt->execute([$status, (int) $committeeId]);
            audit((int) $user['id'], 'committee_status_changed', 'committee_id=' . (int) $committeeId . '; status=' . $status);
            flash('success', 'Committee status updated.');
        } elseif ($module === 'elections' && $action === 'create_election' && $canManage) {
            $title = trim((string) ($_POST['title'] ?? ''));
            $position = trim((string) ($_POST['position'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $nominationsOpen = community_datetime((string) ($_POST['nominations_open_at'] ?? ''));
            $nominationsClose = community_datetime((string) ($_POST['nominations_close_at'] ?? ''));
            $votingOpen = community_datetime((string) ($_POST['voting_open_at'] ?? ''));
            $votingClose = community_datetime((string) ($_POST['voting_close_at'] ?? ''));
            if (preg_match('/^.{2,160}$/us', $title) !== 1 || preg_match('/^.{2,120}$/us', $position) !== 1
                || strlen($description) > 1000 || !$nominationsOpen || !$nominationsClose || !$votingOpen || !$votingClose
                || !($nominationsOpen < $nominationsClose && $nominationsClose <= $votingOpen && $votingOpen < $votingClose)) {
                throw new InvalidArgumentException('Enter an election title and valid nomination/voting schedule in chronological order.');
            }
            $stmt = db()->prepare('INSERT INTO association_elections (title,position,description,nominations_open_at,nominations_close_at,voting_open_at,voting_close_at,created_by) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$title, $position, $description !== '' ? $description : null, $nominationsOpen, $nominationsClose, $votingOpen, $votingClose, (int) $user['id']]);
            audit((int) $user['id'], 'election_created', 'election_id=' . (int) db()->lastInsertId());
            flash('success', 'Election created in draft.');
        } elseif ($module === 'elections' && $action === 'election_status' && $canManage) {
            $electionId = filter_var($_POST['election_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            if (!$electionId || !in_array($status, ['nominations_open', 'voting_open', 'closed', 'cancelled'], true)) {
                throw new InvalidArgumentException('Choose a valid election status.');
            }
            db()->beginTransaction();
            $stmt = db()->prepare('SELECT * FROM association_elections WHERE id=? FOR UPDATE');
            $stmt->execute([(int) $electionId]);
            $election = $stmt->fetch();
            $now = gmdate('Y-m-d H:i:s');
            $validTransition = false;
            if ($election && $status === 'nominations_open') {
                $validTransition = in_array($election['status'], ['draft', 'nominations_open'], true)
                    && $now >= $election['nominations_open_at'] && $now < $election['nominations_close_at'];
            } elseif ($election && $status === 'voting_open') {
                $stmt = db()->prepare("SELECT COUNT(*) FROM election_candidates WHERE election_id=? AND status='approved'");
                $stmt->execute([(int) $electionId]);
                $validTransition = $election['status'] === 'nominations_open'
                    && $now >= $election['voting_open_at'] && $now < $election['voting_close_at']
                    && (int) $stmt->fetchColumn() > 0;
            } elseif ($election && $status === 'closed') {
                $validTransition = $election['status'] === 'voting_open' && $now >= $election['voting_close_at'];
            } elseif ($election && $status === 'cancelled') {
                $validTransition = in_array($election['status'], ['draft', 'nominations_open', 'voting_open'], true);
            }
            if (!$validTransition) {
                db()->rollBack();
                throw new InvalidArgumentException('The election cannot move to that status yet. Check its schedule and approved candidates.');
            }
            $stmt = db()->prepare('UPDATE association_elections SET status=? WHERE id=?');
            $stmt->execute([$status, (int) $electionId]);
            db()->commit();
            audit((int) $user['id'], 'election_status_changed', 'election_id=' . (int) $electionId . '; status=' . $status);
            flash('success', 'Election status updated.');
        } elseif ($module === 'elections' && $action === 'nominate_candidate' && user_has_permission($user, 'elections.vote')) {
            $electionId = filter_var($_POST['election_id'] ?? null, FILTER_VALIDATE_INT);
            $statement = trim((string) ($_POST['statement'] ?? ''));
            if (!$electionId || strlen($statement) > 2000 || !community_member_is_active((int) $user['id'])) {
                throw new InvalidArgumentException('Only an active member can submit an election nomination.');
            }
            $stmt = db()->prepare("SELECT id FROM association_elections WHERE id=? AND status='nominations_open' AND UTC_TIMESTAMP()>=nominations_open_at AND UTC_TIMESTAMP()<nominations_close_at");
            $stmt->execute([(int) $electionId]);
            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException('Nominations are not open for that election.');
            }
            $stmt = db()->prepare('INSERT INTO election_candidates (election_id,user_id,statement) VALUES (?,?,?)');
            $stmt->execute([(int) $electionId, (int) $user['id'], $statement !== '' ? $statement : null]);
            audit((int) $user['id'], 'election_candidate_nominated', 'election_id=' . (int) $electionId);
            flash('success', 'Your nomination was submitted for review.');
        } elseif ($module === 'elections' && $action === 'review_candidate' && $canManage) {
            $candidateId = filter_var($_POST['candidate_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            if (!$candidateId || !in_array($status, ['approved', 'rejected'], true)) {
                throw new InvalidArgumentException('Choose a valid candidate review decision.');
            }
            db()->beginTransaction();
            $stmt = db()->prepare(
                "SELECT c.status AS candidate_status,e.status AS election_status,e.nominations_open_at,e.nominations_close_at
                 FROM election_candidates c JOIN association_elections e ON e.id=c.election_id
                 WHERE c.id=? FOR UPDATE"
            );
            $stmt->execute([(int) $candidateId]);
            $candidate = $stmt->fetch();
            $reviewAt = gmdate('Y-m-d H:i:s');
            if (!$candidate || $candidate['candidate_status'] !== 'pending'
                || $candidate['election_status'] !== 'nominations_open'
                || $reviewAt < $candidate['nominations_open_at']
                || $reviewAt >= $candidate['nominations_close_at']) {
                db()->rollBack();
                throw new InvalidArgumentException('Only pending nominations in the open nomination period can be reviewed.');
            }
            $stmt = db()->prepare("UPDATE election_candidates SET status=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'");
            $stmt->execute([$status, (int) $user['id'], (int) $candidateId]);
            if (!$stmt->rowCount()) {
                db()->rollBack();
                throw new InvalidArgumentException('That nomination is no longer awaiting review.');
            }
            db()->commit();
            audit((int) $user['id'], 'election_candidate_reviewed', 'candidate_id=' . (int) $candidateId . '; status=' . $status);
            flash('success', 'Candidate review saved.');
        } elseif ($module === 'elections' && $action === 'election_details' && $canManage) {
            $electionId = filter_var($_POST['election_id'] ?? null, FILTER_VALIDATE_INT);
            $title = trim((string) ($_POST['title'] ?? ''));
            $position = trim((string) ($_POST['position'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $nominationsOpen = community_datetime((string) ($_POST['nominations_open_at'] ?? ''));
            $nominationsClose = community_datetime((string) ($_POST['nominations_close_at'] ?? ''));
            $votingOpen = community_datetime((string) ($_POST['voting_open_at'] ?? ''));
            $votingClose = community_datetime((string) ($_POST['voting_close_at'] ?? ''));
            if (!$electionId || preg_match('/^.{2,160}$/us', $title) !== 1 || preg_match('/^.{2,120}$/us', $position) !== 1
                || strlen($description) > 1000 || !$nominationsOpen || !$nominationsClose || !$votingOpen || !$votingClose
                || !($nominationsOpen < $nominationsClose && $nominationsClose <= $votingOpen && $votingOpen < $votingClose)) {
                throw new InvalidArgumentException('Check the election details and chronological nomination/voting schedule.');
            }
            $stmt = db()->prepare("UPDATE association_elections SET title=?,position=?,description=?,nominations_open_at=?,nominations_close_at=?,voting_open_at=?,voting_close_at=? WHERE id=? AND status='draft'");
            $stmt->execute([$title, $position, $description !== '' ? $description : null, $nominationsOpen, $nominationsClose, $votingOpen, $votingClose, (int) $electionId]);
            if (!$stmt->rowCount()) {
                throw new InvalidArgumentException('Only a draft election can be edited.');
            }
            audit((int) $user['id'], 'election_details_updated', 'election_id=' . (int) $electionId);
            flash('success', 'Draft election details updated.');
        } elseif ($module === 'elections' && $action === 'cast_vote' && user_has_permission($user, 'elections.vote')) {
            $electionId = filter_var($_POST['election_id'] ?? null, FILTER_VALIDATE_INT);
            $candidateId = filter_var($_POST['candidate_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$electionId || !$candidateId || !community_member_is_active((int) $user['id'])) {
                throw new InvalidArgumentException('Only an active member can vote in an election.');
            }
            db()->beginTransaction();
            $stmt = db()->prepare("SELECT id FROM association_elections WHERE id=? AND status='voting_open' AND UTC_TIMESTAMP()>=voting_open_at AND UTC_TIMESTAMP()<voting_close_at FOR UPDATE");
            $stmt->execute([(int) $electionId]);
            if (!$stmt->fetchColumn()) {
                db()->rollBack();
                throw new InvalidArgumentException('Voting is not open for that election.');
            }
            $stmt = db()->prepare("SELECT id FROM election_candidates WHERE id=? AND election_id=? AND status='approved'");
            $stmt->execute([(int) $candidateId, (int) $electionId]);
            if (!$stmt->fetchColumn()) {
                db()->rollBack();
                throw new InvalidArgumentException('Select an approved candidate for this election.');
            }
            try {
                $stmt = db()->prepare('INSERT INTO election_voters (election_id,user_id) VALUES (?,?)');
                $stmt->execute([(int) $electionId, (int) $user['id']]);
            } catch (PDOException $exception) {
                db()->rollBack();
                if ($exception->getCode() === '23000') {
                    throw new InvalidArgumentException('A ballot has already been recorded for your account in this election.');
                }
                throw $exception;
            }
            $stmt = db()->prepare('INSERT INTO election_votes (election_id,candidate_id,ballot_hash) VALUES (?,?,?)');
            $stmt->execute([(int) $electionId, (int) $candidateId, hash('sha256', random_bytes(32))]);
            db()->commit();
            flash('success', 'Your secret ballot was recorded. Your selection is not linked to your account.');
        } elseif ($module === 'welfare' && $action === 'submit_welfare_case' && user_has_permission($user, 'welfare.submit')) {
            $category = (string) ($_POST['category'] ?? '');
            $subject = trim((string) ($_POST['subject'] ?? ''));
            $details = trim((string) ($_POST['details'] ?? ''));
            if (!in_array($category, ['financial_hardship', 'health', 'bereavement', 'family', 'employment', 'other'], true)
                || preg_match('/^.{2,160}$/us', $subject) !== 1 || strlen($details) < 10 || strlen($details) > 20000
                || !community_member_is_active((int) $user['id'])) {
                throw new InvalidArgumentException('Enter a category, subject, and at least 10 characters of information. Active approved membership is required.');
            }
            $stmt = db()->prepare('INSERT INTO welfare_cases (user_id,category,subject,details) VALUES (?,?,?,?)');
            $stmt->execute([(int) $user['id'], $category, $subject, $details]);
            audit((int) $user['id'], 'welfare_case_submitted', 'case_id=' . (int) db()->lastInsertId());
            flash('success', 'Your confidential request was submitted to the welfare team.');
        } elseif ($module === 'welfare' && $action === 'update_welfare_case' && $canManage) {
            $caseId = filter_var($_POST['case_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            $staffNote = trim((string) ($_POST['staff_note'] ?? ''));
            if (!$caseId || !in_array($status, ['submitted', 'in_review', 'resolved', 'referred', 'closed'], true) || strlen($staffNote) > 20000) {
                throw new InvalidArgumentException('Check the case status and internal note.');
            }
            $stmt = db()->prepare('UPDATE welfare_cases SET status=?,staff_note=?,assigned_to=? WHERE id=?');
            $stmt->execute([$status, $staffNote !== '' ? $staffNote : null, (int) $user['id'], (int) $caseId]);
            audit((int) $user['id'], 'welfare_case_updated', 'case_id=' . (int) $caseId . '; status=' . $status);
            flash('success', 'Welfare case updated.');
        } elseif ($module === 'mentorship' && $action === 'save_mentorship_profile' && user_has_permission($user, 'mentorship.view')) {
            $offering = isset($_POST['offering_mentorship']) ? 1 : 0;
            $seeking = isset($_POST['seeking_mentorship']) ? 1 : 0;
            $expertise = trim((string) ($_POST['expertise'] ?? ''));
            $interests = trim((string) ($_POST['interests'] ?? ''));
            $availability = trim((string) ($_POST['availability'] ?? ''));
            $visible = isset($_POST['visible']) ? 1 : 0;
            if ((!$offering && !$seeking) || strlen($expertise) > 500 || strlen($interests) > 500 || strlen($availability) > 300
                || !community_member_is_active((int) $user['id'])) {
                throw new InvalidArgumentException('Select whether you are offering or seeking mentorship and check the profile field lengths.');
            }
            $stmt = db()->prepare('INSERT INTO mentorship_profiles (user_id,offering_mentorship,seeking_mentorship,expertise,interests,availability,visible) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE offering_mentorship=VALUES(offering_mentorship),seeking_mentorship=VALUES(seeking_mentorship),expertise=VALUES(expertise),interests=VALUES(interests),availability=VALUES(availability),visible=VALUES(visible)');
            $stmt->execute([(int) $user['id'], $offering, $seeking, $expertise !== '' ? $expertise : null, $interests !== '' ? $interests : null, $availability !== '' ? $availability : null, $visible]);
            audit((int) $user['id'], 'mentorship_profile_updated');
            flash('success', 'Mentorship profile saved.');
        } elseif ($module === 'jobs' && $action === 'create_job' && $canManage) {
            $title = trim((string) ($_POST['title'] ?? ''));
            $organisation = trim((string) ($_POST['organisation'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $employmentType = (string) ($_POST['employment_type'] ?? '');
            $salary = trim((string) ($_POST['salary_information'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $qualifications = trim((string) ($_POST['qualifications'] ?? ''));
            $url = trim((string) ($_POST['application_url'] ?? ''));
            $applicationEmail = strtolower(trim((string) ($_POST['application_email'] ?? '')));
            $instructions = trim((string) ($_POST['application_instructions'] ?? ''));
            $closesInput = trim((string) ($_POST['closes_at'] ?? ''));
            $closesAt = $closesInput !== '' ? community_datetime($closesInput) : null;
            $status = (string) ($_POST['status'] ?? 'draft');
            if (preg_match('/^.{2,160}$/us', $title) !== 1 || preg_match('/^.{2,160}$/us', $organisation) !== 1
                || strlen($location) > 160 || !in_array($employmentType, ['full_time','part_time','contract','temporary','internship','volunteer','other'], true)
                || strlen($salary) > 200 || strlen($description) < 10 || strlen($description) > 30000 || strlen($qualifications) > 30000
                || ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false)
                || ($url !== '' && !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))
                || ($applicationEmail !== '' && (!filter_var($applicationEmail, FILTER_VALIDATE_EMAIL) || strlen($applicationEmail) > 254))
                || ($url === '' && $applicationEmail === '')
                || strlen($instructions) > 20000 || ($closesInput !== '' && !$closesAt)
                || !in_array($status, ['draft', 'published'], true)) {
                throw new InvalidArgumentException('Check the job title, organisation, description, employment type, application contact, instructions, closing date, and status.');
            }
            $stmt = db()->prepare(
                'INSERT INTO association_jobs
                 (title,organisation,location,employment_type,salary_information,description,qualifications,
                  application_url,application_instructions,application_email,closes_at,status,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $title,$organisation,$location !== '' ? $location : null,$employmentType,
                $salary !== '' ? $salary : null,$description,$qualifications !== '' ? $qualifications : null,
                $url !== '' ? $url : null,$instructions !== '' ? $instructions : null,
                $applicationEmail !== '' ? $applicationEmail : null,$closesAt,$status,(int) $user['id'],
            ]);
            audit((int) $user['id'], 'job_post_created', 'job_id=' . (int) db()->lastInsertId());
            flash('success', 'Opportunity saved.');
        } elseif ($module === 'jobs' && $action === 'update_job' && $canManage) {
            $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT);
            $title = trim((string) ($_POST['title'] ?? ''));
            $organisation = trim((string) ($_POST['organisation'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $employmentType = (string) ($_POST['employment_type'] ?? '');
            $salary = trim((string) ($_POST['salary_information'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $qualifications = trim((string) ($_POST['qualifications'] ?? ''));
            $url = trim((string) ($_POST['application_url'] ?? ''));
            $applicationEmail = strtolower(trim((string) ($_POST['application_email'] ?? '')));
            $instructions = trim((string) ($_POST['application_instructions'] ?? ''));
            $closesInput = trim((string) ($_POST['closes_at'] ?? ''));
            $closesAt = $closesInput !== '' ? community_datetime($closesInput) : null;
            if (!$jobId || preg_match('/^.{2,160}$/us', $title) !== 1 || preg_match('/^.{2,160}$/us', $organisation) !== 1
                || strlen($location) > 160 || !in_array($employmentType, ['full_time','part_time','contract','temporary','internship','volunteer','other'], true)
                || strlen($salary) > 200 || strlen($description) < 10 || strlen($description) > 30000 || strlen($qualifications) > 30000
                || ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false
                    || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http','https'], true)))
                || ($applicationEmail !== '' && (!filter_var($applicationEmail, FILTER_VALIDATE_EMAIL) || strlen($applicationEmail) > 254))
                || ($url === '' && $applicationEmail === '') || strlen($instructions) > 20000
                || ($closesInput !== '' && !$closesAt)) {
                throw new InvalidArgumentException('Check all job details and provide a valid external application link or email.');
            }
            $stmt = db()->prepare(
                'UPDATE association_jobs SET title=?,organisation=?,location=?,employment_type=?,
                 salary_information=?,description=?,qualifications=?,application_url=?,application_instructions=?,
                 application_email=?,closes_at=? WHERE id=?'
            );
            $stmt->execute([
                $title,$organisation,$location !== '' ? $location : null,$employmentType,
                $salary !== '' ? $salary : null,$description,$qualifications !== '' ? $qualifications : null,
                $url !== '' ? $url : null,$instructions !== '' ? $instructions : null,
                $applicationEmail !== '' ? $applicationEmail : null,$closesAt,(int) $jobId,
            ]);
            audit((int) $user['id'], 'job_post_updated', 'job_id=' . (int) $jobId);
            flash('success', 'Opportunity details updated.');
        } elseif ($module === 'jobs' && $action === 'job_status' && $canManage) {
            $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            if (!$jobId || !in_array($status, ['published', 'closed', 'archived'], true)) {
                throw new InvalidArgumentException('Choose a valid opportunity status.');
            }
            $stmt = db()->prepare('UPDATE association_jobs SET status=? WHERE id=?');
            $stmt->execute([$status, (int) $jobId]);
            audit((int) $user['id'], 'job_post_status_changed', 'job_id=' . (int) $jobId . '; status=' . $status);
            flash('success', 'Opportunity status updated.');
        } elseif ($module === 'jobs' && $action === 'apply_job' && user_has_permission($user, 'jobs.apply')) {
            $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT);
            $coverNote = trim((string) ($_POST['cover_note'] ?? ''));
            if (!$jobId || strlen($coverNote) > 3000 || !community_member_is_active((int) $user['id'])) {
                throw new InvalidArgumentException('Only active approved members can apply. Check your application note.');
            }
            $stmt = db()->prepare("SELECT id FROM association_jobs WHERE id=? AND status='published' AND (closes_at IS NULL OR closes_at>UTC_TIMESTAMP())");
            $stmt->execute([(int) $jobId]);
            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException('That opportunity is no longer accepting applications.');
            }
            $stmt = db()->prepare('INSERT INTO job_applications (job_id,user_id,cover_note) VALUES (?,?,?)');
            $stmt->execute([(int) $jobId, (int) $user['id'], $coverNote !== '' ? $coverNote : null]);
            audit((int) $user['id'], 'job_application_submitted', 'job_id=' . (int) $jobId);
            flash('success', 'Your application was submitted.');
        } elseif ($module === 'jobs' && $action === 'update_application' && $canManage) {
            $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT);
            $memberId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            if (!$jobId || !$memberId || !in_array($status, ['reviewing', 'shortlisted', 'declined'], true)) {
                throw new InvalidArgumentException('Choose a valid application and review status.');
            }
            $stmt = db()->prepare("SELECT 1 FROM job_applications WHERE job_id=? AND user_id=? AND status <> 'withdrawn'");
            $stmt->execute([(int) $jobId, (int) $memberId]);
            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException('That application was not found or has been withdrawn.');
            }
            $stmt = db()->prepare('UPDATE job_applications SET status=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE job_id=? AND user_id=?');
            $stmt->execute([$status, (int) $user['id'], (int) $jobId, (int) $memberId]);
            audit((int) $user['id'], 'job_application_reviewed', 'job_id=' . (int) $jobId . '; user_id=' . (int) $memberId . '; status=' . $status);
            flash('success', 'Application review saved.');
        } else {
            throw new InvalidArgumentException('Your account cannot perform that programme action.');
        }
    } catch (InvalidArgumentException $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $fail($exception->getMessage());
    } catch (PDOException $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if ($exception->getCode() === '23000') {
            $fail('That record already exists or the selected association record is no longer available.');
        }
        throw $exception;
    }
    redirect('community.php?module=' . rawurlencode($module));
}

$members = [];
$data = [];
$myMentorship = [];
$nominationElectionIds = [];
$voteTallies = [];
if (in_array($module, ['chapters', 'committees'], true) && $canManage) {
    $members = community_active_members();
}
if ($module === 'chapters') {
    $data = db()->query('SELECT c.*,u.full_name AS coordinator FROM association_chapters c LEFT JOIN users u ON u.id=c.coordinator_user_id ORDER BY c.status,c.name LIMIT 200')->fetchAll();
    mark_sidebar_content_viewed(
        (int) $user['id'],
        'association_chapter',
        array_map(
            static fn(array $chapter): array => [
                'id' => (int) $chapter['id'],
                'updated_at' => (string) $chapter['updated_at'],
            ],
            array_values(array_filter($data, static fn(array $chapter): bool => $chapter['status'] === 'active'))
        )
    );
} elseif ($module === 'committees') {
    $data = db()->query(
        'SELECT c.*,chair.full_name AS chair_name,
            (SELECT COUNT(*) FROM committee_members cm WHERE cm.committee_id=c.id) AS member_count
         FROM association_committees c LEFT JOIN users chair ON chair.id=c.chair_user_id
         ORDER BY c.status,c.name LIMIT 200'
    )->fetchAll();
    $committeeMembers = db()->query(
        'SELECT cm.committee_id,cm.user_id,cm.committee_role,u.full_name,c.name AS committee_name
         FROM committee_members cm JOIN users u ON u.id=cm.user_id
         JOIN association_committees c ON c.id=cm.committee_id ORDER BY c.name,u.full_name LIMIT 500'
    )->fetchAll();
    $stmt = db()->prepare(
        "SELECT cm.committee_id AS id,cm.assigned_at AS updated_at
         FROM committee_members cm
         JOIN association_committees c ON c.id=cm.committee_id AND c.status='active'
         WHERE cm.user_id=?"
    );
    $stmt->execute([(int) $user['id']]);
    mark_sidebar_content_viewed((int) $user['id'], 'committee_assignment', $stmt->fetchAll());
} elseif ($module === 'elections') {
    $tallySql = $canManage || (isset($_GET['results']) && $_GET['results'] === '1')
        ? "(SELECT COUNT(*) FROM election_votes v WHERE v.election_id=e.id AND e.status='closed') AS vote_count,"
        : '0 AS vote_count,';
    $data = db()->query(
        'SELECT e.*,(SELECT COUNT(*) FROM election_candidates c WHERE c.election_id=e.id AND c.status=\'approved\') AS candidate_count,'
            . $tallySql . '(SELECT COUNT(*) FROM election_voters v WHERE v.election_id=e.id AND v.user_id=' . (int) $user['id'] . ') AS has_voted
         FROM association_elections e ' . ($canManage ? '' : "WHERE e.status <> 'draft' ") . 'ORDER BY e.created_at DESC LIMIT 100'
    )->fetchAll();
    $candidateRows = db()->query(
        'SELECT c.*,u.full_name,e.title AS election_title,e.status AS election_status
         FROM election_candidates c JOIN users u ON u.id=c.user_id
         JOIN association_elections e ON e.id=c.election_id
         WHERE (e.status <> \'draft\' OR ' . ($canManage ? '1=1' : '0=1') . ') AND ' . ($canManage
            ? "c.status IN ('approved','pending','rejected')"
            : "(c.status='approved' OR c.user_id=" . (int) $user['id'] . ')') .
         ' ORDER BY c.nominated_at DESC LIMIT 500'
    )->fetchAll();
    $statement = db()->prepare('SELECT election_id,user_id FROM election_candidates WHERE user_id=?');
    $statement->execute([(int) $user['id']]);
    foreach ($statement->fetchAll() as $candidate) {
        $nominationElectionIds[(int) $candidate['election_id']] = true;
    }
    if ($canManage || (isset($_GET['results']) && $_GET['results'] === '1')) {
        $tallyQuery = db()->query(
            "SELECT v.candidate_id,COUNT(*) AS votes FROM election_votes v
             JOIN association_elections e ON e.id=v.election_id
             WHERE e.status='closed' GROUP BY v.candidate_id"
        );
        foreach ($tallyQuery->fetchAll() as $tally) {
            $voteTallies[(int) $tally['candidate_id']] = (int) $tally['votes'];
        }
    }
} elseif ($module === 'welfare') {
    if ($canManage) {
        $data = db()->query('SELECT w.*,u.full_name FROM welfare_cases w JOIN users u ON u.id=w.user_id ORDER BY FIELD(w.status,\'submitted\',\'in_review\',\'referred\',\'resolved\',\'closed\'),w.created_at DESC LIMIT 200')->fetchAll();
    } else {
        $stmt = db()->prepare('SELECT id,category,subject,details,status,created_at,updated_at FROM welfare_cases WHERE user_id=? ORDER BY created_at DESC LIMIT 100');
        $stmt->execute([(int) $user['id']]);
        $data = $stmt->fetchAll();
        mark_sidebar_content_viewed(
            (int) $user['id'],
            'welfare_case',
            array_map(
                static fn(array $case): array => [
                    'id' => (int) $case['id'],
                    'updated_at' => (string) $case['updated_at'],
                ],
                $data
            )
        );
    }
} elseif ($module === 'mentorship') {
    $stmt = db()->prepare('SELECT * FROM mentorship_profiles WHERE user_id=?');
    $stmt->execute([(int) $user['id']]);
    $myMentorship = $stmt->fetch() ?: [];
    $data = db()->query(
        "SELECT m.*,u.full_name,mp.membership_number
         FROM mentorship_profiles m JOIN users u ON u.id=m.user_id
         JOIN member_profiles mp ON mp.user_id=m.user_id
         WHERE m.visible=1 AND m.user_id <> " . (int) $user['id'] . "
           AND u.status='active' AND u.email_verified_at IS NOT NULL AND mp.membership_status='active'
         ORDER BY m.offering_mentorship DESC,u.full_name LIMIT 200"
    )->fetchAll();
    mark_sidebar_content_viewed(
        (int) $user['id'],
        'mentorship_profile',
        array_map(
            static fn(array $profile): array => [
                'id' => (int) $profile['user_id'],
                'updated_at' => (string) $profile['updated_at'],
            ],
            $data
        )
    );
} elseif ($module === 'jobs') {
    $data = db()->query(
        "SELECT j.*,(SELECT COUNT(*) FROM job_applications a WHERE a.job_id=j.id) AS application_count,
            (SELECT COUNT(*) FROM job_applications a WHERE a.job_id=j.id AND a.user_id=" . (int) $user['id'] . ") AS my_application
         FROM association_jobs j
         WHERE " . ($canManage ? '1=1' : "j.status='published' AND (j.closes_at IS NULL OR j.closes_at>UTC_TIMESTAMP())") . "
         ORDER BY j.created_at DESC LIMIT 200"
    )->fetchAll();
    if (!$canManage) {
        mark_sidebar_content_viewed(
            (int) $user['id'],
            'job_opportunity',
            array_map(
                static fn(array $job): array => [
                    'id' => (int) $job['id'],
                    'updated_at' => (string) $job['updated_at'],
                ],
                array_values(array_filter($data, static fn(array $job): bool => $job['status'] === 'published'))
            )
        );
    }
    if ($canManage) {
        $applications = db()->query(
            'SELECT a.*,u.full_name,j.title AS job_title
             FROM job_applications a JOIN users u ON u.id=a.user_id
             JOIN association_jobs j ON j.id=a.job_id
             ORDER BY a.applied_at DESC LIMIT 300'
        )->fetchAll();
    } else {
        $stmt = db()->prepare('SELECT a.*,j.title AS job_title FROM job_applications a JOIN association_jobs j ON j.id=a.job_id WHERE a.user_id=? ORDER BY a.applied_at DESC LIMIT 100');
        $stmt->execute([(int) $user['id']]);
        $myApplications = $stmt->fetchAll();
    }
}

render_header($definition['title']);
page_title('ASSOCIATION PROGRAMMES', $definition['title'], 'Access to programme information and actions is limited by your association role.');
?>
<?php if ($module === 'chapters'): ?>
    <?php if ($canManage): ?><section class="panel module-form-panel"><h2>Create association chapter</h2><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="chapters"><input type="hidden" name="action" value="create_chapter"><label>Chapter name<input name="name" maxlength="120" required></label><label>Region<input name="region" maxlength="120"></label><label>Location<input name="location" maxlength="200"></label><label>Coordinator<select name="coordinator_user_id"><option value="">Not assigned</option><?php foreach ($members as $member): ?><option value="<?= (int) $member['id'] ?>"><?= e($member['full_name']) ?></option><?php endforeach; ?></select></label><button class="button button-primary" type="submit">Create chapter</button></form></section><?php endif; ?>
    <section class="panel table-panel module-table"><div class="table-scroll"><table><thead><tr><th>Chapter</th><th>Region / location</th><th>Coordinator</th><th>Status</th><?php if ($canManage): ?><th>Update</th><?php endif; ?></tr></thead><tbody><?php foreach ($data as $chapter): ?><tr><td><strong><?= e($chapter['name']) ?></strong></td><td><?= e($chapter['region'] ?: '—') ?><?= $chapter['location'] ? '<br>' . e($chapter['location']) : '' ?></td><td><?= e($chapter['coordinator'] ?: 'Not assigned') ?></td><td><?= e(ucfirst($chapter['status'])) ?></td><?php if ($canManage): ?><td><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="module" value="chapters"><input type="hidden" name="action" value="update_chapter"><input type="hidden" name="chapter_id" value="<?= (int) $chapter['id'] ?>"><select name="coordinator_user_id"><option value="">No coordinator</option><?php foreach ($members as $member): ?><option value="<?= (int) $member['id'] ?>" <?= (int) $chapter['coordinator_user_id'] === (int) $member['id'] ? 'selected' : '' ?>><?= e($member['full_name']) ?></option><?php endforeach; ?></select><select name="status"><option value="active" <?= $chapter['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="archived" <?= $chapter['status'] === 'archived' ? 'selected' : '' ?>>Archived</option></select><button class="button button-small" type="submit">Save</button></form></td><?php endif; ?></tr><?php endforeach; ?><?php if (!$data): ?><tr><td colspan="<?= $canManage ? 5 : 4 ?>" class="muted">No chapters have been registered.</td></tr><?php endif; ?></tbody></table></div></section>
<?php elseif ($module === 'committees'): ?>
    <?php if ($canManage): ?><section class="panel module-form-panel"><h2>Register committee</h2><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="committees"><input type="hidden" name="action" value="create_committee"><label>Committee name<input name="name" maxlength="120" required></label><label>Chairperson<select name="chair_user_id"><option value="">Not assigned</option><?php foreach ($members as $member): ?><option value="<?= (int) $member['id'] ?>"><?= e($member['full_name']) ?></option><?php endforeach; ?></select></label><label class="span-all">Mandate<input name="mandate" maxlength="1000"></label><button class="button button-primary" type="submit">Create committee</button></form></section><?php endif; ?>
    <section class="association-card-grid"><?php foreach ($data as $committee): ?><article class="panel association-card"><div class="association-card-heading"><h2><?= e($committee['name']) ?></h2><span class="status-pill"><?= e(ucfirst($committee['status'])) ?></span></div><p><?= e($committee['mandate'] ?: 'No mandate recorded.') ?></p><p class="muted">Chair: <?= e($committee['chair_name'] ?: 'Not assigned') ?> · <?= (int) $committee['member_count'] ?> member(s)</p><?php if ($canManage): ?><details><summary>Edit committee details</summary><form method="post" class="module-form top-gap"><?= csrf_field() ?><input type="hidden" name="module" value="committees"><input type="hidden" name="action" value="update_committee"><input type="hidden" name="committee_id" value="<?= (int) $committee['id'] ?>"><label>Committee name<input name="name" maxlength="120" value="<?= e($committee['name']) ?>" required></label><label>Chairperson<select name="chair_user_id"><option value="">Not assigned</option><?php foreach ($members as $member): ?><option value="<?= (int) $member['id'] ?>" <?= (int) $committee['chair_user_id'] === (int) $member['id'] ? 'selected' : '' ?>><?= e($member['full_name']) ?></option><?php endforeach; ?></select></label><label class="span-all">Mandate<input name="mandate" maxlength="1000" value="<?= e((string) $committee['mandate']) ?>"></label><button class="button button-small" type="submit">Save details</button></form></details><?php endif; ?><?php if ($canManage && $committee['status'] === 'active'): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="module" value="committees"><input type="hidden" name="action" value="assign_committee_member"><input type="hidden" name="committee_id" value="<?= (int) $committee['id'] ?>"><select name="user_id" required><option value="">Add member</option><?php foreach ($members as $member): ?><option value="<?= (int) $member['id'] ?>"><?= e($member['full_name']) ?></option><?php endforeach; ?></select><input name="committee_role" maxlength="80" value="Member" aria-label="Committee role"><button class="button button-small" type="submit">Assign</button></form><?php endif; ?><?php if ($canManage): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="module" value="committees"><input type="hidden" name="action" value="committee_status"><input type="hidden" name="committee_id" value="<?= (int) $committee['id'] ?>"><select name="status"><option value="active" <?= $committee['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="archived" <?= $committee['status'] === 'archived' ? 'selected' : '' ?>>Archived</option></select><button class="button button-small" type="submit">Update committee</button></form><div class="committee-member-list"><?php foreach ($committeeMembers as $committeeMember): if ((int) $committeeMember['committee_id'] !== (int) $committee['id']) continue; ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="module" value="committees"><input type="hidden" name="action" value="remove_committee_member"><input type="hidden" name="committee_id" value="<?= (int) $committee['id'] ?>"><input type="hidden" name="user_id" value="<?= (int) $committeeMember['user_id'] ?>"><span><?= e($committeeMember['full_name']) ?> · <?= e($committeeMember['committee_role']) ?></span><button class="button button-quiet button-small" type="submit">Remove</button></form><?php endforeach; ?></div><?php endif; ?><?php if (!$canManage && !empty($committeeMembers)): ?><ul class="committee-member-list"><?php foreach ($committeeMembers as $committeeMember): if ((int) $committeeMember['committee_id'] !== (int) $committee['id']) continue; ?><li><?= e($committeeMember['full_name']) ?> · <?= e($committeeMember['committee_role']) ?></li><?php endforeach; ?></ul><?php endif; ?></article><?php endforeach; ?><?php if (!$data): ?><article class="panel"><p class="muted">No committees are configured.</p></article><?php endif; ?></section>
<?php elseif ($module === 'elections'): ?>
    <?php if ($canManage): ?><section class="panel module-form-panel"><h2>Create election</h2><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="elections"><input type="hidden" name="action" value="create_election"><label>Election title<input name="title" maxlength="160" required></label><label>Position<input name="position" maxlength="120" required></label><label>Nominations open (UTC)<input name="nominations_open_at" type="datetime-local" required></label><label>Nominations close (UTC)<input name="nominations_close_at" type="datetime-local" required></label><label>Voting opens (UTC)<input name="voting_open_at" type="datetime-local" required></label><label>Voting closes (UTC)<input name="voting_close_at" type="datetime-local" required></label><label class="span-all">Description<input name="description" maxlength="1000"></label><button class="button button-primary" type="submit">Create election</button></form></section><?php endif; ?>
    <div class="association-card-grid"><?php foreach ($data as $election): ?><article class="panel association-card"><div class="association-card-heading"><h2><?= e($election['title']) ?> · <?= e($election['position']) ?></h2><span class="status-pill"><?= e(str_replace('_', ' ', ucfirst($election['status']))) ?></span></div><p class="muted">Nominations: <?= e(gmdate('M j H:i', strtotime($election['nominations_open_at']))) ?>–<?= e(gmdate('M j H:i', strtotime($election['nominations_close_at']))) ?> UTC · Voting: <?= e(gmdate('M j H:i', strtotime($election['voting_open_at']))) ?>–<?= e(gmdate('M j H:i', strtotime($election['voting_close_at']))) ?> UTC</p><?php if ($election['description']): ?><p><?= e($election['description']) ?></p><?php endif; ?>
        <?php if ($election['status'] === 'closed' && ($canManage || (isset($_GET['results']) && $_GET['results'] === '1'))): ?><p class="muted">Final tally · <?= (int) $election['vote_count'] ?> ballots</p><?php foreach ($candidateRows as $candidate): if ((int) $candidate['election_id'] !== (int) $election['id'] || $candidate['status'] !== 'approved') continue; ?><p><?= e($candidate['full_name']) ?> · <?= (int) ($voteTallies[(int) $candidate['id']] ?? 0) ?> vote(s)</p><?php endforeach; ?><?php elseif ($election['status'] === 'closed'): ?><p><a href="community.php?module=elections&amp;results=1">View final results</a></p><?php endif; ?>
        <?php if ($election['status'] === 'nominations_open' && user_has_permission($user, 'elections.vote') && !isset($nominationElectionIds[(int) $election['id']])): ?><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="module" value="elections"><input type="hidden" name="action" value="nominate_candidate"><input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>"><label>Candidate statement<textarea name="statement" rows="3" maxlength="2000"></textarea></label><button class="button button-small" type="submit">Nominate myself</button></form><?php endif; ?>
        <?php if ($election['status'] === 'voting_open' && user_has_permission($user, 'elections.vote') && !$election['has_voted']): ?><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="module" value="elections"><input type="hidden" name="action" value="cast_vote"><input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>"><?php foreach ($candidateRows as $candidate): if ((int) $candidate['election_id'] !== (int) $election['id'] || $candidate['status'] !== 'approved') continue; ?><label class="check-label"><input type="radio" name="candidate_id" value="<?= (int) $candidate['id'] ?>" required><?= e($candidate['full_name']) ?><?= $candidate['statement'] ? ' · ' . e($candidate['statement']) : '' ?></label><?php endforeach; ?><button class="button button-primary button-small" type="submit">Cast secret ballot</button></form><?php elseif ($election['status'] === 'voting_open' && $election['has_voted']): ?><p class="status-pill status-good">Ballot submitted</p><?php endif; ?>
        <?php if ($canManage): ?><p class="muted"><?= (int) $election['candidate_count'] ?> approved candidate(s)<?php if ($election['status'] === 'closed'): ?> · <?= (int) $election['vote_count'] ?> secret ballot(s)<?php endif; ?></p><?php if ($election['status'] === 'draft'): ?><details class="election-edit"><summary>Edit draft details</summary><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="elections"><input type="hidden" name="action" value="election_details"><input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>"><label>Election title<input name="title" maxlength="160" value="<?= e($election['title']) ?>" required></label><label>Position<input name="position" maxlength="120" value="<?= e($election['position']) ?>" required></label><label>Nominations open (UTC)<input name="nominations_open_at" type="datetime-local" value="<?= e(gmdate('Y-m-d\TH:i', strtotime($election['nominations_open_at']))) ?>" required></label><label>Nominations close (UTC)<input name="nominations_close_at" type="datetime-local" value="<?= e(gmdate('Y-m-d\TH:i', strtotime($election['nominations_close_at']))) ?>" required></label><label>Voting opens (UTC)<input name="voting_open_at" type="datetime-local" value="<?= e(gmdate('Y-m-d\TH:i', strtotime($election['voting_open_at']))) ?>" required></label><label>Voting closes (UTC)<input name="voting_close_at" type="datetime-local" value="<?= e(gmdate('Y-m-d\TH:i', strtotime($election['voting_close_at']))) ?>" required></label><label class="span-all">Description<input name="description" maxlength="1000" value="<?= e($election['description']) ?>"></label><button class="button button-small" type="submit">Save draft details</button></form></details><?php endif; ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="module" value="elections"><input type="hidden" name="action" value="election_status"><input type="hidden" name="election_id" value="<?= (int) $election['id'] ?>"><select name="status"><?php if ($election['status'] === 'draft' || $election['status'] === 'nominations_open'): ?><option value="nominations_open">Open nominations</option><?php endif; ?><?php if ($election['status'] === 'nominations_open'): ?><option value="voting_open">Open voting</option><?php endif; ?><?php if ($election['status'] === 'voting_open'): ?><option value="closed">Close election</option><?php endif; ?><?php if (!in_array($election['status'], ['closed', 'cancelled'], true)): ?><option value="cancelled">Cancel</option><?php endif; ?></select><button class="button button-small" type="submit">Update status</button></form><?php endif; ?>
    </article><?php endforeach; ?><?php if (!$data): ?><article class="panel"><p class="muted">No elections are scheduled.</p></article><?php endif; ?></div>
    <?php if ($canManage): ?><section class="panel table-panel module-table"><h2>Candidate nominations</h2><div class="table-scroll"><table><thead><tr><th>Election</th><th>Candidate</th><th>Statement</th><th>Status</th><th>Review</th></tr></thead><tbody><?php foreach ($candidateRows as $candidate): ?><tr><td><?= e($candidate['election_title']) ?></td><td><?= e($candidate['full_name']) ?></td><td><?= e($candidate['statement']) ?></td><td><?= e(ucfirst($candidate['status'])) ?></td><td><?php if ($candidate['status'] === 'pending'): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="module" value="elections"><input type="hidden" name="action" value="review_candidate"><input type="hidden" name="candidate_id" value="<?= (int) $candidate['id'] ?>"><button class="button button-small" name="status" value="approved">Approve</button><button class="button button-quiet button-small" name="status" value="rejected">Reject</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<?php elseif ($module === 'welfare'): ?>
    <?php if (user_has_permission($user, 'welfare.submit') && community_member_is_active((int) $user['id'])): ?><section class="panel module-form-panel"><h2>Confidential welfare request</h2><p class="muted">Only the welfare team can view your request details. Do not include bank passwords, account credentials, or other sign-in secrets.</p><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="welfare"><input type="hidden" name="action" value="submit_welfare_case"><label>Category<select name="category"><option value="financial_hardship">Financial hardship</option><option value="health">Health</option><option value="bereavement">Bereavement</option><option value="family">Family support</option><option value="employment">Employment</option><option value="other">Other</option></select></label><label class="span-all">Subject<input name="subject" maxlength="160" required></label><label class="span-all">Request details<textarea name="details" rows="5" minlength="10" maxlength="20000" required></textarea></label><button class="button button-primary" type="submit">Submit confidential request</button></form></section><?php endif; ?>
    <section class="panel table-panel module-table"><h2><?= $canManage ? 'Confidential welfare cases' : 'My welfare requests' ?></h2><div class="table-scroll"><table><thead><tr><?php if ($canManage): ?><th>Member</th><?php endif; ?><th>Case</th><th>Status</th><th>Details</th><?php if ($canManage): ?><th>Internal note / update</th><?php endif; ?></tr></thead><tbody><?php foreach ($data as $case): ?><tr><?php if ($canManage): ?><td><?= e($case['full_name']) ?></td><?php endif; ?><td><?= e($case['subject']) ?><br><span class="muted"><?= e(str_replace('_', ' ', $case['category'])) ?> · <?= e(gmdate('Y-m-d', strtotime($case['created_at']))) ?></span></td><td><?= e(str_replace('_', ' ', ucfirst($case['status']))) ?></td><td><?= e($case['details']) ?></td><?php if ($canManage): ?><td><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="module" value="welfare"><input type="hidden" name="action" value="update_welfare_case"><input type="hidden" name="case_id" value="<?= (int) $case['id'] ?>"><select name="status"><?php foreach (['submitted','in_review','resolved','referred','closed'] as $status): ?><option value="<?= e($status) ?>" <?= $case['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select><textarea name="staff_note" rows="2" maxlength="20000" placeholder="Internal note"><?= e($case['staff_note']) ?></textarea><button class="button button-small" type="submit">Save case</button></form></td><?php endif; ?></tr><?php endforeach; ?><?php if (!$data): ?><tr><td colspan="<?= $canManage ? 5 : 3 ?>" class="muted">No welfare requests are on file.</td></tr><?php endif; ?></tbody></table></div></section>
<?php elseif ($module === 'mentorship'): ?>
    <?php if (user_has_permission($user, 'mentorship.view') && community_member_is_active((int) $user['id'])): ?><section class="panel module-form-panel"><h2>My mentorship profile</h2><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="mentorship"><input type="hidden" name="action" value="save_mentorship_profile"><label class="check-label"><input type="checkbox" name="offering_mentorship" value="1" <?= !empty($myMentorship['offering_mentorship']) ? 'checked' : '' ?>> I can mentor</label><label class="check-label"><input type="checkbox" name="seeking_mentorship" value="1" <?= !empty($myMentorship['seeking_mentorship']) ? 'checked' : '' ?>> I am seeking a mentor</label><label>Availability<input name="availability" maxlength="300" value="<?= e($myMentorship['availability'] ?? '') ?>"></label><label>Expertise<input name="expertise" maxlength="500" value="<?= e($myMentorship['expertise'] ?? '') ?>"></label><label>Interests<input name="interests" maxlength="500" value="<?= e($myMentorship['interests'] ?? '') ?>"></label><label class="check-label"><input type="checkbox" name="visible" value="1" <?= !isset($myMentorship['visible']) || $myMentorship['visible'] ? 'checked' : '' ?>> Show my profile in the mentorship directory</label><button class="button button-primary" type="submit">Save mentorship profile</button></form></section><?php endif; ?>
    <section class="association-card-grid"><?php foreach ($data as $mentor): ?><article class="panel association-card"><div class="association-card-heading"><h2><?= e($mentor['full_name']) ?></h2><span class="muted"><?= e($mentor['membership_number'] ?: 'Member') ?></span></div><p><?= e($mentor['offering_mentorship'] ? 'Offers mentorship' : '') ?><?= $mentor['offering_mentorship'] && $mentor['seeking_mentorship'] ? ' · ' : '' ?><?= e($mentor['seeking_mentorship'] ? 'Seeking a mentor' : '') ?></p><?php if ($mentor['expertise']): ?><p><strong>Expertise:</strong> <?= e($mentor['expertise']) ?></p><?php endif; ?><?php if ($mentor['interests']): ?><p><strong>Interests:</strong> <?= e($mentor['interests']) ?></p><?php endif; ?><?php if ($mentor['availability']): ?><p class="muted">Availability: <?= e($mentor['availability']) ?></p><?php endif; ?></article><?php endforeach; ?><?php if (!$data): ?><article class="panel"><p class="muted">No members have opted into the mentorship directory yet.</p></article><?php endif; ?></section>
<?php elseif ($module === 'jobs'): ?>
    <?php if ($canManage): ?><section class="panel module-form-panel"><h2>Post an opportunity</h2><form method="post" class="module-form"><?= csrf_field() ?><input type="hidden" name="module" value="jobs"><input type="hidden" name="action" value="create_job"><label>Position title<input name="title" maxlength="160" required></label><label>Organisation<input name="organisation" maxlength="160" required></label><label>Location<input name="location" maxlength="160"></label><label>Employment type<select name="employment_type" required><option value="full_time">Full-time</option><option value="part_time">Part-time</option><option value="contract">Contract</option><option value="temporary">Temporary</option><option value="internship">Internship</option><option value="volunteer">Volunteer</option><option value="other">Other</option></select></label><label>Salary information<input name="salary_information" maxlength="200"></label><label>Application URL<input name="application_url" type="url" maxlength="1000"></label><label>Application email<input name="application_email" type="email" maxlength="254"></label><label>Closing date (UTC)<input name="closes_at" type="datetime-local"></label><label>Publication<select name="status"><option value="draft">Draft</option><option value="published">Publish</option></select></label><label class="span-all">Description<textarea name="description" maxlength="30000" rows="5" required></textarea></label><label class="span-all">Qualifications and skills<textarea name="qualifications" maxlength="30000" rows="3"></textarea></label><label class="span-all">Application instructions<textarea name="application_instructions" maxlength="20000" rows="3"></textarea></label><small class="muted span-all">Provide at least one valid HTTPS/HTTP application URL or application email address.</small><button class="button button-primary" type="submit">Save opportunity</button></form></section><?php endif; ?>
    <div class="association-card-grid"><?php foreach ($data as $job): ?><article class="panel association-card"><div class="association-card-heading"><h2><?= e($job['title']) ?></h2><span class="status-pill"><?= e(ucfirst($job['status'])) ?></span></div><p><strong><?= e($job['organisation']) ?></strong><?= $job['location'] ? ' · ' . e($job['location']) : '' ?><?= $job['employment_type'] ? ' · ' . e(ucwords(str_replace('_',' ',$job['employment_type']))) : '' ?></p><?php if ($job['salary_information']): ?><p><strong>Salary:</strong> <?= e($job['salary_information']) ?></p><?php endif; ?><p class="module-body"><?= nl2br(e($job['description'])) ?></p><?php if ($job['qualifications']): ?><p><strong>Qualifications and skills</strong><br><?= nl2br(e($job['qualifications'])) ?></p><?php endif; ?><?php if ($job['application_instructions']): ?><p><strong>How to apply</strong><br><?= nl2br(e($job['application_instructions'])) ?></p><?php endif; ?><?php if ($job['closes_at']): ?><p class="muted">Closes <?= e(gmdate('M j, Y H:i', strtotime($job['closes_at']))) ?> UTC</p><?php endif; ?><?php if ($job['application_url'] && $job['status'] === 'published'): ?><p><a href="<?= e($job['application_url']) ?>" target="_blank" rel="noopener noreferrer">Apply online</a></p><?php endif; ?><?php if ($job['application_email'] && $job['status'] === 'published'): ?><p><a href="mailto:<?= e($job['application_email']) ?>">Apply by email</a></p><?php endif; ?>
        <?php if ($canManage): ?><details class="top-gap"><summary>Edit opportunity</summary><form method="post" class="module-form top-gap"><?= csrf_field() ?><input type="hidden" name="module" value="jobs"><input type="hidden" name="action" value="update_job"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><label>Position title<input name="title" maxlength="160" value="<?= e($job['title']) ?>" required></label><label>Organisation<input name="organisation" maxlength="160" value="<?= e($job['organisation']) ?>" required></label><label>Location<input name="location" maxlength="160" value="<?= e((string) $job['location']) ?>"></label><label>Employment type<select name="employment_type"><?php foreach (['full_time'=>'Full-time','part_time'=>'Part-time','contract'=>'Contract','temporary'=>'Temporary','internship'=>'Internship','volunteer'=>'Volunteer','other'=>'Other'] as $type=>$label): ?><option value="<?= e($type) ?>" <?= ($job['employment_type'] ?: 'other') === $type ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><label>Salary information<input name="salary_information" maxlength="200" value="<?= e((string) $job['salary_information']) ?>"></label><label>Application URL<input name="application_url" type="url" maxlength="1000" value="<?= e((string) $job['application_url']) ?>"></label><label>Application email<input name="application_email" type="email" maxlength="254" value="<?= e((string) $job['application_email']) ?>"></label><label>Closing date (UTC)<input name="closes_at" type="datetime-local" value="<?= e($job['closes_at'] ? gmdate('Y-m-d\TH:i', strtotime($job['closes_at'])) : '') ?>"></label><label class="span-all">Description<textarea name="description" rows="4" maxlength="30000" required><?= e($job['description']) ?></textarea></label><label class="span-all">Qualifications and skills<textarea name="qualifications" rows="3" maxlength="30000"><?= e((string) $job['qualifications']) ?></textarea></label><label class="span-all">Application instructions<textarea name="application_instructions" rows="3" maxlength="20000"><?= e((string) $job['application_instructions']) ?></textarea></label><button class="button button-small" type="submit">Save details</button></form></details><?php endif; ?>
        <?php if ($canManage): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="module" value="jobs"><input type="hidden" name="action" value="job_status"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><select name="status" aria-label="Opportunity status"><option value="published" <?= $job['status'] === 'published' ? 'selected' : '' ?>>Published</option><option value="closed" <?= $job['status'] === 'closed' ? 'selected' : '' ?>>Closed</option><option value="archived" <?= $job['status'] === 'archived' ? 'selected' : '' ?>>Archived</option></select><button class="button button-small" type="submit">Save status</button></form><?php endif; ?>
        <?php if (user_has_permission($user, 'jobs.apply') && $job['status'] === 'published' && !$job['my_application']): ?><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="module" value="jobs"><input type="hidden" name="action" value="apply_job"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><label for="cover-note-<?= (int) $job['id'] ?>">Optional note to the poster</label><textarea id="cover-note-<?= (int) $job['id'] ?>" name="cover_note" rows="2" maxlength="3000"></textarea><button class="button button-small" type="submit">Apply through association</button></form><?php elseif ($job['my_application']): ?><span class="status-pill status-good">Applied</span><?php endif; ?>
    </article><?php endforeach; ?><?php if (!$data): ?><article class="panel"><p class="muted">No opportunities are currently available.</p></article><?php endif; ?></div>
    <?php if ($canManage && !empty($applications)): ?><section class="panel table-panel module-table"><h2>Member applications</h2><div class="table-scroll"><table><thead><tr><th>Opportunity</th><th>Member</th><th>Cover note</th><th>Status</th><th>Review</th></tr></thead><tbody><?php foreach ($applications as $application): ?><tr><td><?= e($application['job_title']) ?></td><td><?= e($application['full_name']) ?></td><td><?= e($application['cover_note']) ?></td><td><?= e(ucfirst($application['status'])) ?></td><td><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="module" value="jobs"><input type="hidden" name="action" value="update_application"><input type="hidden" name="job_id" value="<?= (int) $application['job_id'] ?>"><input type="hidden" name="user_id" value="<?= (int) $application['user_id'] ?>"><select name="status"><option value="reviewing">Reviewing</option><option value="shortlisted">Shortlisted</option><option value="declined">Decline</option></select><button class="button button-small" type="submit">Update</button></form></td></tr><?php endforeach; ?></tbody></table></div></section><?php elseif (!$canManage && !empty($myApplications)): ?><section class="panel table-panel module-table"><h2>My applications</h2><div class="table-scroll"><table><thead><tr><th>Opportunity</th><th>Submitted</th><th>Status</th></tr></thead><tbody><?php foreach ($myApplications as $application): ?><tr><td><?= e($application['job_title']) ?></td><td><?= e(gmdate('Y-m-d', strtotime($application['applied_at']))) ?></td><td><?= e(ucfirst($application['status'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<?php endif; ?>
<?php render_footer(); ?>
