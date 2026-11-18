'use strict';

const kioskAutoReset = document.querySelector('[data-kiosk-auto-reset]');
if (kioskAutoReset instanceof HTMLElement) {
    const resetUrl = kioskAutoReset.dataset.resetUrl;
    const countdown = kioskAutoReset.querySelector('[data-kiosk-countdown]');
    const resetSeconds = Number.parseInt(kioskAutoReset.dataset.resetSeconds || '', 10);
    if (resetUrl && countdown instanceof HTMLElement && Number.isInteger(resetSeconds) && resetSeconds > 0) {
        let remainingSeconds = resetSeconds;
        const timer = window.setInterval(() => {
            remainingSeconds -= 1;
            countdown.textContent = String(remainingSeconds);
            if (remainingSeconds <= 0) {
                window.clearInterval(timer);
                window.location.replace(resetUrl);
            }
        }, 1_000);
    }
}

const kioskIdleReset = document.querySelector('[data-kiosk-idle-reset]');
if (kioskIdleReset instanceof HTMLFormElement) {
    const resetUrl = kioskIdleReset.dataset.resetUrl;
    const timeoutSeconds = Number.parseInt(kioskIdleReset.dataset.idleSeconds || '', 10);
    if (resetUrl && Number.isInteger(timeoutSeconds) && timeoutSeconds > 0) {
        let timer = 0;
        const restartIdleTimer = () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => window.location.replace(resetUrl), timeoutSeconds * 1_000);
        };
        ['pointerdown', 'keydown', 'input', 'change', 'touchstart'].forEach((eventName) => {
            kioskIdleReset.addEventListener(eventName, restartIdleTimer, { passive: true });
        });
        restartIdleTimer();
    }
}

const sidebarNav = document.querySelector('.sidebar-nav');
if (sidebarNav instanceof HTMLElement) {
    const currentUrl = new URL(window.location.href);
    const routeLinks = Array.from(sidebarNav.querySelectorAll('a[href]'))
        .filter((link) => new URL(link.href, currentUrl).pathname === currentUrl.pathname);
    const exactQueryMatch = routeLinks.find((link) => {
        const target = new URL(link.href, currentUrl);
        return target.search !== '' && Array.from(target.searchParams)
            .every(([key, value]) => currentUrl.searchParams.get(key) === value)
            && (target.hash === '' || target.hash === currentUrl.hash);
    });
    const identityKeys = new Set(['type', 'module', 'mode', 'view', 'section']);
    const contextualQueryMatch = routeLinks
        .map((link) => {
            const target = new URL(link.href, currentUrl);
            const identityParams = Array.from(target.searchParams)
                .filter(([key]) => identityKeys.has(key));
            const matches = identityParams.length > 0
                && identityParams.every(([key, value]) => currentUrl.searchParams.get(key) === value);
            return { link, matches, specificity: identityParams.length };
        })
        .filter((candidate) => candidate.matches)
        .sort((left, right) => right.specificity - left.specificity)[0]?.link;
    const exactHashMatch = routeLinks.find((link) => {
        const target = new URL(link.href, currentUrl);
        return target.hash !== '' && target.hash === currentUrl.hash;
    });
    const defaultRouteMatch = routeLinks.find((link) => {
        const target = new URL(link.href, currentUrl);
        return target.search === '' && target.hash === '';
    });
    const activeLink = exactQueryMatch
        || contextualQueryMatch
        || exactHashMatch
        || defaultRouteMatch
        || sidebarNav.querySelector('a.active');
    const sidebarLinks = Array.from(sidebarNav.querySelectorAll('a[href]'));
    sidebarLinks.forEach((link) => {
        link.classList.remove('active');
        link.removeAttribute('aria-current');
    });
    if (activeLink) {
        activeLink.classList.add('active');
        activeLink.setAttribute('aria-current', 'page');
    }

    const navigationGroups = Array.from(sidebarNav.querySelectorAll('.sidebar-group-label'))
        .map((heading) => {
            const links = [];
            for (let sibling = heading.nextElementSibling;
                sibling && !sibling.classList.contains('sidebar-group-label')
                    && !sibling.classList.contains('sidebar-divider');
                sibling = sibling.nextElementSibling) {
                if (sibling instanceof HTMLAnchorElement) links.push(sibling);
            }
            if (links.length === 0) return null;

            const toggle = document.createElement('button');
            toggle.className = 'sidebar-group-toggle';
            toggle.type = 'button';
            toggle.textContent = heading.textContent.trim();
            toggle.setAttribute('aria-expanded', 'false');
            heading.replaceChildren(toggle);
            return { heading, links, toggle, expanded: links.includes(activeLink) };
        })
        .filter((group) => group !== null);

    navigationGroups.forEach((group) => {
        const setExpanded = (expanded) => {
            group.expanded = expanded;
            group.toggle.setAttribute('aria-expanded', String(expanded));
            group.links.forEach((link) => {
                link.hidden = !expanded;
            });
        };
        group.toggle.addEventListener('click', () => setExpanded(!group.expanded));
        group.setExpanded = setExpanded;
        group.setExpanded(group.expanded);
    });

    sidebarLinks.forEach((link) => {
        const labelNodes = Array.from(link.childNodes).filter((node) =>
            node.nodeType === Node.TEXT_NODE && node.textContent.trim() !== '');
        if (labelNodes.length === 0) return;
        const label = document.createElement('span');
        label.className = 'sidebar-link-label';
        label.textContent = labelNodes.map((node) => node.textContent.trim()).join(' ');
        labelNodes[0].before(label);
        labelNodes.forEach((node) => node.remove());
    });

    const sidebarSearch = sidebarNav.querySelector('[data-sidebar-search]');
    const sidebarSearchLabel = sidebarSearch?.closest('.sidebar-search');
    const sidebarSearchEmpty = sidebarNav.querySelector('[data-sidebar-search-empty]');
    if (sidebarSearch instanceof HTMLInputElement
        && sidebarSearchLabel instanceof HTMLElement
        && sidebarSearchEmpty instanceof HTMLElement) {
        const links = Array.from(sidebarNav.querySelectorAll('a[href]'));
        const searchableText = new Map(links.map((link) => {
            const pathTerms = new URL(link.href, currentUrl).pathname
                .split('/')
                .pop()
                .replace(/\.php$/i, '')
                .replace(/[_-]+/g, ' ');
            return [link, `${link.textContent || ''} ${pathTerms}`.toLocaleLowerCase()];
        }));

        const filterNavigation = () => {
            const query = sidebarSearch.value.trim().toLocaleLowerCase();
            let visibleCount = 0;
            links.forEach((link) => {
                const matches = query === '' || searchableText.get(link).includes(query);
                const group = navigationGroups.find((candidate) => candidate.links.includes(link));
                link.hidden = !matches || (query === '' && group && !group.expanded);
                if (matches) visibleCount++;
            });
            sidebarNav.querySelectorAll('.sidebar-group-label').forEach((heading) => {
                let hasVisibleLink = false;
                for (let sibling = heading.nextElementSibling;
                    sibling && !sibling.classList.contains('sidebar-group-label');
                    sibling = sibling.nextElementSibling) {
                    if (sibling instanceof HTMLAnchorElement && !sibling.hidden) {
                        hasVisibleLink = true;
                        break;
                    }
                }
                heading.hidden = query !== '' && !hasVisibleLink;
            });
            navigationGroups.forEach((group) => {
                const hasMatch = group.links.some((link) => !link.hidden);
                group.toggle.setAttribute('aria-expanded', String(
                    query !== '' ? hasMatch : group.expanded
                ));
            });
            sidebarSearchEmpty.hidden = query === '' || visibleCount > 0;
        };

        sidebarSearchLabel.hidden = false;
        sidebarSearch.addEventListener('input', filterNavigation);
        sidebarSearch.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && sidebarSearch.value !== '') {
                sidebarSearch.value = '';
                filterNavigation();
            }
        });
    }
}

const actionMain = document.querySelector('main.application-content');
const dirtyForms = new Set();
const actionGrids = actionMain instanceof HTMLElement
    ? Array.from(actionMain.querySelectorAll('.report-action-grid'))
    : [];
