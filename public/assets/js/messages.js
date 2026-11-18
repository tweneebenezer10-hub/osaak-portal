'use strict';

const chat = document.querySelector('[data-member-chat]');
if (chat) {
    const currentUser = Number(chat.dataset.currentUser);
    const conversationId = Number(chat.dataset.conversation || 0);
    const csrfToken = chat.dataset.csrf;
    const history = chat.querySelector('[data-chat-history]');
    const form = chat.querySelector('[data-message-form]');
    const composer = form?.querySelector('textarea');
    const searchForm = chat.querySelector('[data-member-search]');
    const searchInput = searchForm?.querySelector('input');
    const searchResults = chat.querySelector('[data-search-results]');
    const unreadDisplay = chat.querySelector('[data-chat-unread]');
    const chatFeedback = chat.querySelector('[data-chat-feedback]');
    const sendFeedback = chat.querySelector('[data-send-feedback]');
    let lastMessageId = Number(chat.dataset.lastId || 0);
    let polling = false;
    let readObserver;
    const readRequests = new Set();

    const post = async (values) => {
        const body = new URLSearchParams({ csrf_token: csrfToken, ...values });
        const response = await fetch('messages_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'The messaging request failed.');
        return data;
    };

    const formatTime = (dateValue) => {
        const date = new Date(`${dateValue.replace(' ', 'T')}Z`);
        return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
    };

    const messageStatus = (deliveredAt, readAt) => (readAt ? 'read' : (deliveredAt ? 'delivered' : 'sent'));
    const messageStatusLabel = (status) => ({ sent: 'Sent', delivered: 'Delivered', read: 'Read' })[status];

    const createMessageStatus = (status) => {
        const element = document.createElement('span');
        element.className = `message-status message-status-${status}`;
        element.dataset.messageStatus = status;
        element.setAttribute('aria-label', messageStatusLabel(status));
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', status === 'sent' ? 'M5 12.5l4 4L19 7' : 'M2 12.5l4 4L16 6.5M9 12.5l4 4L23 6.5');
        svg.append(path);
        const accessibleLabel = document.createElement('span');
        accessibleLabel.className = 'visually-hidden';
        accessibleLabel.textContent = messageStatusLabel(status);
        element.append(svg, accessibleLabel);
        return element;
    };

    const applyStatus = (messageId, deliveredAt, readAt) => {
        const item = history?.querySelector(`[data-message-id="${Number(messageId)}"]`);
        if (!item || Number(item.dataset.senderId) !== currentUser) return;
        const status = messageStatus(deliveredAt, readAt);
        let indicator = item.querySelector('[data-message-status]');
        if (!indicator) {
            indicator = createMessageStatus(status);
            item.append(indicator);
            return;
        }
        if (indicator.dataset.messageStatus === status) return;
        indicator.replaceWith(createMessageStatus(status));
    };

    const markVisibleMessagesRead = async (messageIds) => {
        const ids = messageIds.filter((id) => !readRequests.has(id));
        if (!ids.length) return;
        ids.forEach((id) => readRequests.add(id));
        try {
            const data = await post({
                action: 'read',
                conversation_id: String(conversationId),
                message_ids: ids.join(','),
            });
            data.status_updates.forEach((message) => applyStatus(message.id, message.delivered_at, message.read_at));
            updateUnread(data.unread_count, data.unread_conversations);
        } catch (error) {
            ids.forEach((id) => readRequests.delete(id));
            if (chatFeedback) chatFeedback.textContent = `Read receipt could not be updated: ${error.message}`;
        }
    };

    const appendMessage = (message, prepend = false) => {
        if (!history || history.querySelector(`[data-message-id="${Number(message.id)}"]`)) return;
        const wasNearBottom = history.scrollHeight - history.scrollTop - history.clientHeight < 120;
        const item = document.createElement('article');
        const mine = Number(message.sender_id) === currentUser;
        item.className = `chat-message${mine ? ' mine' : ''}`;
        item.dataset.messageId = String(message.id);
        item.dataset.senderId = String(message.sender_id);
        const sender = document.createElement('strong');
        sender.textContent = mine ? 'You' : message.sender_name;
        const content = document.createElement('p');
        content.textContent = message.body;
        const timestamp = document.createElement('time');
        timestamp.dateTime = `${message.created_at.replace(' ', 'T')}Z`;
        timestamp.textContent = formatTime(message.created_at);
        item.append(sender, content, timestamp);
        if (mine) {
            item.append(createMessageStatus(messageStatus(message.delivered_at, message.read_at)));
        }
        if (prepend) {
            const firstMessage = history.querySelector('[data-message-id]');
            history.insertBefore(item, firstMessage || null);
        }
        else history.append(item);
        if (!mine) readObserver?.observe(item);
        if (!prepend) {
            lastMessageId = Math.max(lastMessageId, Number(message.id));
            chat.dataset.lastId = String(lastMessageId);
            if (mine || wasNearBottom) history.scrollTop = history.scrollHeight;
            const link = chat.querySelector(`.conversation-link[href="messages.php?conversation=${conversationId}"]`);
            const preview = link?.querySelector('.conversation-summary > span');
            if (preview) preview.textContent = message.body.slice(0, 72);
            const meta = link?.querySelector('.conversation-meta');
            let timestamp = meta?.querySelector('time');
            if (meta && !timestamp) {
                timestamp = document.createElement('time');
                meta.prepend(timestamp);
            }
            if (timestamp) timestamp.textContent = `${message.created_at} UTC`;
            if (link?.parentElement) link.parentElement.prepend(link);
        }
    };

    const updateConversationPreview = (message) => {
        const conversation = Number(message.conversation_id);
        const link = chat.querySelector(`.conversation-link[href="messages.php?conversation=${conversation}"]`);
        const preview = link?.querySelector('.conversation-summary > span');
        if (preview) preview.textContent = message.body.slice(0, 72);
        const meta = link?.querySelector('.conversation-meta');
        let timestamp = meta?.querySelector('time');
        if (meta && !timestamp) {
            timestamp = document.createElement('time');
            meta.prepend(timestamp);
        }
        if (timestamp) timestamp.textContent = `${message.created_at} UTC`;
        if (link?.parentElement) link.parentElement.prepend(link);
    };

    const updateUnread = (count, conversations = []) => {
        const unread = Math.max(0, Number(count) || 0);
        if (unreadDisplay) unreadDisplay.textContent = unread > 0 ? `${unread} unread` : '';
        document.querySelectorAll('[data-message-badge]').forEach((badge) => {
            badge.hidden = unread === 0;
            badge.textContent = unread > 0 ? String(unread) : '';
        });
        chat.querySelectorAll('.conversation-meta').forEach((meta) => meta.querySelector('.notification-badge')?.remove());
        conversations.forEach((conversation) => {
            const id = Number(conversation.conversation_id);
            const link = chat.querySelector(`.conversation-link[href="messages.php?conversation=${id}"]`);
            const meta = link?.querySelector('.conversation-meta');
            if (!meta || Number(conversation.unread_count) < 1) return;
            const badge = document.createElement('span');
            badge.className = 'notification-badge';
            badge.textContent = String(conversation.unread_count);
            meta.append(badge);
        });
    };

    const poll = async () => {
        if (!polling) {
            polling = true;
            try {
                const delivered = await post({ action: 'sync' });
                delivered.messages.forEach((message) => {
                    updateConversationPreview(message);
                    if (Number(message.conversation_id) === conversationId) appendMessage(message);
                });
                updateUnread(delivered.unread_count, delivered.unread_conversations);
                if (chatFeedback) chatFeedback.textContent = '';
                if (conversationId) {
                const data = await post({
                    action: 'poll',
                    conversation_id: String(conversationId),
                    after_id: String(lastMessageId),
                    oldest_id: String(history?.querySelector('[data-message-id]')?.dataset.messageId || lastMessageId),
                });
                data.messages.forEach((message) => appendMessage(message));
                data.status_updates.forEach((message) => applyStatus(message.id, message.delivered_at, message.read_at));
                updateUnread(data.unread_count, data.unread_conversations);
                }
            } catch (error) {
                if (chatFeedback) chatFeedback.textContent = `Connection paused; retrying. ${error.message}`;
            } finally {
                polling = false;
            }
        }
        window.setTimeout(poll, 5000);
    };

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const body = composer.value.trim();
        if (!body) return;
        const sendButton = form.querySelector('button[type="submit"]');
        sendButton.disabled = true;
        if (sendFeedback) sendFeedback.textContent = 'Sending…';
        try {
            const data = await post({ action: 'send', conversation_id: String(conversationId), body });
            appendMessage(data.message);
            updateUnread(data.unread_count, data.unread_conversations);
            if (sendFeedback) sendFeedback.textContent = 'Sent';
            composer.value = '';
            composer.focus();
        } catch (error) {
            if (sendFeedback) sendFeedback.textContent = `Message not sent: ${error.message}`;
        } finally {
            sendButton.disabled = false;
        }
    });

    composer?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    searchForm?.addEventListener('submit', (event) => event.preventDefault());
    let searchTimer;
    searchInput?.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        const query = searchInput.value.trim();
        searchTimer = window.setTimeout(async () => {
            searchResults.replaceChildren();
            if (query.length < 2) return;
            try {
                const response = await fetch(`messages_api.php?action=search&q=${encodeURIComponent(query)}`, { credentials: 'same-origin' });
                if (!response.ok) throw new Error('Member search is unavailable.');
                const data = await response.json();
                data.members.forEach((member) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'chat-search-result';
                    button.textContent = member.full_name;
                    button.addEventListener('click', async () => {
                        button.disabled = true;
                        try {
                            const started = await post({ action: 'start', member_id: String(member.id) });
                            window.location.assign(`messages.php?conversation=${Number(started.conversation_id)}`);
                        } catch (error) {
                            window.alert(error.message);
                            button.disabled = false;
                        }
                    });
                    searchResults.append(button);
                });
                if (!data.members.length) {
                    const empty = document.createElement('p');
                    empty.className = 'muted';
                    empty.textContent = 'No active members found.';
                    searchResults.append(empty);
                }
            } catch (error) {
                searchResults.textContent = error.message;
            }
        }, 250);
    });

    const olderButton = history?.querySelector('[data-load-older]');
    olderButton?.addEventListener('click', async () => {
        const oldest = Number(history.querySelector('[data-message-id]')?.dataset.messageId || 0);
        if (!oldest) return;
        olderButton.disabled = true;
        try {
            const data = await post({
                action: 'older',
                conversation_id: String(conversationId),
                before_id: String(oldest),
            });
            const previousHeight = history.scrollHeight;
            data.messages.forEach((message) => appendMessage(message, true));
            history.scrollTop += history.scrollHeight - previousHeight;
            data.messages.forEach((message) => {
                if (Number(message.sender_id) !== currentUser) readObserver?.observe(history.querySelector(`[data-message-id="${Number(message.id)}"]`));
            });
            if (data.messages.length < 50) olderButton.remove();
            updateUnread(data.unread_count, data.unread_conversations);
        } catch (error) {
            if (chatFeedback) chatFeedback.textContent = `Older messages could not be loaded: ${error.message}`;
            olderButton.disabled = false;
        }
    });

    if (history) {
        readObserver = new IntersectionObserver((entries) => {
            if (document.visibilityState !== 'visible') return;
            const visibleIds = entries
                .filter((entry) => entry.isIntersecting && entry.intersectionRatio >= 0.6
                    && Number(entry.target.dataset.senderId) !== currentUser)
                .map((entry) => Number(entry.target.dataset.messageId));
            markVisibleMessagesRead(visibleIds);
        }, { root: history, threshold: 0.6 });
        history.querySelectorAll('.chat-message:not(.mine)').forEach((message) => readObserver.observe(message));
        history.scrollTop = history.scrollHeight;
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                history.querySelectorAll('.chat-message:not(.mine)').forEach((message) => {
                    readObserver.unobserve(message);
                    readObserver.observe(message);
                });
            }
        });
    }
    poll();
}
