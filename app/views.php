<?php
declare(strict_types=1);

function application_name(): string
{
    global $config;
    $name = trim((string) ($config['app_name'] ?? ''));
    return $name !== '' ? $name : 'CONNEZER EDUTECH SYSTEMS';
}

function school_identity_markup(array $school, bool $showShortName = true, string $size = ''): string
{
    $schoolId = (int) ($school['id'] ?? 0);
    $name = trim((string) ($school['name'] ?? 'School'));
    $logoPath = (string) ($school['logo_path'] ?? '');
    $class = 'school-identity' . ($size !== '' ? ' school-identity-' . e($size) : '');
    $html = '<span class="' . $class . '">';
    if ($schoolId > 0 && preg_match('#^school-logos/[a-f0-9]{48}\.(?:jpg|png|webp)$#', $logoPath) === 1) {
        $logoUrl = 'school_logo.php?school_id=' . $schoolId;
        $html .= '<img class="school-identity-logo" src="' . e($logoUrl) . '" alt="">';
    } else {
        $initials = strtoupper(substr((string) ($showShortName && !empty($school['short_name']) ? $school['short_name'] : $name), 0, 2));
        $html .= '<span class="school-identity-placeholder" aria-hidden="true">' . e($initials !== '' ? $initials : 'S') . '</span>';
    }
    $html .= '<strong class="school-identity-name">' . e($name) . '</strong>';
    if ($showShortName && !empty($school['short_name'])) {
        $html .= '<small class="school-identity-short">' . e((string) $school['short_name']) . '</small>';
    }
    $html .= '</span>';
    return $html;
}

function platform_branding(string $class, ?string $href = null): void
{
    $tag = $href === null ? 'div' : 'a';
    $attributes = $href === null ? '' : ' href="' . e($href) . '"';
    echo '<' . $tag . ' class="application-branding ' . e($class) . '"' . $attributes . '>';
    echo '<img class="application-branding-logo" src="assets/images/super-admin-logo.jpeg" alt="">';
    echo '<strong class="application-branding-acronym">CONNEZER EDUTECH SYSTEMS</strong>';
    echo '<span class="application-branding-name">SMART SCHOOL MANAGEMENT SYSTEM</span>';
    echo '<span class="application-branding-tagline">Smart Technology. Better Education.</span>';
    echo '</' . $tag . '>';
}

function profile_avatar_markup(array $user, string $class = ''): string
{
    $name = (string) ($user['full_name'] ?? '');
    $initial = strtoupper(substr($name, 0, 1));
    $avatarClass = 'profile-avatar' . ($class !== '' ? ' ' . e($class) : '');
    $path = (string) ($user['profile_picture_path'] ?? '');
    if (preg_match('#^profile-pictures/[a-f0-9]{48}\.(?:jpg|png|webp)$#', $path) === 1) {
        $version = substr(hash('sha256', $path), 0, 12);
        return '<img class="' . $avatarClass . ' profile-avatar-image" src="profile_picture.php?v=' . $version . '" alt="">';
    }
    return '<span class="' . $avatarClass . '" aria-hidden="true">' . e($initial !== '' ? $initial : '?') . '</span>';
}

function profile_avatar_button(array $user, string $class = ''): string
{
    $buttonClass = 'profile-avatar-trigger' . ($class !== '' ? ' ' . e($class) : '');
    return '<button class="' . $buttonClass . '" type="button" data-open-profile-photo-editor aria-label="Edit profile photo" title="Edit profile photo">'
        . profile_avatar_markup($user)
        . '</button>';
}

function sidebar_icon(string $name): string
{
    static $paths = [
        'overview' => 'M3 10.5 12 3l9 7.5M5 9v11h14V9M9 20v-6h6v6',
        'members' => 'M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
        'documents' => 'M6 3h9l5 5v13H6zM14 3v6h6M9 13h8M9 17h8',
        'meetings' => 'M3 5h18v14H3zM8 3v4M16 3v4M3 9h18M7 13h4M7 16h7',
        'reports' => 'M4 20V10M10 20V4M16 20v-8M22 20H2',
        'finance' => 'M3 6h18v14H3zM3 6l2-3h14l2 3M16 13h5M16 13a2 2 0 1 0 4 0 2 2 0 0 0-4 0',
        'announcements' => 'M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8z',
        'settings' => 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-1.7 2.94-.08-.02a1.7 1.7 0 0 0-1.74.72l-.04.07h-3.4l-.04-.07a1.7 1.7 0 0 0-1.74-.72l-.08.02-1.7-2.94.06-.06A1.7 1.7 0 0 0 9.7 15l-.08-.04v-3.4l.08-.04a1.7 1.7 0 0 0-.34-1.88L9.3 9.58l1.7-2.94.08.02a1.7 1.7 0 0 0 1.74-.72l.04-.07h3.4l.04.07a1.7 1.7 0 0 0 1.74.72l.08-.02 1.7 2.94-.06.06A1.7 1.7 0 0 0 19.4 11.5l.08.04v3.4z',
        'support' => 'M4 5h16v11H8l-4 4zM8 9h8M8 12h5',
        'phone' => 'M5 3h4l2 5-3 2a16 16 0 0 0 6 6l2-3 5 2v4c0 1-1 2-2 2A17 17 0 0 1 3 5c0-1 1-2 2-2z',
        'message' => 'M20.5 11.5a8.5 8.5 0 0 1-12.6 7.4L3 20l1.2-4.7a8.5 8.5 0 1 1 16.3-3.8zM8 8.5c.3 2.7 2.7 5.1 5.4 5.4l1.2-1.2 2 .8',
        'whatsapp' => 'M20.5 11.5a8.5 8.5 0 0 1-12.6 7.4L3 20l1.2-4.7a8.5 8.5 0 1 1 16.3-3.8zM8.3 7.7c-.3.5-.6 1.1-.5 1.8.1 1.1.9 2.7 2.2 4 1.3 1.3 2.9 2.1 4 2.2.7.1 1.3-.2 1.8-.5l.6-.5-2-1.4-.8.8c-1.1-.4-2-1.3-2.4-2.4l.8-.8-1.4-2z',
        'email' => 'M3 5h18v14H3zM3 6l9 7 9-7',
        'activity' => 'M3 12h4l3-8 4 16 3-8h4',
        'security' => 'M12 3 20 7v5c0 5-3.4 8-8 10-4.6-2-8-5-8-10V7zM9 12l2 2 4-4',
        'school' => 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6M8 9h1M15 9h1',
        'admissions' => 'M12 5v14M5 12h14M4 4h16v16H4z',
        'students' => 'M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M20 8v6M17 11h6',
        'archive' => 'M4 4h16v4H4zM6 8v12h12V8M10 12h4',
        'staff' => 'M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M20 8v6M17 11h6',
        'classroom' => 'M3 4h18v13H3zM8 21h8M12 17v4M7 8h4v5H7zM14 8h3',
        'book' => 'M4 5.5A2.5 2.5 0 0 1 6.5 3H20v17H6.5A2.5 2.5 0 0 0 4 22zM4 5.5v16.5M8 7h8M8 11h8',
        'calendar' => 'M3 5h18v16H3zM7 3v4M17 3v4M3 9h18M7 13h3M7 17h3M14 13h3',
        'attendance' => 'M9 11l2 2 4-4M4 5h2M4 12h2M4 19h2M9 5h11M9 12h11M9 19h11',
        'results' => 'M4 20V10M10 20V4M16 20v-8M22 20H2',
        'timetable' => 'M3 5h18v14H3zM8 3v4M16 3v4M3 9h18M7 13h4M14 13h3M7 16h4',
        'finance' => 'M3 6h18v14H3zM3 6l2-3h14l2 3M16 13h5M16 13a2 2 0 1 0 4 0 2 2 0 0 0-4 0',
        'invoice' => 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6M9 16h3',
        'parent' => 'M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M17 4a4 4 0 0 1 0 7M20 21v-2a4 4 0 0 0-3-3.87',
        'announcement' => 'M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8z',
        'person' => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
        'receipt' => 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6M9 16h4',
        'notifications' => 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4',
        'logout' => 'M10 17l5-5-5-5M15 12H3M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6',
        'close' => 'M18 6 6 18M6 6l12 12',
        'chevron-down' => 'm6 9 6 6 6-6',
        'transfer' => 'M7 7h13l-3-3M20 7l-3 3M17 17H4l3 3M4 17l3-3',
        'person-check' => 'M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M16 11l2 2 4-4',
        'lock' => 'M5 11h14v10H5zM8 11V7a4 4 0 1 1 8 0v4M12 15v2',
        'profile' => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
    ];
    $path = $paths[$name] ?? $paths['overview'];
    return '<svg data-icon="' . e($name) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="' . $path . '"/></svg>';
}

