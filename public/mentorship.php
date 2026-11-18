<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = require_login();
$memberId = (int) $user['id'];
if (!user_has_permission($user, 'mentorship.view') && !user_has_permission($user, 'mentorship.manage')) {
    http_response_code(403);
    render_header('Access denied');
    echo '<section class="panel"><h1>Access denied</h1><p>Your account cannot access mentorship.</p><a class="button" href="dashboard.php">Return to dashboard</a></section>';
    render_footer();
    exit;
}
$activeStmt = db()->prepare(
    "SELECT 1 FROM users u JOIN member_profiles mp ON mp.user_id=u.id
     WHERE u.id=? AND u.status='active' AND u.email_verified_at IS NOT NULL
       AND mp.membership_status='active'"
);
$activeStmt->execute([$memberId]);
if (!$activeStmt->fetchColumn()) {
    http_response_code(403);
    render_header('Access denied');
    echo '<section class="panel"><h1>Active membership required</h1><p>Mentorship is available to active, verified members.</p><a class="button" href="dashboard.php">Return to dashboard</a></section>';
    render_footer();
    exit;
}

function mentorship_notify(int $userId, string $key, string $title, string $body, string $anchor): void
{
    $stmt = db()->prepare(
        "INSERT INTO association_notifications (user_id,notification_key,category,title,body,link_url)
         VALUES (? ,?,'other',?,?,?)
         ON DUPLICATE KEY UPDATE id=id"
    );
    $stmt->execute([$userId, $key, $title, $body, 'community.php?module=mentorship#' . $anchor]);
}

function mentorship_redirect(string $anchor = ''): never
{
    redirect('community.php?module=mentorship' . ($anchor !== '' ? '#' . rawurlencode($anchor) : ''));
}

function mentorship_active_member(int $id): bool
{
    $stmt = db()->prepare(
        "SELECT 1 FROM users u JOIN member_profiles mp ON mp.user_id=u.id
         WHERE u.id=? AND u.status='active' AND u.email_verified_at IS NOT NULL
           AND mp.membership_status='active'"
    );
    $stmt->execute([$id]);
    return (bool) $stmt->fetchColumn();
}

