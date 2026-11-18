'use strict';

const attendanceSyncForm = document.querySelector('[data-attendance-sync-form]');
if (attendanceSyncForm instanceof HTMLFormElement) {
    const syncStatus = attendanceSyncForm.querySelector('[data-attendance-sync-status]');
    const conflictList = attendanceSyncForm.querySelector('[data-attendance-sync-conflicts]');
    const retryButton = attendanceSyncForm.querySelector('[data-attendance-sync-retry]');
    const discardButton = attendanceSyncForm.querySelector('[data-attendance-sync-discard]');
    const syncTokenInput = attendanceSyncForm.querySelector('input[name="sync_token"]');
    let pendingEntries = null;
    let requestInProgress = false;

    const setStatus = (message, isError = false) => {
        syncStatus.textContent = message;
        syncStatus.hidden = message === '';
        syncStatus.classList.toggle('notice-error', isError);
    };

    const lockAttendanceForm = (locked) => {
        attendanceSyncForm.querySelectorAll('input, button[type="submit"]').forEach((control) => {
            control.disabled = locked;
        });
        retryButton.disabled = locked;
        discardButton.disabled = locked;
    };

    const statusLabel = (status) => {
        if (status === null || status === '') return 'Unmarked';
        return status.charAt(0).toUpperCase() + status.slice(1);
    };

    const renderConflicts = (conflicts) => {
        conflictList.replaceChildren();
        conflicts.forEach((conflict) => {
            const input = Array.from(attendanceSyncForm.querySelectorAll('input[type="radio"]'))
                .find((candidate) => candidate.name === `student[${conflict.student_id}]`);
            const name = input?.closest('tr')?.cells[0]?.textContent.trim() || `Student ${conflict.student_id}`;
            const item = document.createElement('li');
            item.textContent = `${name}: current record is ${statusLabel(conflict.current_status)}; draft was ${statusLabel(conflict.queued_status)}.`;
            conflictList.append(item);
        });
        conflictList.hidden = conflicts.length === 0;
    };

    const postPendingDraft = async () => {
        if (pendingEntries === null || requestInProgress) return;
        if (!navigator.onLine) {
            setStatus('Offline draft is held only in this page. Keep this tab open; the draft will be lost if you reload or close it.', true);
            retryButton.disabled = false;
            discardButton.disabled = false;
            retryButton.hidden = true;
            discardButton.hidden = false;
            return;
        }

        requestInProgress = true;
        retryButton.disabled = true;
        setStatus('Saving attendance to the school server...');
        const payload = new FormData();
        pendingEntries.forEach(([name, value]) => payload.append(name, value));
        payload.set('attendance_sync', '1');

        try {
            let response;
            try {
                response = await fetch(attendanceSyncForm.action, {
                    method: 'POST',
                    body: payload,
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });
            } catch (error) {
                if (!(error instanceof TypeError)) throw error;
                setStatus('Offline draft is held only in this page. Keep this tab open; the draft will be lost if you reload or close it.', true);
                retryButton.hidden = !navigator.onLine;
                discardButton.hidden = false;
                return;
            }

            const result = await response.json().catch(() => null);
            if (response.status === 409 && Array.isArray(result?.conflicts)) {
                renderConflicts(result.conflicts);
                setStatus('No draft entries were saved because the register changed. Review these records, then reload and re-enter your choices.', true);
                retryButton.hidden = true;
                discardButton.hidden = false;
                return;
            }
            if (!response.ok || !Array.isArray(result?.records)
                || !/^[a-f0-9]{32}$/.test(result?.next_sync_token || '')) {
                setStatus(result?.error || 'The server response could not be verified. Your draft remains only in this page.', true);
                retryButton.hidden = !navigator.onLine || response.status < 500;
                discardButton.hidden = false;
                return;
            }

            result.records.forEach((record) => {
                const versionInput = attendanceSyncForm.querySelector(
                    `input[name="expected_version[${record.student_id}]"]`,
                );
                if (versionInput) versionInput.value = String(record.row_version);
            });
            syncTokenInput.value = result.next_sync_token;
            pendingEntries = null;
            conflictList.replaceChildren();
            conflictList.hidden = true;
            retryButton.hidden = true;
            discardButton.hidden = true;
            lockAttendanceForm(false);
            setStatus(result.message || 'Attendance saved.');
        } finally {
            requestInProgress = false;
            retryButton.disabled = false;
            discardButton.disabled = false;
        }
    };

    attendanceSyncForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (pendingEntries !== null) {
            setStatus('A draft is already waiting. Sync or discard it before changing attendance.', true);
            return;
        }
        const payload = new FormData(attendanceSyncForm);
        payload.set('attendance_sync', '1');
        pendingEntries = Array.from(payload.entries());
        lockAttendanceForm(true);
        postPendingDraft();
    });

    retryButton.addEventListener('click', () => {
        postPendingDraft();
    });

    discardButton.addEventListener('click', () => {
        pendingEntries = null;
        window.location.reload();
    });

    window.addEventListener('beforeunload', (event) => {
        if (pendingEntries === null) return;
        event.preventDefault();
        event.returnValue = '';
    });

    window.addEventListener('online', () => {
        if (pendingEntries === null) return;
        setStatus('Connection restored. Review the register and choose when to sync the draft.', false);
        retryButton.disabled = false;
        discardButton.disabled = false;
        retryButton.hidden = false;
        discardButton.hidden = false;
    });

    window.addEventListener('offline', () => {
        if (pendingEntries === null) return;
        setStatus('Offline draft is held only in this page. Keep this tab open; the draft will be lost if you reload or close it.', true);
        retryButton.hidden = true;
        discardButton.hidden = false;
    });
}
