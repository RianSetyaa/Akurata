const api = async (endpoint, options = {}) => {
  const response = await fetch(`../api/${endpoint}`, {
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      ...(options.headers ?? {}),
    },
    ...options,
  });

  const text = await response.text();
  let payload = {};
  try {
    payload = text ? JSON.parse(text) : {};
  } catch {
    throw new Error('Server tidak mengirim JSON valid.');
  }

  if (!response.ok) {
    const error = new Error(payload.error || 'Request gagal.');
    error.status = response.status;
    throw error;
  }

  return payload;
};

const ensureToastRegion = () => {
  let region = document.querySelector('[data-toast-region]');
  if (region) return region;

  region = document.createElement('div');
  region.className = 'toast-region';
  region.dataset.toastRegion = 'true';
  region.setAttribute('aria-live', 'polite');
  document.body.append(region);
  return region;
};

const showToast = (message, type = 'error', duration = 6000) => {
  const text = String(message || '').trim();
  if (!text) return;

  const region = ensureToastRegion();
  if ([...region.querySelectorAll('.app-toast p')].some((item) => item.textContent === text)) return;
  const toast = document.createElement('div');
  toast.className = `app-toast ${type === 'success' ? 'is-success' : 'is-danger'}`;
  toast.setAttribute('role', type === 'success' ? 'status' : 'alert');

  const content = document.createElement('div');
  const title = document.createElement('strong');
  title.textContent = type === 'success' ? 'Berhasil' : 'Terjadi masalah';
  const body = document.createElement('p');
  body.textContent = text;
  content.append(title, body);

  const close = document.createElement('button');
  close.type = 'button';
  close.textContent = 'Tutup';
  let timer = null;
  const dismiss = () => {
    window.clearTimeout(timer);
    toast.classList.add('is-leaving');
    window.setTimeout(() => toast.remove(), 180);
  };
  close.addEventListener('click', dismiss);
  toast.append(content, close);
  region.append(toast);
  timer = window.setTimeout(dismiss, duration);
};

const setStatus = (message, type = 'success') => {
  const target = document.querySelector('[data-admin-status]');
  if (!target) return;
  if (type === 'error') {
    target.innerHTML = '';
    showToast(message);
    return;
  }
  target.innerHTML = message ? `<p class="notice ${type}">${escapeHtml(message)}</p>` : '';
};

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#039;',
}[char]));

let selectedSupportConversationId = 0;
let selectedSupportConversationStatus = '';
let supportLastMessageId = 0;
let supportPollTimer = null;
let supportPolling = false;
const renderedSupportMessageIds = new Set();

const setSupportStatus = (message) => {
  const target = document.querySelector('[data-support-chat-status]');
  if (target) target.textContent = message;
};

const renderSupportConversations = (rows) => {
  const container = document.querySelector('[data-support-conversations]');
  if (!container) return;
  const conversations = Array.isArray(rows) ? rows : [];

  container.innerHTML = conversations.length
    ? conversations.map((conversation) => `
      <button class="admin-support-conversation ${Number(conversation.id) === selectedSupportConversationId ? 'is-active' : ''}" type="button" data-support-conversation="${conversation.id}">
        <strong>${escapeHtml(conversation.outlet_name)}</strong>
        <span>${escapeHtml(conversation.last_message || 'Belum ada pesan')}</span>
        <small>${escapeHtml(conversation.last_message_at || conversation.created_at || '')}</small>
        ${conversation.status === 'closed' ? '<mark>Riwayat</mark>' : ''}
        ${Number(conversation.unread_count || 0) > 0 ? `<em>${conversation.unread_count} baru</em>` : ''}
      </button>
    `).join('')
    : '<p class="notice">Belum ada percakapan CS.</p>';
};

const appendSupportMessages = (rows) => {
  const container = document.querySelector('[data-support-messages]');
  if (!container) return;
  let appended = false;

  for (const message of Array.isArray(rows) ? rows : []) {
    const messageId = Number(message.id || 0);
    if (!messageId || renderedSupportMessageIds.has(messageId)) continue;
    renderedSupportMessageIds.add(messageId);
    supportLastMessageId = Math.max(supportLastMessageId, messageId);

    const item = document.createElement('article');
    item.className = `admin-support-message ${message.sender_type === 'administrator' ? 'is-admin' : 'is-outlet'}`;
    const meta = document.createElement('span');
    meta.textContent = `${message.sender_name || 'User'} · ${message.created_at || ''}`;
    const body = document.createElement('p');
    body.textContent = message.message || '';
    item.append(meta, body);
    container.append(item);
    appended = true;
  }

  container.querySelector('[data-support-empty]')?.classList.toggle('is-hidden', renderedSupportMessageIds.size > 0);
  if (appended) container.scrollTop = container.scrollHeight;
};