$profileStmt = db()->prepare('SELECT * FROM mentorship_profiles WHERE user_id=?');
$profileStmt->execute([$memberId]);
$myProfile = $profileStmt->fetch() ?: [
    'offering_mentorship' => 0,
    'seeking_mentorship' => 0,
    'expertise' => '',
    'teach_areas' => '',
    'interests' => '',
    'career_interest' => '',
    'goals' => '',
    'mentorship_bio' => '',
    'availability' => '',
    'visible' => 1,
    'is_available' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_profile') {
            $participation = (string) ($_POST['participation'] ?? '');
            if (!in_array($participation, ['mentor', 'seeker', 'both', 'none'], true)) {
                throw new InvalidArgumentException('Choose a valid mentorship participation option.');
            }
            $fields = [
                'expertise' => [500, 'Skills and expertise you can offer'],
                'teach_areas' => [500, 'Areas you can teach'],
                'interests' => [500, 'Skills and topics you want to learn'],
                'career_interest' => [500, 'Career interests'],
                'goals' => [1000, 'Mentorship goals'],
                'mentorship_bio' => [1000, 'Mentorship biography and experience'],
                'availability' => [300, 'Availability details'],
            ];
            $values = [];
            foreach ($fields as $name => [$limit, $label]) {
                $value = trim((string) ($_POST[$name] ?? ''));
                if (strlen($value) > $limit || preg_match('//u', $value) !== 1) {
                    throw new InvalidArgumentException($label . ' is too long or contains invalid text.');
                }
                $values[$name] = $value !== '' ? $value : null;
            }
            $offering = in_array($participation, ['mentor', 'both'], true) ? 1 : 0;
            $seeking = in_array($participation, ['seeker', 'both'], true) ? 1 : 0;
            $visible = isset($_POST['visible']) ? 1 : 0;
            $available = isset($_POST['is_available']) ? 1 : 0;
            $stmt = db()->prepare(
                'INSERT INTO mentorship_profiles
                 (user_id,offering_mentorship,seeking_mentorship,expertise,teach_areas,interests,
                  career_interest,goals,mentorship_bio,availability,visible,is_available)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE offering_mentorship=VALUES(offering_mentorship),
                  seeking_mentorship=VALUES(seeking_mentorship),expertise=VALUES(expertise),
                  teach_areas=VALUES(teach_areas),interests=VALUES(interests),
                  career_interest=VALUES(career_interest),goals=VALUES(goals),
                  mentorship_bio=VALUES(mentorship_bio),availability=VALUES(availability),
                  visible=VALUES(visible),is_available=VALUES(is_available)'
            );
            $stmt->execute([
                $memberId, $offering, $seeking, $values['expertise'], $values['teach_areas'],
                $values['interests'], $values['career_interest'], $values['goals'],
                $values['mentorship_bio'], $values['availability'], $visible, $available,
            ]);
            audit($memberId, 'mentorship_profile_updated', 'participation=' . $participation);
            flash('success', 'Your mentorship profile was saved.');
            mentorship_redirect('overview');
        }

        if ($action === 'request') {
            $recipientId = filter_var($_POST['recipient_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $message = trim((string) ($_POST['message'] ?? ''));
            $interests = trim((string) ($_POST['interests'] ?? ''));
            if (!$recipientId || (int) $recipientId === $memberId
                || preg_match('/^.{10,2000}$/us', $message) !== 1 || strlen($interests) > 500
                || preg_match('//u', $interests) !== 1) {
                throw new InvalidArgumentException('Enter a message of 10–2000 characters and valid mentorship interests.');
            }
            $stmt = db()->prepare(
                'SELECT p.offering_mentorship,p.seeking_mentorship,p.visible,p.is_available
                 FROM mentorship_profiles p WHERE p.user_id=?'
            );
            $stmt->execute([$memberId]);
            $mine = $stmt->fetch();
            $stmt->execute([(int) $recipientId]);
            $target = $stmt->fetch();
            $compatible = $mine && $target && (bool) $mine['visible'] && (bool) $mine['is_available']
                && (bool) $target['visible'] && (bool) $target['is_available']
                && (((bool) $mine['seeking_mentorship'] && (bool) $target['offering_mentorship'])
                    || ((bool) $mine['offering_mentorship'] && (bool) $target['seeking_mentorship']));
            if (!$compatible || !mentorship_active_member((int) $recipientId)) {
                throw new InvalidArgumentException('That member is not an available, compatible mentorship connection.');
            }
            $stmt = db()->prepare(
                "SELECT id FROM mentorship_requests
                 WHERE status='pending' AND ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?))
                 LIMIT 1"
            );
            $stmt->execute([$memberId, (int) $recipientId, (int) $recipientId, $memberId]);
            if ($stmt->fetchColumn()) {
                throw new InvalidArgumentException('A pending mentorship request already exists between these members.');
            }
            try {
                db()->beginTransaction();
                $stmt = db()->prepare(
                    'INSERT INTO mentorship_requests (sender_id,recipient_id,message,interests)
                     VALUES (?,?,?,?)'
                );
                $stmt->execute([$memberId, (int) $recipientId, $message, $interests !== '' ? $interests : null]);
                $requestId = (int) db()->lastInsertId();
                mentorship_notify(
                    (int) $recipientId,
                    'mentorship-request-' . $requestId . '-pending',
                    'New mentorship request',
                    (string) $user['full_name'] . ' sent you a mentorship request.',
                    'incoming'
                );
                db()->commit();
            } catch (PDOException $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                if ($exception->getCode() === '23000') {
                    throw new InvalidArgumentException('A pending mentorship request already exists between these members.');
                }
                throw $exception;
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
            audit($memberId, 'mentorship_request_sent', 'request_id=' . $requestId);
            flash('success', 'Your mentorship request was sent.');
            mentorship_redirect('sent');
        }

        if (in_array($action, ['accept', 'decline', 'cancel', 'end'], true)) {
            $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$requestId) {
                throw new InvalidArgumentException('Choose a valid mentorship request.');
            }
            db()->beginTransaction();
            try {
                $stmt = db()->prepare('SELECT * FROM mentorship_requests WHERE id=? FOR UPDATE');
                $stmt->execute([(int) $requestId]);
                $request = $stmt->fetch();
                if (!$request) {
                    throw new InvalidArgumentException('That mentorship request does not exist.');
                }
                $otherId = null;
                $nextStatus = '';
                if (in_array($action, ['accept', 'decline'], true)) {
                    if ((int) $request['recipient_id'] !== $memberId || $request['status'] !== 'pending') {
                        throw new InvalidArgumentException('Only the recipient can respond to a pending request.');
                    }
                    if ($action === 'accept' && (!mentorship_active_member((int) $request['sender_id'])
                        || !mentorship_active_member((int) $request['recipient_id']))) {
                        throw new InvalidArgumentException('Both members must remain active to establish a mentorship connection.');
                    }
                    $nextStatus = $action === 'accept' ? 'accepted' : 'declined';
                    $otherId = (int) $request['sender_id'];
                } elseif ($action === 'cancel') {
                    if ((int) $request['sender_id'] !== $memberId || $request['status'] !== 'pending') {
                        throw new InvalidArgumentException('Only the sender can cancel a pending request.');
                    }
                    $nextStatus = 'cancelled';
                    $otherId = (int) $request['recipient_id'];
                } else {
                    if (!in_array($memberId, [(int) $request['sender_id'], (int) $request['recipient_id']], true)
                        || $request['status'] !== 'accepted') {
                        throw new InvalidArgumentException('Only a member in an active mentorship connection can end it.');
                    }
                    $nextStatus = 'ended';
                    $otherId = $memberId === (int) $request['sender_id']
                        ? (int) $request['recipient_id']
                        : (int) $request['sender_id'];
                }
                $stmt = db()->prepare('UPDATE mentorship_requests SET status=?,responded_at=UTC_TIMESTAMP() WHERE id=?');
                $stmt->execute([$nextStatus, (int) $requestId]);
                $actor = (string) $user['full_name'];
                $eventTitle = match ($nextStatus) {
                    'accepted' => 'Mentorship request accepted',
                    'declined' => 'Mentorship request declined',
                    'cancelled' => 'Mentorship request cancelled',
                    default => 'Mentorship connection ended',
                };
                $eventBody = match ($nextStatus) {
                    'accepted' => $actor . ' accepted your mentorship request.',
                    'declined' => $actor . ' declined your mentorship request.',
                    'cancelled' => $actor . ' cancelled a mentorship request.',
                    default => $actor . ' ended your mentorship connection.',
                };
                mentorship_notify((int) $otherId, 'mentorship-request-' . $requestId . '-' . $nextStatus,
                    $eventTitle, $eventBody, $nextStatus === 'accepted' || $nextStatus === 'ended' ? 'connections' : 'sent');
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
            audit($memberId, 'mentorship_request_' . $nextStatus, 'request_id=' . (int) $requestId);
            flash('success', 'Mentorship request updated.');
            mentorship_redirect($nextStatus === 'accepted' || $nextStatus === 'ended' ? 'connections' : 'sent');
        }

        if ($action === 'open_chat') {
            $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$requestId || !user_has_permission($user, 'messages.use')) {
                throw new InvalidArgumentException('Private messaging is unavailable for this account.');
            }
            $stmt = db()->prepare(
                "SELECT sender_id,recipient_id FROM mentorship_requests
                 WHERE id=? AND status='accepted' AND (sender_id=? OR recipient_id=?)"
            );
            $stmt->execute([(int) $requestId, $memberId, $memberId]);
            $connection = $stmt->fetch();
            if (!$connection) {
                throw new InvalidArgumentException('That active mentorship connection is unavailable.');
            }
            $memberA = min((int) $connection['sender_id'], (int) $connection['recipient_id']);
            $memberB = max((int) $connection['sender_id'], (int) $connection['recipient_id']);
            $stmt = db()->prepare(
                'INSERT INTO member_conversations (member_a,member_b,created_at) VALUES (?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
            );
            $stmt->execute([$memberA, $memberB]);
            $conversationId = (int) db()->lastInsertId();
            if ($conversationId < 1) {
                $stmt = db()->prepare('SELECT id FROM member_conversations WHERE member_a=? AND member_b=?');
                $stmt->execute([$memberA, $memberB]);
                $conversationId = (int) $stmt->fetchColumn();
            }
            redirect('messages.php?conversation=' . $conversationId);
        }

        throw new InvalidArgumentException('Unknown mentorship action.');
    } catch (InvalidArgumentException $exception) {
        flash('error', $exception->getMessage());
        mentorship_redirect();
    }
}

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'expertise' => trim((string) ($_GET['expertise'] ?? '')),
    'profession' => trim((string) ($_GET['profession'] ?? '')),
    'year_group' => trim((string) ($_GET['year_group'] ?? '')),
    'interest' => (string) ($_GET['interest'] ?? ''),
];
if (!in_array($filters['interest'], ['', 'mentor', 'seeker', 'both'], true)) {
    $filters['interest'] = '';
}
foreach ($filters as $key => $value) {
    if (strlen($value) > 100) {
        $filters[$key] = substr($value, 0, 100);
    }
}
$sql = "SELECT p.user_id,p.offering_mentorship,p.seeking_mentorship,p.expertise,p.teach_areas,
               p.interests,p.career_interest,p.goals,p.mentorship_bio,p.availability,p.is_available,p.updated_at,
               u.full_name,mp.graduation_year,mp.class_group,mp.occupation,mp.directory_visible,
               mp.profile_picture_path
        FROM mentorship_profiles p
        JOIN users u ON u.id=p.user_id AND u.status='active' AND u.email_verified_at IS NOT NULL
        JOIN member_profiles mp ON mp.user_id=p.user_id AND mp.membership_status='active'
        WHERE p.visible=1 AND p.user_id<>? AND (p.offering_mentorship=1 OR p.seeking_mentorship=1)";