function render_it_support_contact_section(array $contact, bool $mainHeading = false): void
{
    $headingTag = $mainHeading ? 'h1' : 'h2';
    $phoneLink = e((string) $contact['phone_link']);
    $whatsAppLink = e((string) $contact['whatsapp_link']);
    $emailLink = e((string) $contact['email_link']);
    $phone = e((string) $contact['phone_numbers'][0]);
    $email = e((string) $contact['email']);

    echo '<section class="panel it-support-contact" aria-labelledby="it-support-contact-heading">';
    echo '<' . $headingTag . ' id="it-support-contact-heading">IT Support</' . $headingTag . '>';
    echo '<p class="it-support-contact-instruction">Call or WhatsApp IT Support Manager</p>';
    echo '<div class="it-support-contact-options">';
    echo '<div class="it-support-contact-row" role="group" aria-label="Phone and WhatsApp">';
    echo '<a class="it-support-contact-link it-support-phone-number" href="' . $phoneLink . '">' . $phone . '</a>';
    echo '<a class="it-support-contact-link it-support-icon-link" href="' . $phoneLink . '" aria-label="Call IT Support at ' . $phone . '" title="Call IT Support at ' . $phone . '">' . sidebar_icon('phone') . '</a>';
    echo '<a class="it-support-contact-link it-support-icon-link it-support-whatsapp" href="' . $whatsAppLink . '" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp IT Support at ' . $phone . '" title="WhatsApp IT Support at ' . $phone . '">' . sidebar_icon('whatsapp') . '</a>';
    echo '</div>';
    echo '<a class="it-support-contact-link it-support-email-link" href="' . $emailLink . '" aria-label="Email IT Support at ' . $email . '">' . sidebar_icon('email') . '<span>' . $email . '</span></a>';
    echo '</div></section>';
}

function dashboard_section_heading(string $icon, string $title): void
{
    echo '<h2 class="dashboard-section-heading"><span class="dashboard-section-icon">' . sidebar_icon($icon) . '</span><span>' . e($title) . '</span></h2>';
}

function render_dashboard_read_more(string $content, int $previewLength = 240): void
{
    $content = trim($content);
    if ($content === '') {
        return;
    }

    $previewLength = max(80, min(500, $previewLength));
    if (preg_match('/^.{0,' . $previewLength . '}/us', $content, $matches) !== 1) {
        echo '<p>' . nl2br(e($content)) . '</p>';
        return;
    }

    $preview = rtrim($matches[0]);
    $continuation = ltrim(substr($content, strlen($matches[0])));
    if ($continuation === '') {
        echo '<p>' . nl2br(e($content)) . '</p>';
        return;
    }

    echo '<div class="dashboard-read-more"><p class="dashboard-read-more-preview">'
        . nl2br(e($preview)) . '…</p><details class="dashboard-read-more-details">'
        . '<summary><span class="read-more-expand">Read more</span><span class="read-more-collapse">Read less</span></summary>'
        . '<div class="dashboard-read-more-continuation">' . nl2br(e($continuation)) . '</div>'
        . '</details></div>';
}

