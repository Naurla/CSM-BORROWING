<?php
session_start();
require_once "../classes/Transaction.php";

// (Optional) Redirect if not logged in
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'staff') {
    header("Location: ../pages/login.php");
    exit;
}

$transaction = new Transaction();

// Get all forms (transactions)
// Note: This assumes the getAllForms() method was correctly added to Transaction.php.
// CRITICAL: getAllForms() MUST fetch the `is_late_return` column.
$allForms = $transaction->getAllForms();
$apparatusList = $transaction->getAllApparatus();

// Count summary stats
$totalForms = count($allForms);
$totalApparatus = count($apparatusList); // Correct: Counts the number of apparatus types

// Transaction Status Counts (Filtering logic is correct)
$pendingForms = count(array_filter($allForms, fn($f) => $f['status'] === 'waiting_for_approval' || $f['status'] === 'checking')); // Added 'checking' to pending

// We use 'approved' status to represent 'Currently Reserved' (ready for pickup)
$reservedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'approved' || $f['status'] === 'reserved')); // Included 'reserved'

// Use 'borrowed' status for 'Currently Borrowed' (currently checked out)
$borrowedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'borrowed')); 

$returnedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'returned'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --msu-red: #b8312d;
            --msu-red-dark: #a82e2a;
            --msu-blue: #007bff;
            --sidebar-width: 280px;
            /* Define solid colors for reference image */
            --status-returned-solid: #28a745; 
            --status-overdue-solid: #dc3545;
            /* NEW: Define the specific student logout red for consistency */
            --student-logout-red: #dc3545;
        }

        /* --- Global & Sidebar Styles --- */
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f5f6fa; 
            min-height: 100vh;
            display: flex; 
            padding: 0;
            margin: 0;
        }
        
        .sidebar {
            width: var(--sidebar-width);
            min-width: var(--sidebar-width);
            height: 100vh;
            background-color: var(--msu-red);
            color: white;
            padding: 0;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2);
            position: fixed; 
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            text-align: center;
            padding: 20px 15px; 
            font-size: 1.2rem;
            font-weight: 700;
            line-height: 1.2;
            color: #fff;
            border-bottom: 1px solid rgba(255, 255, 255, 0.4); 
            margin-bottom: 20px; 
        }

        .sidebar-header img {
            max-width: 90px; 
            height: auto;
            margin-bottom: 15px;
        }
        
        .sidebar-header .title {
            font-size: 1.3rem; 
            line-height: 1.1;
        }
        
        .sidebar-nav {
            flex-grow: 1; 
        }

        .sidebar-nav .nav-link {
            color: white;
            padding: 15px 20px; 
            font-size: 1rem;
            font-weight: 600;
            transition: background-color 0.2s;
            border-left: none !important; 
        }

        .sidebar-nav .nav-link:hover {
            background-color: var(--msu-red-dark);
        }
        .sidebar-nav .nav-link.active {
            background-color: var(--msu-red-dark);
        }
        
        /* --- FINAL LOGOUT FIX: Match Student's Specific Red (#dc3545) --- */
        .logout-link {
            margin-top: auto; 
            padding: 0; 
            /* The separation line above the logout button */
            border-top: 1px solid rgba(255, 255, 255, 0.1); 
            width: 100%; 
            /* Set the container background to the main MSU red */
            background-color: var(--msu-red); 
        }
        /* Style the anchor tag (.nav-link) using the student's specific red */
        .logout-link .nav-link { 
            display: flex; 
            align-items: center;
            justify-content: flex-start; 
            /* CRITICAL FIX: Use the student's specific #dc3545 red (or var(--student-logout-red)) */
            background-color: var(--student-logout-red) !important; 
            color: white !important;
            padding: 15px 20px; 
            border-radius: 0; 
            text-decoration: none;
            font-weight: 600; 
            font-size: 1rem;
            transition: background 0.3s;
        }
        .logout-link .nav-link:hover {
            /* Use a slightly darker shade of the student's red on hover */
            background-color: #c82333 !important; 
        }
        /* --- END FINAL LOGOUT FIX --- */

        .main-content {
            margin-left: var(--sidebar-width); 
            flex-grow: 1;
            padding: 30px;
        }
        .content-area {
            background: #fff; 
            border-radius: 12px; 
            padding: 30px 40px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .page-header {
            color: #333; 
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--msu-red);
            font-weight: 600;
            font-size: 2rem;
        }

        /* --- STAT CARD STYLES --- */
        .stat-card {
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            text-align: center;
            height: 100%;
            border: 1px solid #eee; 
            transition: transform 0.3s ease-in-out, box-shadow 0.3s ease-in-out; 
        }
        
        .stat-card:hover {
            transform: translateY(-5px); 
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }

        .stat-card h3 {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 5px;
            text-transform: uppercase;
            font-weight: 600;
        }
        .stat-card p {
            font-size: 2.5rem; 
            font-weight: 800;
            color: var(--msu-red);
            margin-bottom: 0;
            line-height: 1.1;
        }
        .stat-icon-wrapper {
            font-size: 3.5rem; 
            display: block;
            margin-bottom: 10px;
        }

        /* Status-specific Colors */
        .stat-card.total p, .stat-card.total .stat-icon-wrapper i { color: #333; } 
        .stat-card.pending p, .stat-card.pending .stat-icon-wrapper i { color: #ffc107; } 
        .stat-card.reserved p, .stat-card.reserved .stat-icon-wrapper i { color: #198754; } 
        .stat-card.borrowed p, .stat-card.borrowed .stat-icon-wrapper i { color: #0d6efd; } 
        
        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            /* Reduced top margin since the button row is gone */
            margin-top: 15px; 
        }
        .table thead th {
            background: var(--msu-red);
            color: white;
            font-weight: 600;
            vertical-align: middle;
            text-align: center;
        }
        .table tbody td {
            vertical-align: middle;
            font-size: 0.95rem;
            text-align: center;
        }
        
        .status-tag {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 15px;
            font-weight: 700;
            text-transform: capitalize;
            font-size: 0.8rem;
            line-height: 1.2;
            white-space: nowrap;
        }
        /* Status Tags with solid color backgrounds for better visibility */
        .status-tag.waiting_for_approval { background-color: #ffc107; color: #333; } /* Solid Yellow */
        .status-tag.approved { background-color: #198754; color: white; } /* Solid Green */
        .status-tag.rejected { background-color: #dc3545; color: white; } /* Solid Red */
        .status-tag.borrowed { background-color: #0d6efd; color: white; } /* Solid Blue */
        
        /* --- FIXES FOR RETURNED/OVERDUE TO MATCH REFERENCE IMAGE (Image 2) --- */
        .status-tag.returned { 
            background-color: var(--status-returned-solid); /* Solid Green */
            color: white; 
        } 
        .status-tag.overdue { 
            background-color: var(--status-overdue-solid); /* Solid Red */
            color: white; 
        } 
        /* Damaged uses the darker style from the previous fix */
        .status-tag.damaged { 
            background-color: #343a40; /* Black/Dark Gray */
            color: white; 
            border: 1px solid #212529;
        }
        
        /* New Styles for LATE RETURN on dashboard */
        .status-tag.returned-late {
            background-color: var(--status-overdue-solid); /* Solid Red background */
            color: white; /* White text */
        }
        .status-tag.returned-late .late-indicator {
            padding-left: 3px;
            font-size: 0.8rem;
        }

    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo" class="img-fluid"> 
        <div class="title">
            CSM LABORATORY <br>APPARATUS BORROWING
        </div>
    </div>
    
    <div class="sidebar-nav nav flex-column">
        <a class="nav-link active" href="staff_dashboard.php">
            <i class="fas fa-chart-line fa-fw me-2"></i>Dashboard
        </a>
        <a class="nav-link" href="staff_apparatus.php">
            <i class="fas fa-vials fa-fw me-2"></i>Apparatus List
        </a>
        <a class="nav-link" href="staff_pending.php">
            <i class="fas fa-hourglass-half fa-fw me-2"></i>Pending Approvals
        </a>
        <a class="nav-link" href="staff_transaction.php">
            <i class="fas fa-list-alt fa-fw me-2"></i>All Transactions
        </a>
        <a class="nav-link" href="staff_report.php">
            <i class="fas fa-print fa-fw me-2"></i>Generate Reports
        </a>
    </div>
    
    <div class="logout-link">
        <a href="../pages/logout.php" class="nav-link">
             <i class="fas fa-sign-out-alt fa-fw me-2"></i> Logout
        </a>
    </div>
    </div>

<div class="main-content">
    <div class="content-area">
        <h2 class="page-header">
            <i class="fas fa-tachometer-alt fa-fw me-2 text-secondary"></i> Dashboard Overview
        </h2>
        
        <h4 class="mb-4 text-dark">Welcome, <span class="text-danger fw-bold"><?= htmlspecialchars($_SESSION['user']['firstname'] ?? 'Staff') ?></span>!</h4>

        <div class="row g-4 mb-4">
            
            <div class="col-lg-3 col-md-6">
                <div class="stat-card bg-white total">
                    <span class="stat-icon-wrapper"><i class="fas fa-file-alt"></i></span>
                    <h3>Total Forms</h3>
                    <p><?= $totalForms ?></p>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="stat-card bg-white pending">
                    <span class="stat-icon-wrapper"><i class="fas fa-clock"></i></span>
                    <h3>Pending</h3>
                    <p><?= $pendingForms ?></p>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="stat-card bg-white reserved">
                    <span class="stat-icon-wrapper"><i class="fas fa-check-circle"></i></span>
                    <h3>Reserved (Approved)</h3>
                    <p><?= $reservedForms ?></p>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="stat-card bg-white borrowed">
                    <span class="stat-icon-wrapper"><i class="fas fa-people-carry"></i></span>
                    <h3>Currently Borrowed</h3>
                    <p><?= $borrowedForms ?></p>
                </div>
            </div>
            
        </div>
        
        <h4 class="mb-3 text-secondary"><i class="fas fa-history me-2"></i>Recent Activity (Last 10 Forms)</h4>
        <div class="table-responsive table-container">
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th>Form ID</th>
                        <th>Student ID</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Borrow Date</th>
                        <th>Expected Return</th>
                        </tr>
                </thead>
                <tbody>
                    <?php if (!empty($allForms)): ?>
                        <?php foreach (array_slice($allForms, 0, 10) as $form): 
                            
                            $clean_status = strtolower($form['status']);
                            $display_status_text = ucfirst(str_replace('_', ' ', $clean_status));
                            $status_class = $clean_status;

                            // *** MODIFIED STATUS LOGIC FOR DASHBOARD (Same as staff_transaction) ***
                            // Check for LATE RETURN and override class/text
                            if ($form['status'] === 'returned' && (isset($form['is_late_return']) && $form['is_late_return'] == 1)) {
                                $status_class = 'returned-late'; 
                                $display_status_text = 'Returned (LATE)';
                            } 
                            // Fallback to standard status if not a late return
                            elseif (in_array($clean_status, ['returned', 'overdue', 'damaged'])) {
                                $status_class = $clean_status;
                            }
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($form['id']) ?></td>
                                <td><?= htmlspecialchars($form['user_id']) ?></td>
                                <td><?= htmlspecialchars(ucfirst($form['form_type'])) ?></td>
                                <td>
                                    <span class="status-tag <?= $status_class ?>">
                                        <?= $display_status_text ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($form['borrow_date'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($form['expected_return_date'] ?? '-') ?></td>
                                </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-muted py-3">No recent forms found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Script to ensure the Dashboard link remains active
    document.addEventListener('DOMContentLoaded', () => {
        const path = window.location.pathname.split('/').pop() || 'staff_dashboard.php';
        const links = document.querySelectorAll('.sidebar .nav-link');
        
        links.forEach(link => {
            const linkPath = link.getAttribute('href').split('/').pop();
            
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