$parameters = [$memberId];
if ($filters['q'] !== '') {
    $sql .= ' AND (u.full_name LIKE ? OR mp.class_group LIKE ? OR mp.occupation LIKE ?
                OR p.expertise LIKE ? OR p.teach_areas LIKE ? OR p.interests LIKE ?
                OR p.career_interest LIKE ? OR p.goals LIKE ?)';
    $like = '%' . $filters['q'] . '%';
    array_push($parameters, $like, $like, $like, $like, $like, $like, $like, $like);
}
if ($filters['expertise'] !== '') {
    $sql .= ' AND (p.expertise LIKE ? OR p.teach_areas LIKE ?)';
    $like = '%' . $filters['expertise'] . '%';
    array_push($parameters, $like, $like);
}
if ($filters['profession'] !== '') {
    $sql .= ' AND mp.occupation LIKE ?';
    $parameters[] = '%' . $filters['profession'] . '%';
}
if ($filters['year_group'] !== '') {
    $sql .= ' AND (CAST(mp.graduation_year AS CHAR) LIKE ? OR mp.class_group LIKE ?)';
    $like = '%' . $filters['year_group'] . '%';
    array_push($parameters, $like, $like);
}
if ($filters['interest'] === 'mentor') {
    $sql .= ' AND p.offering_mentorship=1';
} elseif ($filters['interest'] === 'seeker') {
    $sql .= ' AND p.seeking_mentorship=1';
} elseif ($filters['interest'] === 'both') {
    $sql .= ' AND p.offering_mentorship=1 AND p.seeking_mentorship=1';
}
$sql .= ' ORDER BY p.is_available DESC,u.full_name LIMIT 300';
$stmt = db()->prepare($sql);
$stmt->execute($parameters);
$directoryMembers = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT r.*,s.full_name AS sender_name,rec.full_name AS recipient_name
     FROM mentorship_requests r
     JOIN users s ON s.id=r.sender_id
     JOIN users rec ON rec.id=r.recipient_id
     WHERE r.sender_id=? OR r.recipient_id=?
     ORDER BY r.created_at DESC,r.id DESC LIMIT 200"
);
$stmt->execute([$memberId, $memberId]);
$myRequests = $stmt->fetchAll();
$pendingOtherByMember = [];
$acceptedByMember = [];
foreach ($myRequests as $request) {
    $other = (int) $request['sender_id'] === $memberId
        ? (int) $request['recipient_id'] : (int) $request['sender_id'];
    if ($request['status'] === 'pending') {
        $pendingOtherByMember[$other] = $request;
    } elseif ($request['status'] === 'accepted') {
        $acceptedByMember[$other] = $request;
    }
}

