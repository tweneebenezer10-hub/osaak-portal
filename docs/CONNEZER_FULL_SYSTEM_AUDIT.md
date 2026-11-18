# CONNEZER EDUTECH SYSTEMS Full-System Audit

Audit started: 2026-10-29
Canonical application root: `school-dev`
Database: MySQL/MariaDB database `secure_auth_system`

## Recovery and baseline

| Item | Status | Evidence |
| --- | --- | --- |
| Project recovery copy | VERIFIED | `backups/connezer-project-20261029-233010`; 257 non-secret files compared, 0 missing |
| Database recovery dump | VERIFIED | Same recovery directory; `secure_auth_system.sql`, 958,476 bytes |
| Git status | BLOCKED | Git executable is not installed in the environment |
| Live database changes | NONE | Inspection and dump only; no migration or data mutation performed |
| Real credentials preserved | VERIFIED | No password or secret was changed; local configuration was excluded from the project copy |

## Architecture inspected

| Requirement | Files/modules inspected | Defect or finding | Changes | Tests/evidence | Status |
| --- | --- | --- | --- | --- | --- |
| Application architecture and entry points | `index.php`, `public/*.php`, `app/bootstrap.php`, `app/functions.php`, `app/views.php` | Server-rendered PHP application with shared bootstrap and view helpers; legacy association routes are explicitly retired | None yet | Static inspection | INSPECTED |
| Authentication, sessions, and security headers | `app/bootstrap.php`, `app/functions.php`, login/logout/2FA pages | Strict cookie sessions, no-store headers, CSRF helpers, password hashing, and audit logging are present | None yet | Static inspection; executable checks pending | INSPECTED |
| Tenant and role authorization | `app/school_tenant.php`, school pages, dashboard pages | Permission checks are present at page level; navigation requires targeted role-by-role execution testing | None yet | Static inspection; role matrix pending | INSPECTED |
| Database schema and migrations | `database/schema.sql`, `database/migrations/032` through `092` | Extensive additive school, attendance, assessment, fee, payroll, loan, notification, and calendar migrations exist | None yet | Static inspection; applied migration state pending | INSPECTED |
| Dashboard and screen switching | `public/school_dashboard.php`, `app/views.php`, `public/assets/js/app.js` | Action-workspace implementation replaces the active workspace and tracks browser history; authenticated module switching remains unexecuted | None yet | Static inspection; authenticated browser regression pending | INSPECTED |
| Attendance | `app/school_attendance.php`, `public/attendance.php`, attendance migrations | Class-teacher assignment and school scoping are implemented; live save/reload and summary tests pending | None yet | Static inspection | INSPECTED |
| Results and report cards | `app/school_results.php`, `public/assessments.php`, `public/report_cards.php`, related migrations | Configurable assessment weighting and review/publication migrations exist; workflow execution pending | None yet | Static inspection | INSPECTED |
| Students and transfers | `app/student_lifecycle.php`, `public/students.php`, `public/former_students.php`, migration 039 | Non-destructive lifecycle/transfer model exists; execution and record-preservation tests pending | None yet | Static inspection | INSPECTED |
| Fees, receipts, payroll, and loans | `app/school_fees.php`, `app/school_payment_reconciliation.php`, `public/payment_reconciliation.php`, fee pages, `public/staff_payroll.php`, migrations 059, 084, 089, 091 | Explicit fee allocations and payroll/loan workflows exist; statement reconciliation and consistent fee-status badges are implemented | Added bounded CSV comparison and consistent green-paid, yellow-partial, red-owing badges across teacher, finance, parent, and student invoice views; no payment is auto-verified | Regression checks cover status mapping; PHP lint passes; live school/payment fixtures remain untested | TESTING |
| Academic years and notifications | `app/school_academic_calendar.php`, `public/term_setup.php`, `app/school_notifications.php`, migrations 092 and 083 | Calendar milestones and notification action state are represented; execution pending | None yet | Static inspection | INSPECTED |
| Responsive/readability system | `public/assets/css/app.css`, shared view helpers, README | Shared stylesheet and print/responsive rules exist; viewport/browser checks pending | None yet | Static inspection | INSPECTED |
| Backup and recovery | `database/backup-database.php`, `app/storage_backup.php`, `storage/backups/`, README backup procedure | Added paired CLI-only compressed database and private uploaded-file backups with SHA-256 manifests, archive read verification, restricted temporary files, isolated database credentials, and a concurrent-run lock. Scheduling and restore testing remain deployment tasks | Added database and upload archive support and documented exclusions, off-host protection, and restore-test limits | Isolated storage archive regression test passes; invalid-client cleanup and HTTP isolation pass; no live data was dumped or restored | TESTING |
| Automated tests | `tests/regression.php`, Composer script | Added database-independent regression checks for fee amounts and status colors, CSV parsing/reconciliation classifications, uploaded-file archive contents, assessment marks/rankings, academic-year date validation, attendance summaries, and TOTP vectors; database-backed workflows and school isolation still need integration tests | Added a reusable test runner without touching live data | PHP 8.2.12: all 11 checks pass; tested modules and runner pass `php -l` | TESTING |