const scheduleSupportPoll = (delay = 0) => {
  window.clearTimeout(supportPollTimer);
  supportPollTimer = window.setTimeout(pollSupport, delay);
};

const pollSupport = async () => {
  if (supportPolling) return;
  supportPolling = true;

  try {
    const wait = selectedSupportConversationStatus === 'closed' ? 0 : 15;
    const query = selectedSupportConversationId
      ? `conversation_id=${selectedSupportConversationId}&since_id=${supportLastMessageId}&wait=${wait}`
      : 'wait=3';
    const payload = await api(`support-chat.php?${query}`);
    renderSupportConversations(payload.conversations);
    if (payload.conversation) {
      selectedSupportConversationStatus = payload.conversation.status;
      document.querySelector('[data-support-chat-title]').textContent = payload.conversation.outlet_name;
      const isOpen = payload.conversation.status === 'open';
      setSupportStatus(isOpen ? 'Sesi aktif' : `Riwayat sesi #${payload.conversation.id}`);
      document.querySelector('[data-support-end-chat]')?.classList.toggle('is-hidden', !isOpen);
      document.querySelector('[data-support-form] textarea').disabled = !isOpen;
      document.querySelector('[data-support-form] button').disabled = !isOpen;
      appendSupportMessages(payload.messages);
    }
    scheduleSupportPoll(
      selectedSupportConversationId
        ? (selectedSupportConversationStatus === 'closed' ? 5000 : 0)
        : 4000,
    );
  } catch (error) {
    setSupportStatus(error.status === 409 ? 'Jalankan migration chat CS terlebih dahulu.' : 'Mencoba menyambungkan kembali.');
    if (error.status !== 401 && error.status !== 409) scheduleSupportPoll(5000);
  } finally {
    supportPolling = false;
  }
};

const selectSupportConversation = (conversationId, outletName = 'Outlet') => {
  selectedSupportConversationId = Number(conversationId);
  selectedSupportConversationStatus = '';
  supportLastMessageId = 0;
  renderedSupportMessageIds.clear();
  const messages = document.querySelector('[data-support-messages]');
  if (messages) messages.innerHTML = '<p class="notice" data-support-empty>Memuat pesan.</p>';
  document.querySelector('[data-support-chat-title]').textContent = outletName;
  document.querySelector('[data-support-form] textarea').disabled = true;
  document.querySelector('[data-support-form] button').disabled = true;
  document.querySelector('[data-support-end-chat]')?.classList.add('is-hidden');
  scheduleSupportPoll(0);
};

const render = (payload) => {
  const outlets = Array.isArray(payload.outlets) ? payload.outlets : [];
  const users = Array.isArray(payload.users) ? payload.users : [];
  const admin = payload.admin || {};

  setStatus(admin.impersonating
    ? `Sedang impersonate outlet ${admin.outlet_name}.`
    : 'Mode administrator aktif.');

  const outletTable = document.querySelector('[data-outlet-table]');
  if (outletTable) {
    outletTable.innerHTML = `
      <div class="admin-row admin-head">
        <span>Outlet</span>
        <span>User</span>
        <span>Produk</span>
        <span>Transaksi</span>
        <span>Aksi</span>
      </div>
      ${outlets.length
        ? outlets.map((outlet) => `
          <div class="admin-row">
            <strong>${escapeHtml(outlet.name)}</strong>
            <span>${outlet.user_count}</span>
            <span>${outlet.product_count}</span>
            <span>${outlet.transaction_count}</span>
            <span class="admin-inline-actions">
              <button class="btn btn-secondary" type="button" data-impersonate="${outlet.id}">Impersonate</button>
              <button class="btn btn-ghost" type="button" data-delete-outlet="${outlet.id}" data-name="${escapeHtml(outlet.name)}">Hapus</button>
            </span>
          </div>
        `).join('')
        : '<p class="notice">Belum ada outlet.</p>'}
    `;
  }

  const userList = document.querySelector('[data-user-list]');
  if (userList) {
    userList.innerHTML = users.length
      ? users.map((user) => `
        <div class="user-row">
          <strong>${escapeHtml(user.name)}</strong>
          <span>${escapeHtml(user.outlet_name)}</span>
          <span>${escapeHtml(user.role)}</span>
          ${Number(user.id) === Number(admin.id)
            ? '<button class="btn btn-ghost" type="button" disabled>Akun aktif</button>'
            : `<button class="btn btn-ghost" type="button" data-delete-user="${user.id}" data-name="${escapeHtml(user.name)}">Hapus</button>`}
        </div>
      `).join('')
      : '<p class="notice">Belum ada user.</p>';
  }
};

const load = async () => {
  try {
    render(await api('administrator.php'));
  } catch (error) {
    if (error.status === 401) {
      window.location.href = '../login.html';
      return;
    }

    setStatus(error.message, 'error');
  }
};

