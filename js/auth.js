// js/auth.js

document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');

    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(loginForm);
            formData.append('action', 'login');
            
            const submitBtn = loginForm.querySelector('button[type="submit"]');
            await handleAuthRequest(formData, 'loginAlert', submitBtn, 'Log In');
        });
    }

    if (registerForm) {
        registerForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            // Client-side Validation
            const fullname = registerForm.fullname.value.trim();
            const phone = registerForm.phone.value.trim();
            const email = registerForm.email.value.trim();
            const password = registerForm.password.value;
            const alertBox = document.getElementById('registerAlert');

            // 1. Full name validation (min 3 chars)
            if (fullname.length < 3) {
                showLocalError(alertBox, 'Full name must be at least 3 characters.');
                return;
            }
            if (!/^[a-zA-Z\s]+$/.test(fullname)) {
                showLocalError(alertBox, 'Full name must contain only letters and spaces.');
                return;
            }

            // 2. Phone validation (only digits, 7-15 chars)
            if (!/^\d{7,15}$/.test(phone)) {
                showLocalError(alertBox, 'Phone number must contain between 7 and 15 digits.');
                return;
            }

            // 3. Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showLocalError(alertBox, 'Please enter a valid email address.');
                return;
            }

            // 4. Password validation (min 6 chars)
            if (password.length < 6) {
                showLocalError(alertBox, 'Password must be at least 6 characters.');
                return;
            }

            const formData = new FormData(registerForm);
            formData.append('action', 'register');
            
            const submitBtn = registerForm.querySelector('button[type="submit"]');
            await handleAuthRequest(formData, 'registerAlert', submitBtn, 'Sign Up');
        });
    }
});

function showLocalError(alertBox, message) {
    alertBox.classList.remove('d-none', 'alert-success');
    alertBox.classList.add('alert-danger');
    alertBox.textContent = message;
    alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function handleAuthRequest(formData, alertId, submitBtn, originalText) {
    const alertBox = document.getElementById(alertId);
    alertBox.classList.add('d-none');
    alertBox.textContent = '';

    // Disable button & show spinner/status
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processing...`;
    }

    try {
        const response = await fetch('api/auth.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.status === 'success') {
            alertBox.classList.remove('d-none', 'alert-danger');
            alertBox.classList.add('alert-success');
            alertBox.textContent = result.message;
            
            setTimeout(() => {
                window.location.href = result.data.redirect;
            }, 1000);
        } else {
            alertBox.classList.remove('d-none', 'alert-success');
            alertBox.classList.add('alert-danger');
            alertBox.textContent = result.message;
            
            // Re-enable button
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        }
    } catch (error) {
        alertBox.classList.remove('d-none', 'alert-success');
        alertBox.classList.add('alert-danger');
        alertBox.textContent = 'An error occurred. Please try again.';
        console.error('Auth error:', error);
        
        // Re-enable button
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    }
}
