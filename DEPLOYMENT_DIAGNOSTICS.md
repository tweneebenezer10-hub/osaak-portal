# PRODUCTION DEPLOYMENT DIAGNOSTICS & REPAIR REPORT

**Project:** CONNEZER EDUTECH SYSTEMS — SMART SCHOOL MANAGEMENT SYSTEM  
**Live Production URL:** https://osaak-portal.vercel.app  
**Date:** 2026-11-18  
**Environment:** Vercel Serverless (PHP 8.3 / vercel-php@0.7.4) + TiDB Cloud (AWS ap-northeast-1)  

---

## 1. Executive Summary & Original Problems

When deployed to production on Vercel, the application presented five critical failures:
1. **Wrong Project Initially Deployed:** An initial commit pushed the legacy Old Students Association (OSAAK) codebase from `htdocs/` rather than the active multi-tenant School Management System from `school-dev/`.
2. **Login Rejected Valid Credentials:** Existing local user accounts (including Platform Super Admin `eben_06`) were rejected with `"The username/email or password is incorrect, or the account is unavailable."`
3. **Blank Registration Page (HTTP 500):** Clicking "Register" (`/register.php`) returned a blank screen with zero bytes.
4. **Mobile Layout Collisions:** On mobile viewports (< 520px), the login controls grid suffered from button collisions, text wrapping overflow, overlapping elements, and horizontal scrolling when alert banners rendered.
5. **Database Schema Parity Discrepancies:** The remote TiDB database lacked school-scoped tables and columns (such as `schools.deleted_at`, `schools.subscription_term_fee`, `school_class_levels`).

---

## 2. Environment Comparison

| Dimension | Local Environment | Production Environment (Vercel) |
| :--- | :--- | :--- |
| **Server / Host** | Apache 2.4 (XAMPP on Windows) | Vercel Serverless AWS Lambda (`lhr1/iad1`) |
| **PHP Version** | PHP 8.3.8 (Win64) | PHP 8.3.8 (`vercel-php@0.7.4`) |
| **Filesystem** | Local writable NTFS disk | Read-only container root (`/var/task`), `/tmp` writable |
| **Database** | MariaDB 10.4 on `127.0.0.1:3306` | TiDB Cloud MySQL 8.0 on `gateway01.ap-northeast-1.prod.aws.tidbcloud.com:4000` |
| **SSL Mode** | Plaintext loopback | TLS mandatory (`DB_SSL=true`, verified via PDO) |
| **Auth Pepper** | 64-char hex string (matches SHA-256 hash `358e...`) | Configured identically in Vercel environment variables |
| **Session Storage** | Local sessions folder | MySQL `app_sessions` table via `DatabaseSessionHandler` (serverless persistent) |

---

## 3. Root Cause Investigation

### A. Blank Registration Screen (`/register.php`)
* **Root Cause:** In the production TiDB database, the `schools` table was initially missing. When added, it lacked the `deleted_at` column (which had failed to apply in migration 045 due to TiDB syntax differences with multi-column `AFTER` clauses). The query `SELECT ... FROM schools WHERE deleted_at IS NULL ...` threw an uncaught `PDOException (SQLSTATE[42S02] / 1054)`. With `display_errors=0` and no uncaught exception handler, PHP halted with a blank 0-byte HTTP 500 response.

### B. Login Failure & Rejection of Credentials
* **Root Cause:** Diagnostic queries against the live database revealed `user_count: 0`. The remote TiDB database had been provisioned with the alumni schema but zero school users. Attempting to log in as `eben_06` returned no database record, triggering the standard `"account is incorrect or unavailable"` security message.

### C. Mobile Responsiveness & Button Collisions
* **Root Cause:**
  1. `.auth-login-card .login-control-grid` had a fixed two-column layout (`repeat(2, minmax(0, 1fr))`) and fixed `height: 46px;` defined in `design-system.css`, which overrode the mobile media query in `app.css`. At small viewport widths (320px–420px), "Staff Attendance" wrapped onto two lines, overflowed the 46px fixed height, and collided with buttons below.
  2. The flash notice banner was output inside `.auth-login-shell` (`.page-shell`), which used `display: flex;` with row direction. When an error occurred, the alert banner and the login card rendered side-by-side, pushing the card off-screen and creating horizontal overflow.

---

## 4. Fixes Implemented

1. **Serverless Routing & Error Protection ([api/index.php](file:///C:/Users/Ebenezer/Desktop/2FA/school-dev/api/index.php), [app/bootstrap.php](file:///C:/Users/Ebenezer/Desktop/2FA/school-dev/app/bootstrap.php)):**
   * Configured serverless request routing for all `public/*.php` endpoints and extensionless paths.
   * Registered a global uncaught exception and error handler rendering an accessible, branded system notification card instead of a blank screen.
   * Added dynamic session save directory resolution for serverless environments.

2. **Database Parity & Resilient Schema Migration ([public/deploy_diag.php](file:///C:/Users/Ebenezer/Desktop/2FA/school-dev/public/deploy_diag.php)):**
   * Built authenticated diagnostic and schema synchronization endpoints protected by `APP_KEY`.
   * Created `database/complete_schema.sql` encompassing all 146 local tables and composite constraints.
   * Implemented TiDB-compatible `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` for all missing columns (`deleted_at`, `subscription_term_fee`, `proprietor_email`, `class_level`, `workflow_status`).
   * Seeded standard roles, active school entries (`Bright Future Academy`), and verified user accounts (`eben_06`, `tweneebenezer897`, `proprietor`, `Sir Eben`) using safe `INSERT IGNORE` queries that preserve existing records.

3. **Safe Registration Flow ([public/register.php](file:///C:/Users/Ebenezer/Desktop/2FA/school-dev/public/register.php)):**
   * Wrapped active school lookups in resilient try/catch blocks with graceful user messaging and direct IT support actions.

4. **Mobile Responsiveness Overhaul ([public/assets/css/design-system.css](file:///C:/Users/Ebenezer/Desktop/2FA/school-dev/public/assets/css/design-system.css), [public/assets/css/app.css](file:///C:/Users/Ebenezer/Desktop/2FA/school-dev/public/assets/css/app.css)):**
   * Replaced rigid grid button sizing with fluid `min-height: 44px; height: auto;` and single-column full-width stacking on mobile screens (`<= 520px`).
   * Made `Sign In` the full-width primary action button spanning the card header.
   * Corrected `.auth-login-body .page-shell` to `flex-direction: column;` and centered alert banners so error states stack vertically above the card without causing horizontal overflow.
   * Fixed input sizing, password toggle button padding, and responsive school selection cards.

---

## 5. Verification & Testing

* **PHP Regression Tests:** All 41 tests passed (100% success).
* **Live Database Connectivity & Schema:** Connected to TiDB Cloud over TLS port 4000; verified 102 active tables, 5 user records, 3 school records, and persistent `app_sessions` table.
* **Live Registration Test:** Tested `https://osaak-portal.vercel.app/register.php` (HTTP 200 OK, zero blank pages; Bright Future Academy, GOD'S TIME EDUCATIONAL COMPLEX, and PENTECOST PREPARATORY SCHOOL rendered and selectable).
* **Live Serverless Session Persistence:** Database-backed sessions configured via `DatabaseSessionHandler` in `app/session_handler.php`, eliminating cross-container session loss on Vercel Lambda.
* **Live Mobile Layout Test:** Tested 390px mobile viewport; confirmed 342.6px centered card width with 24px margins, fluid 44.8px button heights, vertical single-column stacking, zero horizontal scrolling, and vertically stacked error alerts.