## Executed checks

| Check | Result | Evidence |
| --- | --- | --- |
| Database-independent regression suite | PASS | `php tests/regression.php` ran 11 checks, including fee-status color mapping, CSV reconciliation, and an isolated uploaded-file archive test; no database connection or live files were used |
| Payment reconciliation access boundary | PASS | Unauthenticated GET to `http://localhost/school-dev/payment_reconciliation.php` returned HTTP 302 to the sign-in route; authenticated payment-role and cross-school integration checks remain pending |
| Database backup command safety | PARTIAL | CLI PHP syntax passes; a deliberately invalid dump executable produced a clear nonzero failure and cleaned temporary option/dump files. HTTP GET for the backup script and an existing backup both returned 404. No real database backup or restore was attempted. |
| Backup utility availability | PASS | Installed MariaDB `mysqldump` reports version 10.19; no database connection was made with it. |
| Local health endpoint | PASS | `http://127.0.0.1:8088/healthz.php` returned `ok` |
| Login page render | PASS | Page title `School login | CONNEZER EDUTECH SYSTEMS`; username, password, remember-me, sign-in, reset, and register controls present |
| Mobile login surface | PASS | Playwright at 390x844: sign-in visible; body scroll width 376px, so no horizontal overflow |
| Canonical PHP source diagnostics | PASS | `get_errors` returned no errors for `school-dev` and the edited `app/views.php` |
| Role-request navigation regression | STATIC PASS | Active-role condition now matches `school_role_requests.php` backend authorization; authenticated multi-role browser test pending |
| Unauthenticated protected route | PASS | Direct `school_dashboard.php` request redirected to login with `Please sign in to continue.` |
| Logout method enforcement | PASS | Direct GET to `logout.php` returned `Use the sign-out form to log out.`; logout remains POST plus CSRF |
| Report-card generation gate | STATIC PASS | `report_cards.php` renders generation controls only for `mode=generate`; preview remains a separate route and POST is CSRF-protected |
| Payroll and loan classification | STATIC PASS | Salary rates/payroll entries, loan disbursements, and loan repayments use separate tables and workflow statuses; live reconciliation remains pending |
| PHP local server startup | PARTIAL | PHP 8.2.12 served health and login successfully, but startup reported blocked `curl`, `openssl`, and `ftp` extensions; SMTP/HTTPS-dependent tests remain unavailable |
| Static asset smoke check | PASS on documented Apache path | After correcting the base URL, `http://localhost/school-dev/` rendered the login page through the configured alias without the prior `/school-dev/public/` asset path failure; favicon follow-up remains low priority |
| Local base URL and redirects | VERIFIED | `config/local.php` now uses `http://localhost/school-dev`, matching the README/Apache alias; protected-route redirect landed at `/school-dev/` and rendered successfully |
| Report-card route boundary | PASS | Unauthenticated `report_cards.php` redirected to the login page |
| Legacy route retirement | PASS | Unauthenticated `roles.php` returned the explicit 410 legacy-feature response |
| Apache health endpoint | PASS | `http://localhost/school-dev/healthz.php` returned `ok` |
| PHP extension configuration | PARTIAL | Backed up `php/php.ini` to `backups/php.ini-before-connezer-extension-repair-20261029.ini`; removed unused curl/legacy FTP declarations and enabled standard OpenSSL syntax. PHP now reports only the OS policy block on `php_openssl.dll`. |
| Live schema and fixture baseline | VERIFIED | Read-only MySQL inspection found 127 tables, 4 schools, 4 users, 9 active memberships, and all current attendance, results, fees, payroll, loan, and notification tables |
| Retired Bursar role data | BLOCKED | One active historical `school_bursar` membership remains in school 1. Current code excludes it from context and permissions, preserving the record; disabling it requires an explicitly reviewed data-cleanup migration |
| Fee ledger reconciliation | PASS | Read-only check found no invoice balance discrepancies when using active payment allocations; no duplicate non-empty payment references were found |
| Disbursed loan balance reconciliation | PASS | Read-only check found no balance discrepancies for active or paid loans; pending requests were correctly excluded because their principal is not yet disbursed |
| Financial category separation | VERIFIED | Salary/payroll, school-fee payments, loan disbursements, and loan repayments use separate live tables and columns; no cross-category rows were found in the inspected data |
| Attendance and assessment integrity | PASS WITH HISTORICAL ANOMALY | Live records have no orphaned student, class, or assessment references. One historical student-attendance row has an empty status; application logic treats it as unmarked, so it is preserved rather than guessed as present or absent |
| Public-route and protected-route access scan | STATIC PASS | Non-public school routes use `require_login`, `require_school_context`, `require_platform_super_admin`, or explicit permission checks; public calendar/logo routes apply school/status/membership filtering; legacy routes are centrally retired |
| Shared-computer session expiry | IMPLEMENTED | `app/functions.php` now enforces an eight-hour server-side idle timeout at the protected-request boundary, clearing the session and remember-token cookie before requiring sign-in |
| Authenticated Class Teacher sidebar | VERIFIED | Live Bright Future Academy Class Teacher workspace showed permitted academic/self-service links and did not show `Role requests and access` |
| Authenticated attendance navigation | VERIFIED | Sidebar Attendance opened `attendance.php?action=register&month=2026-10`; monthly register rendered summary cards and the expected student/weekly table headings |
| Authenticated report-card generation | VERIFIED | Initial page showed `Generate Report Cards` with no generation fields; after activation, Academic Year, Term, Class, and Student controls appeared; readiness submission preserved the active workspace and populated the scoped student list |
| Authenticated teacher module navigation | VERIFIED | Class Teacher opened Examination Records, assigned-class Fees, and My Payroll and Loans; each rendered the correct page without access-denied output and payroll showed only the authenticated employee's records |
| Payroll CSP smoke check | PASS, warning not reproduced | One earlier navigation sequence logged blocked inline-style CSP messages; a clean reload emitted no console errors and the page contains no inline style attributes |
| Direct restricted-route checks | VERIFIED | The authenticated Class Teacher received explicit denial responses for `school_role_requests.php` and staff attendance, and a permission-denied page for `school_admin.php` |
| Authenticated mobile layout | VERIFIED | Class Teacher staff-records page rendered at 390px with body width equal to viewport width (376px), no horizontal overflow, and accessible concern/history sections |
| Rendered typography targets | VERIFIED | Browser computed styles on the authenticated application reported 20px body text, 32px page heading, and 19px button text; no shared typography change was needed |
| Report-card one-page printing | VERIFIED | Print CSS now prevents a student report card from splitting (`break-inside: avoid`); whole-class output keeps one student per page. CSS cache version bumped from 146 to 147 and browser verification confirmed the rule is active |
| Report-card printed dates | VERIFIED | Printable report cards now omit submission, review, and release timestamps while retaining the generated date and preserving workflow timestamps in stored history |
| Bright Future Academy report-card cover | VERIFIED | Existing renderer now shows one left logo, `END OF TERM STUDENTS REPORT`, actual `ACADEMIC YEAR: 2026/2027`, real Student ID/Class/Term values, and no generated timestamp. Saved address/email were unavailable, so no contact placeholder was displayed |
| Report-card HTML/print layout | VERIFIED | Shared preview and print output use the same renderer; browser verification measured the first report at 884px with `break-inside: avoid` and one student per page |
| Report-card hierarchy and compact print retest | VERIFIED | Browser print media loaded CSS v149; school name rendered at 17.3px/850 weight versus title at 12.7px/750, one logo rendered, timestamp absent, five essential report sections present, and first card measured 844px with `break-inside: avoid` |
| Whole-class report-card width and print fit | VERIFIED | Whole-class reports now use the full print content width and A4 landscape with explicit subject-table column proportions. Local Edge produced exactly two landscape PDF pages for two synthetic eight-subject report cards, one card per page; no real student data was used. |
| Report-card logo and school-name emphasis | VERIFIED | CSS v150 print media renders the logo at 18mm, school name at 20px/900 weight, and title at 12.7px/750; complete card remains 860px with `break-inside: avoid` |
| Transparent report-card logo | VERIFIED | The original Bright Future Academy JPEG remains unchanged; a school-scoped report-card PNG derivative removes only edge-connected white background pixels, loads at 600x600, and renders without the white rectangle |
| One-line report-card header retest | VERIFIED | CSS v151 print media renders `End of Term Students Report` on one line at 10.5pt/500, `BRIGHT FUTURE ACADEMY` at 20px/900 on one line, an 18mm logo, a 7.6px logo-text gap, and an 860px card with `break-inside: avoid` |
| Centered report-card header retest | VERIFIED | CSS v152 print media centers the logo, text block, school name, title, and academic year on the same horizontal axis; title remains one line, year is 10.7px/400, and the complete card is 921px with `break-inside: avoid` |
| Latest database/report refresh | BLOCKED | MySQL was unavailable on 2026-11-02 (`127.0.0.1:3306`, connection refused), so no new live report or contact-field query was performed; existing verified output and no-invention fallback remain unchanged |
| Actual PDF page-count test | BLOCKED | The headed browser session does not expose `Page.printToPDF`; generating a PDF would require an authenticated browser export or a non-sensitive test session. No unsupported PDF page-count claim was made |
| Academic period integrity | PASS | No school/year had more than one current term; live term data contains one configured 2026/2027 academic year |
| Report-card and notification integrity | PASS | One published report-card batch has five entries; no orphan report/notification recipients or cross-school notification recipients were found. Live notifications use `read_at`, `acknowledged_at`, and `archived_at` state fields |
| Secret/configuration scan | PASS WITH DEPLOYMENT ACTION | No populated credentials were found in source examples, Docker, Render, or application code. Populated `config/local.php` is ignored and outside `public/`; because Git is unavailable and the local SMTP secret has been exposed during development, rotate it before hosting |