if (actionMain instanceof HTMLElement && actionGrids.length > 0) {
    let activeWorkspace = null;
    let lastKnownUrl = window.location.href;
    let lastKnownState = window.history.state;

    actionGrids.forEach((grid) => {
        grid.tabIndex = -1;
    });

    const resetForm = (form) => {
        Array.from(form.elements).forEach((control) => {
            if (!(control instanceof HTMLElement)) return;
            if (control instanceof HTMLInputElement && control.type === 'checkbox') {
                control.checked = control.defaultChecked;
            } else if (control instanceof HTMLInputElement && control.type === 'radio') {
                control.checked = control.defaultChecked;
            } else if (control instanceof HTMLSelectElement) {
                Array.from(control.options).forEach((option) => {
                    option.selected = option.defaultSelected;
                });
            } else if (control instanceof HTMLInputElement && control.type === 'file') {
                control.value = '';
            } else if (control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement) {
                control.value = control.defaultValue;
            }
        });
        dirtyForms.delete(form);
    };

    const confirmDiscard = () => {
        if (dirtyForms.size === 0) return true;
        return window.confirm('You have unsaved changes. Discard them and leave this screen?');
    };

    const discardDirtyForms = (within = actionMain) => {
        Array.from(dirtyForms).forEach((form) => {
            if (within === actionMain || within.contains(form)) resetForm(form);
        });
    };

    const closeWorkspace = (preserveUrl = false, focusGrid = true) => {
        if (!activeWorkspace) return;
        activeWorkspace.nodes.forEach(({ node, placeholder }) => {
            placeholder.replaceWith(node);
        });
        activeWorkspace.visibility.forEach((wasHidden, node) => {
            node.hidden = wasHidden;
        });
        activeWorkspace.element.remove();
        document.body.classList.remove('action-workspace-active');
        const grid = activeWorkspace.grid;
        activeWorkspace = null;
        if (!preserveUrl && window.location.hash) {
            window.history.replaceState(
                { ...(window.history.state || {}), applicationWorkspace: null },
                '',
                window.location.pathname + window.location.search
            );
        }
        if (focusGrid) grid.focus({ preventScroll: true });
    };

    const openWorkspace = (key, grid = actionGrids[0], updateUrl = true) => {
        const nodes = Array.from(actionMain.querySelectorAll('[data-action-view]'))
            .filter((node) => node.dataset.actionView === key);
        if (nodes.length === 0) return false;
        if (activeWorkspace) closeWorkspace(true, false);
        const visibility = new Map();

        const workspace = document.createElement('section');
        workspace.className = 'action-task-workspace';
        workspace.setAttribute('aria-live', 'polite');
        workspace.setAttribute('aria-label', nodes[0].getAttribute('aria-label') || 'Selected workspace');
        const toolbar = document.createElement('div');
        toolbar.className = 'action-task-toolbar';
        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'button button-quiet';
        closeButton.textContent = 'Back to actions';
        closeButton.setAttribute('aria-label', 'Back to available actions');
        closeButton.addEventListener('click', () => {
            if (!confirmDiscard()) return;
            discardDirtyForms();
            if (window.history.state?.applicationWorkspace) {
                window.history.back();
                return;
            }
            if (grid.dataset.actionReturn) {
                window.location.assign(grid.dataset.actionReturn);
                return;
            }
            closeWorkspace();
            lastKnownUrl = window.location.href;
            lastKnownState = window.history.state;
        });
        toolbar.append(closeButton);
        workspace.append(toolbar);

        const nodeVisibility = new Map(nodes.map((node) => [node, node.hidden]));
        const placeholders = nodes.map((node) => {
            const placeholder = document.createComment('action-view-placeholder');
            node.before(placeholder);
            node.hidden = false;
            return { node, placeholder };
        });
        placeholders.forEach(({ node }) => workspace.append(node));
        actionMain.append(workspace);

        Array.from(actionMain.children).forEach((element) => {
            if (element === workspace || !(element instanceof HTMLElement)
                || element.classList.contains('notice')) return;
            visibility.set(element, element.hidden);
            element.hidden = true;
        });
        actionGrids.forEach((actionGrid) => {
            if (visibility.has(actionGrid)) return;
            visibility.set(actionGrid, actionGrid.hidden);
            actionGrid.hidden = true;
        });
        nodes.forEach((node) => {
            visibility.set(node, nodeVisibility.get(node));
            node.hidden = false;
        });
        activeWorkspace = { element: workspace, nodes: placeholders, visibility, grid };
        document.body.classList.add('action-workspace-active');
        closeButton.focus({ preventScroll: true });
        const focusTarget = key === 'report-view'
            ? workspace.querySelector('#edit-report-details') || workspace
            : workspace;
        focusTarget.scrollIntoView({ block: 'start', behavior: 'auto' });
        if (updateUrl) {
            const state = {
                ...(window.history.state || {}),
                applicationWorkspace: { key, gridIndex: actionGrids.indexOf(grid) },
            };
            window.history.pushState(state, '', `${window.location.pathname}${window.location.search}#${encodeURIComponent(key)}`);
        }
        lastKnownUrl = window.location.href;
        lastKnownState = window.history.state;
        return true;
    };

    actionMain.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const link = target?.closest('a[data-action-open], a[href*="#"]');
        if (!(link instanceof HTMLAnchorElement)) return;
        const destination = new URL(link.href, window.location.href);
        if (destination.pathname !== window.location.pathname || destination.search !== window.location.search) return;
        const hashKey = decodeURIComponent(destination.hash.slice(1));
        const key = link.dataset.actionOpen
            || (hashKey && Array.from(actionMain.querySelectorAll('[data-action-view]'))
                .some((node) => node.dataset.actionView === hashKey) ? hashKey : '');
        if (!key || !Array.from(actionMain.querySelectorAll('[data-action-view]'))
            .some((node) => node.dataset.actionView === key)) return;
        if (dirtyForms.size > 0 && !confirmDiscard()) {
            event.preventDefault();
            return;
        }
        discardDirtyForms();
        const owningGrid = link.closest('.report-action-grid');
        if (openWorkspace(key, owningGrid instanceof HTMLElement ? owningGrid : actionGrids[0])) {
            event.preventDefault();
        }
    });

    const restoreFromHistory = () => {
        if (dirtyForms.size > 0 && !confirmDiscard()) {
            window.history.pushState(lastKnownState, '', lastKnownUrl);
            return;
        }
        discardDirtyForms();
        const hash = window.location.hash.slice(1);
        if (!hash) {
            closeWorkspace(true);
            lastKnownUrl = window.location.href;
            lastKnownState = window.history.state;
            return;
        }
        const workspaceState = window.history.state?.applicationWorkspace;
        const key = typeof workspaceState === 'object' && workspaceState !== null
            ? workspaceState.key
            : hash;
        const gridIndex = typeof workspaceState === 'object' && workspaceState !== null
            ? workspaceState.gridIndex
            : 0;
        const grid = actionGrids[gridIndex] || actionGrids[0];
        const normalizedKey = key === 'edit-report-details' ? 'report-view' : key;
        if (!openWorkspace(decodeURIComponent(normalizedKey), grid, false)) closeWorkspace(true);
        lastKnownUrl = window.location.href;
        lastKnownState = window.history.state;
    };
    window.addEventListener('popstate', restoreFromHistory);

    const initialHash = window.location.hash.slice(1);
    const initialTarget = Array.from(actionMain.querySelectorAll('[data-action-view]'))
        .find((node) => node.dataset.actionView === initialHash);
    const initialGrid = initialTarget?.closest('.report-action-grid');
    const initialKey = initialHash === 'edit-report-details'
        ? 'report-view'
        : (initialHash || actionGrids.find((grid) => grid.dataset.actionStart)?.dataset.actionStart || '');
    if (initialKey) {
        const defaultGrid = actionGrids.find((grid) => grid.dataset.actionStart) || actionGrids[0];
        openWorkspace(
            decodeURIComponent(initialKey),
            initialGrid instanceof HTMLElement ? initialGrid : defaultGrid,
            false
        );
    }
    document.documentElement.classList.add('action-workspace-ready');
}

if (actionMain instanceof HTMLElement) {
    actionMain.addEventListener('toggle', (event) => {
        const opened = event.target;
        if (!(opened instanceof HTMLDetailsElement) || !opened.open || !opened.querySelector('form')) return;
        const scope = opened.closest('.action-task-workspace') || actionMain;
        scope.querySelectorAll('details[open]').forEach((details) => {
            if (details === opened || details.contains(opened) || opened.contains(details)
                || !details.querySelector('form')) return;
            details.open = false;
        });
    }, true);

    actionMain.addEventListener('input', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const form = target?.closest('form');
        if (form instanceof HTMLFormElement) dirtyForms.add(form);
    });
    actionMain.addEventListener('change', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const form = target?.closest('form');
        if (form instanceof HTMLFormElement) dirtyForms.add(form);
    });
    actionMain.addEventListener('submit', (event) => {
        if (event.target instanceof HTMLFormElement) dirtyForms.delete(event.target);
    }, true);
}
window.addEventListener('beforeunload', (event) => {
    if (dirtyForms.size === 0) return;
    event.preventDefault();
    event.returnValue = '';
});

const headerClock = document.querySelector('[data-header-clock]');
if (headerClock instanceof HTMLTimeElement) {
    const dateOutput = headerClock.querySelector('[data-header-clock-date]');
    const timeOutput = headerClock.querySelector('[data-header-clock-time]');
    const timeZone = headerClock.dataset.timezone || 'UTC';
    if (dateOutput instanceof HTMLElement && timeOutput instanceof HTMLElement) {
        const compactDateFormatter = new Intl.DateTimeFormat('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            timeZone,
        });
        const dateFormatter = new Intl.DateTimeFormat('en-GB', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            timeZone,
        });
        const timeFormatter = new Intl.DateTimeFormat('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true,
            timeZone,
        });
        const updateHeaderClock = () => {
            const now = new Date();
            dateOutput.textContent = window.matchMedia('(max-width: 780px)').matches
                ? compactDateFormatter.format(now)
                : dateFormatter.format(now);
            timeOutput.textContent = timeFormatter.format(now);
            headerClock.dateTime = now.toISOString();
        };
        updateHeaderClock();
        window.setInterval(updateHeaderClock, 1_000);
    }
}

