# RENDER DEPLOYMENT & MIGRATION GUIDE
**Project:** CONNEZER EDUTECH SYSTEMS — SMART SCHOOL MANAGEMENT SYSTEM  
**Platform:** Render (Docker Web Service)  
**Target URL Pattern:** `https://connezer-edutech.onrender.com` (or user-chosen Render domain)  
**Database:** External MySQL 8.0 (TiDB Cloud Serverless / Managed MySQL)  
**Last Updated:** 2026-11-18  

---

## 1. Architecture Overview

```
                                    +-----------------------------------------------+
                                    |                 RENDER CLOUD                  |
                                    |                                               |
[ Internet / Users ] -------------> |   Web Service (Docker + Apache + PHP 8.3)     |
    (Desktop & Mobile)              |   - Port: 10000 (auto-assigned by $PORT)       |
                                    |   - DocumentRoot: /var/www/html/public        |
                                    |   - Apache: mod_rewrite, mod_headers enabled  |
                                    |   - Healthcheck: /healthz.php                 |
                                    +-----------------------+-----------------------+
                                                            |
                                    +-----------------------+-----------------------+
                                    |                                               |
                                    v                                               v
            +---------------------------------+             +-------------------------------+
            |     Persistent Disk Storage     |             |      Production Database      |
            |     Mount: /var/www/html/storage|             |   TiDB Cloud MySQL 8.0 (TLS)  |
            |     - student-photos/           |             |   Host: gateway01.ap-northeast-1  |
            |     - school-logos/             |             |   Port: 4000                  |
            |     - profile-pictures/         |             |   Database: secure_auth_system|
            |     - student-lifecycle/        |             |   Tables: 102 verified tables |
            |     - support/ & logs/          |             |   Sessions: app_sessions table|
            +---------------------------------+             +-------------------------------+
```

---

## 2. Production Database Architecture Decision

### Option Comparison

| Feature | Option A: TiDB Cloud Serverless MySQL (Recommended) | Option B: Self-Hosted Docker MySQL on Render |
| :--- | :--- | :--- |
| **Compatibility** | 100% MySQL 8.0 wire-compatible | MariaDB 10.4 / MySQL 8.0 |
| **Current Status** | **Already live & verified (102 tables, 5 users, 3 schools)** | Requires new provisioning & volume mounting |
| **Cost** | **$0.00 / month (Free tier)** with auto-scaling request units | **$7.00/mo minimum** (instance) + $0.25/GB disk |
| **High Availability**| Multi-AZ distributed clustering with automated failover | Single container; potential downtime on restarts |
| **Backups** | Automated daily point-in-time recovery | Requires manual cron dumping scripts |
| **Maintenance** | Zero-maintenance fully managed cloud service | Manual container updates and disk monitoring |

**Decision:** Option A is selected. It is already verified with 100% table schema alignment and zero data loss, completely independent of local development machines, and incurs zero monthly infrastructure costs.

---

## 3. Environment Variables Specification

Set the following environment variables in the Render Dashboard (**Environment** tab of the Web Service).

> [!IMPORTANT]
> Never commit actual production secrets to public repositories. Set them exclusively in the Render Environment Variables dashboard.

| Variable Key | Type / Example | Description |
| :--- | :--- | :--- |
| `PORT` | `10000` | Port for Apache to listen on (Render default) |
| `APP_ENV` | `production` | Enables production error logging and security checks |
| `APP_NAME` | `CONNEZER EDUTECH SYSTEMS` | System title for portal and receipts |
| `APP_URL` | `https://connezer-edutech.onrender.com` | Base public URL of your Render service |
| `APP_TIMEZONE` | `Africa/Accra` | System timezone for school attendance & terms |
| `RECEIPT_FINANCIAL_TITLE` | `CONNEZER EDUTECH SYSTEMS` | Legal receipt title on PDF generation |
| `DB_HOST` | `gateway01.ap-northeast-1.prod.aws.tidbcloud.com` | Remote MySQL/TiDB database host |
| `DB_PORT` | `4000` | Database TCP port (4000 for TiDB, 3306 for MySQL) |
| `DB_NAME` | `secure_auth_system` | Production database schema name |
| `DB_USER` | `4537XGEvg1QQfWd.root` | Database username |
| `DB_PASSWORD` | *(Secret)* | Database user password |
| `DB_CHARSET` | `utf8mb4` | Character encoding |
| `DB_SSL` | `true` | Mandatory SSL/TLS for cloud database connections |
| `AUTH_PEPPER` | *(Secret matching local pepper)* | 64-character SHA-256 pepper for Argon2id hashing |
| `APP_KEY` | *(Secret matching local app key)* | 64-character key for encryption and maintenance |
| `MAIL_HOST` | `smtp.gmail.com` *(or your SMTP)* | Outgoing SMTP mail server |
| `MAIL_PORT` | `587` | SMTP port (587 TLS or 465 SSL) |
| `MAIL_USERNAME` | *(Your email)* | SMTP login username |
| `MAIL_PASSWORD` | *(Your app password)* | SMTP application password |
| `MAIL_ENCRYPTION` | `tls` | SMTP encryption protocol |
| `MAIL_FROM_EMAIL` | `noreply@yourdomain.com` | From email address for OTPs and notifications |
| `MAIL_FROM_NAME` | `CONNEZER EDUTECH SYSTEMS` | Sender display name |

