# Connezer Local Attendance Device Agent — Installation & Maintenance Guide

## 1. Why a Local Device Agent Is Used
Ordinary browser cookies, `localStorage`, browser fingerprints, and IP addresses **do not** reliably identify a physical computer:
- **Cookies / `localStorage`** are isolated per browser profile (registering Chrome does not authorise Edge or Firefox on the same PC, and clearing site data or copying a profile breaks/bypasses control).
- **Browser fingerprints** change across browser updates and can be spoofed.
- **IP addresses** are shared behind school NAT/routers and change on DHCP leases.
- **Mutual TLS (mTLS) client certificates** are terminated at the Vercel edge and are not supported per-tenant on `*.vercel.app` hostnames.

To identify the **physical attendance computer** across all supported browsers (Google Chrome, Microsoft Edge, Mozilla Firefox) on Windows, macOS, or Linux, CONNEZER pairs each authorised attendance PC with a lightweight **Local Device Agent** (`tools/device-agent/connezer_device_agent.py`) listening strictly on `http://127.0.0.1:47832`.

## 2. Security Architecture
1. **OS-Protected Key Storage:** Each enrolled school receives a unique 256-bit `agent_secret` and `device_uid` stored locally in `%ProgramData%\ConnezerAttendanceAgent\devices.json` (Windows) or `/etc/connezer-agent/devices.json` (Linux/macOS, `chmod 600`).
2. **Server Stores Only Hashes:** The CONNEZER database (`school_staff_attendance_kiosk_devices`) stores only `SHA-256(agent_secret)` (`agent_secret_hash`) and `device_uid` scoped per `school_id` (`UNIQUE(school_id, device_uid)`).
3. **Challenge-Response Verification:** When any supported browser opens `attendance_kiosk.php`, the server issues a single-use cryptographic `nonce`, the browser requests an `HMAC-SHA256(nonce | school_id, SHA-256(agent_secret))` signature from `127.0.0.1:47832`, and the server verifies it before permitting attendance.
4. **Immediate Revocation:** Deactivating or revoking a device in Headmaster/Proprietor **Attendance Device Management** immediately blocks attendance on all browsers for that PC.

## 3. Installation on a School Attendance PC (Windows)
1. Install Python 3.9+ on the attendance computer.
2. Copy `tools/device-agent/connezer_device_agent.py` to `C:\Program Files\ConnezerAgent\connezer_device_agent.py`.
3. Restrict permissions on `C:\ProgramData\ConnezerAttendanceAgent` to Windows Administrators and the service account so ordinary users cannot copy `devices.json`.
4. Register a Windows Scheduled Task (run at system startup under an unprivileged/service account) or run:
   ```powershell
   python "C:\Program Files\ConnezerAgent\connezer_device_agent.py"
   ```
5. Sign in as the school's **Headmaster** or **Proprietor**, open **Staff attendance & punctuality → Kiosk setup** (or **Attendance Device Management** on the dashboard), register the device (Name + Location), copy the **one-time Enrollment Code** (`CNZ-XXXX-XXXX`), and click **Pair Local Device Agent**.

## 4. Maintenance & Replacement
- **Check agent status:** `python connezer_device_agent.py --check` or open `http://127.0.0.1:47832/status` locally.
- **Temporary maintenance:** Use **Deactivate** in the dashboard; click **Activate** when the PC returns to service.
- **Lost, stolen, or replaced PC:** Click **Revoke** or **Replace** on the old device immediately. Revoked devices cannot be reactivated and are blocked on all browsers.