document.querySelectorAll('[data-print-button]').forEach((button) => {
    button.addEventListener('click', async () => {
        if (button.closest('.report-card-page')) document.body.classList.add('report-card-print-active');
        if (button.closest('.payment-receipt-page')) document.body.classList.add('payment-receipt-print-active');
        await document.fonts.ready;
        await Promise.all(Array.from(document.querySelectorAll('.terminal-report-card img')).map((image) => {
            if (image.complete) return Promise.resolve();
            return new Promise((resolve) => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            });
        }));
        window.print();
    });
});
window.addEventListener('afterprint', () => {
    document.body.classList.remove('payment-receipt-print-active');
});
document.querySelectorAll('[data-print-individual-report]').forEach((button) => {
    button.addEventListener('click', async () => {
        document.body.classList.add('individual-report-print-active');
        await document.fonts.ready;
        await Promise.all(Array.from(document.querySelectorAll('.individual-report-output img')).map((image) => {
            if (image.complete) return Promise.resolve();
            return new Promise((resolve) => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            });
        }));
        window.print();
    });
});
document.querySelectorAll('[data-report-card-selector]').forEach((form) => {
    const year = form.querySelector('[data-report-year]');
    const term = form.querySelector('[data-report-term]');
    const classSelect = form.querySelector('[data-report-class]');
    const student = form.querySelector('[data-report-student]');
    const assessment = form.querySelector('[data-report-assessment]');
    const selectorHelp = form.querySelector('[data-report-selector-help]');
    if (!(year instanceof HTMLSelectElement) || !(term instanceof HTMLSelectElement)
        || !(classSelect instanceof HTMLSelectElement)) return;
    let submitting = false;

    const filterOptions = (select, predicate) => {
        Array.from(select.options).slice(1).forEach((option) => {
            option.hidden = !predicate(option);
            option.disabled = option.hidden;
        });
        if (select.selectedOptions[0]?.hidden) select.value = '';
    };
    const updateChoices = () => {
        const selectedYear = year.value;
        filterOptions(term, (option) => !selectedYear || option.dataset.academicYear === selectedYear);
        filterOptions(classSelect, (option) => !selectedYear || option.dataset.academicYear === selectedYear);
        if (student instanceof HTMLSelectElement) {
            const selectedClass = classSelect.value;
            filterOptions(student, (option) => Boolean(selectedClass)
                && option.dataset.classId === selectedClass
                && option.dataset.academicYear === selectedYear);
        }
        const hasStudent = !(student instanceof HTMLSelectElement) || student.value !== '';
        const hasAssessment = !(assessment instanceof HTMLSelectElement) || assessment.value !== '';
        const canGenerate = Boolean(selectedYear && term.value && classSelect.value && hasStudent && hasAssessment);
        if (selectorHelp instanceof HTMLElement) {
            selectorHelp.textContent = canGenerate
                ? 'Opening the selected report…'
                : 'Select an academic year, term, class, student, and examination where prompted. The report opens when your selections are complete.';
        }
        return canGenerate;
    };
    [year, term, classSelect, student, assessment].forEach((select) => {
        if (select instanceof HTMLSelectElement) {
            select.addEventListener('change', () => {
                if (select !== assessment && assessment instanceof HTMLSelectElement) assessment.value = '';
                const canGenerate = updateChoices();
                if (canGenerate && !submitting && form.reportValidity()) {
                    submitting = true;
                    form.requestSubmit();
                }
            });
        }
    });
    updateChoices();
});
document.querySelectorAll('[data-report-card-editor] form').forEach((form) => {
    let hasUnsavedChanges = false;
    form.addEventListener('input', () => { hasUnsavedChanges = true; });
    form.addEventListener('change', () => { hasUnsavedChanges = true; });
    form.addEventListener('submit', () => { hasUnsavedChanges = false; });
    window.addEventListener('beforeunload', (event) => {
        if (!hasUnsavedChanges) return;
        event.preventDefault();
        event.returnValue = '';
    });
    document.querySelectorAll('.report-review-navigation a').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (hasUnsavedChanges && !window.confirm('You have unsaved report details. Leave without saving?')) {
                event.preventDefault();
            }
        });
    });
});
document.querySelectorAll('[data-register-student-search]').forEach((search) => {
    const select = document.getElementById('register-report-student');
    if (!select) return;
    const options = Array.from(select.options).slice(1);
    search.addEventListener('input', () => {
        const query = search.value.trim().toLocaleLowerCase();
        options.forEach((option) => {
            option.hidden = query !== '' && !option.textContent.toLocaleLowerCase().includes(query);
        });
        if (select.selectedOptions.length && select.selectedOptions[0].hidden) select.value = '';
    });
});
window.addEventListener('afterprint', () => document.body.classList.remove('report-card-print-active'));
window.addEventListener('afterprint', () => document.body.classList.remove('individual-report-print-active'));

const fitReportCardsToPrintPage = () => {
    document.querySelectorAll('.terminal-report-card').forEach((card) => {
        if (!(card instanceof HTMLElement)) return;
        card.style.removeProperty('--report-print-scale');
        const height = Math.max(card.getBoundingClientRect().height, card.scrollHeight);
        if (height <= 0) return;
        const printableHeight = 280 * 96 / 25.4;
        card.style.setProperty('--report-print-scale', String(Math.min(1, printableHeight / height)));
    });
};

window.addEventListener('beforeprint', fitReportCardsToPrintPage);
window.addEventListener('afterprint', () => {
    document.querySelectorAll('.terminal-report-card').forEach((card) => {
        if (card instanceof HTMLElement) card.style.removeProperty('--report-print-scale');
    });
});
document.querySelectorAll('[data-role-decision]').forEach((button) => {
    button.addEventListener('click', () => {
        const form = button.closest('form');
        const reasonPanel = form?.querySelector('[data-role-decision-reason]');
        const reasonInput = reasonPanel?.querySelector('textarea[name="decision_reason"]');
        if (!(reasonPanel instanceof HTMLElement) || !(reasonInput instanceof HTMLTextAreaElement)) return;
        const reasonRequired = button.value === 'reject' || button.value === 'transfer';
        reasonPanel.hidden = !reasonRequired;
        reasonInput.disabled = !reasonRequired;
        reasonInput.required = reasonRequired;
    });
});
const schoolSearchInput = document.getElementById('school-search');
const schoolSearchResults = document.querySelector('[data-school-search-results]');
if (schoolSearchInput instanceof HTMLInputElement && schoolSearchResults instanceof HTMLElement) {
    const schoolOptions = [...schoolSearchResults.querySelectorAll('[data-school-option]')];
    const emptyMessage = document.querySelector('[data-school-search-empty]');
    const searchStatus = document.getElementById('school-search-status');
    const schoolConfirmation = document.querySelector('[data-school-confirmation]');
    const confirmationIdentity = document.querySelector('[data-selected-school-identity]');
    const confirmationLocation = document.querySelector('[data-selected-school-location]');
    const confirmationCheckbox = document.querySelector('[data-confirm-school]');
    const confirmedSchoolId = document.querySelector('[data-confirmed-school-id]');
    const continueButton = document.querySelector('[data-school-continue]');
    let currentSchoolId = '';
    const normalizeSchoolSearch = (value) => value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase()
        .trim()
        .replace(/\s+/g, ' ');
    const updateSchoolConfirmation = (resetConfirmation = false) => {
        const selectedRadio = schoolSearchResults.querySelector('input[type="radio"]:checked');
        const selectedOption = selectedRadio?.closest('[data-school-option]');
        if (!(selectedRadio instanceof HTMLInputElement) || !(selectedOption instanceof HTMLElement)) {
            if (schoolConfirmation instanceof HTMLElement) schoolConfirmation.hidden = true;
            if (confirmationCheckbox instanceof HTMLInputElement) confirmationCheckbox.checked = false;
            if (confirmedSchoolId instanceof HTMLInputElement) confirmedSchoolId.value = '';
            if (continueButton instanceof HTMLButtonElement) continueButton.disabled = true;
            currentSchoolId = '';
            return;
        }

        if (resetConfirmation || currentSchoolId !== selectedRadio.value) {
            currentSchoolId = selectedRadio.value;
            const identity = selectedOption.querySelector('.school-identity');
            if (confirmationIdentity instanceof HTMLElement && identity instanceof HTMLElement) {
                confirmationIdentity.replaceChildren(identity.cloneNode(true));
            }
            if (confirmationLocation instanceof HTMLElement) {
                confirmationLocation.textContent = selectedOption.querySelector('.registration-school-location')?.textContent.trim() || '';
            }
            if (confirmationCheckbox instanceof HTMLInputElement) confirmationCheckbox.checked = false;
            if (confirmedSchoolId instanceof HTMLInputElement) confirmedSchoolId.value = '';
        }
        if (schoolConfirmation instanceof HTMLElement) schoolConfirmation.hidden = false;
        if (continueButton instanceof HTMLButtonElement) {
            continueButton.disabled = !(confirmationCheckbox instanceof HTMLInputElement && confirmationCheckbox.checked);
        }
    };
    const filterSchools = (resetConfirmation = false) => {
        const query = normalizeSchoolSearch(schoolSearchInput.value);
        let visibleCount = 0;
        schoolOptions.forEach((option) => {
            const matches = query === '' || normalizeSchoolSearch(option.dataset.schoolSearch || '').includes(query);
            option.hidden = !matches;
            visibleCount += matches ? 1 : 0;
            const radio = option.querySelector('input[type="radio"]');
            if (radio instanceof HTMLInputElement && radio.checked && !matches) radio.checked = false;
            option.classList.toggle('is-selected', radio instanceof HTMLInputElement && radio.checked);
        });
        if (emptyMessage instanceof HTMLElement) emptyMessage.hidden = visibleCount > 0;
        if (searchStatus instanceof HTMLElement) {
            searchStatus.textContent = query === ''
                ? 'Search by school name, short name, region, or district.'
                : `${visibleCount} matching school${visibleCount === 1 ? '' : 's'}.`;
        }
        updateSchoolConfirmation(resetConfirmation);
    };
    schoolSearchInput.addEventListener('input', filterSchools);
    schoolOptions.forEach((option) => {
        option.querySelector('input[type="radio"]')?.addEventListener('change', () => filterSchools(true));
    });
    confirmationCheckbox?.addEventListener('change', () => {
        if (confirmedSchoolId instanceof HTMLInputElement) {
            confirmedSchoolId.value = confirmationCheckbox.checked ? currentSchoolId : '';
        }
        if (continueButton instanceof HTMLButtonElement) continueButton.disabled = !confirmationCheckbox.checked;
    });
    filterSchools();
}

const classScoreWeightInput = document.getElementById('class-score-weight');
const examScoreWeightInput = document.getElementById('exam-score-weight');
if (classScoreWeightInput instanceof HTMLInputElement && examScoreWeightInput instanceof HTMLInputElement) {
    classScoreWeightInput.addEventListener('input', () => {
        const classWeight = Number(classScoreWeightInput.value);
        examScoreWeightInput.value = Number.isFinite(classWeight) && classWeight >= 0 && classWeight <= 100
            ? (100 - classWeight).toFixed(2)
            : '';
    });
}

