<?php
declare(strict_types=1);

function render_platform_documentation_section(bool $showPopoutButton = false): void
{
    ?>
    <section id="project-documentation" class="panel spaced-top" aria-labelledby="project-documentation-heading">
        <div class="page-heading">
            <p class="eyebrow">PLATFORM REFERENCE</p>
            <h2 id="project-documentation-heading">Project Documentation &amp; Security Information</h2>
            <p class="muted">Live system specification, verified role permissions, security architecture, and external technology references for CONNEZER EDUTECH SYSTEMS.</p>
            <p>
                <strong>Website:</strong> <a href="https://connezer-edutech-system.vercel.app/" target="_blank" rel="noopener noreferrer">https://connezer-edutech-system.vercel.app/ ↗</a>
                <?php if ($showPopoutButton): ?>
                    · <a class="button button-small" href="platform_documentation.php" target="_blank" rel="noopener noreferrer">Open in independent window ↗</a>
                <?php endif; ?>
            </p>
        </div>

        <div class="dashboard-grid">
            <article class="panel">
                <h3>Project Information</h3>
                <p><strong>Description &amp; Purpose:</strong> CONNEZER EDUTECH SYSTEMS is a multi-tenant PHP and MySQL/MariaDB/TiDB Cloud Smart School Management System that enables multiple independent schools to manage admissions, student records, staff payroll, academics, fees, attendance, and reporting from one secure platform.</p>
                <p><strong>Objectives:</strong></p>
                <ul>
                    <li>Provide strict per-school tenant isolation across all academic, financial, and operational records.</li>
                    <li>Enforce multi-factor authentication (password + email OTP + optional TOTP) and role-based access control.</li>
                    <li>Support independent multi-PC physical attendance-device registries per school without cross-school interference.</li>
                    <li>Maintain verifiable audit trails for security, fees, and staff attendance.</li>
                </ul>
                <p><strong>Scope &amp; Available Modules:</strong> School Onboarding &amp; Subscriptions, Admissions, Student Management &amp; Graduation, Staff &amp; Payroll, Classes &amp; Levels, Subjects, Academic Calendar, Smart Timetable, Examination Records &amp; Report Cards, Fees &amp; Verified Payments, Staff Attendance &amp; Multi-PC Kiosk, Announcements, Support Tickets, and Connezer AI.</p>
                <p><strong>Technologies &amp; Architecture:</strong> PHP 8.3 (PDO prepared statements), MySQL 8.0 / MariaDB / TiDB Cloud (SSL/TLS), HTML5, CSS3, JavaScript, PHPMailer, Vercel Serverless (<code>vercel-php@0.7.4</code>) with database-backed sessions (<code>app_sessions</code>) and media storage (<code>app_media_files</code>), plus Python 3 Local Device Agent (<code>127.0.0.1:47832</code>) for cross-browser physical PC attendance verification.</p>
            </article>

            <article class="panel">
                <h3>Security Information</h3>
                <ul>
                    <li><strong>Authentication Process:</strong> Email/username and password verification, followed by mandatory 6-digit email OTP (<code>login_otp_tokens</code>) and optional authenticator TOTP / 16-char recovery codes, protected by IP/identity rate limiting.</li>
                    <li><strong>Password Hashing:</strong> Passwords are never stored in plain text. PHP <code>password_hash()</code> hashes passwords using <code>PASSWORD_ARGON2ID</code> (with <code>PASSWORD_DEFAULT</code> fallback).</li>
                    <li><strong>Password Salt:</strong> PHP <code>password_hash()</code> automatically generates a unique, cryptographically random salt per password and embeds it in the hash string.</li>
                    <li><strong>Password Pepper:</strong> Before hashing and verification, passwords are keyed with an HMAC-SHA256 server-side secret (<code>AUTH_PEPPER</code>) stored outside the database in environment configuration (never displayed or committed).</li>
                    <li><strong>Database Protection:</strong> All queries use parameterized PDO prepared statements over TLS/SSL; strict SQL compatibility is enforced.</li>
                    <li><strong>Multi-School Tenant Isolation:</strong> Every school-scoped table includes <code>school_id</code>, enforced server-side from verified active memberships and session context.</li>
                    <li><strong>Attendance-Device Authorisation:</strong> Each school manages its own independent device registry (no global one-PC limit). Only Headmasters and Proprietors can register, activate, deactivate, revoke, or replace devices. Physical PCs pair via the Local Device Agent (<code>127.0.0.1:47832</code>) using HMAC-SHA256 challenge-response across Chrome, Edge, and Firefox, alongside <code>HttpOnly; SameSite=Strict</code> credentials.</li>
                    <li><strong>Audit Trails &amp; Access Control:</strong> Security events are logged to <code>security_logs</code>, kiosk/device events to <code>school_staff_attendance_security_events</code>, and attendance edits to <code>school_staff_attendance_audit</code>.</li>
                </ul>
            </article>
        </div>

        <h3 class="spaced-top">User Roles &amp; Permissions</h3>
        <div class="table-scroll"><table>
            <thead><tr><th>Role</th><th>Code</th><th>Verified Responsibilities &amp; Permissions</th></tr></thead>
            <tbody>
                <tr><td><strong>Super Admin</strong></td><td><code>platform_super_admin</code></td><td>Platform-wide school onboarding, status &amp; subscription management, proprietor designation, support ticket review, school deletion/restoration, and read-only/audited IT Support mode. Does not manage individual schools' attendance devices.</td></tr>
                <tr><td><strong>Proprietor</strong></td><td><code>school_proprietor</code></td><td>Full school authority: <code>school.manage</code>, <code>school.view</code>, <code>school.settings.edit</code>, <code>school.branding.manage</code>, <code>students.manage</code>, <code>students.lifecycle.manage</code>, <code>staff.manage</code>, <code>staff.view</code>, <code>classes.manage</code>, <code>attendance.manage</code>, <code>results.manage</code>, <code>learning.manage</code>, <code>timetable.manage</code>, <code>timetable.view</code>, <code>fees.manage</code>, <code>payments.manage</code>, <code>payroll.manage</code>, <code>support.manage</code>, <code>announcements.manage</code>, <code>reports.view</code>, <code>report_cards.view</code>, <code>parent_links.manage</code>, plus Attendance Device Management.</td></tr>
                <tr><td><strong>Headmaster</strong></td><td><code>school_headmaster</code></td><td>School executive operations: <code>school.view</code>, <code>school.settings.edit</code>, <code>school.branding.manage</code>, <code>students.manage</code>, <code>students.lifecycle.manage</code>, <code>staff.view</code>, <code>classes.manage</code>, <code>attendance.manage</code>, <code>results.manage</code>, <code>learning.manage</code>, <code>timetable.manage</code>, <code>timetable.view</code>, <code>fees.manage</code>, <code>announcements.manage</code>, <code>reports.view</code>, <code>report_cards.view</code>, <code>parent_links.manage</code>, plus Attendance Device Management.</td></tr>
                <tr><td><strong>Assistant Headmaster</strong></td><td><code>school_assistant_headmaster</code></td><td><code>school.view</code>, <code>students.manage</code>, <code>students.lifecycle.manage</code>, <code>staff.view</code>, <code>classes.manage</code>, <code>attendance.manage</code>, <code>results.manage</code>, <code>learning.manage</code>, <code>timetable.manage</code>, <code>timetable.view</code>, <code>announcements.manage</code>, <code>reports.view</code>, <code>report_cards.view</code>, <code>parent_links.manage</code>.</td></tr>
                <tr><td><strong>Secretary / Administrator</strong></td><td><code>school_secretary</code></td><td><code>school.view</code>, <code>students.manage</code>, <code>staff.view</code>, <code>attendance.view</code>, <code>timetable.view</code>, <code>announcements.manage</code>, <code>reports.view</code>.</td></tr>
                <tr><td><strong>Class Teacher</strong></td><td><code>class_teacher</code></td><td><code>school.view</code>, <code>students.view</code>, <code>attendance.manage</code>, <code>classes.view</code>, <code>results.manage</code>, <code>learning.manage</code>, <code>timetable.view</code>, <code>announcements.view</code>, <code>report_cards.view</code> (and <code>fees.view_assigned</code> when assigned to a class).</td></tr>
                <tr><td><strong>Subject Teacher</strong></td><td><code>subject_teacher</code></td><td><code>school.view</code>, <code>students.view</code>, <code>results.manage</code>, <code>learning.manage</code>, <code>timetable.view</code>, <code>attendance.view</code> (and class-assigned view/attendance permissions where assigned).</td></tr>
                <tr><td><strong>Bursar / Accountant</strong></td><td><code>school_accountant</code></td><td><code>fees.manage</code>, <code>payments.manage</code>, <code>payroll.manage</code>, <code>reports.view</code>, <code>school.view</code>. (Legacy <code>school_bursar</code> role is excluded from active memberships.)</td></tr>
                <tr><td><strong>Parent / Guardian</strong></td><td><code>school_parent</code></td><td><code>students.view_own</code>, <code>fees.view_own</code>, <code>announcements.view</code>, <code>report_cards.view_own</code>, <code>learning.view_own</code>.</td></tr>
                <tr><td><strong>Student</strong></td><td><code>school_student</code></td><td><code>student.portal.view</code> (student dashboard, timetable, assignments, and published results).</td></tr>
            </tbody>
        </table></div>

        <h3 class="spaced-top">References &amp; External Sources</h3>
        <ul>
            <li><a href="https://www.php.net/manual/en/" target="_blank" rel="noopener noreferrer">PHP Official Documentation</a> &amp; <a href="https://www.php.net/manual/en/function.password-hash.php" target="_blank" rel="noopener noreferrer">PHP <code>password_hash()</code> Manual</a></li>
            <li><a href="https://dev.mysql.com/doc/refman/8.0/en/" target="_blank" rel="noopener noreferrer">MySQL 8.0 Reference Manual</a> &amp; <a href="https://mariadb.com/kb/en/documentation/" target="_blank" rel="noopener noreferrer">MariaDB Knowledge Base</a></li>
            <li><a href="https://developer.mozilla.org/en-US/docs/Web/HTML" target="_blank" rel="noopener noreferrer">MDN HTML Documentation</a>, <a href="https://developer.mozilla.org/en-US/docs/Web/CSS" target="_blank" rel="noopener noreferrer">MDN CSS Documentation</a>, &amp; <a href="https://developer.mozilla.org/en-US/docs/Web/JavaScript" target="_blank" rel="noopener noreferrer">MDN JavaScript Documentation</a></li>
            <li><a href="https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html" target="_blank" rel="noopener noreferrer">OWASP Password Storage Cheat Sheet</a></li>
            <li><a href="https://github.com/PHPMailer/PHPMailer" target="_blank" rel="noopener noreferrer">PHPMailer Repository &amp; Documentation</a></li>
            <li><a href="https://vercel.com/docs" target="_blank" rel="noopener noreferrer">Vercel Documentation</a></li>
        </ul>
    </section>
    <?php
}