$keywords = static function (array $values): array {
    $parts = [];
    foreach ($values as $value) {
        foreach (preg_split('/[,;|\\n]+/u', (string) $value) ?: [] as $part) {
            $part = mb_strtolower(trim($part), 'UTF-8');
            if (mb_strlen($part, 'UTF-8') >= 3) {
                $parts[$part] = true;
            }
        }
    }
    return array_keys($parts);
};
$myOfferTerms = $keywords([$myProfile['expertise'] ?? '', $myProfile['teach_areas'] ?? '', $myProfile['career_interest'] ?? '']);
$myNeedTerms = $keywords([$myProfile['interests'] ?? '', $myProfile['career_interest'] ?? '', $myProfile['goals'] ?? '']);
$matches = [];
foreach ($directoryMembers as $candidate) {
    $compatible = !empty($candidate['is_available'])
        && ((!empty($myProfile['seeking_mentorship']) && !empty($candidate['offering_mentorship']))
            || (!empty($myProfile['offering_mentorship']) && !empty($candidate['seeking_mentorship'])));
    if (!$compatible || isset($pendingOtherByMember[(int) $candidate['user_id']])
        || isset($acceptedByMember[(int) $candidate['user_id']])) {
        continue;
    }
    $candidateTerms = $keywords([
        !empty($myProfile['seeking_mentorship']) ? ($candidate['expertise'] ?? '') : ($candidate['interests'] ?? ''),
        $candidate['teach_areas'] ?? '',
        $candidate['career_interest'] ?? '',
        $candidate['goals'] ?? '',
    ]);
    $score = count(array_intersect($myProfile['seeking_mentorship'] ? $myNeedTerms : $myOfferTerms, $candidateTerms));
    $candidate['match_score'] = $score;
    $matches[] = $candidate;
}
usort($matches, static fn(array $a, array $b): int => $b['match_score'] <=> $a['match_score']);
$matches = array_slice($matches, 0, 6);
$myParticipation = !empty($myProfile['offering_mentorship'])
    ? (!empty($myProfile['seeking_mentorship']) ? 'both' : 'mentor')
    : (!empty($myProfile['seeking_mentorship']) ? 'seeker' : 'none');
