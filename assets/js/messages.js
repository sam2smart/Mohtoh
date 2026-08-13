// assets/js/messages.js
// The MohToh Intelligent Chat System's client. Opens via the generic
// [data-open-sheet-id="messagesSheet"] handler already wired in
// navigation.js — this file only owns messagesSheet's own content:
// list/detail view switching, sending, polling, and the unread badge.
// AJAX polling only (5s while a chat is open, list refreshed on open) —
// deliberately shaped so a future WebSocket push only has to call
// renderNewMessages() instead of a poll response, per the "no DB/API
// reshape needed later" requirement.

document.addEventListener('DOMContentLoaded', () => {
  const sheet = qs('#messagesSheet');
  if (!sheet) return; // page doesn't include the chat sheet

  const listView = qs('#msgListView');
  const detailView = qs('#msgDetailView');
  const conversationListEl = qs('#msgConversationList');
  const listEmptyEl = qs('#msgListEmpty');
  const startSupportBtn = qs('#msgStartSupportBtn');

  const detailTitle = qs('#msgDetailTitle');
  const detailSubtitle = qs('#msgDetailSubtitle');
  const archivedBanner = qs('#msgArchivedBanner');
  const bubbleList = qs('#msgBubbleList');
  const sendForm = qs('#msgSendForm');
  const msgInput = qs('#msgInput');
  const sendBtn = qs('#msgSendBtn');
  const locationBtn = qs('#msgLocationBtn');
  const backBtn = qs('#msgBackBtn');

  let activeConversationId = null;
  let activeConversationArchived = false;
  let lastMessageId = 0;
  let pollTimer = null;

  function showListView() {
    stopPolling();
    activeConversationId = null;
    detailView.classList.add('u-hidden');
    listView.classList.remove('u-hidden');
    loadConversationList();
  }

  function showDetailView() {
    listView.classList.add('u-hidden');
    detailView.classList.remove('u-hidden');
  }

  function conversationIcon(type) {
    if (type === 'ride') return 'bi-signpost-split';
    if (type === 'private') return 'bi-person';
    if (type === 'support') return 'bi-headset';
    if (type === 'broadcast') return 'bi-megaphone';
    return 'bi-chat-dots';
  }

  function renderConversationRow(c) {
    const preview = c.last_message
      ? `${c.last_message.is_system ? '' : escapeHtml(c.last_message.sender_name) + ': '}${escapeHtml(c.last_message.text || '')}`
      : 'No messages yet';
    return `
      <button type="button" class="msg-conv-item ${c.unread_count > 0 ? 'has-unread' : ''}" data-conversation-id="${c.conversation_id}">
        <span class="msg-conv-item__icon"><i class="bi ${conversationIcon(c.type)}"></i></span>
        <span class="msg-conv-item__body">
          <span class="msg-conv-item__title"><span>${escapeHtml(c.title || 'Conversation')}</span></span>
          <span class="msg-conv-item__preview">${preview}</span>
        </span>
        ${c.unread_count > 0 ? `<span class="msg-conv-item__badge">${c.unread_count > 9 ? '9+' : c.unread_count}</span>` : ''}
      </button>`;
  }

  async function loadConversationList() {
    try {
      const data = await apiGet('../api/messages.php', { action: 'list_conversations' });
      if (data.status !== 'success') return;
      const conversations = data.data.conversations || [];
      conversationListEl.innerHTML = conversations.map(renderConversationRow).join('');
      listEmptyEl.classList.toggle('u-hidden', conversations.length > 0);
    } catch (error) {
      // Degrades silently, same as every other poll in this app.
    }
  }

  conversationListEl?.addEventListener('click', (e) => {
    const row = e.target.closest('[data-conversation-id]');
    if (!row) return;
    openConversation(Number(row.dataset.conversationId));
  });

  startSupportBtn?.addEventListener('click', async () => {
    try {
      const data = await apiPost('../api/messages.php', { action: 'start_support' });
      if (data.status === 'success') openConversation(data.data.conversation_id);
      else showToast(data.message || 'Could not start a support chat.');
    } catch (error) {
      showToast('Could not start a support chat.');
    }
  });

  function renderBubble(m) {
    if (m.is_system) {
      return `<div class="msg-bubble msg-bubble--system">${escapeHtml(m.message)}</div>`;
    }
    const cls = m.is_own ? 'msg-bubble--own' : 'msg-bubble--other';
    const flagged = m.flagged_for_review ? ' msg-bubble--flagged' : '';
    let body;
    if (m.message_type === 'location' && m.latitude !== null && m.longitude !== null) {
      const mapUrl = `https://maps.google.com/?q=${m.latitude},${m.longitude}`;
      body = `<i class="bi bi-geo-alt-fill"></i> Shared a location<a class="msg-location-preview" href="${mapUrl}" target="_blank" rel="noopener">View on map</a>`;
    } else {
      body = escapeHtml(m.message);
    }
    const senderLine = m.is_own ? '' : `<div class="msg-bubble__sender">${escapeHtml(m.sender_name)}</div>`;
    const time = new Date(m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    return `<div class="msg-bubble ${cls}${flagged}" data-message-id="${m.public_id}">${senderLine}${body}<span class="msg-bubble__time">${time}</span></div>`;
  }

  function scrollToBottom() {
    bubbleList.scrollTop = bubbleList.scrollHeight;
  }

  async function openConversation(conversationId) {
    activeConversationId = conversationId;
    lastMessageId = 0;
    bubbleList.innerHTML = '';
    detailTitle.textContent = 'Loading…';
    detailSubtitle.textContent = '';
    archivedBanner.classList.add('u-hidden');
    showDetailView();
    await fetchMessages(true);
    startPolling();
  }

  async function fetchMessages(isInitialLoad) {
    if (!activeConversationId) return;
    try {
      const data = await apiGet('../api/messages.php', { action: 'get_messages', conversation_id: activeConversationId, after_id: lastMessageId });
      if (data.status !== 'success') {
        if (isInitialLoad) showToast(data.message || 'Could not open this conversation.');
        return;
      }

      activeConversationArchived = data.data.conversation_status !== 'active';
      archivedBanner.classList.toggle('u-hidden', !activeConversationArchived);
      setComposerEnabled(!activeConversationArchived);

      const messages = data.data.messages || [];
      if (messages.length) {
        bubbleList.insertAdjacentHTML('beforeend', messages.map(renderBubble).join(''));
        lastMessageId = messages[messages.length - 1].id;
        scrollToBottom();
        markRead(lastMessageId);
      }

      if (isInitialLoad) {
        const row = qs(`[data-conversation-id="${activeConversationId}"]`, conversationListEl);
        detailTitle.textContent = row ? row.querySelector('.msg-conv-item__title span')?.textContent || 'Chat' : 'Chat';
      }
    } catch (error) {
      // Poll failures degrade silently — same pattern as pollNotifications().
    }
  }

  function setComposerEnabled(enabled) {
    msgInput.disabled = !enabled;
    sendBtn.disabled = !enabled;
    locationBtn.disabled = !enabled;
    msgInput.placeholder = enabled ? 'Type a message…' : 'This conversation is archived';
  }

  async function markRead(upToMessageId) {
    if (!activeConversationId || !upToMessageId) return;
    try {
      await apiPost('../api/messages.php', { action: 'mark_read', conversation_id: activeConversationId, up_to_message_id: upToMessageId });
    } catch (error) {
      // Non-critical — the badge just stays slightly stale until the next successful mark.
    }
  }

  function startPolling() {
    stopPolling();
    pollTimer = setInterval(() => fetchMessages(false), 5000);
  }
  function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
  }

  backBtn?.addEventListener('click', showListView);

  sendForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = msgInput.value.trim();
    if (!text || !activeConversationId) return;
    setButtonLoading(sendBtn, true, '');
    try {
      const data = await apiPost('../api/messages.php', { action: 'send', conversation_id: activeConversationId, message: text, message_type: 'text' });
      if (data.status === 'success') {
        msgInput.value = '';
        await fetchMessages(false);
      } else {
        showToast(data.message || 'Message not sent.');
      }
    } catch (error) {
      showToast('Message not sent — check your connection.');
    } finally {
      setButtonLoading(sendBtn, false);
    }
  });

  locationBtn?.addEventListener('click', () => {
    if (!activeConversationId || !window.MohToh || !window.MohToh.location) {
      showToast('Location is not available on this device.');
      return;
    }
    locationBtn.disabled = true;
    window.MohToh.location.getCurrentLocation({ timeout: 8000 }).then(
      async (position) => {
        try {
          const data = await apiPost('../api/messages.php', {
            action: 'send', conversation_id: activeConversationId, message_type: 'location',
            latitude: position.latitude, longitude: position.longitude,
          });
          if (data.status === 'success') await fetchMessages(false);
          else showToast(data.message || 'Could not share your location.');
        } catch (error) {
          showToast('Could not share your location.');
        } finally {
          locationBtn.disabled = false;
        }
      },
      () => { showToast('Location permission denied.'); locationBtn.disabled = false; }
    );
  });

  // Reset to the conversation list every time the sheet is (re)opened —
  // mirrors the notification sheet's "always fresh on open" behavior.
  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-open-sheet-id="messagesSheet"]');
    if (opener) showListView();
  });

  // Public entry point for other screens' markup (e.g. the rider active-
  // trip card's Chat button, assets/js/rider-trips.js's
  // [data-open-ride-chat]) that need to jump straight into a specific
  // driver-passenger chat rather than the conversation list. Gated
  // server-side by get_or_create_private_conversation()'s confirmed-
  // booking check — this is just the UI entry point.
  window.mtOpenPrivateChat = async function (rideShareId, otherUserId) {
    openSheet(sheet, qs('[data-sheet-scrim]'));
    listView.classList.add('u-hidden');
    detailView.classList.remove('u-hidden');
    detailTitle.textContent = 'Loading…';
    try {
      const data = await apiPost('../api/messages.php', { action: 'start_private', ride_share_id: rideShareId, other_user_id: otherUserId });
      if (data.status === 'success') {
        openConversation(data.data.conversation_id);
      } else {
        showToast(data.message || 'Chat is not available for this ride yet.');
        showListView();
      }
    } catch (error) {
      showToast('Could not open chat — check your connection.');
      showListView();
    }
  };
  document.addEventListener('click', (e) => {
    const chatOpener = e.target.closest('[data-open-ride-chat]');
    if (!chatOpener) return;
    e.preventDefault();
    window.mtOpenPrivateChat(Number(chatOpener.dataset.openRideChat), Number(chatOpener.dataset.chatDriverId));
  });

  // Closing the sheet (X button or scrim) should stop the 5s poll even
  // if a chat is open — data-close-sheet is handled generically by
  // navigation.js, this just adds the poll-teardown side effect.
  sheet.addEventListener('click', (e) => {
    if (e.target.closest('[data-close-sheet]')) stopPolling();
  });
  qs('[data-sheet-scrim]')?.addEventListener('click', stopPolling);

  // ---- Unread badge on the bottom-nav Messages tab ----
  const msgNavDot = qs('#messagesNavDot');
  async function pollChatUnread() {
    try {
      const data = await apiGet('../api/messages.php', { action: 'unread_count' });
      if (data.status !== 'success' || !msgNavDot) return;
      const count = data.data.unread_count;
      if (count > 0) {
        msgNavDot.textContent = count > 9 ? '9+' : String(count);
        msgNavDot.classList.remove('u-hidden');
      } else {
        msgNavDot.classList.add('u-hidden');
      }
    } catch (error) {
      // Degrades silently.
    }
  }
  if (msgNavDot) {
    pollChatUnread();
    setInterval(pollChatUnread, 30000);
  }
});
