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

// --- BAN/LOCK STATUS CHECKS (used only for sidebar rendering) ---
$isBanned = $transaction->isStudentBanned($student_id); 
$activeCount = $transaction->getActiveTransactionCount($student_id);

// --- FILTERING LOGIC ---
$filter = isset($_GET["filter"]) ? $_GET["filter"] : "all";

// Fetch ALL student transactions for history view (targets borrow_forms)
$transactions = $transaction->getStudentTransactions($student_id);

// We rely on PHP's array filtering for simplicity, as in the original code.
if ($filter != "all") {
    $filtered_transactions = array_filter($transactions, function($t) use ($filter) {
        return strtolower($t["status"]) === strtolower($filter);
    });
} else {
    $filtered_transactions = $transactions;
}

// Re-index array after filtering for use with foreach
$filtered_transactions = array_values($filtered_transactions);

// Define the absolute web root path. If your site is http://localhost/wd123/...
$webRootURL = "/wd123/uploads/apparatus_images/"; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student - Transaction History</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    
    <style>
        /* CSS styles remain unchanged */
        :root {
            --msu-red: #b8312d; 
            --msu-red-dark: #a82e2a;
            --sidebar-width: 280px; 
            --bg-light: #f5f6fa;
            --header-bg: #e9ecef;
            --danger-light: #fbe6e7;
            --danger-dark: #8b0000;
            
            /* Define solid colors based on staff_dashboard.php */
            --status-returned-solid: #198754; 
            --status-overdue-solid: #dc3545; 
            --status-borrowed-solid: #0d6efd; 
            --status-pending-solid: #ffc107; 
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg-light); 
            padding: 0;
            margin: 0;
            display: flex; 
            min-height: 100vh;
        }
        
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
        
        /* ✅ FINAL LOGO SIZE: Set to 90px for balance ✅ */
        .sidebar-header img { 
            max-width: 90px; 
            height: auto; 
            margin: 0 auto 15px auto; 
            display: block; 
        }
        /* ------------------------------------------- */
        
        .sidebar-header .title { font-size: 1.3rem; line-height: 1.1; }

        .sidebar .nav-link {
            color: white;
            padding: 15px 20px; 
            font-size: 1rem;
            font-weight: 600;
            transition: background-color 0.3s;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background-color: var(--msu-red-dark);
        }
        .sidebar .nav-link.banned { 
            background-color: #5a2624; 
            opacity: 0.8; 
        }
        .sidebar .nav-link.history {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: 5px;
        }
        .logout-link { margin-top: auto; border-top: 1px solid rgba(255, 255, 255, 0.1); }
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
        /* END MODIFIED */
        
        h2 { 
            border-bottom: 2px solid var(--msu-red); 
            padding-bottom: 10px; 
            font-size: 2rem;
            font-weight: 600;
        }
        .lead { margin-bottom: 30px; }

        /* --- Table Redesign Styles --- */
        .table-responsive {
              border-radius: 8px;
              overflow: hidden;
              border: 1px solid #e0e0e0;
              margin-top: 10px;
        }
        .table {
            --bs-table-bg: #fff;
            --bs-table-striped-bg: #f8f8f8;
        }

        /* Table Header */
        .table thead th { 
            background: #e9ecef !important; 
            color: #555; 
            font-weight: 700;
            border-bottom: 2px solid #ccc;
            vertical-align: middle;
            font-size: 1rem;
            padding: 15px 12px;
        }

        /* Table Body */
        .table td {
            padding: 15px 12px;
            border-top: 1px solid #e9ecef;
            vertical-align: middle;
            font-size: 0.95rem;
        }

        /* Row Highlight for Critical Statuses */
        .status-danger-row {
            background-color: var(--danger-light) !important;
        }
        .table-striped > tbody > .status-danger-row:nth-of-type(odd) > * {
            background-color: #fcebeb !important;
        }

        /* Status Tags */
        .status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 700;
            text-transform: uppercase; 
            font-size: 0.75rem; 
            line-height: 1.2;
            min-width: 100px;
            text-align: center;
            color: white; /* Set default text color to white for solid backgrounds */
        }
        
        /* --- MODIFIED STATUS STYLES TO MATCH PHP OUTPUT --- */
        /* FIX: Changed selector to match PHP's str_replace output (no underscores) */
        .status.waitingforapproval { 
            background-color: var(--status-pending-solid); /* Solid Yellow */
            color: #333; /* Darker text for better contrast on yellow */
        } 
        .status.approved { 
            background-color: var(--status-returned-solid); /* Solid Green */
        }
        .status.rejected { 
            background-color: var(--status-overdue-solid); /* Solid Red */
        }
        .status.borrowed { 
            background-color: var(--status-borrowed-solid); /* Solid Blue */
        }
        .status.returned { 
            background-color: var(--status-returned-solid); /* Solid Green (Based on Image 1/Staff Dashboard returned) */
        }
        
        /* Critical Status Highlight */
        .status.overdue, .status.returned_late { 
             background-color: var(--status-overdue-solid); /* Solid Red */
             color: white; 
             border: none; 
        }
        
        .status.damaged { 
             background-color: #343a40; /* Dark Gray (from staff_dashboard) */
             color: white; 
             border: none;
        }
        /* --- END MODIFIED STATUS STYLES --- */

        /* View Button */
        .btn-view-items {
            background: var(--msu-red); 
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .btn-view-items:hover { background: var(--msu-red-dark); color: white; }

        /* Transaction Details Column (Image + ID/Type) */
        .trans-details {
            display: flex;
            align-items: center;
            text-align: left !important;
        }
        .trans-details img {
            width: 50px;
            height: 50px;
            object-fit: contain;
            border-radius: 6px; 
            margin-right: 15px;
            border: 1px solid #ddd;
            padding: 4px;
        }
        .trans-id {
            font-weight: 700;
            font-size: 1.1rem;
            color: #333;
        }
        .trans-type {
            font-size: 0.85rem;
            color: #6c757d;
            display: block;
        }
        
        /* Date Styles */
        .date-col {
            line-height: 1.5;
            font-size: 0.95rem;
        }
        .date-col span {
            display: block;
        }
        .date-col .expected { color: #dc3545; font-weight: 600; }
        .date-col .actual { color: #198754; }
        .date-col .borrow { color: #333; font-weight: 500;}

        /* Remarks style */
        .remarks-col {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis; 
            text-align: left !important;
        }
        .remarks-col:hover {
            overflow: visible; 
            white-space: normal;
            position: relative;
            background: #fff;
            z-index: 10;
            box-shadow: 0 0 5px rgba(0,0,0,0.2);
            border: 1px solid #ccc;
        }
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
            <a href="student_dashboard.php" class="nav-link">
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
                <i class="fas fa-redo fa-fw me-2"></i> Initiate Return
            </a>
        </li>
        <li class="nav-item">
            <a href="student_transaction.php" class="nav-link active">
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

<div class="main-wrapper">
    <div class="container">
        <h2 class="mb-4"><i class="fas fa-history me-2 text-secondary"></i> Full Transaction History</h2>
        <p class="lead text-start">View all past and current borrowing/reservation records.</p>

        <div class="filter">
            <form method="get" action="student_transaction.php" class="d-flex align-items-center">
                <label class="form-label me-2 mb-0 fw-bold text-secondary">Filter by Status:</label>
                <select name="filter" onchange="this.form.submit()" class="form-select form-select-sm w-auto">
                    <option value="all" <?= $filter == 'all' ? 'selected' : '' ?>>Show All</option>
                    <option value="waiting_for_approval" <?= $filter == 'waiting_for_approval' ? 'selected' : '' ?>>Pending Approval</option>
                    <option value="approved" <?= $filter == 'approved' ? 'selected' : '' ?>>Approved/Reserved</option>
                    <option value="rejected" <?= $filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="borrowed" <?= $filter == 'borrowed' ? 'selected' : '' ?>>Borrowed (Active)</option>
                    <option value="returned" <?= $filter == 'returned' ? 'selected' : '' ?>>Returned</option>
                    <option value="overdue" <?= $filter == 'overdue' ? 'selected' : '' ?>>Overdue</option>
                    <option value="damaged" <?= $filter == 'damaged' ? 'selected' : '' ?>>Damaged/Lost</option>
                </select>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-striped align-middle">
            <thead>
            <tr>
                <th style="width: 25%;">Transaction Details</th> 
                <th style="width: 30%;">Dates (Borrow / Expected / Actual)</th>
                <th style="width: 15%;">Status</th>
                <th style="width: 20%;">Staff Remarks</th>
                <th style="width: 10%;">Action</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $hasData = false;
            
            foreach ($filtered_transactions as $t):
                $hasData = true;
                
                // --- MODIFIED PHP LOGIC TO HANDLE RETURNED (LATE) CONSISTENTLY ---
                $raw_status = strtolower($t['status']);
                $display_status = $raw_status; // Base status text

                // Note: The status_class is generated here, removing underscores
                $status_class = str_replace('_', '', $raw_status);
                $row_class = ''; // Initialize row class

                // Check for LATE RETURN and override class/text
                if ($raw_status === 'returned' && (isset($t['is_late_return']) && $t['is_late_return'] == 1)) {
                    $display_status = 'returned (late)';
                    // Use a clean, consistent class name for CSS targeting
                    $status_class = 'returned_late'; 
                }

                // If the form status is a critical one (Damaged/Overdue) OR if it is a LATE RETURN, highlight the row.
                if (in_array($raw_status, ['overdue', 'damaged']) || $status_class === 'returned_late') {
                    $row_class = 'status-danger-row';
                }
                // --- END MODIFIED PHP LOGIC ---
                
                // FIX: Call getFormApparatus to ensure we have the image path
                $apparatusList = $transaction->getFormApparatus($t["id"]); 
                $firstApparatus = $apparatusList[0] ?? null;
                
                $imageFile = $firstApparatus["image"] ?? "default.jpg";
                
                // CRITICAL FIX: The full URL path (browser side)
                $imageURL = "/wd123/uploads/apparatus_images/" . $imageFile; 
                
                // Server-side check for robust fallback path
                $serverPath = __DIR__ . "/../uploads/apparatus_images/" . $imageFile;
                
                if (!file_exists($serverPath) || is_dir($serverPath)) {
                    $imageURL = "/wd123/uploads/apparatus_images/default.jpg";
                }
            ?>
                <tr class="<?= $row_class ?>">
                    <td class="trans-details">
                        <img src="<?= htmlspecialchars($imageURL) ?>" 
                            alt="Apparatus Image"
                            title="<?= htmlspecialchars($firstApparatus["name"] ?? 'N/A') ?>"
                            class="me-2"
                            style="width: 50px; height: 50px; object-fit: contain; border-radius: 6px; border: 1px solid #ddd; padding: 4px;">
                        <div>
                            <span class="trans-id">ID: <?= htmlspecialchars($t["id"]) ?></span>
                            <span class="trans-type"><?= htmlspecialchars(ucfirst($t["form_type"])) ?></span>
                        </div>
                    </td>

                    <td class="date-col">
                        <span class="borrow" title="Borrow Date"><i class="fas fa-calendar-alt fa-fw me-2 text-secondary"></i> **Borrow:** <?= htmlspecialchars($t["borrow_date"]) ?></span>
                        <span class="expected" title="Expected Return"><i class="fas fa-clock fa-fw me-2"></i> **Expected:** <?= htmlspecialchars($t["expected_return_date"]) ?></span>
                        <span class="actual" title="Actual Return Date"><i class="fas fa-check-circle fa-fw me-2"></i> **Actual:** <?= htmlspecialchars($t["actual_return_date"] ?? '-') ?></span>
                    </td>

                    <td>
                        <span class="status <?= htmlspecialchars($status_class) ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', $display_status)) ?>
                        </span>
                    </td>
                    <td class="remarks-col text-start" title="<?= htmlspecialchars($t["staff_remarks"] ?? '-') ?>">
                        <?= htmlspecialchars($t["staff_remarks"] ?? '-') ?>
                    </td>
                    <td>
                        <a href="student_view_items.php?form_id=<?= $t["id"] ?>&context=history" class="btn-view-items">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$hasData): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">No transactions found for the selected filter.</td></tr>
            <?php endif; ?>
            </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Script to correctly set the active link in the sidebar menu
    document.addEventListener('DOMContentLoaded', () => {
        const path = window.location.pathname.split('/').pop() || 'student_dashboard.php';
        const links = document.querySelectorAll('.sidebar .nav-link');
        links.forEach(link => {
            const linkPath = link.getAttribute('href').split('/').pop();
            
            // This ensures the link is active based on the current file name
            if (linkPath === path) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
    });
</script>
</body>
</html>