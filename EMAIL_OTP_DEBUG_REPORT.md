# Email OTP Debug Report

## Progress
- [x] 1. Event fired
- [x] 2. Request sent
- [x] 3. Endpoint reached
- [x] 4. User authenticated
- [x] 5. OTP generated
- [x] 6. OTP saved
- [x] 7. Template generated
- [x] 8. send() called
- [x] 9. Result of send() - Failed: SMTP Error: Could not connect to SMTP host
- [ ] 10. JSON response
- [ ] 11. Frontend response handling
- [ ] 12. Root Cause
- [ ] 13. Final Proof

## Logs
*   Triggered `send_email_verification`.
*   Found user.
*   Generated token.
*   Updated database.
*   PHPMailer `send()` called.
*   `PHPMailer` returned: `SMTP Error: Could not connect to SMTP host. SMTP server error: QUIT command failed`
