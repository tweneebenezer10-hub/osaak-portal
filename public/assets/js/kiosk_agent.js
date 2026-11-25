(function () {
    const AGENT_URL = 'http://127.0.0.1:47832';

    async function verifyKioskAgent(root) {
        const schoolId = root.getAttribute('data-kiosk-school-id');
        const alreadyAuthorized = root.getAttribute('data-kiosk-authorized') === '1';
        if (!schoolId || alreadyAuthorized) return;

        const statusEl = root.querySelector('[data-agent-status]');
        try {
            const challengeResp = await fetch('attendance_device_api.php?action=challenge&school_id=' + encodeURIComponent(schoolId), {
                credentials: 'same-origin'
            });
            if (!challengeResp.ok) return;
            const challengeData = await challengeResp.json();

            const signResp = await fetch(AGENT_URL + '/sign', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ school_id: Number(schoolId), nonce: challengeData.nonce })
            });
            if (!signResp.ok) {
                if (statusEl) statusEl.textContent = 'Local Device Agent detected, but this physical PC is not paired for this school.';
                return;
            }
            const signData = await signResp.json();

            const verifyResp = await fetch('attendance_device_api.php?action=verify', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(signData)
            });
            const verifyData = await verifyResp.json();
            if (verifyResp.ok && verifyData.verified) {
                window.location.reload();
            } else if (statusEl && verifyData.error) {
                statusEl.textContent = verifyData.error;
            }
        } catch (err) {
            if (statusEl) {
                statusEl.textContent = 'Local Device Agent not running on this computer (127.0.0.1:47832).';
            }
        }
    }

    async function pairLocalAgent(button) {
        const schoolId = button.getAttribute('data-pair-school-id');
        const code = button.getAttribute('data-pair-code');
        const outEl = document.querySelector('[data-pair-result]');
        if (!schoolId || !code) return;
        button.disabled = true;
        try {
            const enrollResp = await fetch(AGENT_URL + '/enroll', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ school_id: Number(schoolId), enrollment_code: code })
            });
            const enrollData = await enrollResp.json();
            if (!enrollResp.ok || !enrollData.device_uid) {
                throw new Error(enrollData.error || 'Local agent did not respond.');
            }
            const saveResp = await fetch('attendance_device_api.php?action=enroll', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    school_id: Number(schoolId),
                    enrollment_code: code,
                    device_uid: enrollData.device_uid,
                    agent_secret: enrollData.agent_secret
                })
            });
            const saveData = await saveResp.json();
            if (!saveResp.ok || !saveData.enrolled) {
                throw new Error(saveData.error || 'Server enrollment failed.');
            }
            if (outEl) {
                outEl.textContent = 'Physical PC paired across all supported browsers (' + saveData.device.device_name + ').';
            }
        } catch (err) {
            if (outEl) {
                outEl.textContent = 'Could not reach Local Device Agent on 127.0.0.1:47832. Start connezer_device_agent.py on this PC first.';
            }
        } finally {
            button.disabled = false;
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const kioskRoot = document.querySelector('[data-kiosk-school-id]');
        if (kioskRoot) verifyKioskAgent(kioskRoot);

        document.querySelectorAll('[data-pair-agent-button]').forEach((btn) => {
            btn.addEventListener('click', () => pairLocalAgent(btn));
        });
    });
})();
