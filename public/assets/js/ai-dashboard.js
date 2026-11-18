(() => {
    const form = document.querySelector('[data-ai-form]');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const messages = document.querySelector('[data-ai-messages]');
    const error = document.querySelector('[data-ai-error]');
    const status = document.querySelector('[data-ai-status]');
    const submit = document.querySelector('[data-ai-submit]');
    const conversation = document.querySelector('[data-ai-conversation]');
    const messageInput = form.querySelector('[name="message"]');
    const voiceButton = form.querySelector('[data-ai-voice]');
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let submitting = false;
    let activeRecognition = null;

    const resizeMessageInput = () => {
        messageInput.style.height = 'auto';
        const contentHeight = messageInput.scrollHeight;
        const maxHeight = 160;
        messageInput.style.height = `${Math.min(contentHeight, maxHeight)}px`;
        messageInput.style.overflowY = contentHeight > maxHeight ? 'auto' : 'hidden';
    };

    const updateComposerState = () => {
        submit.disabled = submitting || messageInput.value.trim() === '';
        voiceButton.disabled = submitting || typeof SpeechRecognition !== 'function';
    };

    messageInput.addEventListener('input', () => {
        resizeMessageInput();
        updateComposerState();
    });
    messageInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            if (!submit.disabled) {
                form.requestSubmit();
            }
        }
    });

    if (typeof SpeechRecognition !== 'function') {
        voiceButton.disabled = true;
        voiceButton.title = 'Voice input is unavailable in this browser.';
        voiceButton.setAttribute('aria-label', 'Voice input unavailable in this browser');
    } else {
        voiceButton.disabled = false;
        voiceButton.title = 'Start voice input';
        voiceButton.setAttribute('aria-label', 'Start voice input');
        voiceButton.addEventListener('click', () => {
            if (submitting) {
                return;
            }
            if (activeRecognition !== null) {
                activeRecognition.stop();
                return;
            }

            let recognition;
            try {
                recognition = new SpeechRecognition();
            } catch {
                status.textContent = 'Voice input could not be started.';
                return;
            }
            activeRecognition = recognition;
            const startingText = messageInput.value.trim();
            recognition.lang = document.documentElement.lang || navigator.language || 'en-US';
            recognition.interimResults = true;
            recognition.continuous = false;
            recognition.onresult = (event) => {
                const transcript = Array.from(event.results)
                    .map((result) => result[0]?.transcript || '')
                    .join('')
                    .trim();
                messageInput.value = [startingText, transcript].filter(Boolean).join(' ');
                resizeMessageInput();
                updateComposerState();
            };
            recognition.onerror = (event) => {
                status.textContent = ['not-allowed', 'service-not-allowed'].includes(event.error)
                    ? 'Microphone access was denied. Allow microphone access for this site in your browser settings, then try again.'
                    : 'Voice input could not be completed.';
            };
            recognition.onend = () => {
                if (activeRecognition !== recognition) {
                    return;
                }
                activeRecognition = null;
                voiceButton.dataset.listening = 'false';
                voiceButton.title = 'Start voice input';
                voiceButton.setAttribute('aria-label', 'Start voice input');
                if (!submitting && status.textContent === 'Listening...') {
                    status.textContent = 'Ready';
                }
            };
            try {
                recognition.start();
                voiceButton.dataset.listening = 'true';
                voiceButton.title = 'Stop voice input';
                voiceButton.setAttribute('aria-label', 'Stop voice input');
                status.textContent = 'Listening...';
            } catch {
                activeRecognition = null;
                status.textContent = 'Voice input could not be started.';
            }
        });
    }

    resizeMessageInput();
    updateComposerState();

    const addConversationLink = (conversationId, title) => {
        let list = document.querySelector('[data-ai-conversations]');
        if (!list) {
            list = document.createElement('ul');
            list.className = 'ai-conversation-list';
            list.dataset.aiConversations = '';
            document.querySelector('.ai-conversation-heading')?.after(list);
        }
        document.querySelector('[data-ai-no-conversations]')?.remove();
        list.querySelectorAll('a.active').forEach((activeLink) => {
            activeLink.classList.remove('active');
            activeLink.removeAttribute('aria-current');
        });
        let link = list.querySelector(`a[data-conversation-id="${conversationId}"]`);
        if (!link) {
            const item = document.createElement('li');
            link = document.createElement('a');
            link.dataset.conversationId = String(conversationId);
            item.append(link);
            list.prepend(item);
        } else {
            list.prepend(link.parentElement);
        }
        const assistant = String(form.elements.assistant.value);
        link.className = 'active';
        link.href = `ai_dashboard.php?assistant=${encodeURIComponent(assistant)}&conversation=${conversationId}`;
        link.textContent = title;
        link.title = title;
        link.setAttribute('aria-current', 'page');
    };

    const addMessage = (role, label, content) => {
        const article = document.createElement('article');
        article.className = `ai-message ${role}`;
        const heading = document.createElement('strong');
        heading.textContent = label;
        const paragraph = document.createElement('p');
        paragraph.textContent = content;
        article.append(heading, paragraph);
        messages.append(article);
        article.scrollIntoView({ block: 'nearest' });
    };

    const addThinkingIndicator = () => {
        const article = document.createElement('article');
        article.className = 'ai-message assistant ai-thinking';
        article.dataset.aiThinking = '';
        article.setAttribute('role', 'status');
        article.setAttribute('aria-live', 'polite');
        const paragraph = document.createElement('p');
        paragraph.className = 'ai-thinking-copy';
        const text = document.createElement('span');
        text.textContent = 'Connezer AI is thinking...';
        const dots = document.createElement('span');
        dots.className = 'ai-thinking-dots';
        dots.setAttribute('aria-hidden', 'true');
        for (let index = 0; index < 3; index += 1) {
            dots.append(document.createElement('span'));
        }
        paragraph.append(text, dots);
        article.append(paragraph);
        messages.append(article);
        article.scrollIntoView({ block: 'nearest' });
        return article;
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submitting) {
            return;
        }
        error.hidden = true;
        const formData = new FormData(form);
        const message = String(formData.get('message') || '').trim();
        if (!message) {
            error.textContent = 'Enter a message before sending.';
            error.hidden = false;
            return;
        }

        submitting = true;
        form.dataset.loading = 'true';
        form.setAttribute('aria-busy', 'true');
        updateComposerState();
        let thinkingIndicator;
        try {
            activeRecognition?.stop();
            addMessage('user', 'You', message);
            const source = String(formData.get('source_material') || '').trim();
            if (source) {
                addMessage('user', 'Source material', source);
            }
            const upload = form.querySelector('[name="source_file"]');
            if (upload instanceof HTMLInputElement && upload.files && upload.files.length > 0) {
                addMessage('user', 'Attached text document', upload.files[0].name);
            }
            form.querySelector('[data-ai-empty]')?.remove();
            status.textContent = 'Connezer AI is thinking...';
            thinkingIndicator = addThinkingIndicator();

            const response = await fetch('ai_chat.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const result = await response.json();
            if (Number.isInteger(result.conversation_id) && result.conversation_id > 0) {
                conversation.value = String(result.conversation_id);
                const url = new URL(window.location.href);
                url.searchParams.set('conversation', String(result.conversation_id));
                window.history.replaceState({}, '', url);
                addConversationLink(
                    result.conversation_id,
                    typeof result.conversation_title === 'string' ? result.conversation_title : 'New conversation'
                );
            }
            if (!response.ok) {
                throw new Error(typeof result.error === 'string' ? result.error : 'The request could not be completed.');
            }
            addMessage('assistant', document.querySelector('#ai-assistant-title').textContent, result.answer);
            messageInput.value = '';
            resizeMessageInput();
            form.elements.source_material.value = '';
            if (upload instanceof HTMLInputElement) {
                upload.value = '';
            }
            form.querySelector('.ai-options')?.removeAttribute('open');
            status.textContent = 'Response generated by the local AI model.';
        } catch (requestError) {
            if (requestError instanceof Error && requestError.name === 'AbortError') {
                status.textContent = 'The AI request was cancelled.';
            } else {
                error.textContent = requestError instanceof Error ? requestError.message : 'The request could not be completed.';
                error.hidden = false;
                status.textContent = 'The local AI request did not complete.';
            }
        } finally {
            thinkingIndicator?.remove();
            submitting = false;
            form.dataset.loading = 'false';
            form.removeAttribute('aria-busy');
            updateComposerState();
        }
    });
})();