const gradeRuleList = document.querySelector('[data-grade-rule-list]');
if (gradeRuleList instanceof HTMLTableSectionElement) {
    gradeRuleList.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLButtonElement)) return;
        if (!target.hasAttribute('data-remove-grade-rule')) return;
        const rows = gradeRuleList.querySelectorAll('[data-grade-rule-row]');
        if (rows.length > 1) target.closest('[data-grade-rule-row]')?.remove();
    });
    document.querySelector('[data-add-grade-rule]')?.addEventListener('click', (event) => {
        const target = event.currentTarget;
        if (!(target instanceof HTMLButtonElement)) return;
        const rows = [...gradeRuleList.querySelectorAll('[data-grade-rule-row]')];
        if (rows.length >= 12) {
            target.textContent = 'Maximum of 12 grade rules';
            target.disabled = true;
            return;
        }
        const row = rows[0]?.cloneNode(true);
        if (!(row instanceof HTMLTableRowElement)) return;
        row.querySelectorAll('input').forEach((input) => { input.value = ''; });
        const orderInput = row.querySelector('[name="grade_order[]"]');
        const nextOrder = Math.max(0, ...rows.map((item) => Number(item.querySelector('[name="grade_order[]"]')?.value) || 0)) + 1;
        if (orderInput instanceof HTMLInputElement) orderInput.value = String(nextOrder);
        gradeRuleList.append(row);
    });
}

document.querySelectorAll('[data-marks-entry-form]').forEach((form) => {
    let hasUnsavedMarks = false;
    let isSubmitting = false;
    let gradeRules = [];
    try {
        gradeRules = JSON.parse(form.dataset.gradeRules || '[]');
    } catch {
        gradeRules = [];
    }
    const updateCalculatedResult = (row) => {
        const inputs = [...row.querySelectorAll('[data-mark-component]')];
        const totalOutput = row.querySelector('[data-calculated-total]');
        const gradeOutput = row.querySelector('[data-calculated-grade]');
        const remarkOutput = row.querySelector('[data-calculated-remark]');
        if (!inputs.length || !(totalOutput instanceof HTMLOutputElement)
            || !(gradeOutput instanceof HTMLOutputElement)
            || !(remarkOutput instanceof HTMLOutputElement)) return;
        if (inputs.some((input) => input.value.trim() === '')) {
            totalOutput.value = '—';
            gradeOutput.value = '—';
            remarkOutput.value = '—';
            return;
        }
        const classInput = row.querySelector('[data-mark-component="class"]');
        const examInput = row.querySelector('[data-mark-component="exam"]');
        const classScore = classInput instanceof HTMLInputElement ? Number(classInput.value) : 0;
        const examScore = examInput instanceof HTMLInputElement ? Number(examInput.value) : 0;
        const classMaximum = Number(row.dataset.classScoreMaximum || 0);
        const examMaximum = Number(row.dataset.examScoreMaximum || 0);
        if (!Number.isFinite(classScore) || !Number.isFinite(examScore)
            || classScore < 0 || classScore > classMaximum
            || examScore < 0 || examScore > examMaximum) {
            totalOutput.value = '—';
            gradeOutput.value = '—';
            remarkOutput.value = '—';
            return;
        }
        const total = inputs.reduce((sum, input) => sum + Number(input.value), 0);
        const maximum = Number(row.dataset.totalMarks || 0);
        const grade = gradeRules
            .filter((rule) => maximum > 0 && total / maximum * 100 >= Number(rule.minimum_percent))
            .sort((left, right) => Number(right.minimum_percent) - Number(left.minimum_percent))[0];
        totalOutput.value = total.toFixed(2);
        gradeOutput.value = grade?.grade || '—';
        remarkOutput.value = grade?.remark || '—';
    };
    const rows = [...form.querySelectorAll('[data-result-row]')];
    rows.forEach((row) => {
        row.querySelectorAll('[data-mark-component]').forEach((input) => {
            input.addEventListener('input', () => updateCalculatedResult(row));
        });
    });
    const entryRows = [...form.querySelectorAll('[data-entry-row]')];
    const isComplete = (row) => {
        const inputs = [...row.querySelectorAll('[data-mark-component]')];
        return inputs.length > 0 && inputs.every((input) => input.value.trim() !== '');
    };
    const hasInvalidMark = (row) => [...row.querySelectorAll('[data-mark-component]')].some((input) => {
        if (input.value.trim() === '') return false;
        const value = Number(input.value);
        const maximum = Number(input.max);
        return !Number.isFinite(value) || value < 0 || value > maximum;
    });
    const updateEntryProgress = () => {
        const status = form.querySelector('[data-entry-progress]');
        if (!(status instanceof HTMLElement)) return;
        const completeCount = entryRows.filter(isComplete).length;
        const missingCount = entryRows.filter((row) => !isComplete(row) && !hasInvalidMark(row)).length;
        const invalidCount = entryRows.filter(hasInvalidMark).length;
        status.textContent = `Current form: ${completeCount} complete, ${missingCount} incomplete, ${invalidCount} invalid.`;
    };
    form.querySelector('[data-add-another-student]')?.addEventListener('click', () => {
        const nextRow = entryRows.find((row) => !isComplete(row));
        if (!nextRow) {
            const status = form.querySelector('[data-entry-progress]');
            if (status instanceof HTMLElement) {
                status.textContent = 'Every eligible student currently has marks entered. Review the table before saving.';
            }
            return;
        }
        nextRow.querySelector('[data-mark-component]')?.focus();
        nextRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    form.querySelector('[data-review-records]')?.addEventListener('click', () => {
        updateEntryProgress();
        form.querySelector('[data-result-row]')?.closest('table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    entryRows.forEach((row) => {
        row.querySelectorAll('[data-mark-component]').forEach((input) => {
            input.addEventListener('input', () => {
                hasUnsavedMarks = true;
                updateEntryProgress();
            });
        });
    });
    updateEntryProgress();
    form.querySelector('[data-student-picker]')?.addEventListener('change', (event) => {
        const studentId = event.currentTarget.value;
        const row = entryRows.find((candidate) => candidate.dataset.studentId === studentId);
        if (!row) return;
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.querySelector('[data-mark-component]')?.focus();
    });
    form.querySelectorAll('[data-edit-student-record]').forEach((button) => {
        button.addEventListener('click', () => {
            const studentId = button.dataset.editStudentRecord;
            const row = entryRows.find((candidate) => candidate.dataset.studentId === studentId);
            if (!row) return;
            row.querySelector('[data-mark-component]')?.focus();
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });
    form.addEventListener('submit', (event) => {
        if (event.submitter instanceof HTMLButtonElement && event.submitter.value === 'submit_results') {
            const completeCount = entryRows.filter(isComplete).length;
            const missingCount = entryRows.length - completeCount;
            const invalidCount = entryRows.filter(hasInvalidMark).length;
            const summary = form.dataset.assessmentSummary || 'Selected examination batch';
            if (!window.confirm(`${summary}\nStudents with entered marks: ${completeCount} of ${entryRows.length}\nStudents missing marks: ${missingCount}\nStudents with invalid marks: ${invalidCount}\n\nSubmit all records to the assigned Class Teacher?`)) {
                event.preventDefault();
            }
        }
        if (!event.defaultPrevented) isSubmitting = true;
    });
    window.addEventListener('beforeunload', (event) => {
        if (!hasUnsavedMarks || isSubmitting) return;
        event.preventDefault();
        event.returnValue = '';
    });
});

const classEditorDialog = document.querySelector('[data-class-editor-dialog]');
if (classEditorDialog instanceof HTMLDialogElement) {
    document.querySelector('[data-open-class-dialog]')?.addEventListener('click', () => classEditorDialog.showModal());
    document.querySelector('[data-close-class-dialog]')?.addEventListener('click', () => classEditorDialog.close());
    if (classEditorDialog.dataset.autoOpen === 'true') classEditorDialog.showModal();
}

const admissionDateInput = document.querySelector('[data-admission-date]');
const admissionNumberInput = document.querySelector('[data-admission-number]');
const admissionNumberStatus = document.querySelector('[data-admission-number-status]');
if (admissionDateInput instanceof HTMLInputElement
    && admissionNumberInput instanceof HTMLInputElement
    && admissionNumberStatus instanceof HTMLElement) {
    let admissionPreviewController;
    const refreshAdmissionNumber = async () => {
        admissionPreviewController?.abort();
        if (!admissionDateInput.value) {
            admissionNumberInput.value = '';
            admissionNumberStatus.textContent = 'Enter the admission date to preview its number.';
            return;
        }
        admissionPreviewController = new AbortController();
        admissionNumberInput.value = '';
        admissionNumberStatus.textContent = 'Loading the next number…';
        try {
            const response = await fetch(
                `admission_number_preview.php?admission_date=${encodeURIComponent(admissionDateInput.value)}`,
                { headers: { Accept: 'application/json' }, signal: admissionPreviewController.signal }
            );
            const result = await response.json();
            if (!response.ok || typeof result.admission_number !== 'string') {
                throw new Error(result.error || 'The admission number preview is unavailable.');
            }
            admissionNumberInput.value = result.admission_number;
            admissionNumberStatus.textContent = 'Suggested number; the server confirms the next number when saved.';
        } catch (error) {
            if (error instanceof Error && error.name === 'AbortError') return;
            admissionNumberInput.value = '';
            admissionNumberStatus.textContent = error instanceof Error
                ? error.message
                : 'The admission number preview is unavailable. The server will validate the number when saved.';
        }
    };
    admissionDateInput.addEventListener('change', refreshAdmissionNumber);
    refreshAdmissionNumber();
}

const assessmentYearSelect = document.querySelector('[data-assessment-year-select]');
const assessmentClassSelect = document.querySelector('[data-assessment-class-select]');
const assessmentTermSelect = document.querySelector('[data-assessment-term-select]');
const assessmentSubjectSelect = document.querySelector('[data-assessment-subject-select]');
if (assessmentYearSelect instanceof HTMLSelectElement
    && assessmentClassSelect instanceof HTMLSelectElement
    && assessmentTermSelect instanceof HTMLSelectElement
    && assessmentSubjectSelect instanceof HTMLSelectElement) {
    const filterAssessmentSetup = (resetClass = false, resetTerm = false, resetSubject = false) => {
        const year = assessmentYearSelect.value;
        const selectedClass = assessmentClassSelect.selectedOptions[0];
        const classId = selectedClass?.value || '';
        [...assessmentTermSelect.options].forEach((option, index) => {
            if (index === 0) return;
            const matches = year !== '' && option.dataset.academicYear === year;
            option.hidden = !matches;
            option.disabled = !matches;
        });
        [...assessmentClassSelect.options].forEach((option, index) => {
            if (index === 0) return;
            const matches = year !== '' && option.dataset.academicYear === year;
            option.hidden = !matches;
            option.disabled = !matches;
        });
        if (resetClass) assessmentClassSelect.value = '';
        const activeClassId = resetClass ? '' : classId;
        [...assessmentSubjectSelect.options].forEach((option, index) => {
            if (index === 0) return;
            const assignedClasses = (option.dataset.classIds || '').split(',').filter(Boolean);
            const matches = activeClassId !== '' && assignedClasses.includes(activeClassId);
            option.hidden = !matches;
            option.disabled = !matches;
        });
        if (resetTerm) assessmentTermSelect.value = '';
        if (resetSubject || resetClass) assessmentSubjectSelect.value = '';
        if (assessmentTermSelect.selectedOptions[0]?.disabled) assessmentTermSelect.value = '';
        if (assessmentClassSelect.selectedOptions[0]?.disabled) assessmentClassSelect.value = '';
        if (assessmentSubjectSelect.selectedOptions[0]?.disabled) assessmentSubjectSelect.value = '';
    };
    assessmentYearSelect.addEventListener('change', () => filterAssessmentSetup(true, true, true));
    assessmentClassSelect.addEventListener('change', () => filterAssessmentSetup(false, false, true));
    filterAssessmentSetup();
}

const registerYearSelect = document.querySelector('[data-register-year]');
const registerTermSelect = document.querySelector('[data-register-term]');
const registerClassSelect = document.querySelector('[data-register-class]');
if (registerYearSelect instanceof HTMLSelectElement
    && registerTermSelect instanceof HTMLSelectElement
    && registerClassSelect instanceof HTMLSelectElement) {
    const filterRegisterOptions = (resetSelections = false) => {
        const year = registerYearSelect.value;
        [registerTermSelect, registerClassSelect].forEach((select) => {
            let selectedValueIsVisible = false;
            [...select.options].forEach((option, index) => {
                if (index === 0) return;
                const matches = year !== '' && option.dataset.academicYear === year;
                option.hidden = !matches;
                option.disabled = !matches;
                if (matches && option.selected) selectedValueIsVisible = true;
            });
            if (resetSelections || !selectedValueIsVisible) select.value = '';
        });
    };
    registerYearSelect.addEventListener('change', () => filterRegisterOptions(true));
    filterRegisterOptions();
}

const mobileNavToggle = document.querySelector('[data-mobile-nav-toggle]');
const mobileNav = document.getElementById('app-sidebar');
const mobileNavBackdrop = document.querySelector('[data-mobile-nav-close]');
const mobileNavCloseButton = document.querySelector('[data-mobile-nav-close-button]');
const desktopNavStateKey = 'app-sidebar-collapsed';

if (mobileNavToggle && mobileNav && mobileNavBackdrop) {
    const setMobileNavOpen = (open) => {
        mobileNav.classList.toggle('is-open', open);
        document.body.classList.toggle('mobile-nav-open', open);
        mobileNavToggle.setAttribute('aria-expanded', String(open));
        mobileNavToggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
        mobileNavBackdrop.tabIndex = open ? 0 : -1;
    };

    const setDesktopNavCollapsed = (collapsed) => {
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        mobileNavToggle.setAttribute('aria-expanded', String(!collapsed));
        mobileNavToggle.setAttribute('aria-label', collapsed ? 'Open navigation menu' : 'Close navigation menu');
        sessionStorage.setItem(desktopNavStateKey, String(collapsed));
    };

    mobileNavToggle.addEventListener('click', () => {
        if (window.matchMedia('(min-width: 901px)').matches) {
            setDesktopNavCollapsed(!document.body.classList.contains('sidebar-collapsed'));
            return;
        }
        setMobileNavOpen(!document.body.classList.contains('mobile-nav-open'));
    });
    mobileNavBackdrop.addEventListener('click', () => setMobileNavOpen(false));
    mobileNavCloseButton?.addEventListener('click', () => {
        setMobileNavOpen(false);
        mobileNavToggle.focus();
    });
    mobileNav.querySelectorAll('a[href]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey
                || event.shiftKey || event.altKey || link.target === '_blank') {
                return;
            }
            if (window.matchMedia('(min-width: 901px)').matches) {
                setDesktopNavCollapsed(true);
            } else {
                setMobileNavOpen(false);
            }
        });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && window.matchMedia('(max-width: 900px)').matches
            && mobileNavToggle.getAttribute('aria-expanded') === 'true') {
            setMobileNavOpen(false);
            mobileNavToggle.focus();
        }
    });
    const syncNavigationMode = () => {
        if (window.matchMedia('(min-width: 901px)').matches) {
            setMobileNavOpen(false);
            const collapsed = sessionStorage.getItem(desktopNavStateKey) === 'true';
            document.body.classList.toggle('sidebar-collapsed', collapsed);
            mobileNavToggle.setAttribute('aria-expanded', String(!collapsed));
            mobileNavToggle.setAttribute('aria-label', collapsed ? 'Open navigation menu' : 'Close navigation menu');
        } else {
            document.body.classList.remove('sidebar-collapsed');
            setMobileNavOpen(false);
        }
    };
    syncNavigationMode();
    window.addEventListener('resize', syncNavigationMode);
}

