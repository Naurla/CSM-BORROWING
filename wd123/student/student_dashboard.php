<?php
session_start();
require_once "../classes/Transaction.php";
require_once "../classes/Database.php";

if (!isset($_SESSION["user"]) || $_SESSION["user"]["role"] != "student") {
    header("Location: ../pages/login.php");
    exit;
}

$transaction = new Transaction();
$student_id = $_SESSION["user"]["id"];

// --- BAN/LOCK STATUS CHECKS ---
$isBanned = $transaction->isStudentBanned($student_id); // Now defined in Transaction.php
$activeCount = $transaction->getActiveTransactionCount($student_id); // Now defined in Transaction.php

// --- OVERDUE WARNING LOGIC ---
// Uses BCNF-compliant getStudentActiveTransactions (returns borrow_forms data)
$transactions = $transaction->getStudentActiveTransactions($student_id);
$current_datetime = new DateTime();
$today_date_str = $current_datetime->format("Y-m-d");
$overdue_count = 0;
$critical_date_passed = false;
$next_suspension_date = null;

foreach ($transactions as &$t) {
    $expected_return_date = new DateTime($t['expected_return_date']);
    
    if (in_array(strtolower($t['status']), ['borrowed', 'approved', 'checking', 'reserved']) && $t['expected_return_date'] < $today_date_str) {
        $t['is_overdue'] = true;
        $overdue_count++;
        
        $suspension_trigger_date = clone $expected_return_date;
        $suspension_trigger_date->modify('+2 days');
        
        if (!$next_suspension_date || $suspension_trigger_date < $next_suspension_date) {
             $next_suspension_date = $suspension_trigger_date;
        }

        $grace_period_end = clone $expected_return_date;
        $grace_period_end->modify('+1 day');
        
        if ($current_datetime > $grace_period_end) {
            $critical_date_passed = true;
        }

    } else {
        $t['is_overdue'] = false;
    }
}
unset($t);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Current Activity</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --msu-red: #b8312d; 
            --msu-red-dark: #a82e2a;
            --sidebar-width: 280px; 
            --bg-light: #f5f6fa;
            --header-height: 60px; /* Define header height */
            --danger-light: #fdd;
            --danger-dark: #8b0000;
            --warning-dark: #b8860b;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg-light); 
            padding: 0;
            margin: 0;
            display: flex; 
            min-height: 100vh;
        }
        
        /* --- Top Header Bar Styles (NEW) --- */
        .top-header-bar {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            height: var(--header-height);
            background-color: #fff;
            border-bottom: 1px solid #ddd;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: flex-end; /* Push content to the right */
            padding: 0 20px;
            z-index: 1000;
        }
        .edit-profile-link {
            color: var(--msu-red);
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s;
        }
        .edit-profile-link:hover {
            color: var(--msu-red-dark);
            text-decoration: underline;
        }
        /* --- END Top Header Bar Styles --- */
        
        /* --- Sidebar Styles (Consistent Look) --- */
        .sidebar {
            width: var(--sidebar-width);
            min-width: var(--sidebar-width);
            background-color: var(--msu-red);
            color: white;
            padding: 0;
            position: fixed;
            height: 100%;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2);
            z-index: 1050;
        }
        .sidebar-header {
            text-align: center;
            padding: 20px 15px; 
            font-size: 1.2rem;
            font-weight: 700;
            line-height: 1.15; 
            color: #fff;
            border-bottom: 1px solid rgba(255, 255, 255, 0.4);
            margin-bottom: 20px;
        }
        .sidebar-header img { max-width: 90px; height: auto; margin-bottom: 15px; }
        .sidebar-header .title { font-size: 1.3rem; line-height: 1.1; }
        .sidebar .nav-link {
            color: white;
            padding: 15px 20px; 
            font-weight: 600;
            transition: background-color 0.3s;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { 
            background-color: var(--msu-red-dark); 
        }
        .sidebar .nav-link.banned { 
            background-color: #5a2624; 
            opacity: 0.8; 
            cursor: pointer; 
            pointer-events: auto; 
        }
        .sidebar .nav-link.history { 
            border-top: 1px solid rgba(255, 255, 255, 0.1); 
            margin-top: 5px; 
        }
        .logout-link { 
            margin-top: auto; 
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        .logout-link .nav-link { 
            background-color: #dc3545 !important; 
            color: white !important;
        }
        .logout-link .nav-link:hover {
            background-color: var(--msu-red-dark) !important; 
        }
        
        .main-wrapper {
            margin-left: var(--sidebar-width); 
            padding: 20px;
            padding-top: calc(var(--header-height) + 20px); /* PUSH DOWN content below header */
            flex-grow: 1;
        }
        
        /* MODIFIED: Stretched Container for Full Width */
        .container {
            background: #fff;
            border-radius: 10px;
            padding: 30px 40px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            max-width: none; 
            width: 95%; 
            margin: 0 auto; 
        }
        
        h2 { 
            border-bottom: 2px solid var(--msu-red); 
            padding-bottom: 10px; 
            font-size: 2rem;
            font-weight: 600;
        }

        /* --- MODERN CARD STYLES --- */
        .transaction-list {
            display: flex;
            flex-direction: column;
            gap: 15px; 
            padding: 0;
            list-style: none;
            margin-top: 20px;
        }
        .transaction-card {
            background-color: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05); 
            transition: transform 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .transaction-card:hover {
            transform: translateY(-2px); 
            border-color: var(--msu-red);
        }
        .card-critical {
            background-color: var(--danger-light) !important;
            border-left: 5px solid var(--danger-dark);
        }
        .alert-overdue {
            border-left: 5px solid var(--danger-dark);
            font-weight: 600;
            font-size: 1.1rem;
            background-color: #fdd; 
            color: var(--danger-dark);
        }
        .card-col-details { width: 35%; display: flex; align-items: center; }
        .card-col-dates { width: 30%; line-height: 1.5; }
        .card-col-status { width: 20%; text-align: center; }
        .card-col-action { width: 15%; text-align: right; }
        .app-image {
            width: 55px; 
            height: 55px;
            object-fit: contain;
            border-radius: 6px;
            margin-right: 15px;
            border: 1px solid #ddd;
            padding: 5px;
        }
        .trans-id-text {
            font-weight: 700;
            font-size: 1.2rem;
            color: #333;
            display: block;
        }
        .trans-type-text {
            font-size: 0.85rem;
            color: #6c757d;
            display: block;
        }
        .date-item { display: block; font-size: 0.95rem; }
        .date-label { font-weight: 600; color: #555; display: inline-block; min-width: 85px; }
        .date-value { font-weight: 500; }
        .date-col .expected-date { color: var(--warning-dark); font-weight: 700; }
        .date-col .expected-date.overdue { color: var(--danger-dark); } 
        .date-col .actual-date { color: #198754; font-weight: 700; }
        .date-col .borrow { color: #333; font-weight: 500;}
        .status {
            display: inline-block;
            padding: 8px 15px; 
            border-radius: 20px;
            font-weight: 700;
            text-transform: uppercase; 
            font-size: 0.8rem; 
        }
        .status.waiting_for_approval { background-color: #ffc107; color: #333; font-weight: 600; border: 1px solid #ffc107; } 
        .status.approved { background-color: #28a745; color: white; }
        .status.borrowed { background-color: #007bff; color: white; }
        .status.returned { background-color: #6c757d; color: white; }
        .status.overdue, .status.damaged { 
            background-color: var(--danger-dark); 
            color: white; 
        }
        .status.checking { 
            background-color: #ff8c00; /* Deep Orange */
            color: white; 
            border: 1px solid #ff7f50; /* Complementary Border */
        }
        .btn-view-items {
            background: var(--msu-red); 
            color: white;
            padding: 10px 20px; 
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
        }
        .btn-view-items:hover { background: var(--msu-red-dark); color: white; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo"> 
        <div class="title">
            CSM LABORATORY <br>APPARATUS <br>BORROWING
        </div>
    </div>

    <ul class="nav flex-column mb-4 sidebar-nav">
        <li class="nav-item">
            <a href="student_dashboard.php" class="nav-link active">
                <i class="fas fa-clock fa-fw me-2"></i> Current Activity
            </a>
        </li>
        <li class="nav-item">
            <a href="student_borrow.php" class="nav-link <?= $isBanned ? 'banned' : '' ?>">
                <i class="fas fa-plus-circle fa-fw me-2"></i> Borrow/Reserve <?= $isBanned ? ' (BANNED)' : '' ?>
            </a>
        </li>
        <li class="nav-item">
            <a href="student_return.php" class="nav-link">
                <i class="fas fa-undo-alt fa-fw me-2"></i> Initiate Return
            </a>
        </li>
        <li class="nav-item">
            <a href="student_transaction.php" class="nav-link">
                <i class="fas fa-history fa-fw me-2"></i> Transaction History
            </a>
        </li>
    </ul>

    <div class="logout-link">
        <a href="../pages/logout.php" class="nav-link">
            <i class="fas fa-sign-out-alt fa-fw me-2"></i> Logout
        </a>
    </div>
</div>

<header class="top-header-bar">
    <a href="student_edit.php" class="edit-profile-link">
        </a>
</header>
<div class="main-wrapper">
    <div class="container">
        <h2 class="mb-4"><i class="fas fa-clock me-2 text-secondary"></i> Current & Pending Activity</h2>
        <p class="lead text-start">Welcome, **<?= htmlspecialchars($_SESSION["user"]["firstname"]) ?>**! Below are your active, pending, or overdue
            transactions requiring attention.</p>
        
        <?php if ($overdue_count > 0): ?>
            <div class="alert alert-overdue mt-4" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i> 
                **URGENT:** You have **<?= $overdue_count ?>** item(s) past the expected return date. Please **Initiate Return** immediately!
                <?php if ($critical_date_passed): ?>
                    <span class="d-block mt-2">
                        Your account is currently eligible for suspension. Please contact staff immediately.
                    </span>
                <? elseif ($next_suspension_date): ?>
                    <span class="d-block mt-2">
                        If not returned by **<?= $next_suspension_date->format('Y-m-d') ?>**, your borrowing privileges may be suspended.
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($transactions)): ?>
            <div class="alert alert-info text-center mt-4">
                <i class="fas fa-info-circle me-2"></i> You have no current or pending transactions. Ready to place a new request?
            </div>
        <?php else: ?>
            <div class="transaction-list">
                <?php
                foreach ($transactions as $t):
                    $status_class = strtolower($t['status']);
                    $is_overdue = $t['is_overdue'] ?? false;
                    
                    // Critical class applied if overdue or damaged, but NOT for 'checking'
                    $card_class = (in_array($status_class, ['damaged']) || $is_overdue) ? 'card-critical' : '';
                    
                    // FIX: Use BCNF compliant getFormApparatus for image data. This returns a grouped array.
                    $apparatusList = $transaction->getFormApparatus($t["id"]); 
                    $firstApparatus = $apparatusList[0] ?? null;
                    
                    // Image logic uses the first item in the list
                    $imageFile = $firstApparatus["image"] ?? "default.jpg";
                    $imagePath = "../uploads/apparatus_images/" . $imageFile;
                    if (!file_exists($imagePath) || is_dir($imagePath)) {
                        $imagePath = "../uploads/apparatus_images/default.jpg";
                    }
                    
                    // Determine status display text and class
                    $display_status_class = $is_overdue ? 'overdue' : $status_class;
                    $display_status_text = $is_overdue ? 'OVERDUE' : str_replace('_', ' ', $t["status"]);
                ?>
                    <div class="transaction-card <?= $card_class ?>">
                        
                        <div class="card-col-details">
                            <img src="<?= $imagePath ?>" 
                                alt="Apparatus Image"
                                title="<?= htmlspecialchars($firstApparatus["name"] ?? 'N/A') ?>"
                                class="app-image">
                            <div>
                                <span class="trans-id-text">ID: <?= htmlspecialchars($t["id"]) ?></span>
                                <span class="trans-type-text"><?= htmlspecialchars(ucfirst($t["form_type"])) ?> Request</span>
                            </div>
                        </div>

                        <div class="card-col-dates">
                            <span class="date-item"><span class="date-label"><i class="fas fa-calendar-alt fa-fw me-1 text-secondary"></i> Borrow:</span> <span class="date-value"><?= htmlspecialchars($t["borrow_date"]) ?></span></span>
                            <span class="date-item expected-date <?= $is_overdue ? 'overdue' : '' ?>"><span class="date-label"><i class="fas fa-exclamation-triangle fa-fw me-1"></i> Expected:</span> <span class="date-value"><?= htmlspecialchars($t["expected_return_date"]) ?></span></span>
                        </div>

                        <div class="card-col-status">
                            <span class="status <?= $display_status_class ?>">
                                <?= htmlspecialchars(ucfirst($display_status_text)) ?>
                            </span>
                            <?php if ($t['staff_remarks']): ?>
                                <span class="text-muted small d-block mt-1" title="Staff Remark"><?= htmlspecialchars(substr($t['staff_remarks'], 0, 30)) . (strlen($t['staff_remarks']) > 30 ? '...' : '') ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="card-col-action">
                            <a href="student_view_items.php?form_id=<?= $t["id"] ?>&context=dashboard" class="btn-view-items">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Script to correctly set the active link in the sidebar menu
    document.addEventListener('DOMContentLoaded', () => {
        const path = window.location.pathname.split('/').pop();
        const links = document.querySelectorAll('.sidebar .nav-link');
        links.forEach(link => {
            const linkPath = link.getAttribute('href').split('/').pop();
            
            // This ensures the link is active based on the current file name
            if (linkPath === 'student_dashboard.php' && path === 'student_dashboard.php') {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
    });
</script>

</body>
</html>