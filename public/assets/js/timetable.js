'use strict';

const timetablePrintButton = document.querySelector('[data-timetable-print]');
if (timetablePrintButton instanceof HTMLButtonElement) {
    timetablePrintButton.addEventListener('click', () => window.print());
}

const unavailableType = document.querySelector('#unavailable_type');
const unavailableEntity = document.querySelector('#unavailable_entity');
const unavailableSubmit = document.querySelector('[data-unavailable-submit]');
if (unavailableType instanceof HTMLSelectElement && unavailableEntity instanceof HTMLSelectElement) {
    const updateUnavailableEntities = () => {
        const resourceType = unavailableType.value;
        const matchingOptions = Array.from(unavailableEntity.options)
            .filter((option) => option.dataset.resourceType === resourceType);
        unavailableEntity.querySelectorAll('option[data-resource-type]').forEach((option) => {
            option.hidden = option.dataset.resourceType !== resourceType;
            option.disabled = option.hidden;
        });
        const selectedOption = unavailableEntity.selectedOptions[0];
        if (!selectedOption || selectedOption.disabled) {
            const firstAvailable = matchingOptions.find((option) => !option.disabled);
            if (firstAvailable) {
                firstAvailable.selected = true;
            }
        }
        unavailableEntity.disabled = matchingOptions.length === 0;
        if (unavailableSubmit instanceof HTMLButtonElement) {
            unavailableSubmit.disabled = matchingOptions.length === 0;
        }
    };
    unavailableType.addEventListener('change', updateUnavailableEntities);
    updateUnavailableEntities();
}

const offeringSelector = document.querySelector('#requirement_offering');
const assignedTeacherInput = document.querySelector('[data-assigned-teacher-id]');
const assignedTeacherName = document.querySelector('[data-assigned-teacher-name]');
const requirementSubmit = document.querySelector('[data-requirement-submit]');
if (offeringSelector instanceof HTMLSelectElement
    && assignedTeacherInput instanceof HTMLInputElement
    && assignedTeacherName instanceof HTMLInputElement) {
    const updateAssignedTeacher = () => {
        const option = offeringSelector.selectedOptions[0];
        const teacherId = option?.dataset.assignedTeacher || '';
        assignedTeacherInput.value = teacherId;
        assignedTeacherName.value = option?.dataset.assignedTeacherName || 'No assigned teacher';
        if (requirementSubmit instanceof HTMLButtonElement) {
            requirementSubmit.disabled = teacherId === '' || teacherId === '0';
        }
    };
    offeringSelector.addEventListener('change', updateAssignedTeacher);
    updateAssignedTeacher();
}

document.querySelectorAll('form').forEach((form) => {
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    form.addEventListener('submit', (event) => {
        const action = form.querySelector('input[name="action"]');
        if (action instanceof HTMLInputElement && action.value === 'publish_version'
            && !window.confirm('Publish this timetable for the selected effective date? The prior schedule remains active until then.')) {
            event.preventDefault();
        }
    });
});