const accountMenu = document.querySelector('[data-account-menu]');
const accountMenuToggle = accountMenu?.querySelector('[data-account-menu-toggle]');
const accountMenuPanel = accountMenu?.querySelector('[data-account-menu-panel]');

const notificationCenters = Array.from(document.querySelectorAll('details.school-notification-center'));
if (notificationCenters.length > 0) {
    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Node)) return;
        notificationCenters.forEach((center) => {
            if (!center.contains(event.target)) center.open = false;
        });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const openCenter = notificationCenters.find((center) => center.open);
        if (!openCenter) return;
        openCenter.open = false;
        openCenter.querySelector('summary')?.focus();
    });
}

if (accountMenu && accountMenuToggle && accountMenuPanel) {
    const setAccountMenuOpen = (open) => {
        accountMenuPanel.hidden = !open;
        accountMenuToggle.setAttribute('aria-expanded', String(open));
    };

    accountMenuToggle.addEventListener('click', () => {
        setAccountMenuOpen(accountMenuPanel.hidden);
    });
    accountMenuPanel.querySelectorAll('a, button').forEach((item) => {
        item.addEventListener('click', () => setAccountMenuOpen(false));
    });
    document.addEventListener('click', (event) => {
        if (!accountMenu.contains(event.target)) setAccountMenuOpen(false);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !accountMenuPanel.hidden) {
            setAccountMenuOpen(false);
            accountMenuToggle.focus();
        }
    });
}

const currentPath = new URL(window.location.href).pathname;
const sidebarLinks = [...document.querySelectorAll('.sidebar-nav a')];
const currentSidebarLink = sidebarLinks.find((link) => new URL(link.href, window.location.href).pathname === currentPath);
if (currentSidebarLink) {
    sidebarLinks.forEach((link) => {
        link.classList.toggle('active', link === currentSidebarLink);
        if (link === currentSidebarLink) {
            link.setAttribute('aria-current', 'page');
        } else {
            link.removeAttribute('aria-current');
        }
    });
}

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) return;
        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.textContent = reveal ? 'Hide' : 'Show';
        button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
    });
});

const studentRoleSelect = document.querySelector('[data-student-role-select]');
const studentRegistrationFields = document.querySelector('[data-student-registration-fields]');
const proprietorRoleSelect = document.querySelector('input[name="requested_roles[]"][value="school_proprietor"]');
const roleRequestDetails = document.querySelector('[data-role-request-details]');

if (proprietorRoleSelect instanceof HTMLInputElement && roleRequestDetails instanceof HTMLElement) {
    const otherRegistrationRoles = [...document.querySelectorAll('input[name="requested_roles[]"]')]
        .filter((input) => input !== proprietorRoleSelect);
    const updateRoleRequestDetails = () => {
        const proprietorSelected = proprietorRoleSelect.checked;
        roleRequestDetails.hidden = proprietorSelected;
        roleRequestDetails.querySelectorAll('textarea').forEach((field) => {
            field.required = !proprietorSelected;
        });
        otherRegistrationRoles.forEach((input) => {
            if (proprietorSelected) input.checked = false;
            input.disabled = proprietorSelected;
        });
    };
    proprietorRoleSelect.addEventListener('change', updateRoleRequestDetails);
    updateRoleRequestDetails();
}

