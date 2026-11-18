'use strict';

const jobForm = document.querySelector('[data-job-form]');
const jobToggle = document.querySelector('[data-toggle-job-form]');
if (jobForm && jobToggle) {
    jobToggle.addEventListener('click', () => {
        jobForm.hidden = !jobForm.hidden;
        if (!jobForm.hidden) jobForm.querySelector('input[name="title"]')?.focus();
    });
}