document.addEventListener('click', async (event) => {
  const supportConversation = event.target.closest('[data-support-conversation]');
  if (supportConversation) {
    selectSupportConversation(
      supportConversation.dataset.supportConversation,
      supportConversation.querySelector('strong')?.textContent || 'Outlet',
    );
    return;
  }

  const endSupportChatButton = event.target.closest('[data-support-end-chat]');
  if (endSupportChatButton && selectedSupportConversationId) {
    if (!window.confirm('Akhiri sesi ini? Percakapan tetap tersimpan sebagai riwayat.')) return;
    endSupportChatButton.disabled = true;
    try {
      const payload = await api('support-chat.php', {
        method: 'PATCH',
        body: JSON.stringify({ conversation_id: selectedSupportConversationId }),
      });
      selectedSupportConversationStatus = 'closed';
      setSupportStatus(payload.message || 'Sesi chat diakhiri.');
      document.querySelector('[data-support-form] textarea').disabled = true;
      document.querySelector('[data-support-form] button').disabled = true;
      endSupportChatButton.classList.add('is-hidden');
      scheduleSupportPoll(0);
    } catch (error) {
      setSupportStatus(error.message);
    } finally {
      endSupportChatButton.disabled = false;
    }
    return;
  }

  const impersonateButton = event.target.closest('[data-impersonate]');
  if (impersonateButton) {
    impersonateButton.disabled = true;
    try {
      const payload = await api('administrator.php', {
        method: 'POST',
        body: JSON.stringify({
          action: 'impersonate',
          outlet_id: Number(impersonateButton.dataset.impersonate),
        }),
      });
      window.location.href = payload.redirect || '../dashboard/index.html';
    } catch (error) {
      setStatus(error.message, 'error');
      impersonateButton.disabled = false;
    }
    return;
  }

  const deleteUserButton = event.target.closest('[data-delete-user]');
  if (deleteUserButton) {
    const userName = deleteUserButton.dataset.name || 'user ini';
    if (!window.confirm(`Hapus akun ${userName}? Histori tetap disimpan, tapi user tidak bisa login lagi.`)) {
      return;
    }

    deleteUserButton.disabled = true;
    try {
      const payload = await api('administrator.php', {
        method: 'DELETE',
        body: JSON.stringify({
          type: 'user',
          id: Number(deleteUserButton.dataset.deleteUser),
        }),
      });
      await load();
      setStatus(payload.message || 'User berhasil dihapus.');
    } catch (error) {
      setStatus(error.message, 'error');
      deleteUserButton.disabled = false;
    }
    return;
  }

  const deleteOutletButton = event.target.closest('[data-delete-outlet]');
  if (deleteOutletButton) {
    const outletName = deleteOutletButton.dataset.name || 'toko ini';
    const confirmed = window.confirm(`Hapus toko ${outletName} beserta seluruh data outletnya? Aksi ini tidak bisa dibatalkan.`);
    if (!confirmed) {
      return;
    }

    deleteOutletButton.disabled = true;
    try {
      const payload = await api('administrator.php', {
        method: 'DELETE',
        body: JSON.stringify({
          type: 'outlet',
          id: Number(deleteOutletButton.dataset.deleteOutlet),
        }),
      });
      await load();
      setStatus(payload.message || 'Toko berhasil dihapus.');
    } catch (error) {
      setStatus(error.message, 'error');
      deleteOutletButton.disabled = false;
    }
    return;
  }

  if (event.target.closest('[data-stop-impersonate]')) {
    try {
      await api('administrator.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'stop_impersonate' }),
      });
      await load();
    } catch (error) {
      setStatus(error.message, 'error');
    }
    return;
  }

  if (event.target.closest('[data-logout]')) {
    try {
      await api('logout.php', { method: 'POST' });
    } finally {
      window.location.href = '../login.html';
    }
  }
});

document.querySelector('[data-support-form]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!selectedSupportConversationId || selectedSupportConversationStatus !== 'open') return;
  const form = event.currentTarget;
  const textarea = form.querySelector('[name="message"]');
  const button = form.querySelector('button[type="submit"]');
  const message = textarea.value.trim();
  if (!message) return;

  button.disabled = true;
  try {
    await api('support-chat.php', {
      method: 'POST',
      body: JSON.stringify({
        conversation_id: selectedSupportConversationId,
        message,
      }),
    });
    textarea.value = '';
    setSupportStatus('Balasan terkirim');
    scheduleSupportPoll(0);
  } catch (error) {
    setSupportStatus(error.message);
  } finally {
    button.disabled = false;
    textarea.focus();
  }
});

window.addEventListener('pagehide', () => {
  window.clearTimeout(supportPollTimer);
});

load();
scheduleSupportPoll(0);
