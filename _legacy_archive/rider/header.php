<?php
// rider/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';

require_login();

if ($_SESSION['user_type'] !== 'rider') {
    header("Location: ../driver/dashboard.php");
    exit;
}

$active_page = basename($_SERVER['PHP_SELF'], ".php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Dashboard') ?> - Merit Drives</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php if (isset($extra_css) && is_array($extra_css)): ?>
        <?php foreach ($extra_css as $css_file): ?>
            <link rel="stylesheet" href="<?= htmlspecialchars($css_file) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <link rel="stylesheet" href="../css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

<div class="d-flex">
    <aside class="sidebar p-3">
        <div class="sidebar-header mb-4">
            <a class="navbar-brand fw-bold d-flex align-items-center" href="dashboard.php">
                <i class="fas fa-taxi me-2"></i>Merit Drives
            </a>
            <small class="sidebar-subtitle">Rider Console</small>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link <?= $active_page == 'dashboard' ? 'active' : '' ?>" href="dashboard.php"><i class="fas fa-home fa-fw me-2"></i><span>Home</span></a>
            <a class="nav-link <?= $active_page == 'my-trips' ? 'active' : '' ?>" href="my-trips.php"><i class="fas fa-route fa-fw me-2"></i><span>My Trips</span></a>
            <a class="nav-link <?= $active_page == 'wallet' ? 'active' : '' ?>" href="wallet.php"><i class="fas fa-wallet fa-fw me-2"></i><span>Wallet</span></a>
            <a class="nav-link <?= $active_page == 'notifications' ? 'active' : '' ?>" href="notifications.php"><i class="fas fa-bell fa-fw me-2"></i><span>Notifications</span></a>
            <a class="nav-link <?= $active_page == 'profile' ? 'active' : '' ?>" href="profile.php"><i class="fas fa-user-circle fa-fw me-2"></i><span>Profile</span></a>
            <a class="nav-link <?= $active_page == 'settings' ? 'active' : '' ?>" href="settings.php"><i class="fas fa-cog fa-fw me-2"></i><span>Settings</span></a>
        </nav>
        <div class="sidebar-footer mt-auto">
            <a class="nav-link" href="../help.php"><i class="fas fa-question-circle fa-fw me-2"></i><span>Help & Support</span></a>
            <a class="nav-link" href="#"><i class="fas fa-user-plus fa-fw me-2"></i><span>Grow My Community</span></a>
            <a class="nav-link" href="#" onclick="logout()"><i class="fas fa-sign-out-alt fa-fw me-2"></i><span>Logout</span></a>
        </div>
    </aside>

    <main class="main-area flex-grow-1">
        <header class="topbar px-4 py-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <button class="btn btn-light btn-sm me-3" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <h5 class="fw-bold mb-0"><?= htmlspecialchars($page_title ?? 'Dashboard') ?></h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="dropdown">
                    <button class="btn btn-light btn-sm dropdown-toggle rounded-pill" type="button" id="userMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?= htmlspecialchars($_SESSION['fullname']) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-circle fa-fw me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="wallet.php"><i class="fas fa-wallet fa-fw me-2"></i>Wallet</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><button class="dropdown-item" type="button" onclick="logout()"><i class="fas fa-sign-out-alt me-2"></i>Log Out</button></li>
                    </ul>
                </div>
                <div class="dropdown">
                    <button class="btn btn-light btn-sm rounded-circle position-relative" type="button" id="notificationMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-bell"></i>
                        <span id="notification-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none"></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationMenu" id="notification-list">
                        <li><a class="dropdown-item text-muted text-center small" href="#">No new notifications</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <div class="p-4">