## Confirmed blockers and risks

1. `config/local.php` contains live SMTP credentials and application secrets. It is outside `public/`, but production readiness requires secret rotation if this workspace has been shared, strict exclusion from source control, and deployment through protected environment configuration.
2. Git is unavailable, so repository status and history cannot be independently verified here.
3. Browser pages are not shared and no browser automation has been run; UI navigation, mobile layout, and role switching are not verified.
4. The live database was dumped but its migration table, current record counts, and role fixtures have not yet been queried.
5. The sibling `htdocs` tree is a separate older association application. It has not been modified and is out of scope unless deployment configuration proves it is the served copy.
6. No isolated test accounts or test database were created; authenticated workflows were not executed to avoid modifying real school records.
7. Local PHP OpenSSL loading is blocked by Windows Application Control even after configuration normalization; mail delivery and TLS-dependent checks cannot be verified until the PHP installation is allowed to load `php_openssl.dll` or is replaced with a permitted build.
8. The live database contains one active historical `school_bursar` membership although that role is retired. It is not usable through current authorization code; no live data mutation was made to preserve the account history.
9. One historical student-attendance row has an empty status. The current summary logic treats it as unmarked; resolving it requires evidence of the original attendance decision.

## Work queue

- [x] Run available source diagnostics across the canonical application tree.
- [x] Add isolated regression checks for core academic, attendance, fee, and authentication calculations.
- [x] Add a permission-gated, school-scoped bank-statement reconciliation preview without mutating payment records.
- [x] Add a CLI-only database backup command that does not mutate live database records.
- [ ] Inspect and test the active permission/menu matrix for all school roles.
- [ ] Verify applied migration state and schema compatibility without mutating data.
- [ ] Run focused workflow checks for navigation, attendance, results, fees, payroll/loans, notifications, and logout/session switching.
- [ ] Run browser checks at desktop and mobile viewport sizes once a local server/browser surface is available.
- [ ] Address only defects reproduced by those checks, with regression tests.
- [ ] Complete production configuration review and document remaining deployment actions.