function render_dashboard_bar_chart(string $title, array $data, string $unit = ''): void
{
    $validData = [];
    foreach ($data as $label => $value) {
        if (!is_numeric($value)) {
            continue;
        }
        $validData[] = ['label' => (string) $label, 'value' => max(0, (float) $value)];
    }

    echo '<figure class="data-chart"><figcaption>' . e($title . ($unit !== '' ? ' (' . $unit . ')' : '')) . '</figcaption>';
    if ($validData === []) {
        echo '<p class="muted">No recorded data is available for this chart.</p></figure>';
        return;
    }

    $maximum = max(1, ...array_column($validData, 'value'));
    $description = $title . ': ' . implode('; ', array_map(
        static fn (array $item): string => $item['label'] . ' ' . rtrim(rtrim(number_format($item['value'], 2, '.', ''), '0'), '.') . ($unit !== '' ? ' ' . $unit : ''),
        $validData
    ));
    echo '<ol class="data-chart-bars" role="img" aria-label="' . e($description) . '">';
    foreach ($validData as $index => $item) {
        $displayValue = rtrim(rtrim(number_format($item['value'], 2, '.', ''), '0'), '.');
        $accessibleValue = $item['label'] . ': ' . $displayValue . ($unit !== '' ? ' ' . $unit : '');
        echo '<li><span class="data-chart-label">' . e($item['label']) . '</span>';
        echo '<progress class="data-chart-track data-chart-tone-' . ($index % 3) . '" max="'
            . e(number_format($maximum, 2, '.', '')) . '" value="'
            . e(number_format($item['value'], 2, '.', '')) . '" aria-label="'
            . e($accessibleValue) . '">' . e($displayValue) . '</progress>';
        echo '<strong class="data-chart-value">' . e($displayValue . ($unit !== '' ? ' ' . $unit : '')) . '</strong></li>';
    }
    echo '</ol></figure>';
}