if (studentRoleSelect && studentRegistrationFields) {
    const admissionNumber = studentRegistrationFields.querySelector('[name="student_admission_number"]');
    const dateOfBirth = studentRegistrationFields.querySelector('[name="student_date_of_birth"]');
    const roleCheckboxes = document.querySelectorAll('input[name="role_names[]"]');
    const registrationRoleCheckboxes = document.querySelectorAll('input[name="requested_roles[]"]');
    const allRoleCheckboxes = [...roleCheckboxes, ...registrationRoleCheckboxes];
    const updateRoleSelectionValidity = () => {
        const roleSelectionAnchor = allRoleCheckboxes.find((checkbox) => !checkbox.disabled);
        if (roleSelectionAnchor) {
            roleSelectionAnchor.setCustomValidity(
                [...allRoleCheckboxes].some((checkbox) => checkbox.checked)
                    ? ''
                    : 'Select at least one school role.'
            );
        }
    };
    const updateStudentFields = () => {
        const isStudent = studentRoleSelect.type === 'checkbox'
            ? studentRoleSelect.checked
            : studentRoleSelect.value === 'school_student';
        studentRegistrationFields.hidden = !isStudent;
        admissionNumber.required = isStudent;
        dateOfBirth.required = isStudent;
    };
    if (studentRoleSelect.type === 'checkbox') {
        allRoleCheckboxes.forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                if (checkbox.checked && checkbox === studentRoleSelect) {
                    allRoleCheckboxes.forEach((other) => {
                        if (other !== studentRoleSelect) other.checked = false;
                    });
                } else if (checkbox.checked && studentRoleSelect.checked) {
                    studentRoleSelect.checked = false;
                }
                updateRoleSelectionValidity();
                updateStudentFields();
            });
        });
    } else {
        studentRoleSelect.addEventListener('change', updateStudentFields);
    }
    updateRoleSelectionValidity();
    updateStudentFields();
}

document.querySelectorAll('[data-loading-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!form.reportValidity()) return;
        const button = event.submitter || form.querySelector('button[type="submit"]');
        if (button) {
            if (button.name && button.value) {
                const submittedValue = document.createElement('input');
                submittedValue.type = 'hidden';
                submittedValue.name = button.name;
                submittedValue.value = button.value;
                form.append(submittedValue);
            }
            button.classList.add('is-loading');
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        }
    });
});

document.querySelectorAll('[data-toggle-target]').forEach((toggle) => {
    const target = document.getElementById(toggle.dataset.toggleTarget);
    if (!target) return;
    toggle.addEventListener('click', () => {
        const open = target.hidden;
        target.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        if (open) {
            target.classList.remove('is-revealed');
            requestAnimationFrame(() => target.classList.add('is-revealed'));
        } else {
            target.classList.remove('is-revealed');
        }
    });
});

const schoolDeleteDialog = document.querySelector('[data-school-delete-dialog]');
if (schoolDeleteDialog instanceof HTMLDialogElement) {
    const schoolName = schoolDeleteDialog.querySelector('[data-school-delete-dialog-name]');
    const schoolId = schoolDeleteDialog.querySelector('[data-school-delete-dialog-id]');
    document.querySelectorAll('[data-open-school-delete-dialog]').forEach((button) => {
        button.addEventListener('click', () => {
            schoolName.textContent = button.dataset.schoolName;
            schoolId.value = button.dataset.schoolId;
            schoolDeleteDialog.showModal();
        });
    });
}

const schoolPurgeDialog = document.querySelector('[data-school-purge-dialog]');
if (schoolPurgeDialog instanceof HTMLDialogElement) {
    const schoolName = schoolPurgeDialog.querySelector('[data-school-purge-dialog-name]');
    const schoolId = schoolPurgeDialog.querySelector('[data-school-purge-dialog-id]');
    const confirmationName = schoolPurgeDialog.querySelector('[data-school-purge-dialog-confirm-name]');
    document.querySelectorAll('[data-open-school-purge-dialog]').forEach((button) => {
        button.addEventListener('click', () => {
            schoolName.textContent = button.dataset.schoolName;
            schoolId.value = button.dataset.schoolId;
            confirmationName.value = '';
            schoolPurgeDialog.showModal();
        });
    });
}

document.querySelectorAll('[data-image-preview-input]').forEach((input) => {
    const targetId = input.dataset.previewTarget;
    const preview = targetId
        ? document.getElementById(targetId)
        : input.closest('form')?.querySelector('[data-image-preview]');
    if (!preview) return;

    let currentObjectUrl = null;
    const originalSrc = preview.getAttribute('src');
    const originalAlt = preview.getAttribute('alt') || '';
    input.addEventListener('change', () => {
        const file = input.files?.[0];
        if (!file) {
            if (currentObjectUrl) URL.revokeObjectURL(currentObjectUrl);
            currentObjectUrl = null;
            if (originalSrc) preview.src = originalSrc;
            preview.alt = originalAlt;
            input.setCustomValidity('');
            return;
        }
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
            input.value = '';
            if (currentObjectUrl) URL.revokeObjectURL(currentObjectUrl);
            currentObjectUrl = null;
            if (originalSrc) preview.src = originalSrc;
            preview.alt = originalAlt;
            input.setCustomValidity('Choose a JPEG, PNG, or WebP image no larger than 2 MB.');
            input.reportValidity();
            return;
        }
        input.setCustomValidity('');
        if (currentObjectUrl) URL.revokeObjectURL(currentObjectUrl);
        currentObjectUrl = URL.createObjectURL(file);
        preview.src = currentObjectUrl;
        preview.alt = 'Selected school logo preview';
    });

    document.querySelectorAll('[data-attendance-live]').forEach((dashboard) => {
        const url = dashboard.dataset.feedUrl;
        const body = dashboard.querySelector('[data-attendance-live-body]');
        const refreshButton = dashboard.querySelector('[data-attendance-refresh]');
        if (!url || !body) return;

        let snapshot = dashboard.dataset.snapshot || '';
        let dirty = false;
        dashboard.addEventListener('input', (event) => {
            if (event.target instanceof HTMLInputElement
                || event.target instanceof HTMLSelectElement
                || event.target instanceof HTMLTextAreaElement) {
                dirty = true;
            }
        });
        refreshButton?.addEventListener('click', () => window.location.reload());

        const refreshAttendance = async () => {
            try {
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!response.ok) throw new Error(`Attendance refresh failed (${response.status}).`);
                const data = await response.json();
                const nextSnapshot = JSON.stringify(data.rows);
                if (nextSnapshot === snapshot) return;
                if (dirty) {
                    dashboard.dataset.pendingAttendanceUpdate = 'true';
                    return;
                }
                window.location.reload();
            } catch (error) {
                console.error('Could not refresh the staff attendance dashboard.', error);
            }
        };

        window.setInterval(refreshAttendance, 30000);
    });
    input.addEventListener('input', () => input.setCustomValidity(''));
    window.addEventListener('pagehide', () => {
        if (currentObjectUrl) URL.revokeObjectURL(currentObjectUrl);
    }, { once: true });
});

const profilePhotoDialog = document.querySelector('[data-profile-photo-dialog]');
if (profilePhotoDialog instanceof HTMLDialogElement) {
    const profilePhotoForm = profilePhotoDialog.querySelector('[data-profile-photo-form]');
    const profilePhotoInput = profilePhotoDialog.querySelector('[data-profile-photo-input]');
    const profilePhotoPreview = profilePhotoDialog.querySelector('[data-profile-photo-preview]');
    const profilePhotoSave = profilePhotoDialog.querySelector('[data-profile-photo-save]');
    const profilePhotoRemoveValue = profilePhotoForm?.querySelector('[name="remove_profile_picture"]');
    const originalPreview = profilePhotoPreview?.innerHTML ?? '';
    let previewObjectUrl = null;

    const resetProfilePhotoEditor = () => {
        if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
        previewObjectUrl = null;
        if (profilePhotoPreview) profilePhotoPreview.innerHTML = originalPreview;
        if (profilePhotoInput) {
            profilePhotoInput.value = '';
            profilePhotoInput.setCustomValidity('');
        }
        if (profilePhotoRemoveValue) profilePhotoRemoveValue.value = '';
        if (profilePhotoSave instanceof HTMLButtonElement) {
            profilePhotoSave.disabled = true;
            profilePhotoSave.textContent = 'Save photo';
        }
    };

    document.querySelectorAll('[data-open-profile-photo-editor]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            resetProfilePhotoEditor();
            profilePhotoDialog.showModal();
        });
    });

    profilePhotoDialog.querySelector('[data-profile-photo-change]')?.addEventListener('click', () => {
        profilePhotoInput?.click();
    });

    profilePhotoInput?.addEventListener('change', () => {
        const file = profilePhotoInput.files?.[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
            profilePhotoInput.value = '';
            profilePhotoInput.setCustomValidity('Choose a JPEG, PNG, or WebP image no larger than 2 MB.');
            profilePhotoInput.reportValidity();
            if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
            previewObjectUrl = null;
            if (profilePhotoPreview) profilePhotoPreview.innerHTML = originalPreview;
            if (profilePhotoSave instanceof HTMLButtonElement) profilePhotoSave.disabled = true;
            return;
        }
        profilePhotoInput.setCustomValidity('');
        if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
        previewObjectUrl = URL.createObjectURL(file);
        if (profilePhotoPreview) {
            const image = document.createElement('img');
            image.className = 'profile-avatar profile-avatar-image profile-photo-preview-avatar';
            image.src = previewObjectUrl;
            image.alt = 'Selected profile photo preview';
            profilePhotoPreview.replaceChildren(image);
        }
        if (profilePhotoRemoveValue) profilePhotoRemoveValue.value = '';
        if (profilePhotoSave instanceof HTMLButtonElement) {
            profilePhotoSave.disabled = false;
            profilePhotoSave.textContent = 'Save photo';
        }
    });

    profilePhotoDialog.querySelector('[data-profile-photo-remove]')?.addEventListener('click', () => {
        if (!window.confirm('Remove your current profile photo?')) return;
        if (profilePhotoInput) {
            profilePhotoInput.value = '';
            profilePhotoInput.setCustomValidity('');
        }
        if (profilePhotoRemoveValue) profilePhotoRemoveValue.value = '1';
        if (profilePhotoSave instanceof HTMLButtonElement) {
            profilePhotoSave.disabled = false;
            profilePhotoSave.textContent = 'Remove photo';
        }
        profilePhotoForm?.requestSubmit();
    });

    profilePhotoDialog.querySelector('[data-profile-photo-cancel]')?.addEventListener('click', () => {
        profilePhotoDialog.close();
    });
    profilePhotoDialog.addEventListener('click', (event) => {
        if (event.target === profilePhotoDialog) profilePhotoDialog.close();
    });
    profilePhotoDialog.addEventListener('close', resetProfilePhotoEditor);
    profilePhotoForm?.addEventListener('submit', (event) => {
        if (!profilePhotoInput?.files?.length && profilePhotoRemoveValue?.value !== '1') {
            event.preventDefault();
        }
    });
}

