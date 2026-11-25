#!/usr/bin/env python3
"""
CONNEZER EDUTECH SYSTEMS — Lightweight Local Attendance Device Agent
Binds strictly to 127.0.0.1:47832 so authorized browsers (Chrome, Edge, Firefox)
on the same physical school attendance computer can prove physical-device identity
via HMAC-SHA256 challenge-response without relying on browser-profile cookies or
spoofable fingerprints.
"""
import hashlib
import hmac
import json
import os
import platform
import secrets
import sys
import uuid
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

HOST = "127.0.0.1"
PORT = int(os.environ.get("CONNEZER_AGENT_PORT", "47832"))
ALLOWED_ORIGINS = {
    "https://connezer-edutech-system.vercel.app",
    "https://osaak-portal.vercel.app",
    "http://localhost",
    "http://127.0.0.1",
}


def store_path() -> Path:
    if os.name == "nt":
        base = Path(os.environ.get("PROGRAMDATA", r"C:\ProgramData")) / "ConnezerAttendanceAgent"
    else:
        base = Path("/etc/connezer-agent") if os.geteuid() == 0 else Path.home() / ".connezer-agent"
    base.mkdir(parents=True, exist_ok=True)
    return base / "devices.json"


def load_store() -> dict:
    p = store_path()
    if not p.is_file():
        data = {
            "machine_id": hashlib.sha256(f"{platform.node()}-{uuid.getnode()}".encode()).hexdigest(),
            "schools": {},
        }
        save_store(data)
        return data
    return json.loads(p.read_text(encoding="utf-8"))


def save_store(data: dict) -> None:
    p = store_path()
    p.write_text(json.dumps(data, indent=2), encoding="utf-8")
    if os.name != "nt":
        os.chmod(p, 0o600)


class AgentHandler(BaseHTTPRequestHandler):
    def _cors_origin(self) -> str:
        origin = self.headers.get("Origin", "")
        if origin in ALLOWED_ORIGINS or origin.startswith("http://localhost:") or origin.startswith("http://127.0.0.1:"):
            return origin
        return "https://connezer-edutech-system.vercel.app"

    def _send_json(self, code: int, payload: dict) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Access-Control-Allow-Origin", self._cors_origin())
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self) -> None:
        self._send_json(204, {})

    def do_GET(self) -> None:
        if self.path == "/status":
            data = load_store()
            self._send_json(200, {
                "status": "running",
                "agent": "connezer-device-agent/1.0",
                "enrolled_schools": list(data.get("schools", {}).keys()),
            })
            return
        self._send_json(404, {"error": "Not found"})

    def do_POST(self) -> None:
        length = int(self.headers.get("Content-Length", "0") or "0")
        if length <= 0 or length > 16384:
            self._send_json(400, {"error": "Invalid request body"})
            return
        try:
            body = json.loads(self.rfile.read(length).decode("utf-8"))
        except Exception:
            self._send_json(400, {"error": "Invalid JSON"})
            return

        data = load_store()
        schools = data.setdefault("schools", {})

        if self.path == "/enroll":
            school_id = str(body.get("school_id", "")).strip()
            if not school_id.isdigit():
                self._send_json(400, {"error": "Invalid school_id"})
                return
            agent_secret = secrets.token_hex(32)
            device_uid = hashlib.sha256(
                f"{data['machine_id']}|{school_id}|{secrets.token_hex(8)}".encode()
            ).hexdigest()
            schools[school_id] = {
                "device_uid": device_uid,
                "agent_secret": agent_secret,
            }
            save_store(data)
            self._send_json(200, {
                "school_id": int(school_id),
                "device_uid": device_uid,
                "agent_secret": agent_secret,
            })
            return

        if self.path == "/sign":
            school_id = str(body.get("school_id", "")).strip()
            nonce = str(body.get("nonce", "")).strip()
            entry = schools.get(school_id)
            if not entry or not nonce:
                self._send_json(404, {"error": "Physical device is not enrolled for this school"})
                return
            # Server stores sha256(agent_secret) and verifies HMAC-SHA256(nonce|school_id, sha256(agent_secret))
            signing_key = hashlib.sha256(entry["agent_secret"].encode("utf-8")).hexdigest().encode("utf-8")
            sig = hmac.new(signing_key, f"{nonce}|{school_id}".encode("utf-8"), hashlib.sha256).hexdigest()
            self._send_json(200, {
                "school_id": int(school_id),
                "device_uid": entry["device_uid"],
                "signature": sig,
            })
            return

        self._send_json(404, {"error": "Not found"})

    def log_message(self, format: str, *args) -> None:
        return


def main() -> None:
    if len(sys.argv) > 1 and sys.argv[1] == "--check":
        data = load_store()
        print(f"Store: {store_path()} | Enrolled schools: {list(data.get('schools', {}).keys())}")
        return
    server = HTTPServer((HOST, PORT), AgentHandler)
    print(f"Connezer Attendance Device Agent listening on http://{HOST}:{PORT}")
    server.serve_forever()


if __name__ == "__main__":
    main()