function render_dashboard_line_chart(string $title, array $data, string $unit = ''): void
{
    $validData = [];
    foreach ($data as $label => $value) {
        if (is_numeric($value)) {
            $validData[] = ['label' => (string) $label, 'value' => max(0, (float) $value)];
        }
    }

    echo '<figure class="data-chart"><figcaption>' . e($title . ($unit !== '' ? ' (' . $unit . ')' : '')) . '</figcaption>';
    if ($validData === []) {
        echo '<p class="muted">No recorded data is available for this chart.</p></figure>';
        return;
    }

    $maximum = max(1, ...array_column($validData, 'value'));
    $left = 68;
    $right = 614;
    $top = 24;
    $bottom = 218;
    $lastIndex = count($validData) - 1;
    $points = [];
    foreach ($validData as $index => $item) {
        $x = $lastIndex === 0 ? ($left + $right) / 2 : $left + ($index / $lastIndex) * ($right - $left);
        $y = $bottom - ($item['value'] / $maximum) * ($bottom - $top);
        $points[] = ['x' => $x, 'y' => $y, 'label' => $item['label'], 'value' => $item['value']];
    }
    $description = $title . ': ' . implode('; ', array_map(
        static fn (array $item): string => $item['label'] . ' ' . rtrim(rtrim(number_format($item['value'], 2, '.', ''), '0'), '.') . ($unit !== '' ? ' ' . $unit : ''),
        $validData
    ));

    echo '<svg class="data-chart-line" viewBox="0 0 640 280" role="img" aria-label="' . e($description) . '" preserveAspectRatio="xMidYMid meet">';
    for ($tick = 0; $tick <= 4; $tick++) {
        $y = $bottom - ($tick / 4) * ($bottom - $top);
        $value = $maximum * $tick / 4;
        echo '<line class="data-chart-gridline" x1="' . $left . '" y1="' . number_format($y, 2, '.', '') . '" x2="' . $right . '" y2="' . number_format($y, 2, '.', '') . '"></line>';
        echo '<text class="data-chart-axis-label" x="' . ($left - 8) . '" y="' . number_format($y + 4, 2, '.', '') . '" text-anchor="end">' . e(rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.')) . '</text>';
    }
    $polyline = implode(' ', array_map(
        static fn (array $point): string => number_format($point['x'], 2, '.', '') . ',' . number_format($point['y'], 2, '.', ''),
        $points
    ));
    echo '<polyline class="data-chart-line-path" points="' . e($polyline) . '"></polyline>';
    foreach ($points as $point) {
        $labelDate = DateTimeImmutable::createFromFormat('!Y-m', $point['label']);
        $label = $labelDate ? $labelDate->format('M y') : $point['label'];
        $pointValue = rtrim(rtrim(number_format($point['value'], 2, '.', ''), '0'), '.');
        echo '<circle class="data-chart-point" cx="' . number_format($point['x'], 2, '.', '') . '" cy="' . number_format($point['y'], 2, '.', '') . '" r="5"><title>' . e($label . ': ' . $pointValue . ($unit !== '' ? ' ' . $unit : '')) . '</title></circle>';
        echo '<text class="data-chart-axis-label" x="' . number_format($point['x'], 2, '.', '') . '" y="' . ($bottom + 25) . '" text-anchor="middle">' . e($label) . '</text>';
    }
    echo '</svg></figure>';
}

function render_header(string $title, bool $showNav = true): void
{
    $user = !empty($_SESSION['user_id']) ? current_user() : null;
    $flash = take_flash();
    $isApplication = $showNav && $user !== null;
    $profilePicturePath = (string) ($user['profile_picture_path'] ?? '');
    $hasProfilePhoto = preg_match('#^profile-pictures/[a-f0-9]{48}\.(?:jpg|png|webp)$#', $profilePicturePath) === 1;
    $supportContext = $isApplication ? active_it_support_context($user) : null;
    $isPlatformSuperAdmin = $isApplication && platform_super_admin_user($user);
    $platformAdmin = $isPlatformSuperAdmin && $supportContext === null;
    $school = $isApplication && !$platformAdmin ? active_school_context() : null;
    $schoolId = $school === null ? null : (int) $school['id'];
    $showSchoolNotifications = $isApplication && $schoolId !== null && $supportContext === null;
    $schoolUnreadNotifications = $showSchoolNotifications
        ? school_notification_unread_count($schoolId, (int) $user['id'])
        : 0;
    $recentSchoolNotifications = $showSchoolNotifications
        ? school_notification_recent($schoolId, (int) $user['id'])
        : [];
    $associationUnreadNotifications = 0;
    if ($isApplication) {
        $associationUnreadStmt = db()->prepare(
            'SELECT COUNT(*) FROM association_notifications
             WHERE user_id = ? AND read_at IS NULL'
        );
        $associationUnreadStmt->execute([(int) $user['id']]);
        $associationUnreadNotifications = (int) $associationUnreadStmt->fetchColumn();
    }
    $schoolRole = $schoolId === null ? null : school_user_role_for_school((int) $user['id'], $schoolId);
    $hasStaffWorkspace = false;
    if ($schoolId !== null && $supportContext === null) {
        $staffWorkspaceStmt = db()->prepare(
            'SELECT 1 FROM school_staff staff
             JOIN users account ON account.id = staff.user_id
               AND account.status = "active" AND account.email_verified_at IS NOT NULL
             WHERE staff.school_id = ? AND staff.user_id = ? AND staff.status = "active"
               AND EXISTS (
                   SELECT 1 FROM school_memberships membership
                   WHERE membership.school_id = staff.school_id AND membership.user_id = staff.user_id
                     AND membership.status = "active"
                     AND membership.role_name NOT IN ("school_parent", "school_student")
               )
             LIMIT 1'
        );
        $staffWorkspaceStmt->execute([$schoolId, (int) $user['id']]);
        $hasStaffWorkspace = (bool) $staffWorkspaceStmt->fetchColumn();
    }
    $schoolAcademicPeriod = $schoolId === null ? null : school_active_academic_year($schoolId);
    $schoolCurrentPeriod = $schoolId === null ? null : school_academic_period_for_date($schoolId);
    $schoolCurrentTerm = $schoolCurrentPeriod['term_name'] ?? null;
    $schoolAcademicYears = $schoolId === null ? [] : school_academic_years_for_school($schoolId);
    $canSelectSchoolPeriod = $schoolId !== null && $supportContext === null
        && in_array($schoolRole, ['school_headmaster', 'school_proprietor'], true);
    $schoolPagePeriod = $schoolId === null ? null : ($canSelectSchoolPeriod
        ? school_page_academic_period($schoolId)
        : $schoolCurrentPeriod);
    $schoolAcademicTerms = $schoolId === null ? [] : school_academic_terms_for_school($schoolId);
    $teacherWorkspace = in_array($schoolRole, ['class_teacher', 'subject_teacher'], true);
    $teacherHasClass = $teacherWorkspace
        && school_teacher_has_class_assignment((int) $user['id'], (int) $schoolId);
    $teacherHasSubject = $teacherWorkspace
        && school_teacher_has_subject_assignment((int) $user['id'], (int) $schoolId);
    $roleLabels = [
        'platform_super_admin' => 'Platform Super Admin',
        'school_proprietor' => 'Proprietor',
        'school_headmaster' => 'Headmaster',
        'school_assistant_headmaster' => 'Assistant Headmaster',
        'school_secretary' => 'Secretary / Administrator',
        'class_teacher' => 'Class Teacher',
        'subject_teacher' => 'Subject Teacher',
        'school_accountant' => 'Accountant',
        'school_parent' => 'Parent / Guardian',
        'school_student' => 'Student',
    ];
    $activeRole = $user['active_role'] ?? null;
    $roleLabel = $isPlatformSuperAdmin
        ? $roleLabels['platform_super_admin']
        : ($schoolRole !== null
            ? ($roleLabels[$schoolRole] ?? role_display_name($schoolRole))
            : (is_string($activeRole)
                ? ($roleLabels[$activeRole] ?? role_display_name($activeRole))
                : 'Choose workspace'));
    $hasRoleSwitch = count($user['roles'] ?? []) > 1
        || ($schoolId !== null && count(school_user_roles_for_school((int) $user['id'], $schoolId)) > 1);
    $canOpenSchoolSettings = $schoolId !== null && $supportContext === null
        && (school_has_permission($user, $schoolId, 'school.settings.edit')
            || school_has_permission($user, $schoolId, 'school.branding.manage'));
    $home = $platformAdmin ? 'platform_admin.php' : ($schoolId !== null
        ? ($schoolRole === 'school_student' ? 'student_dashboard.php' : 'school_dashboard.php')
        : 'schools.php');
    $contextLabel = $platformAdmin
        ? 'Platform administration'
        : ($school === null ? 'School workspaces' : (string) $school['name']);
    $applicationName = $school === null ? application_name() : strtoupper((string) $school['name']);
        $clockTimezone = (string) ($config['timezone'] ?? 'Africa/Accra');
        $headerClockTime = new DateTimeImmutable('now', new DateTimeZone($clockTimezone));
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#061D3D">
    <?php if ($isApplication): ?><meta name="csrf-token" content="<?= e(csrf_token()) ?>"><?php endif; ?>
    <title><?= e($title) ?> | <?= e($applicationName) ?></title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="apple-touch-icon" href="assets/images/connezer-app-icon.png">
    <link rel="icon" href="assets/images/super-admin-logo.jpeg" type="image/jpeg">
    <link rel="stylesheet" href="assets/css/app.css?v=202">
    <link rel="stylesheet" href="assets/css/design-system.css?v=6">
    <script src="assets/js/app.js?v=92" defer></script>
</head>
<body class="<?= $isApplication ? 'application-body' : 'auth-body' ?><?= !$isApplication && $title === 'School login' ? ' auth-login-body' : '' ?><?= $title === 'Staff attendance kiosk' ? ' attendance-kiosk-body' : '' ?>">
<?php if ($isApplication): ?>
<aside class="app-sidebar" id="app-sidebar" aria-label="Main navigation">
    <button class="sidebar-close-button" type="button" data-mobile-nav-close-button aria-label="Close navigation menu" title="Close navigation menu"><?= sidebar_icon('close') ?></button>
    <?php if ($school !== null): ?>
        <a class="sidebar-school-brand" href="<?= e($home) ?>"><?= school_identity_markup($school, false, 'sidebar') ?></a>
    <?php else: ?>
        <?php platform_branding('application-branding-sidebar', $home); ?>
        <?php if ($platformAdmin): ?><p class="sidebar-caption">PLATFORM ADMINISTRATION</p><?php else: ?><p class="sidebar-caption">SCHOOL WORKSPACES</p><?php endif; ?>
    <?php endif; ?>
    <div class="sidebar-user-profile">
        <?= profile_avatar_button($user, 'sidebar-user-avatar') ?>
        <span class="sidebar-user-meta"><strong><?= e((string) $user['full_name']) ?></strong><small><?= e($roleLabel) ?></small></span>
    </div>
    <nav class="sidebar-nav" aria-label="School management navigation">
        <label class="sidebar-search" hidden>
            <span class="sidebar-search-label">Find a page</span>
            <input type="search" data-sidebar-search placeholder="Search pages..." autocomplete="off">
        </label>
        <p class="sidebar-search-empty" data-sidebar-search-empty role="status" hidden>No menu pages match your search.</p>
        <?php if ($platformAdmin): ?>
            <p class="sidebar-group-label">Platform overview</p>
            <a href="platform_admin.php" class="<?= $title === 'Platform administration' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('overview') ?></span> Platform dashboard</a>
            <p class="sidebar-group-label">School administration</p>
            <a href="platform_admin.php#school-records" class="<?= $title === 'School management' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('school') ?></span> Schools and subscriptions</a>
        <?php elseif ($schoolId !== null): ?>
            <?php if ($schoolRole === 'school_student'): ?>
                <a href="student_dashboard.php" class="<?= $title === 'Student dashboard' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('overview') ?></span> Student dashboard</a>
            <?php elseif (school_has_permission($user, $schoolId, 'school.view') || school_has_permission($user, $schoolId, 'students.view_own')): ?>
                <a href="school_dashboard.php" class="<?= in_array($title, ['School dashboard', 'Teacher dashboard'], true) ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('overview') ?></span> <?= $teacherWorkspace ? 'Teacher dashboard' : 'School dashboard' ?></a>
            <?php endif; ?>
            <?php if ($supportContext === null): ?><a class="sidebar-school-assignment" href="schools.php"><span class="sidebar-icon"><?= sidebar_icon('school') ?></span><span class="sidebar-link-label">My school assignment</span></a><?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'students.manage')
                || school_has_permission($user, $schoolId, 'students.view')
                || school_has_permission($user, $schoolId, 'students.lifecycle.manage')
                || in_array($schoolRole, ['school_headmaster', 'school_proprietor'], true)): ?>
                <p class="sidebar-group-label">Student management</p>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'students.manage')): ?>
                <a href="admissions.php" class="<?= $title === 'Admissions' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('admissions') ?></span> Admissions</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'students.manage') || school_has_permission($user, $schoolId, 'students.view')): ?>
                <a href="students.php" class="<?= in_array($title, ['Students', 'Student profile'], true) ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('students') ?></span> Students</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'students.lifecycle.manage')): ?>
                <a href="former_students.php"><span class="sidebar-icon"><?= sidebar_icon('archive') ?></span> Former students & transfers</a>
                <?php if (in_array($schoolRole, ['school_headmaster', 'school_proprietor'], true)): ?>
                    <a href="graduation.php"><span class="sidebar-icon"><?= sidebar_icon('results') ?></span> Graduation approvals</a>
                    <a href="graduation_certificates.php"><span class="sidebar-icon"><?= sidebar_icon('receipt') ?></span> Graduation certificates</a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)): ?>
                <a href="student_leadership.php" class="<?= $title === 'Student leadership' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('person-check') ?></span> Student leadership</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'staff.manage')
                || school_has_permission($user, $schoolId, 'staff.view')
                || in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)
                || $hasStaffWorkspace): ?>
                <p class="sidebar-group-label">Staff management</p>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'staff.manage')): ?>
                <a href="staff.php"><span class="sidebar-icon"><?= sidebar_icon('staff') ?></span> Staff and teachers</a>
            <?php endif; ?>
            <?php if ($hasStaffWorkspace): ?>
                <a href="staff_self_service.php" class="<?= $title === 'My payroll and attendance' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('finance') ?></span> My payroll and attendance</a>
                <a href="staff_attendance_pin.php" class="<?= $title === 'Staff attendance history' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('attendance') ?></span> Attendance history</a>
            <?php endif; ?>
            <?php if (in_array($schoolRole, ['school_headmaster', 'school_proprietor'], true)): ?>
                <a href="staff_attendance.php" class="<?= $title === 'Staff attendance management' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('attendance') ?></span> Staff attendance &amp; punctuality</a>
            <?php endif; ?>
            <?php if (in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true) || $hasStaffWorkspace): ?>
                <a href="staff_records.php" class="<?= in_array($title, ['Staff records and concerns', 'My staff concerns and records'], true) ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('staff') ?></span> <?= in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true) ? 'Staff records and concerns' : 'My staff concerns and records' ?></a>
            <?php endif; ?>
            <?php if (in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)): ?>
                <a href="school_administration_roster.php" class="<?= $title === 'School administration roster' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('staff') ?></span> Administration roster</a>
            <?php endif; ?>
            <?php if (in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)
                || school_has_permission($user, $schoolId, 'school.manage')
                || school_has_permission($user, $schoolId, 'parent_links.manage')
                || $canOpenSchoolSettings): ?>
                <p class="sidebar-group-label">Roles and approvals</p>
            <?php endif; ?>
            <?php if (in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)): ?>
                <a href="school_role_requests.php" class="<?= $title === 'School role requests' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('person-check') ?></span> Role requests and access</a>
            <?php endif; ?>
            <?php if (count(school_user_roles_for_school((int) $user['id'], $schoolId)) > 1): ?>
                <a href="schools.php"><span class="sidebar-icon"><?= sidebar_icon('transfer') ?></span> Switch workspace or role</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'staff.view') && !school_has_permission($user, $schoolId, 'staff.manage')): ?>
                <a href="profiles.php"><span class="sidebar-icon"><?= sidebar_icon('profile') ?></span> School profiles</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'school.manage')
                || school_has_permission($user, $schoolId, 'parent_links.manage')
                || $canOpenSchoolSettings
                || in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)): ?>
                <p class="sidebar-group-label">School administration</p>
            <?php endif; ?>
            <?php if (in_array($schoolRole, ['school_proprietor', 'school_headmaster'], true)): ?>
                <a href="school_setup_checklist.php" class="<?= $title === 'School setup checklist' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('overview') ?></span> School setup checklist</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'school.manage')): ?>
                <a href="school_admin.php"><span class="sidebar-icon"><?= sidebar_icon('settings') ?></span> School administration</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'parent_links.manage')): ?>
                <a href="parent_links.php"><span class="sidebar-icon"><?= sidebar_icon('parent') ?></span> Parent / guardian links</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'school.settings.edit') || school_has_permission($user, $schoolId, 'school.branding.manage')): ?>
                <a href="school_settings.php"><span class="sidebar-icon"><?= sidebar_icon('settings') ?></span> School settings</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'classes.manage')
                || school_has_permission($user, $schoolId, 'attendance.manage')
                || school_has_permission($user, $schoolId, 'results.manage')
                || school_has_permission($user, $schoolId, 'learning.manage')
                || school_has_permission($user, $schoolId, 'report_cards.view')
                || school_has_permission($user, $schoolId, 'report_cards.view_own')
                || school_has_permission($user, $schoolId, 'student.portal.view')
                || $schoolId > 0): ?>
                <p class="sidebar-group-label">Academic management</p>
            <?php endif; ?>
            <a href="term_setup.php" class="<?= $title === 'Academic calendar' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('calendar') ?></span> Academic calendar</a>
            <?php if (school_has_permission($user, $schoolId, 'classes.manage')): ?>
                <a href="classes.php" class="<?= in_array($title, ['Classes', 'Classes & Levels'], true) ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('classroom') ?></span> Classes &amp; Levels</a>
                <a href="subjects.php"><span class="sidebar-icon"><?= sidebar_icon('book') ?></span> Subjects</a>
            <?php endif; ?>
            <?php if ($schoolRole === 'class_teacher' && $teacherHasClass && school_has_permission($user, $schoolId, 'attendance.manage')): ?>
                <a href="attendance.php?action=register&amp;month=<?= e(date('Y-m')) ?>"><span class="sidebar-icon"><?= sidebar_icon('attendance') ?></span> Attendance</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'results.manage') && (!$teacherWorkspace || $teacherHasClass || $teacherHasSubject)): ?>
                <a href="assessments.php"><span class="sidebar-icon"><?= sidebar_icon('results') ?></span> Examination Records</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'learning.manage')): ?>
                <a href="learning.php"><span class="sidebar-icon"><?= sidebar_icon('timetable') ?></span> Learning and timetable</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'timetable.view')): ?>
                <a href="timetable.php"><span class="sidebar-icon"><?= sidebar_icon('timetable') ?></span> Smart timetable</a>
            <?php endif; ?>
            <?php if ($supportContext === null && school_ai_assistant_keys_for_role((string) $schoolRole) !== []): ?>
                <a href="ai_dashboard.php" class="<?= $title === 'Connezer Ai' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('overview') ?></span> Connezer Ai</a>
            <?php endif; ?>
            <?php if ((school_has_permission($user, $schoolId, 'report_cards.view')
                || school_has_permission($user, $schoolId, 'report_cards.view_own')
                || school_has_permission($user, $schoolId, 'student.portal.view'))
                && (!$teacherWorkspace || $teacherHasClass)): ?>
                <a href="report_cards.php"><span class="sidebar-icon"><?= sidebar_icon('receipt') ?></span> Report cards</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'fees.manage')
                || school_has_permission($user, $schoolId, 'fees.view_assigned')
                || school_has_permission($user, $schoolId, 'payroll.manage')): ?>
                <p class="sidebar-group-label">Financial management</p>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'fees.manage') || school_has_permission($user, $schoolId, 'fees.view_assigned')): ?>
                <a href="<?= school_has_permission($user, $schoolId, 'fees.manage') ? 'fee_management.php' : 'fees.php' ?>"><span class="sidebar-icon"><?= sidebar_icon('finance') ?></span> Fees Management</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'payroll.manage')): ?>
                <a href="staff_payroll.php" class="<?= $title === 'Staff payroll' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('finance') ?></span> Staff payroll</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'announcements.manage')): ?>
                <p class="sidebar-group-label">Communication</p>
                <a href="announcements.php"><span class="sidebar-icon"><?= sidebar_icon('announcement') ?></span> Announcements</a>
            <?php endif; ?>
            <?php if (school_has_permission($user, $schoolId, 'students.view_own')): ?>
                <p class="sidebar-group-label">Family access</p>
                <a href="parent_portal.php"><span class="sidebar-icon"><?= sidebar_icon('parent') ?></span> Parent portal</a>
            <?php endif; ?>
            <p class="sidebar-group-label">Support</p>
            <a href="school_support.php"><span class="sidebar-icon"><?= sidebar_icon('support') ?></span> IT support</a>
        <?php else: ?>
            <a href="schools.php"><span class="sidebar-icon"><?= sidebar_icon('members') ?></span> School workspaces</a>
        <?php endif; ?>
        <span class="sidebar-divider" aria-hidden="true"></span>
        <?php if ($platformAdmin && $title === 'Platform administration'): ?>
            <a href="activity.php" class="<?= $title === 'Account activity' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('activity') ?></span> Account activity</a>
            <a href="two_factor.php" class="<?= $title === 'Sign-in security' ? 'active' : '' ?>"><span class="sidebar-icon"><?= sidebar_icon('security') ?></span> Sign-in security</a>
        <?php endif; ?>
    </nav>
    <?php if ($school === null): ?><div class="sidebar-footer"><?= e($applicationName) ?><br><span><?= $platformAdmin ? 'Platform administration' : 'School workspaces' ?></span></div><?php endif; ?>