const studentPhotoDialog = document.querySelector('[data-student-photo-dialog]');
if (studentPhotoDialog instanceof HTMLDialogElement) {
    const studentPhotoForm = studentPhotoDialog.querySelector('[data-student-photo-form]');
    const studentPhotoInput = studentPhotoDialog.querySelector('[data-student-photo-input]');
    const studentPhotoPreview = studentPhotoDialog.querySelector('[data-student-photo-preview]');
    const studentPhotoTitle = studentPhotoDialog.querySelector('[data-student-photo-title]');
    const studentPhotoId = studentPhotoDialog.querySelector('[data-student-photo-id]');
    const studentPhotoView = studentPhotoDialog.querySelector('[data-student-photo-view]');
    const studentPhotoRemoveValue = studentPhotoForm?.querySelector('[name="remove_photo"]');
    const studentPhotoSave = studentPhotoDialog.querySelector('[data-student-photo-save]');
    const studentPhotoRemove = studentPhotoDialog.querySelector('[data-student-photo-remove]');
    const defaultStudentPreview = studentPhotoPreview?.firstElementChild?.cloneNode(true) ?? null;
    let studentInitialPreview = defaultStudentPreview;
    let studentPreviewObjectUrl = null;

    const resetStudentPhotoEditor = () => {
        if (studentPreviewObjectUrl) URL.revokeObjectURL(studentPreviewObjectUrl);
        studentPreviewObjectUrl = null;
        if (studentPhotoPreview) {
            studentPhotoPreview.replaceChildren(
                studentInitialPreview?.cloneNode(true) ?? document.createTextNode('?')
            );
        }
        if (studentPhotoInput) {
            studentPhotoInput.value = '';
            studentPhotoInput.setCustomValidity('');
        }
        if (studentPhotoId) studentPhotoId.value = '';
        if (studentPhotoRemoveValue) studentPhotoRemoveValue.value = '';
        if (studentPhotoView instanceof HTMLAnchorElement) {
            studentPhotoView.href = '#';
            studentPhotoView.hidden = true;
        }
        if (studentPhotoRemove instanceof HTMLButtonElement) studentPhotoRemove.hidden = true;
        if (studentPhotoSave instanceof HTMLButtonElement) {
            studentPhotoSave.disabled = true;
            studentPhotoSave.textContent = 'Save photo';
        }
    };

    document.querySelectorAll('[data-open-student-photo-editor]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            resetStudentPhotoEditor();
            const studentName = trigger.dataset.studentName || 'Student';
            const photoUrl = trigger.dataset.studentPhotoUrl || '';
            const hasPhoto = trigger.dataset.hasStudentPhoto === 'true';
            if (studentPhotoTitle) studentPhotoTitle.textContent = `Photo for ${studentName}`;
            if (studentPhotoId) studentPhotoId.value = trigger.dataset.studentId || '';
            if (studentPhotoPreview) {
                if (hasPhoto) {
                    const image = document.createElement('img');
                    image.className = 'student-photo';
                    image.src = photoUrl;
                    image.alt = '';
                    studentPhotoPreview.replaceChildren(image);
                } else {
                    const placeholder = document.createElement('span');
                    placeholder.className = 'student-photo student-photo-placeholder';
                    placeholder.setAttribute('aria-hidden', 'true');
                    placeholder.textContent = studentName.slice(0, 1).toLocaleUpperCase();
                    studentPhotoPreview.replaceChildren(placeholder);
                }
                studentInitialPreview = studentPhotoPreview.firstElementChild?.cloneNode(true) ?? defaultStudentPreview;
            }
            if (studentPhotoView instanceof HTMLAnchorElement && hasPhoto) {
                studentPhotoView.href = photoUrl;
                studentPhotoView.hidden = false;
            }
            if (studentPhotoRemove instanceof HTMLButtonElement) studentPhotoRemove.hidden = !hasPhoto;
            studentPhotoDialog.showModal();
        });
    });

    studentPhotoDialog.querySelector('[data-student-photo-change]')?.addEventListener('click', () => {
        studentPhotoInput?.click();
    });

    studentPhotoInput?.addEventListener('change', () => {
        const file = studentPhotoInput.files?.[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
            studentPhotoInput.value = '';
            studentPhotoInput.setCustomValidity('Choose a JPEG, PNG, or WebP image no larger than 2 MB.');
            studentPhotoInput.reportValidity();
            if (studentPreviewObjectUrl) URL.revokeObjectURL(studentPreviewObjectUrl);
            studentPreviewObjectUrl = null;
            if (studentPhotoPreview) {
                studentPhotoPreview.replaceChildren(
                    studentInitialPreview?.cloneNode(true) ?? document.createTextNode('?')
                );
            }
            if (studentPhotoSave instanceof HTMLButtonElement) studentPhotoSave.disabled = true;
            return;
        }
        studentPhotoInput.setCustomValidity('');
        if (studentPreviewObjectUrl) URL.revokeObjectURL(studentPreviewObjectUrl);
        studentPreviewObjectUrl = URL.createObjectURL(file);
        if (studentPhotoPreview) {
            const image = document.createElement('img');
            image.className = 'student-photo';
            image.src = studentPreviewObjectUrl;
            image.alt = 'Selected student photo preview';
            studentPhotoPreview.replaceChildren(image);
        }
        if (studentPhotoRemoveValue) studentPhotoRemoveValue.value = '';
        if (studentPhotoSave instanceof HTMLButtonElement) {
            studentPhotoSave.disabled = false;
            studentPhotoSave.textContent = 'Save photo';
        }
    });

    studentPhotoRemove?.addEventListener('click', () => {
        const studentName = studentPhotoTitle?.textContent || 'this student';
        if (!window.confirm(`Remove the photo for ${studentName}?`)) return;
        if (studentPhotoInput) {
            studentPhotoInput.value = '';
            studentPhotoInput.setCustomValidity('');
        }
        if (studentPhotoRemoveValue) studentPhotoRemoveValue.value = '1';
        if (studentPhotoSave instanceof HTMLButtonElement) {
            studentPhotoSave.disabled = false;
            studentPhotoSave.textContent = 'Remove photo';
        }
        studentPhotoForm?.requestSubmit();
    });

    studentPhotoDialog.querySelector('[data-student-photo-cancel]')?.addEventListener('click', () => {
        studentPhotoDialog.close();
    });
    studentPhotoDialog.addEventListener('click', (event) => {
        if (event.target === studentPhotoDialog) studentPhotoDialog.close();
    });
    studentPhotoDialog.addEventListener('close', resetStudentPhotoEditor);
    studentPhotoForm?.addEventListener('submit', (event) => {
        if (!studentPhotoInput?.files?.length && studentPhotoRemoveValue?.value !== '1') {
            event.preventDefault();
        }
    });
}

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('service-worker-v2.js', { updateViaCache: 'none' }).catch((error) => {
            console.warn('Service worker registration failed.', error);
        });
    });
}

document.querySelectorAll('[data-table-controls]').forEach((controls) => {
    const table = document.getElementById(controls.dataset.tableControls);
    const search = controls.querySelector('[data-table-search]');
    const summary = controls.querySelector('[data-table-summary]');
    const previous = controls.querySelector('[data-table-previous]');
    const next = controls.querySelector('[data-table-next]');
    const rows = table ? Array.from(table.querySelectorAll('tbody tr')) : [];
    const pageSize = 10;
    let page = 1;

    if (!table || !search || !summary || !previous || !next) return;

    const render = () => {
        const query = search.value.trim().toLocaleLowerCase();
        const filtered = rows.filter((row) => row.textContent.toLocaleLowerCase().includes(query));
        const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
        page = Math.min(page, pageCount);
        const start = (page - 1) * pageSize;

        rows.forEach((row) => { row.hidden = true; });
        filtered.slice(start, start + pageSize).forEach((row) => { row.hidden = false; });

        const first = filtered.length === 0 ? 0 : start + 1;
        const last = Math.min(start + pageSize, filtered.length);
        summary.textContent = `Showing ${first}–${last} of ${filtered.length}`;
        previous.disabled = page <= 1;
        next.disabled = page >= pageCount;
    };

    search.addEventListener('input', () => {
        page = 1;
        render();
    });

    document.querySelectorAll('[data-school-filter]').forEach((search) => {
        const cards = Array.from(document.querySelectorAll('[data-school-card]'));
        search.addEventListener('input', () => {
            const query = search.value.trim().toLocaleLowerCase();
            cards.forEach((card) => {
                card.hidden = !card.dataset.schoolSearch.includes(query);
            });
        });
    });
    previous.addEventListener('click', () => {
        page -= 1;
        render();
    });
    next.addEventListener('click', () => {
        page += 1;
        render();
    });
    render();
});

