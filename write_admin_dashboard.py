from pathlib import Path

content = r'''<?php
session_start();
require_once '../includes/functions.php';
require_login();

if ($_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Merit Drives</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        :root { --primary:#2563eb; --secondary:#7c3aed; --success:#10b981; --warning:#f59e0b; --danger:#ef4444; --dark:#0f172a; --light:#f8fafc; --border:#e2e8f0; }
        body { background: var(--light); color: var(--dark); font-family: 'Segoe UI', sans-serif; }
        .sidebar { width: 260px; background: linear-gradient(135deg, var(--dark), #1e293b); min-height: 100vh; position: sticky; top: 0; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); border-radius: 10px; margin: 2px 0; }
        .sidebar .nav-link.active, .sidebar .nav-link:hover { background: rgba(255,255,255,0.12); color: #fff; }
        .main-area { min-height: 100vh; }
        .topbar { background: white; border-bottom: 1px solid var(--border); }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }
        .stat-card { border: 0; border-radius: 16px; box-shadow: 0 8px 18px rgba(15,23,42,0.06); }
        .map-card { border: 0; border-radius: 16px; overflow: hidden; }
        #admin-map { height: 420px; border-radius: 16px; }
        .table thead th { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; }
        .form-card { max-width: 760px; }
    </style>
</head>
<body>
<div class="d-flex">
    <aside class="sidebar p-3">
        <div class="text-white mb-4">
            <h4 class="fw-bold mb-0"><i class="fas fa-car-side me-2"></i>Merit Drives</h4>
            <small class="text-white-50">Mohtoh Admin</small>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link active" href="#" data-tab="dashboard"><i class="fas fa-chart-line me-2"></i>Dashboard</a>
            <a class="nav-link" href="#" data-tab="drivers"><i class="fas fa-user-tie me-2"></i>Drivers</a>
            <a class="nav-link" href="#" data-tab="passengers"><i class="fas fa-user me-2"></i>Passengers</a>
            <a class="nav-link" href="#" data-tab="ride-sessions"><i class="fas fa-calendar-check me-2"></i>Ride Sessions</a>
            <a class="nav-link" href="#" data-tab="bookings"><i class="fas fa-ticket-alt me-2"></i>Bookings</a>
            <a class="nav-link" href="#" data-tab="live-map"><i class="fas fa-map-marked-alt me-2"></i>Live Map</a>
            <a class="nav-link" href="#" data-tab="pricing"><i class="fas fa-tags me-2"></i>Pricing</a>
            <a class="nav-link" href="#" data-tab="notifications"><i class="fas fa-bullhorn me-2"></i>Announcements</a>
            <a class="nav-link" href="#" data-tab="settings"><i class="fas fa-cog me-2"></i>Settings</a>
        </nav>
        <div class="mt-5 border-top border-secondary pt-3">
            <button class="btn btn-outline-light btn-sm w-100" onclick="logout()"><i class="fas fa-sign-out-alt me-2"></i>Logout</button>
        </div>
    </aside>

    <main class="main-area flex-grow-1">
        <header class="topbar px-4 py-3 d-flex justify-content-between align-items-center">
            <div>
                <h5 class="fw-bold mb-0">Mohtoh Admin Console</h5>
                <small class="text-muted">Monitor riders, drivers, rides and pricing</small>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="badge bg-success-subtle text-success"><i class="fas fa-circle me-1"></i>Online</span>
                <div class="text-end">
                    <div class="fw-semibold">Administrator</div>
                    <small class="text-muted">System Operations</small>
                </div>
            </div>
        </header>

        <div class="p-4">
            <section id="tab-dashboard" class="tab-pane active">
                <div class="row g-3 mb-4">
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Users</div><h3 class="fw-bold mb-0" id="stat-users">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Drivers</div><h3 class="fw-bold mb-0" id="stat-drivers">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Passengers</div><h3 class="fw-bold mb-0" id="stat-passengers">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Active Rides</div><h3 class="fw-bold mb-0" id="stat-active-rides">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Mohtoh Rides</div><h3 class="fw-bold mb-0" id="stat-mohtoh-rides">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Pending Vehicles</div><h3 class="fw-bold mb-0" id="stat-pending-vehicles">0</h3></div></div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="card shadow-sm border-0">
                            <div class="card-body">
                                <h5 class="fw-bold mb-3"><i class="fas fa-users me-2"></i>Recent Registrations</h5>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead><tr><th>Name</th><th>Type</th><th>Joined</th></tr></thead>
                                        <tbody id="recent-users-body"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="card shadow-sm border-0">
                            <div class="card-body">
                                <h5 class="fw-bold mb-3"><i class="fas fa-route me-2"></i>Recent Rides</h5>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead><tr><th>ID</th><th>Destination</th><th>Status</th></tr></thead>
                                        <tbody id="recent-rides-body"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="tab-drivers" class="tab-pane">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold mb-0"><i class="fas fa-user-tie me-2"></i>Driver Management</h5>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Name</th><th>Email</th><th>Rating</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody id="drivers-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section id="tab-passengers" class="tab-pane">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-user me-2"></i>Passenger Management</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Name</th><th>Email</th><th>Rating</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody id="passengers-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section id="tab-ride-sessions" class="tab-pane">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-calendar-check me-2"></i>Mohtoh Ride Sessions</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Destination</th><th>Driver</th><th>Date</th><th>Seats</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody id="rides-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section id="tab-bookings" class="tab-pane">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-ticket-alt me-2"></i>Ride Bookings</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Ride</th><th>Rider</th><th>Seats</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody id="bookings-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section id="tab-live-map" class="tab-pane">
                <div class="card map-card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-map-marked-alt me-2"></i>Live Tracking</h5>
                        <div id="admin-map"></div>
                    </div>
                </div>
            </section>

            <section id="tab-pricing" class="tab-pane">
                <div class="card shadow-sm border-0 form-card">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-tags me-2"></i>Pricing Configuration</h5>
                        <form id="pricing-form">
                            <div class="mb-3"><label class="form-label">Base price per km</label><input type="number" step="0.01" class="form-control" id="price-per-km" required></div>
                            <div class="mb-3"><label class="form-label">Platform commission (%)</label><input type="number" step="0.01" class="form-control" id="platform-commission" required></div>
                            <div class="mb-3"><label class="form-label">Service charge</label><input type="number" step="0.01" class="form-control" id="service-charge" required></div>
                            <button class="btn btn-primary" type="submit">Save Pricing</button>
                        </form>
                    </div>
                </div>
            </section>

            <section id="tab-notifications" class="tab-pane">
                <div class="card shadow-sm border-0 form-card">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-bullhorn me-2"></i>Send Announcement</h5>
                        <form id="announcement-form">
                            <div class="mb-3"><label class="form-label">Audience</label><select class="form-select" id="announcement-target"><option value="drivers">Drivers</option><option value="passengers">Passengers</option></select></div>
                            <div class="mb-3"><label class="form-label">Message</label><textarea class="form-control" id="announcement-message" rows="4" required></textarea></div>
                            <button class="btn btn-primary" type="submit">Send Announcement</button>
                        </form>
                    </div>
                </div>
            </section>

            <section id="tab-settings" class="tab-pane">
                <div class="card shadow-sm border-0 form-card">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-cog me-2"></i>Platform Settings</h5>
                        <div class="alert d-none" id="settings-alert"></div>
                        <form id="settings-form">
                            <div class="mb-3"><label class="form-label">Commission Percentage (%)</label><input type="number" min="0" max="100" class="form-control" id="commission-input" required></div>
                            <button class="btn btn-primary" type="submit">Save Settings</button>
                        </form>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    let map; let layerGroup = L.layerGroup(); let dashboardData = {};
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.sidebar .nav-link').forEach(link => link.addEventListener('click', e => { e.preventDefault(); switchTab(link.dataset.tab); }));
        initMap(); loadDashboardData(); setInterval(loadDashboardData, 15000);
        document.getElementById('settings-form').addEventListener('submit', handleSettingsSubmit);
        document.getElementById('pricing-form').addEventListener('submit', handlePricingSubmit);
        document.getElementById('announcement-form').addEventListener('submit', handleAnnouncementSubmit);
    });
    function switchTab(tabName) { document.querySelectorAll('.tab-pane').forEach(tab => tab.classList.remove('active')); document.querySelectorAll('.sidebar .nav-link').forEach(link => link.classList.remove('active')); document.getElementById(`tab-${tabName}`).classList.add('active'); document.querySelector(`.sidebar .nav-link[data-tab="${tabName}"]`).classList.add('active'); }
    function initMap() { map = L.map('admin-map').setView([6.5244, 3.3792], 10); L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map); layerGroup.addTo(map); }
    async function loadDashboardData() { try { const res = await fetch('../api/admin-api.php?action=get_dashboard_data'); const data = await res.json(); if (data.status !== 'success') throw new Error(data.message || 'Failed'); dashboardData = data.data; renderDashboard(); } catch (err) { console.error(err); } }
    function renderDashboard() { const stats = dashboardData.stats || {}; document.getElementById('stat-users').textContent = stats.total_users || 0; document.getElementById('stat-drivers').textContent = stats.total_drivers || 0; document.getElementById('stat-passengers').textContent = stats.total_riders || 0; document.getElementById('stat-active-rides').textContent = stats.active_rides || 0; document.getElementById('stat-mohtoh-rides').textContent = stats.mohtoh_rides_total || 0; document.getElementById('stat-pending-vehicles').textContent = stats.pending_vehicle_reviews || 0; renderRecentUsers(dashboardData.users || []); renderRecentRides(dashboardData.rides || []); renderDrivers(dashboardData.drivers || []); renderPassengers(dashboardData.passengers || []); renderRides(dashboardData.mohtoh_rides || []); renderBookings(dashboardData.bookings || []); renderPricing(dashboardData.pricing || {}); renderSettings(dashboardData.commission_percentage || 10); renderMap(dashboardData.online_users || []); }
    function renderRecentUsers(users){ const body=document.getElementById('recent-users-body'); if(!users.length){body.innerHTML='<tr><td colspan="3" class="text-muted">No users yet.</td></tr>';return;} body.innerHTML=users.slice(0,6).map(user=>`<tr><td>${escapeHtml(user.fullname||'Unknown')}</td><td>${escapeHtml(user.user_type||'')}</td><td>${new Date(user.created_at).toLocaleDateString()}</td></tr>`).join(''); }
    function renderRecentRides(rides){ const body=document.getElementById('recent-rides-body'); if(!rides.length){body.innerHTML='<tr><td colspan="3" class="text-muted">No rides yet.</td></tr>';return;} body.innerHTML=rides.slice(0,6).map(ride=>`<tr><td>#${ride.id}</td><td>${escapeHtml(ride.destination||'N/A')}</td><td><span class="badge bg-secondary">${escapeHtml(ride.status||'unknown')}</span></td></tr>`).join(''); }
    function renderDrivers(drivers){ const body=document.getElementById('drivers-body'); if(!drivers.length){body.innerHTML='<tr><td colspan="5" class="text-muted">No drivers available.</td></tr>';return;} body.innerHTML=drivers.map(driver=>`<tr><td>${escapeHtml(driver.fullname||'Unknown')}</td><td>${escapeHtml(driver.email||'N/A')}</td><td>${parseFloat(driver.rating||0).toFixed(2)}</td><td>${driver.is_suspended?'<span class="badge bg-danger">Suspended</span>':(driver.is_verified?'<span class="badge bg-success">Verified</span>':'<span class="badge bg-warning text-dark">Pending</span>')}</td><td>${driver.is_verified?'':`<button class="btn btn-sm btn-outline-primary me-2" onclick="approveDriver(${driver.id})">Approve</button>`}<button class="btn btn-sm btn-outline-${driver.is_suspended?'success':'danger'}" onclick="toggleUserAccess(${driver.id}, '${driver.is_suspended?'unsuspend_user':'suspend_user'}')">${driver.is_suspended?'Unsuspend':'Suspend'}</button></td></tr>`).join(''); }
    function renderPassengers(passengers){ const body=document.getElementById('passengers-body'); if(!passengers.length){body.innerHTML='<tr><td colspan="5" class="text-muted">No passengers available.</td></tr>';return;} body.innerHTML=passengers.map(passenger=>`<tr><td>${escapeHtml(passenger.fullname||'Unknown')}</td><td>${escapeHtml(passenger.email||'N/A')}</td><td>${parseFloat(passenger.rating||0).toFixed(2)}</td><td>${passenger.is_suspended?'<span class="badge bg-danger">Suspended</span>':'<span class="badge bg-success">Active</span>'}</td><td><button class="btn btn-sm btn-outline-${passenger.is_suspended?'success':'danger'}" onclick="toggleUserAccess(${passenger.id}, '${passenger.is_suspended?'unsuspend_user':'suspend_user'}')">${passenger.is_suspended?'Unsuspend':'Suspend'}</button></td></tr>`).join(''); }
    function renderRides(rides){ const body=document.getElementById('rides-body'); if(!rides.length){body.innerHTML='<tr><td colspan="6" class="text-muted">No ride sessions yet.</td></tr>';return;} body.innerHTML=rides.map(ride=>`<tr><td>${escapeHtml(ride.destination||'N/A')}</td><td>${escapeHtml(ride.driver_name||'N/A')}</td><td>${ride.departure_date||'N/A'}</td><td>${ride.available_seats||0}/${ride.total_seats||0}</td><td><span class="badge bg-secondary">${escapeHtml(ride.status||'unknown')}</span></td><td><button class="btn btn-sm btn-outline-warning me-2" onclick="cancelRide(${ride.id})">Cancel</button><button class="btn btn-sm btn-outline-danger" onclick="removeRide(${ride.id})">Remove</button></td></tr>`).join(''); }
    function renderBookings(bookings){ const body=document.getElementById('bookings-body'); if(!bookings.length){body.innerHTML='<tr><td colspan="5" class="text-muted">No bookings yet.</td></tr>';return;} body.innerHTML=bookings.map(booking=>`<tr><td>#${booking.ride_share_id}</td><td>${escapeHtml(booking.rider_name||'N/A')}</td><td>${booking.seats_booked||1}</td><td><span class="badge bg-secondary">${escapeHtml(booking.status||'unknown')}</span></td><td><button class="btn btn-sm btn-outline-danger" onclick="cancelBooking(${booking.id})">Cancel</button></td></tr>`).join(''); }
    function renderPricing(pricing){ document.getElementById('price-per-km').value = pricing.price_per_km || 0; document.getElementById('platform-commission').value = pricing.platform_commission || 0; document.getElementById('service-charge').value = pricing.service_charge || 0; }
    function renderSettings(commission){ document.getElementById('commission-input').value = commission; }
    function renderMap(users){ layerGroup.clearLayers(); const bounds=[]; users.forEach(user=>{ if(user.latitude && user.longitude){ const latlng=[parseFloat(user.latitude),parseFloat(user.longitude)]; bounds.push(latlng); const icon=L.divIcon({html:`<div style="background:${user.user_type==='driver'?'#f59e0b':'#2563eb'};width:14px;height:14px;border-radius:50%;border:2px solid white;"></div>`,className:''}); const marker=L.marker(latlng,{icon}); marker.bindPopup(`<b>${escapeHtml(user.fullname||'User')}</b><br>${escapeHtml(user.user_type||'')}`); layerGroup.addLayer(marker); } }); if(bounds.length){ map.fitBounds(bounds,{padding:[40,40]}); } }
    async function approveDriver(userId){ const res=await sendAction({action:'approve_driver',user_id:userId}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to approve driver'); }
    async function toggleUserAccess(userId, action){ const res=await sendAction({action,user_id:userId,reason:'Administrative action'}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to update user access'); }
    async function cancelRide(rideId){ const res=await sendAction({action:'cancel_ride_share',ride_id:rideId}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to cancel ride'); }
    async function removeRide(rideId){ const res=await sendAction({action:'remove_ride_share',ride_id:rideId}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to remove ride'); }
    async function cancelBooking(bookingId){ const res=await sendAction({action:'cancel_booking',booking_id:bookingId}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to cancel booking'); }
    async function sendAction(payload){ const res=await fetch('../api/admin-api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}); return res.json(); }
    async function handleSettingsSubmit(event){ event.preventDefault(); const alertBox=document.getElementById('settings-alert'); const res=await sendAction({action:'update_settings',commission_percentage:document.getElementById('commission-input').value}); alertBox.className='alert '+(res.status==='success'?'alert-success':'alert-danger'); alertBox.textContent=res.message||'Settings saved'; alertBox.classList.remove('d-none'); if(res.status==='success') loadDashboardData(); }
    async function handlePricingSubmit(event){ event.preventDefault(); const res=await sendAction({ action:'update_pricing_settings', price_per_km:document.getElementById('price-per-km').value, platform_commission:document.getElementById('platform-commission').value, service_charge:document.getElementById('service-charge').value }); if(res.status==='success') alert('Pricing updated'); else alert(res.message||'Failed'); }
    async function handleAnnouncementSubmit(event){ event.preventDefault(); const res=await sendAction({ action:'send_announcement', target:document.getElementById('announcement-target').value, message:document.getElementById('announcement-message').value }); if(res.status==='success'){ alert('Announcement sent'); document.getElementById('announcement-form').reset(); } else { alert(res.message||'Unable to send announcement'); } }
    async function logout(){ const formData=new FormData(); formData.append('action','logout'); const res=await fetch('../api/auth.php',{method:'POST',body:formData}); const data=await res.json(); if(data.status==='success') window.location.href=data.data.redirect||'../index.php'; }
    function escapeHtml(value){ return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char])); }
</script>
</body>
</html>
'''
Path('admin/dashboard.php').write_text(content, encoding='utf-8')
print('wrote admin/dashboard.php')