</aside>
<button class="mobile-nav-backdrop" type="button" data-mobile-nav-close aria-label="Close navigation menu" tabindex="-1"></button>
<header class="app-topbar">
    <div class="topbar-start">
    <button class="mobile-nav-toggle" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="app-sidebar" data-mobile-nav-toggle>
        <span></span><span></span><span></span>
    </button>
    <div class="topbar-context">
        <?php if ($school !== null): ?><?= school_identity_markup($school, false, 'compact') ?><?php else: ?><span><?= e($contextLabel) ?></span><?php endif; ?>
        <span class="breadcrumb-separator">/</span><strong><?= e($title) ?></strong>
    </div>
    </div>
    <time class="topbar-date-time" data-header-clock data-timezone="<?= e($clockTimezone) ?>" datetime="<?= e($headerClockTime->format(DateTimeInterface::ATOM)) ?>" aria-label="Current date and time">
        <span class="topbar-clock-item"><span data-header-clock-date><?= e($headerClockTime->format('D, j M Y')) ?></span></span>
        <span class="topbar-clock-item" data-header-clock-time><?= e($headerClockTime->format('g:i:s A')) ?></span>
    </time>
    <div class="topbar-actions">
        <?php if (!$platformAdmin && $supportContext === null && $schoolId !== null): ?><a class="topbar-icon-button topbar-activity" href="schools.php" aria-label="My school assignment" title="My school assignment"><?= sidebar_icon('school') ?></a><?php endif; ?>
        <?php if ($isApplication && $associationUnreadNotifications > 0): ?>
            <a class="topbar-icon-button" href="notifications.php" aria-label="Member notifications, <?= $associationUnreadNotifications ?> unread" title="Member notifications, <?= $associationUnreadNotifications ?> unread">
                <?= sidebar_icon('notifications') ?>
                <span class="notification-count"><?= $associationUnreadNotifications > 99 ? '99+' : $associationUnreadNotifications ?></span>
            </a>
        <?php endif; ?>
        <?php if ($showSchoolNotifications): ?>
            <details class="school-notification-center">
                <summary aria-label="Notifications<?= $schoolUnreadNotifications > 0 ? ', ' . $schoolUnreadNotifications . ' unread' : '' ?>" title="Notifications<?= $schoolUnreadNotifications > 0 ? ', ' . $schoolUnreadNotifications . ' unread' : '' ?>">
                    <?= sidebar_icon('notifications') ?>
                    <?php if ($schoolUnreadNotifications > 0): ?><span class="notification-count"><?= $schoolUnreadNotifications > 99 ? '99+' : $schoolUnreadNotifications ?></span><?php endif; ?>
                </summary>
                <section class="school-notification-panel" aria-label="Recent notifications">
                    <div class="school-notification-panel-heading"><strong>Notifications</strong><a class="header-action-link" href="notifications.php">View all</a></div>
                    <?php if ($recentSchoolNotifications === []): ?>
                        <p class="muted">No school notifications yet.</p>
                    <?php else: ?>
                        <ul data-show-more>
                            <?php foreach ($recentSchoolNotifications as $notification): ?>
                                <li class="<?= $notification['read_at'] === null ? 'is-unread' : '' ?>">
                                    <strong><?= e((string) $notification['title']) ?></strong>
                                    <?php render_dashboard_read_more((string) $notification['body']); ?>
                                    <small><?= e((string) ($notification['sender_name'] ?? 'System')) ?><?php if ($notification['class_level']): ?> · <?= e(school_class_label(['level' => $notification['class_level'], 'section' => $notification['class_section']])) ?><?php endif; ?><?php if ($notification['subject_name']): ?> · <?= e((string) $notification['subject_name']) ?><?php endif; ?> · <?= e((string) $notification['created_at']) ?> UTC</small>
                                    <?php if ($notification['link_url']): ?>
                                        <form method="post" action="notifications.php">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="open_school_notification"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                                            <button class="school-notification-open" type="submit">Open notification →</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($notification['read_at'] === null): ?>
                                        <form method="post" action="notifications.php">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="mark_school_read"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                                            <button class="school-notification-open" type="submit">Mark as read</button>
                                        </form>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </details>
        <?php endif; ?>
        <div class="account-menu" data-account-menu>
            <?= profile_avatar_button($user, 'topbar-profile-avatar') ?>
            <button class="account-menu-toggle" type="button" data-account-menu-toggle aria-expanded="false" aria-haspopup="true" aria-controls="account-menu-panel">
                <span class="profile-meta"><strong><?= e((string) $user['full_name']) ?></strong><small><?= e($roleLabel) ?></small></span>
                <span class="account-menu-chevron" aria-hidden="true"><?= sidebar_icon('chevron-down') ?></span>
            </button>
            <div class="account-menu-panel" id="account-menu-panel" data-account-menu-panel hidden>
                <a href="profile.php"><?= sidebar_icon('profile') ?><span>My profile</span></a>
                <?php if ($canOpenSchoolSettings): ?><a href="school_settings.php"><?= sidebar_icon('settings') ?><span>School settings</span></a><?php endif; ?>
                <?php if ($hasRoleSwitch): ?><a href="schools.php"><?= sidebar_icon('transfer') ?><span>Switch workspace or role</span></a><?php endif; ?>
                <form class="account-menu-signout" method="post" action="logout.php">
                    <?= csrf_field() ?><button type="submit"><?= sidebar_icon('logout') ?><span>Sign out</span></button>
                </form>
            </div>
        </div>
    </div>
