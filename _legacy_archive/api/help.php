<?php
session_start();
require_once 'includes/functions.php';

$user_type = $_SESSION['user_type'] ?? 'guest';
$dashboard_link = $user_type === 'driver' ? 'driver/dashboard.php' : 'rider/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help & Support - Merit Drives</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-moh py-3">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center" href="<?= is_logged_in() ? $dashboard_link : 'index.php' ?>">
            <i class="fas fa-car-side me-2"></i>Merit Drives
        </a>
        <?php if (is_logged_in()): ?>
        <a href="<?= $dashboard_link ?>" class="btn btn-outline-light btn-sm">Back to Dashboard</a>
        <?php endif; ?>
    </div>
</nav>

<div class="container py-5">
    <div class="text-center mb-5">
        <h1 class="fw-bold">Help & Support</h1>
        <p class="text-muted fs-5">Find answers to common questions and get in touch with us.</p>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="content-card p-4 mb-4">
                <h4 class="fw-bold mb-3">Frequently Asked Questions</h4>
                <div class="accordion" id="faqAccordion">
                    <!-- Rider FAQs -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingOne">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                How do I book a ride?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                On your dashboard, use the "Find a Ride" form to enter your pickup location, destination, and travel date. Browse the available rides and click "View & Book" to see details and book your seat.
                            </div>
                        </div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingTwo">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                How does the wallet system work?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                You must have sufficient funds in your wallet to book a ride. The ride fare is deducted from your balance upon booking. If you cancel a ride, the amount is refunded to your wallet. You can add funds from the "Wallet" page.
                            </div>
                        </div>
                    </div>

                    <!-- Driver FAQs -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingThree">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                How do I create a ride for passengers to join?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                From your driver dashboard, click the "Create Ride" button. Fill in your trip details, including route, date, time, and available seats. Once created, your ride will be visible to passengers searching for that route.
                            </div>
                        </div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingFour">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                When do I get paid for a ride?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                After you successfully complete a ride, the total earnings from all passengers on that trip are automatically credited to your wallet. You can view your earnings summary and history on the "Earnings" page.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-card p-4 text-center">
                <h4 class="fw-bold">Still need help?</h4>
                <p class="text-muted">Our support team is here for you.</p>
                <a href="mailto:support@meritdrives.com" class="btn btn-primary">
                    <i class="fas fa-envelope me-2"></i>Contact Support
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>