$directoryByMember = [];
foreach ($directoryMembers as $directoryMember) {
    $directoryByMember[(int) $directoryMember['user_id']] = $directoryMember;
}
$canViewDirectoryPhotos = user_has_permission($user, 'directory.view')
    || user_has_permission($user, 'mentorship.view');

render_header('Mentorship');
page_title('MEMBER SERVICES', 'Mentorship', 'Discover members, request a connection, and build a consent-based mentorship relationship.');
?>
<nav class="panel mentorship-nav" aria-label="Mentorship sections">
    <a href="#overview">Overview</a><a href="#mentors">Available mentors</a><a href="#seekers">Members seeking mentors</a>
    <a href="#matches">Suggested matches</a><a href="#incoming">Incoming requests<?php
        $incomingCount = count(array_filter($myRequests, static fn(array $r): bool => (int) $r['recipient_id'] === $memberId && $r['status'] === 'pending'));
        if ($incomingCount): ?> <span class="notification-badge"><?= $incomingCount ?></span><?php endif; ?></a>
    <a href="#sent">Sent requests</a><a href="#connections">My connections</a>
</nav>
<section class="panel" id="overview">
    <h2>Mentorship overview</h2>
    <p class="muted">Choose whether you can mentor, need a mentor, both, or are not participating. A connection becomes active only after the recipient accepts. Your messages remain in the existing private chat if you end a connection.</p>
    <form method="post" class="module-form mentorship-profile-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_profile">
        <fieldset class="span-all"><legend>Participation</legend>
            <?php foreach (['mentor' => 'I can mentor', 'seeker' => 'I need a mentor', 'both' => 'Both: I can mentor and I need a mentor', 'none' => 'Not currently participating'] as $value => $label): ?>
                <label class="check-label"><input type="radio" name="participation" value="<?= e($value) ?>"<?= $myParticipation === $value ? ' checked' : '' ?>> <?= e($label) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <label>Skills and expertise you can offer<input name="expertise" maxlength="500" value="<?= e($myProfile['expertise'] ?? '') ?>"></label>
        <label>Areas you can teach<input name="teach_areas" maxlength="500" value="<?= e($myProfile['teach_areas'] ?? '') ?>"></label>
        <label>Skills and topics you want to learn<input name="interests" maxlength="500" value="<?= e($myProfile['interests'] ?? '') ?>"></label>
        <label>Career interests / industry<input name="career_interest" maxlength="500" value="<?= e($myProfile['career_interest'] ?? '') ?>"></label>
        <label class="span-all">Mentorship goals<textarea name="goals" rows="2" maxlength="1000"><?= e($myProfile['goals'] ?? '') ?></textarea></label>
        <label class="span-all">Short biography and relevant experience<textarea name="mentorship_bio" rows="3" maxlength="1000"><?= e($myProfile['mentorship_bio'] ?? '') ?></textarea></label>
        <label>Availability details<input name="availability" maxlength="300" value="<?= e($myProfile['availability'] ?? '') ?>" placeholder="For example: weekday evenings"></label>
        <label class="check-label"><input type="checkbox" name="visible" value="1"<?= !empty($myProfile['visible']) ? ' checked' : '' ?>> Show my mentorship profile in discovery directories</label>
        <label class="check-label"><input type="checkbox" name="is_available" value="1"<?= !empty($myProfile['is_available']) ? ' checked' : '' ?>> I am currently available for mentorship connections</label>
        <p class="muted span-all">Opting out or hiding your profile removes it from discovery. Only details entered here and your name, year group, and profession are shown to participating members.</p>
        <button class="button button-primary" type="submit">Save mentorship profile</button>
    </form>