document.querySelectorAll('[data-student-attendance-form]').forEach((form) => {
    const rows = Array.from(form.querySelectorAll('[data-attendance-student]'));
    const search = form.querySelector('[data-student-search]');
    const presentCount = form.querySelector('[data-present-count]');
    const absentCount = form.querySelector('[data-absent-count]');
    const unmarkedCount = form.querySelector('[data-unmarked-count]');

    const updateCounts = () => {
        let present = 0;
        let absent = 0;
        let unmarked = 0;
        rows.forEach((row) => {
            const selected = row.querySelector('input[type="radio"]:checked');
            row.classList.toggle('is-unmarked', !selected);
            if (selected?.value === 'present') present += 1;
            else if (selected?.value === 'absent') absent += 1;
            else unmarked += 1;
        });
        if (presentCount) presentCount.textContent = String(present);
        if (absentCount) absentCount.textContent = String(absent);
        if (unmarkedCount) unmarkedCount.textContent = String(unmarked);
    };

    search?.addEventListener('input', () => {
        const query = search.value.trim().toLocaleLowerCase();
        rows.forEach((row) => {
            row.hidden = !row.dataset.searchText.includes(query);
        });
    });

    form.querySelector('[data-mark-all-present]')?.addEventListener('click', () => {
        const hasConflictingMarks = rows.some((row) => row.querySelector('input[type="radio"]:checked')?.value === 'absent');
        if (hasConflictingMarks && !window.confirm('Replace existing Absent selections with Present for every student?')) return;
        rows.forEach((row) => {
            const present = row.querySelector('input[value="present"]');
            if (present) present.checked = true;
        });
        updateCounts();
    });

    form.querySelector('[data-mark-all-absent]')?.addEventListener('click', () => {
        if (!window.confirm('Mark every student Absent? Existing attendance selections will be replaced.')) return;
        rows.forEach((row) => {
            const absent = row.querySelector('input[value="absent"]');
            if (absent) absent.checked = true;
        });
        updateCounts();
    });

    form.addEventListener('change', (event) => {
        if (event.target.matches('input[type="radio"]')) updateCounts();
    });

    form.addEventListener('submit', (event) => {
        if (event.submitter?.value !== 'submit_attendance') return;
        const unmarkedRows = rows.filter((row) => !row.querySelector('input[type="radio"]:checked'));
        if (unmarkedRows.length === 0) return;
        event.preventDefault();
        updateCounts();
        unmarkedRows.forEach((row) => row.classList.add('is-unmarked'));
        const firstUnmarked = unmarkedRows[0];
        firstUnmarked.scrollIntoView({ behavior: 'smooth', block: 'center' });
        firstUnmarked.querySelector('input[type="radio"]')?.focus({ preventScroll: true });
        window.alert(`Mark all students before submission. ${unmarkedRows.length} student${unmarkedRows.length === 1 ? ' is' : 's are'} still unmarked.`);
    });

    updateCounts();
});

document.querySelectorAll('[data-confirm]').forEach((control) => {
    control.addEventListener('click', (event) => {
        if (!window.confirm(control.dataset.confirm || 'Are you sure?')) {
            event.preventDefault();
        }
    });
});

document.querySelectorAll('[data-remark-suggestion]').forEach((button) => {
    button.addEventListener('click', () => {
        const target = document.getElementById(button.dataset.remarkTarget || '');
        if (!(target instanceof HTMLTextAreaElement) || target.value.trim() !== '') return;
        target.value = button.dataset.remarkText || '';
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.focus();
        button.disabled = true;
        button.textContent = 'Suggestion added — review and save';
    });
});

document.querySelectorAll('[data-all-day-toggle]').forEach((toggle) => {
    const form = toggle.closest('form');
    const timeFields = form?.querySelector('[data-event-time-fields]');
    if (!timeFields) return;
    const updateTimeFields = () => {
        timeFields.hidden = toggle.checked;
        timeFields.querySelectorAll('input[type="time"]').forEach((input) => {
            input.required = !toggle.checked;
        });
    };
    toggle.addEventListener('change', updateTimeFields);
    updateTimeFields();
});

const initializeRevealableList = (list) => {
    if (!(list instanceof HTMLElement) || list.dataset.showMoreInitialized === 'true') return;
    const items = Array.from(list.children).filter((item) => item instanceof HTMLElement);
    const visibleCount = Number.parseInt(list.dataset.showMoreCount || '3', 10);
    if (items.length <= visibleCount || visibleCount < 1) return;

    const toggle = document.createElement('button');
    toggle.className = 'show-more-toggle';
    toggle.type = 'button';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.textContent = `Show more (${items.length - visibleCount})`;
    const controlAnchor = list.closest('.table-scroll') || list;
    controlAnchor.insertAdjacentElement('afterend', toggle);
    list.dataset.showMoreInitialized = 'true';

    items.forEach((item, index) => {
        item.hidden = index >= visibleCount;
    });

    toggle.addEventListener('click', () => {
        const expanded = toggle.getAttribute('aria-expanded') !== 'true';
        items.forEach((item, index) => {
            item.hidden = !expanded && index >= visibleCount;
        });
        toggle.setAttribute('aria-expanded', String(expanded));
        toggle.textContent = expanded ? 'Show less' : `Show more (${items.length - visibleCount})`;
    });
};

const dashboardListSelector =
    '.dashboard-grid > .panel ul, .dashboard-grid > .panel tbody, ' +
    '.student-preview-grid > .panel ul, .student-preview-grid > .panel tbody, ' +
    '.student-preview-profile > .panel ul, .student-preview-profile > .panel tbody, ' +
    '.stat-grid > .panel ul, .stat-grid > .panel tbody';

const initializeRevealableLists = (root) => {
    if (!(root instanceof Element)) return;
    if (root.matches(dashboardListSelector)) root.dataset.showMore = '';
    root.querySelectorAll(dashboardListSelector).forEach((list) => {
        list.dataset.showMore = '';
    });
    if (root.matches('[data-show-more]')) initializeRevealableList(root);
    root.querySelectorAll('[data-show-more]').forEach(initializeRevealableList);
    const containingList = root.closest('[data-show-more]');
    if (containingList) initializeRevealableList(containingList);
};

initializeRevealableLists(document.documentElement);

const cardGridSelector = [
    '.dashboard-grid',
    '.settings-grid',
    '.school-support-grid',
    '.association-card-grid',
    '.report-action-grid',
    '.stat-grid',
    '.directory-grid',
    '.support-summary-grid',
    '.student-preview-profile',
    '.student-preview-grid',
    '.student-class-navigation',
].join(', ');

const initializeCardTextPreviews = (root) => {
    if (!(root instanceof Element)) return;
    const cardGrids = [
        ...(root.matches(cardGridSelector) ? [root] : []),
        ...root.querySelectorAll(cardGridSelector),
    ];

    cardGrids.forEach((grid) => {
        Array.from(grid.children).forEach((card) => {
            if (!(card instanceof HTMLElement) || card.dataset.cardTextPreviewsInitialized === 'true') return;
            card.dataset.cardTextPreviewsInitialized = 'true';
            const textBlocks = card.matches('.student-class-card')
                ? Array.from(card.querySelectorAll(':scope > small'))
                : Array.from(card.querySelectorAll(':scope > p, :scope > .description, :scope > .muted, :scope > div > p, :scope > div > .description'));

            textBlocks.forEach((textBlock) => {
                if (!(textBlock instanceof HTMLElement) || textBlock.textContent.trim() === '') return;
                textBlock.classList.add('card-text-preview');

                const toggle = document.createElement('button');
                toggle.className = 'card-text-toggle';
                toggle.type = 'button';
                toggle.textContent = 'Read more';
                toggle.setAttribute('aria-expanded', 'false');
                toggle.hidden = true;
                textBlock.insertAdjacentElement('afterend', toggle);

                const updateToggleVisibility = () => {
                    if (!textBlock.classList.contains('is-expanded')) {
                        toggle.hidden = textBlock.scrollHeight <= textBlock.clientHeight + 1;
                    }
                };
                const resizeObserver = new ResizeObserver(updateToggleVisibility);
                resizeObserver.observe(textBlock);
                requestAnimationFrame(updateToggleVisibility);

                toggle.addEventListener('click', () => {
                    const expanded = textBlock.classList.toggle('is-expanded');
                    toggle.setAttribute('aria-expanded', String(expanded));
                    toggle.textContent = expanded ? 'Read less' : 'Read more';
                    updateToggleVisibility();
                });
            });
        });
    });
};

initializeCardTextPreviews(document.documentElement);

const transferTooltipLabels = (root, changedAttribute = null) => {
    if (!(root instanceof Element)) return;
    const syncLabel = (element) => {
        const title = element.getAttribute('title')?.trim() || '';
        const tooltipLabel = element.dataset.appTooltip?.trim() || '';
        const label = changedAttribute === 'title'
            ? title
            : changedAttribute === 'data-app-tooltip'
                ? tooltipLabel
                : title || tooltipLabel;
        if (!label) return;
        if (title !== label) element.setAttribute('title', label);
        if (tooltipLabel !== label) element.dataset.appTooltip = label;
    };
    if (root.matches('[title], [data-app-tooltip]')) syncLabel(root);
    root.querySelectorAll('[title], [data-app-tooltip]').forEach(syncLabel);
    const svgTitles = root.matches('svg title')
        ? [root]
        : root.querySelectorAll('svg title');
    svgTitles.forEach((svgTitle) => {
        const owner = svgTitle.closest('svg');
        const label = svgTitle.textContent?.trim();
        if (owner && label && !owner.hasAttribute('title') && !owner.dataset.appTooltip) {
            owner.setAttribute('title', label);
            owner.dataset.appTooltip = label;
        }
    });
};

transferTooltipLabels(document.documentElement);

new MutationObserver((records) => {
    records.forEach((record) => {
        if (record.type === 'attributes' && record.target instanceof Element) {
            transferTooltipLabels(record.target, record.attributeName);
        }
        record.addedNodes.forEach((node) => {
            if (node instanceof Element) {
                transferTooltipLabels(node);
                initializeRevealableLists(node);
            }
        });
    });
}).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['title', 'data-app-tooltip'],
    childList: true,
    subtree: true,
});