</header>
<?php elseif ($title !== 'School login'): ?>
<header class="site-header">
    <?php platform_branding('application-branding-public', $title === 'Staff attendance kiosk' ? null : 'index.php'); ?>
</header>
<?php endif; ?>
<?php if ($isApplication): ?>
<dialog class="profile-photo-dialog" data-profile-photo-dialog aria-labelledby="profile-photo-dialog-title">
    <form method="post" action="profile.php" enctype="multipart/form-data" data-profile-photo-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="profile_picture">
        <input type="hidden" name="remove_profile_picture" value="">
        <h2 id="profile-photo-dialog-title">Profile photo</h2>
        <div class="profile-photo-dialog-preview" data-profile-photo-preview>
            <?= profile_avatar_markup($user, 'profile-photo-preview-avatar') ?>
        </div>
        <p class="muted profile-photo-dialog-help" data-profile-photo-help>JPEG, PNG, or WebP · maximum 2 MB</p>
        <input class="visually-hidden" type="file" name="profile_picture" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-profile-photo-input tabindex="-1">
        <div class="profile-photo-dialog-actions">
            <?php if ($supportContext === null): ?>
                <button class="button button-primary" type="button" data-profile-photo-change>Change photo</button>
                <?php if ($hasProfilePhoto): ?>
                    <a class="button button-quiet" href="profile_picture.php?v=<?= e(substr(hash('sha256', $profilePicturePath), 0, 12)) ?>" target="_blank" rel="noopener">View photo</a>
                    <button class="button button-quiet" type="button" data-profile-photo-remove>Remove photo</button>
                <?php endif; ?>
                <button class="button button-quiet" type="button" data-profile-photo-cancel>Cancel</button>
                <button class="button button-primary" type="submit" data-profile-photo-save disabled>Save photo</button>
            <?php else: ?>
                <?php if ($hasProfilePhoto): ?><a class="button button-quiet" href="profile_picture.php?v=<?= e(substr(hash('sha256', $profilePicturePath), 0, 12)) ?>" target="_blank" rel="noopener">View photo</a><?php endif; ?>
                <button class="button button-quiet" type="button" data-profile-photo-cancel>Close</button>
            <?php endif; ?>
        </div>
    </form>