</section>

<section class="panel mentorship-filter-panel">
    <h2>Find members</h2>
    <form method="get" action="community.php" class="module-form">
        <input type="hidden" name="module" value="mentorship">
        <label>Search members<input type="search" name="q" maxlength="100" value="<?= e($filters['q']) ?>" placeholder="Name, skills, career, or goals"></label>
        <label>Expertise<input name="expertise" maxlength="100" value="<?= e($filters['expertise']) ?>"></label>
        <label>Profession / industry<input name="profession" maxlength="100" value="<?= e($filters['profession']) ?>"></label>
        <label>Graduation year / group<input name="year_group" maxlength="100" value="<?= e($filters['year_group']) ?>"></label>
        <label>Mentorship interest<select name="interest"><option value="">All participating members</option><option value="mentor"<?= $filters['interest'] === 'mentor' ? ' selected' : '' ?>>Can mentor</option><option value="seeker"<?= $filters['interest'] === 'seeker' ? ' selected' : '' ?>>Needs a mentor</option><option value="both"<?= $filters['interest'] === 'both' ? ' selected' : '' ?>>Both</option></select></label>
        <button class="button button-primary" type="submit">Search</button><a class="button button-quiet" href="community.php?module=mentorship">Clear filters</a>
    </form>
</section>

