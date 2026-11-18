<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$viewer = require_permission('directory.view');

$query = trim((string) ($_GET['q'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'name');
$direction = (string) ($_GET['dir'] ?? 'asc');
$page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$pageSize = 20;
$allowedSorts = [
    'name' => ['column' => 'u.full_name', 'label' => 'Name'],
    'membership' => ['column' => 'mp.membership_number', 'label' => 'Membership #'],
    'occupation' => ['column' => 'mp.occupation', 'label' => 'Occupation'],
    'location' => ['column' => 'mp.location', 'label' => 'Location'],
    'joined' => ['column' => 'u.created_at', 'label' => 'Joined'],
];
if (!isset($allowedSorts[$sort])) {
    $sort = 'name';
}
if (!in_array($direction, ['asc', 'desc'], true)) {
    $direction = 'asc';
}
$conditions = [
    "u.status = 'active'",
    'u.email_verified_at IS NOT NULL',
    "mp.membership_status = 'active'",
];
$parameters = [];
if ($query !== '') {
    $conditions[] = '(u.full_name LIKE ? OR mp.membership_number LIKE ?
        OR (mp.directory_visible = 1 AND (
            mp.occupation LIKE ? OR mp.employer LIKE ? OR mp.location LIKE ?
        )))';
    $search = '%' . $query . '%';
    array_push($parameters, $search, $search, $search, $search, $search);
}
$where = implode(' AND ', $conditions);
$stmt = db()->prepare(
    "SELECT COUNT(*) FROM users u JOIN member_profiles mp ON mp.user_id = u.id WHERE $where"
);
$stmt->execute($parameters);
$total = (int) $stmt->fetchColumn();
$stmt = db()->query(
    "SELECT COUNT(*)
     FROM users u JOIN member_profiles mp ON mp.user_id = u.id
     WHERE u.status = 'active'
       AND u.email_verified_at IS NOT NULL
       AND mp.membership_status = 'active'"
);
$activeMemberTotal = (int) $stmt->fetchColumn();
$pages = max(1, (int) ceil($total / $pageSize));
$page = min($page, $pages);
$offset = ($page - 1) * $pageSize;
$orderColumn = $allowedSorts[$sort]['column'];
$orderDirection = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
$stmt = db()->prepare(
    "SELECT u.id AS user_id, u.full_name, mp.phone, mp.phone_visible,
            mp.directory_visible, mp.membership_number,
            mp.profile_picture_path, mp.occupation,
            mp.employer, mp.location, mp.biography, u.created_at
     FROM users u JOIN member_profiles mp ON mp.user_id = u.id
     WHERE $where
     ORDER BY $orderColumn $orderDirection, u.full_name ASC
     LIMIT $pageSize OFFSET $offset"
);
$stmt->execute($parameters);
$members = $stmt->fetchAll();

render_header('Member directory');
page_title('ASSOCIATION', 'Member directory', 'All active, verified members appear by name and membership number. Additional profile details are shown only when a member opts in.');
?>
<form method="get" action="directory.php" class="directory-filters">
    <label for="directory-search">Search members
        <input id="directory-search" name="q" type="search" value="<?= e($query) ?>" maxlength="100" placeholder="Name, membership number, or profession">
    </label>
    <label for="directory-sort">Sort by
        <select id="directory-sort" name="sort">
            <?php foreach ($allowedSorts as $value => $meta): ?>
                <option value="<?= e($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label for="directory-dir">Direction
        <select id="directory-dir" name="dir">
            <option value="asc" <?= $direction === 'asc' ? 'selected' : '' ?>>Ascending</option>
            <option value="desc" <?= $direction === 'desc' ? 'selected' : '' ?>>Descending</option>
        </select>
    </label>
    <button class="button button-primary" type="submit">Apply</button>
    <?php if ($query !== '' || $sort !== 'name' || $direction !== 'asc'): ?><a class="button button-quiet" href="directory.php">Clear</a><?php endif; ?>
</form>
<section class="directory-results" aria-live="polite">
    <p class="muted"><?= $activeMemberTotal ?> active member<?= $activeMemberTotal === 1 ? '' : 's' ?> in the association<?= $query !== '' ? ' · ' . $total . ' matching this search' : '' ?> · sorted by <?= e($allowedSorts[$sort]['label']) ?> (<?= e($direction) ?>) · page <?= $page ?> of <?= $pages ?></p>
    <?php if (!$members): ?>
        <article class="panel"><h2>No members found</h2><p class="muted">Try a different name, membership number, or opted-in professional detail.</p></article>
    <?php else: ?>
        <div class="directory-grid">
        <?php foreach ($members as $member): ?>
            <article class="panel member-card">
                <div class="member-card-heading"><?php if ($member['directory_visible'] && $member['profile_picture_path']): ?><img class="profile-avatar directory-avatar" src="profile_picture.php?user_id=<?= (int) $member['user_id'] ?>" alt=""><?php else: ?><span class="profile-avatar" aria-hidden="true"><?= e(strtoupper(substr((string) $member['full_name'], 0, 1))) ?></span><?php endif; ?>
                    <div><h2><?= e($member['full_name']) ?></h2><p class="muted"><?= e($member['membership_number'] ?? 'Member') ?></p></div>
                </div>
                <?php if ($member['directory_visible']): ?>
                <dl>
                    <?php foreach (['occupation' => 'Occupation', 'employer' => 'Employer', 'location' => 'Location'] as $field => $label): ?>
                        <?php if (!empty($member[$field])): ?><div><dt><?= e($label) ?></dt><dd><?= e((string) $member[$field]) ?></dd></div><?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($member['phone_visible'] && $member['phone']): ?><div><dt>Phone</dt><dd><?= e((string) $member['phone']) ?></dd></div><?php endif; ?>
                </dl>
                <?php if (!empty($member['biography'])): ?><p class="member-biography"><?= e((string) $member['biography']) ?></p><?php endif; ?>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
        <?php if ($pages > 1): ?><nav class="directory-pagination" aria-label="Directory pages">
            <?php if ($page > 1): ?><a class="button button-quiet button-small" href="directory.php?q=<?= rawurlencode($query) ?>&amp;sort=<?= rawurlencode($sort) ?>&amp;dir=<?= rawurlencode($direction) ?>&amp;page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
            <span>Page <?= $page ?> of <?= $pages ?></span>
            <?php if ($page < $pages): ?><a class="button button-quiet button-small" href="directory.php?q=<?= rawurlencode($query) ?>&amp;sort=<?= rawurlencode($sort) ?>&amp;dir=<?= rawurlencode($direction) ?>&amp;page=<?= $page + 1 ?>">Next</a><?php endif; ?>
        </nav><?php endif; ?>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
