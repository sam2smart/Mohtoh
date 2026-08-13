// js/app.js

let watchId = null;
let isOnline = false;
let userType = ''; // This remains useful for context

function startLocationTracking() {
    if (!navigator.geolocation) {
        console.error("Geolocation is not supported by your browser");
        return;
    }

    watchId = navigator.geolocation.watchPosition(
        async (position) => {
            const { latitude, longitude } = position.coords;
            
            if (window.currentUserType === 'driver' && typeof updateMapCenter === 'function' && typeof map !== 'undefined' && map) {
                updateMapCenter(latitude, longitude);
            }

            try {
                await fetch('../api/update-location.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ latitude, longitude })
                });
            } catch (e) {
                console.error("Failed to update location:", e);
            }
        },
        (error) => {
            console.error("Error watching position:", error);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}

// Chat details
let activeChatPartnerId = null;

// Voice call variables
let activeCallPartnerId = null;
let isCallActive = false;
let localStream = null;
let isMuted = false;

function initAddressAutocomplete(inputEl, latInput, lngInput) {
    if (!inputEl) return;

    const escapeHtml = (value = '') => String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char] || char));
    const wrapper = document.createElement('div');
    wrapper.className = 'location-autocomplete-wrapper';
    inputEl.parentNode.insertBefore(wrapper, inputEl);
    wrapper.appendChild(inputEl);

    const suggestionList = document.createElement('div');
    suggestionList.className = 'list-group location-autocomplete-list d-none';
    wrapper.appendChild(suggestionList);

    let debounceTimer = null;

    const hideSuggestions = () => {
        suggestionList.innerHTML = '';
        suggestionList.classList.add('d-none');
    };

    const showSuggestions = () => {
        suggestionList.classList.remove('d-none');
    };

    const selectSuggestion = (address, lat, lng) => {
        inputEl.value = address;
        if (latInput) latInput.value = lat;
        if (lngInput) lngInput.value = lng;
        hideSuggestions();
    };

    const fetchSuggestions = async (query) => {
        const cleanedQuery = query.trim();
        if (cleanedQuery.length < 2) {
            hideSuggestions();
            return;
        }

        try {
            const response = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&addressdetails=1&countrycodes=ng&q=${encodeURIComponent(cleanedQuery)}`, {
                headers: { 'Accept': 'application/json' }
            });
            const results = await response.json();

            if (!results.length) {
                suggestionList.innerHTML = '<div class="location-autocomplete-item text-muted">No matching addresses found.</div>';
                showSuggestions();
                return;
            }

            suggestionList.innerHTML = results.map((result) => `
                <button type="button" class="list-group-item list-group-item-action location-autocomplete-item" data-address="${escapeHtml(result.display_name || '').replace(/"/g, '&quot;')}" data-lat="${result.lat}" data-lng="${result.lon}">
                    <div class="fw-semibold">${escapeHtml(result.display_name || 'Address')}</div>
                </button>
            `).join('');
            showSuggestions();
        } catch (error) {
            console.error('Unable to load location suggestions:', error);
            hideSuggestions();
        }
    };

    inputEl.addEventListener('input', () => {
        const query = inputEl.value;
        if (debounceTimer) clearTimeout(debounceTimer);
        if (!query.trim()) {
            hideSuggestions();
            return;
        }

        debounceTimer = setTimeout(() => fetchSuggestions(query), 250);
    });

    inputEl.addEventListener('focus', () => {
        if (inputEl.value.trim()) {
            fetchSuggestions(inputEl.value);
        }
    });

    inputEl.addEventListener('blur', () => {
        setTimeout(hideSuggestions, 150);
    });

    suggestionList.addEventListener('mousedown', (event) => {
        const button = event.target.closest('.location-autocomplete-item');
        if (!button) return;
        event.preventDefault();
        selectSuggestion(button.dataset.address, button.dataset.lat, button.dataset.lng);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // Initialize map
    if (typeof initMap === 'function') {
        initMap();
    }

    if (document.getElementById('driverPickupLocation')) {
        initAddressAutocomplete(document.getElementById('driverPickupLocation'), document.getElementById('driverPickupLat'), document.getElementById('driverPickupLng'));
    }
    if (document.getElementById('driverDestinationLocation')) {
        initAddressAutocomplete(document.getElementById('driverDestinationLocation'), document.getElementById('driverDestinationLat'), document.getElementById('driverDestinationLng'));
    }
    if (document.getElementById('pickupLocation')) {
        initAddressAutocomplete(document.getElementById('pickupLocation'), document.getElementById('pickupLatitude'), document.getElementById('pickupLongitude'));
    }
    if (document.getElementById('destinationLocation')) {
        initAddressAutocomplete(document.getElementById('destinationLocation'), document.getElementById('destinationLatitude'), document.getElementById('destinationLongitude'));
    }
    if (document.getElementById('inputDestination')) {
        initAddressAutocomplete(document.getElementById('inputDestination'), document.getElementById('inputDestLat'), document.getElementById('inputDestLng'));
    }

    // Driver specifications form submit listener
    const specsForm = document.getElementById('vehicleSpecsForm');
    if (specsForm) {
        specsForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const alertBox = document.getElementById('specsAlert');
            alertBox.className = 'alert d-none';
            
            const formData = {};
            new FormData(specsForm).forEach((val, key) => {
                formData[key] = val;
            });
            formData['action'] = 'setup_vehicle';

            try {
                const res = await fetch('../api/driver-api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alertBox.className = 'alert alert-success small py-2';
                    alertBox.textContent = data.message;
                    checkVehicleVerification();
                    setTimeout(() => {
                        alertBox.classList.add('d-none');
                        const photosTab = document.getElementById('photos-tab');
                        if (photosTab) photosTab.click();
                    }, 1500);
                } else {
                    alertBox.className = 'alert alert-danger small py-2';
                    alertBox.textContent = data.message;
                }
            } catch (err) {
                alertBox.className = 'alert alert-danger small py-2';
                alertBox.textContent = 'An error occurred. Please try again.';
            }
        });
    }

    // Driver photo upload form submit listener
    const photoForm = document.getElementById('vehiclePhotoForm');
    if (photoForm) {
        photoForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const alertBox = document.getElementById('photosAlert');
            alertBox.className = 'alert d-none';
            
            const formData = new FormData(photoForm);

            try {
                const res = await fetch('../api/driver-api.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alertBox.className = 'alert alert-success small py-2';
                    alertBox.textContent = data.message;
                    photoForm.reset();
                    loadVehicleDetails();
                } else {
                    alertBox.className = 'alert alert-danger small py-2';
                    alertBox.textContent = data.message;
                }
            } catch (err) {
                alertBox.className = 'alert alert-danger small py-2';
                alertBox.textContent = 'Failed to upload photo.';
            }
        });
    }

    // Driver verification docs form submit listener
    const docForm = document.getElementById('vehicleDocForm');
    if (docForm) {
        docForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const alertBox = document.getElementById('docsAlert');
            alertBox.className = 'alert d-none';
            
            const formData = new FormData(docForm);

            try {
                const res = await fetch('../api/driver-api.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alertBox.className = 'alert alert-success small py-2';
                    alertBox.textContent = data.message;
                    docForm.reset();
                    loadVehicleDetails();
                    checkVehicleVerification();
                } else {
                    alertBox.className = 'alert alert-danger small py-2';
                    alertBox.textContent = data.message;
                }
            } catch (err) {
                alertBox.className = 'alert alert-danger small py-2';
                alertBox.textContent = 'Failed to submit document.';
            }
        });
    }
});


/* ==========================================
   DRIVER-SIDE BIDDING FLOW
   ========================================== */

// Driver: Read nearby open broadcast requests and active bookings
async function pollDriverRequests() {
    // This function is now obsolete for the carpool model.
}


/* ==========================================
   DIRECT MESSAGING LIVE CHAT SYSTEM
   ========================================== */

function toggleChat(show, partnerId = null, partnerName = '') {
    const panel = document.getElementById('chatSlidingPanel');
    if (!panel) return;

    if (show) {
        activeChatPartnerId = partnerId;
        document.getElementById('chatTitle').innerHTML = `<i class="fas fa-comments me-2"></i>Chat: ${partnerName}`;
        panel.classList.remove('d-none');
        loadChatHistory(partnerId);
    } else {
        panel.classList.add('d-none');
        activeChatPartnerId = null;
    }
}

async function loadChatHistory(partnerId) {
    try {
        const res = await fetch(`../api/chat.php?other_user_id=${partnerId}`);
        const data = await res.json();
        
        if (data.status === 'success') {
            const messages = Array.isArray(data.data) ? data.data : [];
            const chatBody = document.getElementById('chatBody');
            if (!chatBody) return;
            let html = '';

            // Filter out system call messages so they don't clog the chat logs
            const filteredMessages = messages.filter(m => !m.message.startsWith('__CALL_'));

            filteredMessages.forEach(msg => {
                const isSent = parseInt(msg.sender_id) !== parseInt(partnerId);
                const bubbleClass = isSent ? 'sent' : 'received';
                html += `
                    <div class="chat-bubble ${bubbleClass}">
                        ${msg.message}
                    </div>
                `;
            });

            const scrolledToBottom = chatBody.scrollHeight - chatBody.clientHeight <= chatBody.scrollTop + 50;
            chatBody.innerHTML = html;
            
            // Auto scroll to bottom on new messages
            if (scrolledToBottom || chatBody.scrollTop === 0) {
                chatBody.scrollTop = chatBody.scrollHeight;
            }
        }
    } catch (e) {
        console.error("Chat loading error:", e);
    }
}

async function sendChatMessage() {
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    if (!message || !activeChatPartnerId) return;

    try {
        const res = await fetch('../api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ receiver_id: activeChatPartnerId, message })
        });
        const data = await res.json();
        if (data.status === 'success') {
            input.value = '';
            loadChatHistory(activeChatPartnerId);
        }
    } catch (e) {
        console.error("Failed to send message:", e);
    }
}


/* ==========================================
   IN-APP WEBRTC CALL NEGOTIATION SIGNALING
   ========================================== */

// 1. Initiate an outgoing call (sends __CALL_INIT__ system signal)
async function startVoiceCall(partnerId, partnerName) {
    if (isCallActive) return;

    const callUserName = document.getElementById('callUserName');
    const callStateLabel = document.getElementById('callStateLabel');
    const answerBtn = document.getElementById('btnCallAnswer');
    const overlay = document.getElementById('voiceCallOverlay');
    if (!callUserName || !callStateLabel || !answerBtn || !overlay) return;

    activeCallPartnerId = partnerId;
    callUserName.textContent = partnerName;
    callStateLabel.textContent = 'Calling Driver...';
    answerBtn.classList.add('d-none');
    overlay.classList.remove('d-none');
    
    isCallActive = true;

    // Send call initialization signal through the chat DB channel
    try {
        await fetch('../api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ receiver_id: partnerId, message: '__CALL_INIT__' })
        });
    } catch (e) {
        console.error("Call signaling error:", e);
    }
}

// 2. Poll call signals from database logs
async function pollCallSignaling(partnerId) {
    try {
        const res = await fetch(`../api/chat.php?other_user_id=${partnerId}`);
        const data = await res.json();
        
        if (data.status === 'success') {
            const messages = data.data;
            if (messages.length === 0) return;

            // Get last call signal
            const lastSignal = messages.filter(m => m.message.startsWith('__CALL_')).pop();
            if (!lastSignal) return;

            // Check if signal sender is NOT me (incoming action)
            const isIncoming = parseInt(lastSignal.sender_id) === parseInt(partnerId);

            if (lastSignal.message === '__CALL_INIT__' && isIncoming && !isCallActive) {
                // Incoming Call detected!
                const callUserName = document.getElementById('callUserName');
                const callStateLabel = document.getElementById('callStateLabel');
                const answerBtn = document.getElementById('btnCallAnswer');
                const overlay = document.getElementById('voiceCallOverlay');
                if (callUserName && callStateLabel && answerBtn && overlay) {
                    activeCallPartnerId = partnerId;
                    callUserName.textContent = userType === 'rider' ? 'Driver Calling' : 'Passenger Calling';
                    callStateLabel.textContent = 'Incoming Voice Call...';
                    answerBtn.classList.remove('d-none');
                    overlay.classList.remove('d-none');
                    isCallActive = true;
                }
            } else if (lastSignal.message === '__CALL_ANSWER__' && !isIncoming && isCallActive) {
                // Outgoing call answered!
                connectAudioWaves();
            } else if (lastSignal.message === '__CALL_HANGUP__' && isIncoming && isCallActive) {
                // Hangup signal received
                terminateCallUI();
            }
        }
    } catch (e) {
        console.error("Call polling signal error:", e);
    }
}

// 3. Answer incoming call (sends __CALL_ANSWER__ signal)
async function answerCall() {
    if (!activeCallPartnerId) return;

    try {
        await fetch('../api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ receiver_id: activeCallPartnerId, message: '__CALL_ANSWER__' })
        });
        
        document.getElementById('btnCallAnswer').classList.add('d-none');
        connectAudioWaves();
    } catch (e) {
        console.error("Call answer signaling error:", e);
    }
}

// 4. Hangup active/outgoing call (sends __CALL_HANGUP__ signal)
async function hangupCall() {
    if (!activeCallPartnerId) return;

    try {
        await fetch('../api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ receiver_id: activeCallPartnerId, message: '__CALL_HANGUP__' })
        });
    } catch (e) {}

    terminateCallUI();
}

// 5. Connect voice stream and activate talking waveforms
function connectAudioWaves() {
    const callStateLabel = document.getElementById('callStateLabel');
    const audioWaves = document.getElementById('callAudioWaves');
    if (callStateLabel) callStateLabel.textContent = 'Connected (0:01)';
    if (audioWaves) audioWaves.classList.remove('d-none');

    // Simulate duration counter
    let seconds = 1;
    if (window.callTimer) clearInterval(window.callTimer);
    window.callTimer = setInterval(() => {
        seconds++;
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        const callStateLabel = document.getElementById('callStateLabel');
        if (callStateLabel) callStateLabel.textContent = `Connected (${mins}:${secs < 10 ? '0' + secs : secs})`;
    }, 1000);

    // Request browser audio mic permissions for true WebRTC sandbox experience
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        navigator.mediaDevices.getUserMedia({ audio: true })
            .then(stream => {
                localStream = stream;
            })
            .catch(err => {
                console.warn("Microphone access denied. Call will operate in high-fidelity mock mode.", err);
            });
    }
}

// Mute microphone
function toggleCallMute() {
    const btn = document.getElementById('btnCallMute');
    if (!isMuted) {
        isMuted = true;
        btn.classList.add('muted');
        btn.innerHTML = '<i class="fas fa-microphone-slash"></i>';
        if (localStream) {
            localStream.getAudioTracks().forEach(track => track.enabled = false);
        }
    } else {
        isMuted = false;
        btn.classList.remove('muted');
        btn.innerHTML = '<i class="fas fa-microphone"></i>';
        if (localStream) {
            localStream.getAudioTracks().forEach(track => track.enabled = true);
        }
    }
}

// Clean calling UI and stop media channels
function terminateCallUI() {
    isCallActive = false;
    const overlay = document.getElementById('voiceCallOverlay');
    const audioWaves = document.getElementById('callAudioWaves');
    if (overlay) overlay.classList.add('d-none');
    if (audioWaves) audioWaves.classList.add('d-none');
    
    if (window.callTimer) clearInterval(window.callTimer);
    
    if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
        localStream = null;
    }
    
    activeCallPartnerId = null;
}

// Bidirectional driver inputs calculator using event delegation
document.addEventListener('input', (e) => {
    if (e.target.classList.contains('rate-per-km-input')) {
        const reqId = e.target.dataset.reqId;
        const tripDist = parseFloat(e.target.dataset.tripDist);
        const rate = parseFloat(e.target.value) || 0;
        const totalInput = document.getElementById(`counter-price-${reqId}`);
        if (totalInput && tripDist > 0) {
            totalInput.value = (rate * tripDist).toFixed(2);
        }
    } else if (e.target.classList.contains('total-price-input')) {
        const reqId = e.target.dataset.reqId;
        const tripDist = parseFloat(e.target.dataset.tripDist);
        const total = parseFloat(e.target.value) || 0;
        const rateInput = document.getElementById(`rate-per-km-${reqId}`);
        if (rateInput && tripDist > 0) {
            rateInput.value = (total / tripDist).toFixed(2);
        }
    }
});

// Driver Vehicle verification system
let vehicleModal = null;

function openVehicleModal() {
    const modalEl = document.getElementById('vehicleSetupModal');
    if (!modalEl) return;
    if (!vehicleModal) {
        vehicleModal = new bootstrap.Modal(modalEl);
    }
    loadVehicleDetails();
    vehicleModal.show();
}