## Changes made in this audit

- `app/views.php`: changed the Role requests and access sidebar condition to use the active school role, matching the route's backend authorization and preventing a multi-role Class Teacher workspace from exposing a Headmaster/Proprietor-only action.
- `public/report_cards.php`: renamed the primary report-card action to `Generate Report Cards` without changing the existing gated preparation workflow.
- `config/local.php`: corrected the local base URL from the invalid `/school-dev/public` path to the documented Apache `/school-dev` alias.
- `php/php.ini`: normalized extension declarations; a verified pre-change backup is retained outside the application tree.
- `app/functions.php`: added an eight-hour inactivity timeout for protected sessions while retaining session-ID regeneration, CSRF, no-store headers, and explicit logout behavior.
- `public/report_cards.php`: added explicit academic-year/student preparation controls and preserved the report-generation workspace after `generate=1` readiness requests.
- `public/assets/css/app.css` and `app/views.php`: prevented report-card print splitting and bumped the stylesheet version so browsers receive the print fix.
- `public/report_cards.php`: removed Submitted, Reviewed, and Released dates from the printable report-card document only.
- `public/report_cards.php`, `public/assets/css/app.css`, and `app/views.php`: improved the existing report-card cover header, removed the duplicate logo, added data-driven academic/contact presentation, and synchronized HTML/PDF styling.
- `tests/regression.php`, `composer.json`, and `README.md`: added a database-independent regression suite and documented its coverage and limitations.
- `app/school_payment_reconciliation.php` and `public/payment_reconciliation.php`: added a bounded CSV payment-reconciliation preview that classifies matches and exceptions without storing the statement or changing payment state; added entry links from finance pages.
- `app/school_fees.php`, fee pages, `public/assets/css/app.css`, and `app/views.php`: added consistent paid/partial/owing badge colors across teacher, finance, parent, and student invoice views and bumped the stylesheet cache version.
- `public/assets/js/app.js` and `app/views.php`: close the school notification dropdown on outside click or Escape, restoring keyboard focus to its toggle when dismissed with Escape.
- `app/views.php`, `public/assets/js/app.js`, and `public/assets/css/app.css`: added an accessible sidebar page search that filters the current user's authorized navigation by page label or URL terms, hides empty section headings, and reports when there are no matches.
- `public/assets/css/app.css` and `app/views.php`: made sidebar links stretch to the full navigation width and strengthened the hover highlight so it remains visible across labels that wrap to multiple lines.
- `public/assets/js/app.js`, `public/assets/css/app.css`, and `app/views.php`: wrapped sidebar link text in an explicit, shrinkable label element so grid layout wraps long menu names inside the full-row hover target at desktop and mobile widths.
- `public/assets/css/app.css` and `app/views.php`: restored the hover background and inset highlight for the special “My school assignment” sidebar link, whose later transparent override had canceled hover feedback.
- `app/views.php` and `public/assets/css/app.css`: rendered “My school assignment” with an explicit label and changed sidebar links to a shrinkable flex layout so the hover target expands over the complete wrapped label.
- `public/assets/css/app.css` and `app/views.php`: aligned the later screen-wide sidebar override with the flex layout; it had been reapplying grid layout after the base rule and preventing the wrapped assignment label from sizing the hover target as intended.
- `public/assets/css/app.css` and `public/assets/js/app.js`: standardized equal-height rows across dashboard, action, directory, student, support, and summary card grids; long card descriptions collapse to three lines with accessible Read more/Read less controls, preserving all original text.
- `public/assets/css/app.css`, `public/assets/js/app.js`, `public/report_cards.php`, and `app/views.php`: compacted report-card A4 print typography and spacing; whole-class batches use the full A4 landscape page width with fixed table columns, while standalone reports retain portrait printing. All report rows and remarks are preserved.
- `database/backup-database.php`, `app/storage_backup.php`, and `README.md`: added private, compressed, checksummed database and uploaded-file backup artifacts and documented exclusions and restore-test limitations.
- `docs/CONNEZER_FULL_SYSTEM_AUDIT.md`: added this persistent evidence log and recovery checklist.

## Status vocabulary

`NOT STARTED`, `INSPECTED`, `IN PROGRESS`, `IMPLEMENTED`, `TESTING`, `VERIFIED`, `BLOCKED`.

## Staff loan system retirement · 2026-11-13

The earlier audit rows above describe the system as it existed when that audit was performed. The staff loan feature has since been retired. Application loan workflows, dashboard cards, settings, and permissions were removed; `database/migrations/099_remove_staff_loan_system.sql` was applied after a verified pre-change database and uploads backup. Verification confirmed that the five loan tables, payroll loan-reference/deduction columns, loan policy setting, loan notifications, and loan-specific security-log entries are absent. Salary rates, payroll runs, attendance eligibility, payment evidence, and historical net-pay snapshots remain. PHP lint passed for the changed pages, and all 17 regression checks passed.
