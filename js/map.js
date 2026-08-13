// js/map.js
let map = null;
let userMarker = null;
let destinationMarker = null;
let driverMarker = null;
let routeLine = null;
let markersLayer = null;

// Initialize the map (using Leaflet.js as a keyless, robust, and highly custom tile provider)
function initMap() {
    const mapElement = document.getElementById('map');
    if (!mapElement) return;

    // Center on Lagos, Nigeria by default
    map = L.map('map', {
        zoomControl: true,
        attributionControl: false
    }).setView([6.5244, 3.3792], 13);

    // Modern styled high-contrast OSM tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19
    }).addTo(map);

    markersLayer = L.layerGroup().addTo(map);
}

// Update user's own map center
function updateMapCenter(lat, lng) {
    if (!map) return;

    const latLng = [lat, lng];
    map.setView(latLng, 15);

    // Custom blue pulsating marker for "You"
    const youIcon = L.divIcon({
        className: 'pulsating-user-marker',
        html: `<div class="pulse-ring"></div><div class="user-dot bg-primary"></div>`,
        iconSize: [20, 20],
        iconAnchor: [10, 10]
    });

    if (userMarker) {
        userMarker.setLatLng(latLng);
    } else {
        userMarker = L.marker(latLng, { icon: youIcon }).addTo(map);
        userMarker.bindPopup("<b>Your Current Location</b>").openPopup();
    }
}

// Draw a beautiful route line on the map (pickup to destination) and place a checkered flag marker
function drawRideRoute(pickupLat, pickupLng, destLat, destLng, destName) {
    if (!map) return;

    // Clear previous route
    if (routeLine) {
        map.removeLayer(routeLine);
    }
    if (destinationMarker) {
        map.removeLayer(destinationMarker);
    }

    const pickup = [parseFloat(pickupLat), parseFloat(pickupLng)];
    const destination = [parseFloat(destLat), parseFloat(destLng)];

    // Draw solid purple routing line
    routeLine = L.polyline([pickup, destination], {
        color: '#6366f1',
        weight: 5,
        opacity: 0.8,
        dashArray: '10, 10',
        lineCap: 'round'
    }).addTo(map);

    // Custom checkered destination flag icon
    const destIcon = L.icon({
        iconUrl: 'https://cdn-icons-png.flaticon.com/512/9356/9356230.png',
        iconSize: [35, 35],
        iconAnchor: [5, 35]
    });

    destinationMarker = L.marker(destination, { icon: destIcon }).addTo(map);
    destinationMarker.bindPopup(`<b>Destination</b><br>${destName}`);

    // Zoom out map to fit both pickup and destination coordinates
    const bounds = L.latLngBounds([pickup, destination]);
    map.fitBounds(bounds, { padding: [60, 60] });
}

// Clear active routes and markers
function clearRoute() {
    if (routeLine && map) {
        map.removeLayer(routeLine);
        routeLine = null;
    }
    if (destinationMarker && map) {
        map.removeLayer(destinationMarker);
        destinationMarker = null;
    }
    if (driverMarker && map) {
        map.removeLayer(driverMarker);
        driverMarker = null;
    }
}

// Smoothly animate marker transitions (sliding car marker transition, like Uber)
function animateMarker(marker, targetLat, targetLng, duration = 1000) {
    const startLat = marker.getLatLng().lat;
    const startLng = marker.getLatLng().lng;
    const startTime = performance.now();

    function step(timestamp) {
        const elapsed = timestamp - startTime;
        const progress = Math.min(elapsed / duration, 1);

        // Ease in-out interpolation
        const easeProgress = progress < 0.5 
            ? 2 * progress * progress 
            : -1 + (4 - 2 * progress) * progress;

        const currentLat = startLat + (targetLat - startLat) * easeProgress;
        const currentLng = startLng + (targetLng - startLng) * easeProgress;

        marker.setLatLng([currentLat, currentLng]);

        if (progress < 1) {
            requestAnimationFrame(step);
        }
    }

    requestAnimationFrame(step);
}

// Update driver position on map with smooth car movement animations
function updateDriverMapPosition(lat, lng, driverName) {
    if (!map) return;

    const latLng = [parseFloat(lat), parseFloat(lng)];

    // Premium glowing car marker icon
    const carIcon = L.icon({
        iconUrl: 'https://cdn-icons-png.flaticon.com/512/3202/3202926.png', // Gold car icon
        iconSize: [38, 38],
        iconAnchor: [19, 19],
        popupAnchor: [0, -15]
    });

    if (driverMarker) {
        // Animate the car moving smoothly from its current position to the new position
        animateMarker(driverMarker, latLng[0], latLng[1], 2000);
    } else {
        // Create new car marker
        driverMarker = L.marker(latLng, { icon: carIcon }).addTo(map);
        driverMarker.bindPopup(`<b>${driverName} (Driver)</b><br>Currently driving`).openPopup();
    }
}

// Draw online markers for surrounding users
function updateMapMarkers(users) {
    if (!map || !markersLayer) return;

    markersLayer.clearLayers();

    const currentType = document.getElementById('toggleOnlineBtn') ? document.getElementById('toggleOnlineBtn').dataset.type : '';
    const targetType = currentType === 'rider' ? 'driver' : 'rider';

    // Icons definitions
    const driverIcon = L.icon({
        iconUrl: 'https://cdn-icons-png.flaticon.com/512/3202/3202926.png',
        iconSize: [32, 32],
        iconAnchor: [16, 16]
    });

    const riderIcon = L.icon({
        iconUrl: 'https://cdn-icons-png.flaticon.com/512/2856/2856338.png',
        iconSize: [30, 30],
        iconAnchor: [15, 15]
    });

    users.forEach(user => {
        // Avoid drawing driver/rider if they are the current user
        if (user.latitude && user.longitude) {
            const latLng = [parseFloat(user.latitude), parseFloat(user.longitude)];
            const icon = user.user_type === 'driver' ? driverIcon : riderIcon;
            
            const marker = L.marker(latLng, { icon: icon });
            
            const content = `
                <div class="text-center" style="font-family: 'Outfit', sans-serif; min-width: 100px;">
                    <img src="../assets/${user.profile_image}" class="rounded-circle mb-1" width="35" height="35" onerror="this.src='https://ui-avatars.com/api/?name=${user.fullname}'">
                    <br><b>${user.fullname}</b><br>
                    <span class="badge bg-soft-success text-success">${parseFloat(user.distance).toFixed(2)} km away</span>
                </div>
            `;
            marker.bindPopup(content);
            markersLayer.addLayer(marker);
        }
    });
}
