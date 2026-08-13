// rider/js/main.js

/**
 * Escapes HTML special characters in a string.
 * @param {string} value The string to escape.
 * @returns {string} The escaped string.
 */
function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        return map[char];
    });
}

/**
 * Logs the user out by calling the auth API and redirecting.
 */
async function logout() {
    const formData = new FormData();
    formData.append('action', 'logout');
    try {
        const res = await fetch('../api/auth.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success' && data.data.redirect) {
            window.location.href = data.data.redirect;
        } else {
            window.location.href = '../index.php';
        }
    } catch (e) {
        console.error('Logout failed', e);
        window.location.href = '../index.php';
    }
}

/**
 * Polls for unread notifications and updates the UI.
 */
async function pollNotifications() {
    try {
        const response = await fetch('../api/notifications.php?action=get_unread');
        const data = await response.json();
        if (data.status === 'success') {
            const badge = document.getElementById('notification-badge');
            const list = document.getElementById('notification-list');
            if (!badge || !list) return;

            if (data.data.unread_count > 0) {
                badge.textContent = data.data.unread_count;
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }

            if (data.data.notifications.length > 0) {
                list.innerHTML = data.data.notifications.map(n => `<li><a class="dropdown-item small" href="${n.link || '#'}">${escapeHtml(n.message)}</a></li>`).join('');
            } else {
                list.innerHTML = '<li><a class="dropdown-item text-muted text-center small" href="#">No new notifications</a></li>';
            }
        }
    } catch (error) { 
        console.error('Error polling notifications:', error); 
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Sidebar toggle functionality
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            document.body.classList.toggle('sidebar-collapsed');
        });
    }
    
    // Notification polling
    pollNotifications();
    setInterval(pollNotifications, 30000); // Poll every 30 seconds
});