<?php
$renderMemberCard = static function (array $person, string $section) use ($memberId, $myProfile, $pendingOtherByMember, $acceptedByMember, $canViewDirectoryPhotos): void {
    $id = (int) $person['user_id'];
    $canRequest = $id !== $memberId && !empty($myProfile['visible']) && !empty($myProfile['is_available'])
        && !isset($pendingOtherByMember[$id]) && !isset($acceptedByMember[$id])
        && (((!empty($myProfile['seeking_mentorship'])) && !empty($person['offering_mentorship']) && !empty($person['is_available']))
            || ((!empty($myProfile['offering_mentorship'])) && !empty($person['seeking_mentorship'])));
    ?>
    <article class="panel association-card mentorship-card" id="member-<?= $id ?>">
        <div class="association-card-heading">
            <?php if ($canViewDirectoryPhotos && !empty($person['profile_picture_path'])): ?><img class="chat-avatar" src="profile_picture.php?user_id=<?= $id ?>" alt=""><?php endif; ?>
            <h3><?= e($person['full_name']) ?></h3>
            <span class="status-pill"><?= !empty($person['offering_mentorship']) && !empty($person['seeking_mentorship']) ? 'Mentor and seeking' : (!empty($person['offering_mentorship']) ? 'Can mentor' : 'Seeking mentor') ?></span>
        </div>
        <p><?= e($person['graduation_year'] ? (string) $person['graduation_year'] : ($person['class_group'] ?: 'Year group not provided')) ?>
            <?= !empty($person['occupation']) ? ' · ' . e($person['occupation']) : '' ?></p>
        <?php if (!empty($person['expertise'])): ?><p><strong>Skills / expertise:</strong> <?= e($person['expertise']) ?></p><?php endif; ?>
        <?php if (!empty($person['teach_areas'])): ?><p><strong>Can teach:</strong> <?= e($person['teach_areas']) ?></p><?php endif; ?>
        <?php if (!empty($person['interests'])): ?><p><strong>Wants to learn:</strong> <?= e($person['interests']) ?></p><?php endif; ?>
        <?php if (!empty($person['career_interest'])): ?><p><strong>Career interest:</strong> <?= e($person['career_interest']) ?></p><?php endif; ?>
        <?php if (!empty($person['goals'])): ?><p><strong>Goals:</strong> <?= e($person['goals']) ?></p><?php endif; ?>
        <?php if (!empty($person['mentorship_bio'])): ?><p><?= nl2br(e($person['mentorship_bio'])) ?></p><?php endif; ?>
        <p class="muted"><?= !empty($person['is_available']) ? 'Currently available' : 'Temporarily unavailable' ?><?= !empty($person['availability']) ? ' · ' . e($person['availability']) : '' ?></p>
        <?php if (isset($person['match_score'])): ?><span class="status-pill"><?= (int) $person['match_score'] ?> matching topic(s)</span><?php endif; ?>
        <?php if ($canRequest && !empty($person['is_available'])): ?>
            <details><summary>Request mentorship</summary>
                <form method="post" class="form-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="request"><input type="hidden" name="recipient_id" value="<?= $id ?>">
                    <label>Why would you like to connect?<textarea name="message" rows="3" minlength="10" maxlength="2000" required></textarea></label>
                    <label>Relevant skills or interests<input name="interests" maxlength="500"></label>
                    <button class="button button-primary button-small" type="submit">Send request</button>
                </form>
            </details>
        <?php elseif (isset($acceptedByMember[$id])): ?><p class="status-pill status-good">Connected</p>
        <?php elseif (isset($pendingOtherByMember[$id])): ?><p class="muted">A request between you is pending.</p><?php endif; ?>
    </article>
    <?php
};
?>
<section class="mentorship-section" id="mentors"><h2>Available Mentors</h2><div class="association-card-grid">
    <?php $count = 0; foreach ($directoryMembers as $person): if (empty($person['offering_mentorship'])) continue; $count++; $renderMemberCard($person, 'mentors'); endforeach; ?>
    <?php if (!$count): ?><article class="panel"><p class="muted">No mentors match these filters. Adjust your search or check back later.</p></article><?php endif; ?>
</div></section>
<section class="mentorship-section" id="seekers"><h2>Members Seeking Mentors</h2><div class="association-card-grid">
    <?php $count = 0; foreach ($directoryMembers as $person): if (empty($person['seeking_mentorship'])) continue; $count++; $renderMemberCard($person, 'seekers'); endforeach; ?>
    <?php if (!$count): ?><article class="panel"><p class="muted">No members seeking mentors match these filters.</p></article><?php endif; ?>