---

## 4. Persistent Disk Configuration

Render containers have an ephemeral filesystem by default. To preserve student photos, school logos, certificates, and lifecycle attachments across redeployments:

1. In Render Dashboard, go to your Web Service -> **Disks**.
2. Click **Add Disk**:
   - **Name:** `connezer-storage`
   - **Mount Path:** `/var/www/html/storage`
   - **Size:** `1 GB` (or higher as student records grow)
3. The container entrypoint script (`docker-entrypoint.sh`) automatically initializes all required subdirectories on container startup:
   - `/var/www/html/storage/branding`
   - `/var/www/html/storage/school-logos`
   - `/var/www/html/storage/student-photos`
   - `/var/www/html/storage/student-lifecycle`
   - `/var/www/html/storage/profile-pictures`
   - `/var/www/html/storage/support`
   - `/var/www/html/storage/logs`
   - `/var/www/html/storage/sessions`
   and assigns ownership to `www-data:www-data` with mode `0775`.

---

## 5. Deployment Step-by-Step Instructions

### Method 1: Render Blueprint (`render.yaml` - One Click)
1. Log into your Render account at [https://dashboard.render.com](https://dashboard.render.com).
2. Click **New +** -> **Blueprint**.
3. Connect your GitHub repository (`tweneebenezer10-hub/osaak-portal` or `tweneebenezer10-hub/connezer-edutech`).
4. Render automatically parses `render.yaml` and provisions:
   - The Docker web service `connezer-edutech-web`.
   - The persistent disk `connezer-storage`.
   - The health check route `/healthz.php`.
5. Enter the secret environment variables (`DB_PASSWORD`, `AUTH_PEPPER`, `APP_KEY`, `APP_URL`).
6. Click **Apply**.

### Method 2: Manual Web Service Setup
1. In Render Dashboard, click **New +** -> **Web Service**.
2. Select **Build and deploy from a Git repository**.
3. Select your repository.
4. Set the service properties:
   - **Name:** `connezer-edutech-web`
   - **Region:** Frankfurt (EU Central) or Oregon (US West)
   - **Branch:** `main`
   - **Root Directory:** *(leave blank for root)*
   - **Runtime:** `Docker`
   - **Dockerfile Path:** `./Dockerfile`
   - **Docker Context:** `.`
   - **Instance Type:** Starter (or Free tier if disk not immediately needed)
5. Under **Advanced**:
   - **Health Check Path:** `/healthz.php`
   - **Auto-Deploy:** Yes
6. Under **Environment**, add the variables listed in Section 3.
7. Under **Disks**, attach `connezer-storage` to `/var/www/html/storage`.
8. Click **Create Web Service**.

---

## 6. Verification & Health Check

Once the deployment finishes and shows **Live**:

1. **Container Health:** Check `https://<your-render-url>/healthz.php`. It should return `HTTP 200` with body `ok`.
2. **Login Screen:** Check `https://<your-render-url>/`. Verify mobile responsiveness, single-column button layout, and clear inputs.
3. **Database Connectivity:** Sign in with `eben_06`. Verify that the database validates credentials and routes to OTP verification.
4. **Registration:** Visit `https://<your-render-url>/register.php`. Verify all 3 schools (Bright Future Academy, etc.) render with zero errors.
5. **Session Persistence:** Navigate between pages; confirm your session stays active across requests without bouncing.
6. **File Uploads:** In School Settings or Student Profile, upload a logo or student photo and verify image persistence.