</dialog>
<?php endif; ?>
<main class="page-shell<?= $isApplication ? ' application-content' : '' ?><?= !$isApplication && $title === 'School login' ? ' auth-login-shell' : '' ?>">
    <?php if ($isApplication && $school !== null && !$platformAdmin): ?>
        <aside class="school-current-period" aria-label="Selected school academic context">
            <div class="school-current-period-context">
                <span class="school-current-period-label">Workspace reporting period</span>
                <?php if ($schoolPagePeriod !== null): ?>
                    <strong><?= e($schoolPagePeriod['term_name'] . ' · ' . school_academic_year_label((string) $schoolPagePeriod['academic_year'])) ?></strong>
                <?php else: ?>
                    <strong>No academic period configured</strong>
                <?php endif; ?>
            </div>
            <?php if ($canSelectSchoolPeriod && $schoolAcademicTerms !== []): ?>
                <form class="school-period-selector" method="get" action="select_academic_period.php">
                    <label class="visually-hidden" for="school-page-period">Select academic year and term</label>
                    <select id="school-page-period" name="period" required>
                        <option value="">Select an academic period</option>
                        <?php foreach ($schoolAcademicTerms as $period): ?>
                            <?php $periodValue = (int) $period['academic_year_id'] . '|' . (string) $period['term_name']; ?>
                            <option value="<?= e($periodValue) ?>" <?= $schoolPagePeriod !== null
                                && (int) $schoolPagePeriod['academic_year_id'] === (int) $period['academic_year_id']
                                && $schoolPagePeriod['term_name'] === $period['term_name'] ? 'selected' : '' ?>>
                                <?= e($period['term_name'] . ' · ' . school_academic_year_label((string) $period['academic_year'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="return" value="<?= e(basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH))) ?>">
                    <input type="hidden" name="return_query" value="<?= e(http_build_query($_GET)) ?>">
                    <button class="button button-small" type="submit">Apply</button>
                </form>
            <?php elseif (!$canSelectSchoolPeriod && $schoolAcademicYears !== []): ?>
                <details class="school-period-readonly">
                    <summary>Browse configured periods (read only)</summary>
                    <div class="school-period-readonly-list">
                        <?php foreach ($schoolAcademicYears as $year): ?>
                            <section>
                                <strong><?= e(school_academic_year_label((string) $year['academic_year'])) ?></strong>
                                <ul>
                                    <?php $yearHasTerms = false; ?>
                                    <?php foreach ($schoolAcademicTerms as $period): ?>
                                        <?php if ((int) $period['academic_year_id'] !== (int) $year['id']) { continue; } ?>
                                        <?php $yearHasTerms = true; ?>
                                        <li>
                                            <?= e((string) $period['term_name']) ?>
                                            <span><?= e((string) $period['starts_on'] . ' to ' . (string) $period['ends_on']) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if (!$yearHasTerms): ?><li>No terms configured</li><?php endif; ?>
                                </ul>
                            </section>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php elseif ($schoolAcademicYears === []): ?>
                <strong>No academic years configured</strong>
            <?php endif; ?>
            <span class="school-current-period-official">School term today: <?= e($schoolCurrentTerm ?? 'No active term is scheduled for today') ?>
                <?php if ($schoolAcademicPeriod !== null): ?> · <?= e(school_academic_year_label((string) $schoolAcademicPeriod['academic_year'])) ?><?php endif; ?>
            </span>
            <?php if ($supportContext === null && in_array($schoolRole, ['school_headmaster', 'school_proprietor'], true)
                && ($schoolAcademicPeriod === null || $schoolCurrentTerm === null)): ?>
                <a href="term_setup.php">Configure academic calendar</a>
            <?php endif; ?>
        </aside>
    <?php endif; ?>
    <?php if ($flash): ?><div class="notice notice-<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></div><?php endif; ?>
    <?php if ($supportContext !== null && $school !== null): ?>
        <section class="it-support-banner" role="status">
            <div>
                <strong><?= $supportContext['preview_role'] === 'school_student' ? 'STUDENT PREVIEW MODE' : 'IT SUPPORT MODE' ?></strong>
                <span>Viewing <?= e(strtoupper((string) $school['name'])) ?> as <?= e($roleLabels[$supportContext['preview_role']] ?? role_display_name((string) $supportContext['preview_role'])) ?></span>
                <small>
                    <?= $supportContext['access_mode'] === 'administrative' ? 'Administrative access enabled · changes are audited' : 'Read-only preview' ?>
                    · <?= e((string) $supportContext['reason']) ?>
                </small>
            </div>
            <form method="post" action="exit_it_support.php"><?= csrf_field() ?><button class="button button-primary" type="submit">Exit IT Support Mode</button></form>
        </section>
    <?php endif; ?>
<?php
}

function render_dashboard_profile_card(array $user): void
{
    ?>
<section class="panel dashboard-profile-card">
    <div class="profile-picture-editor">
        <?= profile_avatar_button($user, 'profile-avatar-trigger-large') ?>
        <div>
            <h2><?= e((string) $user['full_name']) ?></h2>
            <a href="profile.php">Edit profile</a>
        </div>
    </div>
</section>
    <?php
}

function render_footer(): void
{
    $isApplication = !empty($_SESSION['user_id']);
    $school = $isApplication ? active_school_context() : null;
    $footerBrand = $school === null
        ? 'CONNEZER EDUTECH SYSTEMS'
        : strtoupper((string) $school['name']);
    ?>
</main>
<footer class="site-footer<?= $isApplication ? ' application-footer' : '' ?>"><span>&copy; <?= gmdate('Y') ?> <?= e($footerBrand) ?></span></footer>
</body>
</html>
<?php
}

function page_title(string $eyebrow, string $title, string $description = ''): void
{
    echo '<div class="page-heading"><p class="eyebrow">' . e($eyebrow) . '</p><h1>' . e($title) . '</h1>';
    if ($description !== '') {
        echo '<p class="muted">' . e($description) . '</p>';
    }
    echo '</div>';
}