</div></section>
<section class="mentorship-section" id="matches"><h2>Suggested Matches</h2><p class="muted">Suggestions are based on compatible roles and overlapping skills, career interests, and goals. No relationship is created until a request is accepted.</p><div class="association-card-grid">
    <?php foreach ($matches as $person): $renderMemberCard($person, 'matches'); endforeach; ?>
    <?php if (!$matches): ?><article class="panel"><p class="muted">Complete your profile and choose a mentorship role to see suggested matches.</p></article><?php endif; ?>
</div></section>

<?php
$incomingRequests = array_values(array_filter($myRequests, static fn(array $r): bool => (int) $r['recipient_id'] === $memberId && $r['status'] === 'pending'));
$sentRequests = array_values(array_filter($myRequests, static fn(array $r): bool => (int) $r['sender_id'] === $memberId));
$connections = array_values(array_filter($myRequests, static fn(array $r): bool => $r['status'] === 'accepted'));
$renderRequestCard = static function (array $request, string $role): void {
    $incoming = $role === 'incoming';
    $otherName = $incoming ? $request['sender_name'] : $request['recipient_name'];
    ?>
    <article class="panel mentorship-request">
        <div class="association-card-heading"><h3><?= e($otherName) ?></h3><span class="status-pill"><?= e(ucfirst($request['status'])) ?></span></div>
        <p><?= nl2br(e($request['message'])) ?></p>
        <?php if (!empty($request['interests'])): ?><p><strong>Interests:</strong> <?= e($request['interests']) ?></p><?php endif; ?>
        <p class="muted"><?= e($request['created_at']) ?> UTC</p>
        <?php if ($request['status'] === 'pending' && $incoming): ?>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>"><button class="button button-primary button-small" name="action" value="accept">Accept</button><button class="button button-quiet button-small" name="action" value="decline">Decline</button></form>
        <?php elseif ($request['status'] === 'pending'): ?>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>"><button class="button button-quiet button-small" name="action" value="cancel">Cancel request</button></form>
        <?php endif; ?>
    </article>
    <?php
};
?>
<section class="mentorship-section" id="incoming"><h2>Incoming Requests</h2><div class="association-card-grid">
    <?php foreach ($incomingRequests as $request): $renderRequestCard($request, 'incoming'); endforeach; ?>
    <?php if (!$incomingRequests): ?><article class="panel"><p class="muted">You have no pending incoming requests.</p></article><?php endif; ?>
</div></section>
<section class="mentorship-section" id="sent"><h2>Sent Requests</h2><div class="association-card-grid">
    <?php foreach ($sentRequests as $request): $renderRequestCard($request, 'sent'); endforeach; ?>
    <?php if (!$sentRequests): ?><article class="panel"><p class="muted">You have not sent a mentorship request.</p></article><?php endif; ?>
</div></section>
<section class="mentorship-section" id="connections"><h2>My Mentorship Connections</h2><div class="association-card-grid">
    <?php foreach ($connections as $connection): $otherId = (int) $connection['sender_id'] === $memberId ? (int) $connection['recipient_id'] : (int) $connection['sender_id']; $otherName = (int) $connection['sender_id'] === $memberId ? $connection['recipient_name'] : $connection['sender_name']; ?>
        <article class="panel mentorship-request"><div class="association-card-heading"><h3><?= e($otherName) ?></h3><span class="status-pill status-good">Connected</span></div>
            <p class="muted">Connected <?= e($connection['responded_at'] ?? $connection['created_at']) ?> UTC</p>
                <?php if (isset($directoryByMember[$otherId])): ?><a class="text-link" href="#member-<?= $otherId ?>">View mentorship profile</a><?php endif; ?>
            <?php if (user_has_permission($user, 'messages.use')): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) $connection['id'] ?>"><button class="button button-primary button-small" name="action" value="open_chat">Open private conversation</button></form><?php endif; ?>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) $connection['id'] ?>"><button class="button button-quiet button-small" name="action" value="end">End connection</button></form>
        </article>
    <?php endforeach; ?>
    <?php if (!$connections): ?><article class="panel"><p class="muted">You do not have accepted mentorship connections yet.</p></article><?php endif; ?>
</div></section>
<?php render_footer(); ?>
