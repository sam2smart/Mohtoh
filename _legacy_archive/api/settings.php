<?php
// rider/settings.php
$page_title = 'Settings';
require_once __DIR__ . '/includes/header.php';
?>

<div id="settings-container">
    <!-- Settings content will be loaded here -->
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
    async function loadSettings() {
        const container = document.getElementById('settings-container');
        container.innerHTML = '<div class="content-card p-5 text-center"><div class="spinner-border"></div></div>';
        try {
            const response = await fetch('../api/profile.php');
            const data = await response.json();
            if (data.status === 'success') {
                renderSettingsForm(data.data);
            } else {
                container.innerHTML = `<div class="content-card p-4"><div class="alert alert-danger">${escapeHtml(data.message)}</div></div>`;
            }
        } catch (error) {
            container.innerHTML = `<div class="content-card p-4"><div class="alert alert-danger">Could not load settings.</div></div>`;
        }
    }

    function renderSettingsForm(settings) {
        const container = document.getElementById('settings-container');
        container.innerHTML = `
            <div class="content-card p-4">
                <h4 class="fw-bold mb-4">Notification Settings</h4>
                <div id="settingsAlert" class="alert d-none"></div>
                <form id="settingsForm">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="notify_ride_start" id="notifyRideStart" ${settings.notify_ride_start == 1 ? 'checked' : ''}>
                        <label class="form-check-label" for="notifyRideStart">
                            <strong>Ride Start Alerts</strong>
                            <small class="d-block text-muted">Receive a notification when your driver starts the trip.</small>
                        </label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="notify_ride_cancel" id="notifyRideCancel" ${settings.notify_ride_cancel == 1 ? 'checked' : ''}>
                        <label class="form-check-label" for="notifyRideCancel">
                            <strong>Cancellation Alerts</strong>
                            <small class="d-block text-muted">Get notified if a driver cancels a ride you've booked.</small>
                        </label>
                    </div>
                    <div class="d-flex justify-content-end mt-4">
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </div>
                </form>
            </div>
        `;

        const settingsForm = document.getElementById('settingsForm');
        if (settingsForm) {
            settingsForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(settingsForm);
                if (!formData.has('notify_ride_start')) formData.set('notify_ride_start', 0);
                if (!formData.has('notify_ride_cancel')) formData.set('notify_ride_cancel', 0);

                const alertEl = document.getElementById('settingsAlert');
                alertEl.className = 'alert d-none';

                try {
                    const response = await fetch('../api/profile.php', { method: 'POST', body: formData });
                    const data = await response.json();
                    alertEl.className = `alert alert-${data.status === 'success' ? 'success' : 'danger'}`;
                    alertEl.textContent = data.message;
                    alertEl.classList.remove('d-none');
                } catch (error) {
                    alertEl.className = 'alert alert-danger';
                    alertEl.textContent = 'An error occurred while saving settings.';
                    alertEl.classList.remove('d-none');
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        loadSettings();
    });
</script>