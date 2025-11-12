<?php
session_start();
// Include the Transaction class (now BCNF-compliant)
require_once "../classes/Transaction.php";

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'staff') {
    header("Location: ../pages/login.php");
    exit;
}

$transaction = new Transaction();

// --- Helper Functions for Report Logic ---

/**
 * Checks if a form is overdue by comparing expected_return_date against today's date.
 */
function isOverdue($expected_return_date) {
    if (!$expected_return_date) return false;
    $expected_date = new DateTime($expected_return_date);
    $today = new DateTime();
    return $expected_date->format('Y-m-d') < $today->format('Y-m-d');
}

/**
 * Helper to fetch and format apparatus list for display, including individual item status.
 */
function getFormItemsText($form_id, $transaction) {
    // getFormItems returns ONE ROW PER UNIT, with the item's specific status (item_status)
    $items = $transaction->getFormItems($form_id); 
    
    if (empty($items)) return '<span class="text-muted">N/A</span>';
    
    $output = '';
    
    foreach ($items as $item) {
        $name = htmlspecialchars($item['name'] ?? 'Unknown');
        $item_status = strtolower($item['item_status'] ?? 'pending');
        $quantity = $item['quantity'] ?? 1;

        // Determine badge class/text based on individual item status
        $tag_class = 'bg-secondary'; // Default gray/Rejected
        $tag_text = ucfirst(str_replace('_', ' ', $item_status));
        
        // --- CUSTOM FIX: Use bg-dark for damaged to achieve monochrome look ---
        if ($item_status === 'damaged') {
             $tag_class = 'bg-dark-monochrome'; // New custom class for Black/White appearance
             $tag_text = 'Damaged';
        } elseif ($item_status === 'returned') {
             $tag_class = 'bg-success'; // Green
             $tag_text = 'Returned';
        } elseif ($item_status === 'overdue') {
             $tag_class = 'bg-danger'; // Red
             $tag_text = 'Overdue';
        } elseif ($item_status === 'borrowed') {
             $tag_class = 'bg-primary'; // Blue
        }
        // --- END CUSTOM FIX ---
        
        // This generates one line per item row.
        $output .= '<div class="d-flex align-items-center justify-content-between my-1">';
        $output .= '    <span class="me-2">' . $name . ' (x' . $quantity . ')</span>';
        $output .= '    <span class="badge ' . $tag_class . '">' . $tag_text . '</span>';
        $output .= '</div>';
    }
    return $output;
}


/**
 * Helper to generate status badge for history table. 
 */
function getStatusBadge(array $form) {
    $status = $form['status'];
    $clean_status = strtolower(str_replace(' ', '_', $status));
    $display_status = ucfirst(str_replace('_', ' ', $clean_status));
    
    // NOTE: This color map must use fixed Bootstrap classes as requested.
    $color_map = [
        'returned' => 'success',
        'approved' => 'info', 
        'borrowed' => 'primary',
        'overdue' => 'danger',
        'damaged' => 'dark-monochrome', // Use custom dark class for overall Damaged form status
        'rejected' => 'secondary',
        'waiting_for_approval' => 'warning'
    ];
    $color = $color_map[$clean_status] ?? 'secondary';
    
    // Handling Late Return Status
    if ($status === 'returned' && isset($form['is_late_return']) && $form['is_late_return'] == 1) {
        // Use danger/red for LATE.
        $color = 'danger'; 
        $display_status = 'Returned (LATE)';
    }

    return '<span class="badge bg-' . $color . '">' . $display_status . '</span>';
}


// --- 1. Data Retrieval for Reports ---

$allForms = $transaction->getAllForms(); 
$allApparatus = $transaction->getAllApparatus(); 

// Calculate Transaction Summaries
$totalForms = count($allForms);
$pendingForms = count(array_filter($allForms, fn($f) => $f['status'] === 'waiting_for_approval'));
$reservedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'approved'));
$borrowedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'borrowed'));
$returnedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'returned'));
$damagedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'damaged'));

// Calculate Overdue Count
$overdueFormsCount = count(array_filter($allForms, fn($f) => 
    ($f['status'] === 'borrowed' || $f['status'] === 'approved') && isOverdue($f['expected_return_date'])
));

// Forms currently borrowed or reserved
$activeForms = array_filter($allForms, fn($f) => $f['status'] === 'borrowed' || $f['status'] === 'approved');

// Apparatus summary
$totalApparatusCount = 0; 
$availableApparatusCount = 0;
$damagedApparatusCount = 0;
$lostApparatusCount = 0;

// Recalculate totals by summing the aggregated stock fields from the returned list
foreach ($allApparatus as $app) {
    $totalApparatusCount += (int)$app['total_stock'];
    $availableApparatusCount += (int)$app['available_stock'];
    $damagedApparatusCount += (int)$app['damaged_stock'];
    $lostApparatusCount += (int)$app['lost_stock'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Reports Hub - WMSU CSM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --msu-red: #b8312d;
            --msu-red-dark: #a82e2a;
            --sidebar-width: 280px;
            --student-logout-red: #dc3545; /* Added this definition */
        }

        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f5f6fa; 
            min-height: 100vh;
            display: flex; 
            padding: 0;
            margin: 0;
        }

        /* --- Sidebar & Main Content Setup --- */
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
        /* ... (sidebar styles are assumed to be correct) ... */
        .sidebar-header { text-align: center; padding: 20px 15px; font-size: 1.2rem; font-weight: 700; line-height: 1.2; color: #fff; border-bottom: 1px solid rgba(255, 255, 255, 0.4); margin-bottom: 20px; }
        .sidebar-header img { max-width: 90px; height: auto; margin-bottom: 15px; }
        .sidebar-header .title { font-size: 1.3rem; line-height: 1.1; }
        .sidebar-nav { flex-grow: 1; }
        .sidebar-nav .nav-link { color: white; padding: 15px 20px; font-size: 1rem; font-weight: 600; transition: background-color 0.2s; border-left: none !important; }
        .sidebar-nav .nav-link:hover { background-color: var(--msu-red-dark); }
        .sidebar-nav .nav-link.active { background-color: var(--msu-red-dark); }
        
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
        /* CRITICAL: Style the anchor tag (.nav-link) using the student's specific red */
        .logout-link .nav-link { 
            display: flex; 
            align-items: center;
            justify-content: flex-start; 
            /* Use the student's specific #dc3545 red */
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
        
        /* --- Report Specific Styles --- */
        .report-section {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            background: #fff;
        }

        .report-section h3 {
            color: var(--msu-red);
            padding-bottom: 10px;
            border-bottom: 1px dashed #eee;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .report-stat-box {
            background: #f8f9fa;
            border-left: 5px solid var(--msu-red);
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        .report-stat-box p {
            margin: 0;
            font-size: 1rem;
            font-weight: 500;
        }
        
        /* Table Headers */
        .table thead th {
            background-color: var(--msu-red);
            color: white;
            font-weight: 600;
            vertical-align: middle;
        }
        .table tbody td {
            vertical-align: middle;
        }

        /* Specific styles for the detailed item list */
        
        /* Monochromatic Fix for Damaged status */
        .badge.bg-dark-monochrome { 
            background-color: #343a40 !important; /* Dark Gray */
            color: white !important; 
        }

        /* Fixed Color Definitions */
        .badge.bg-success { background-color: #28a745 !important; } /* Returned (Green) */
        .badge.bg-warning { background-color: #ffc107 !important; color: #343a40 !important; } /* Pending (Yellow/Orange) */
        .badge.bg-danger { background-color: #dc3545 !important; } /* Overdue/Late (Red) */
        .badge.bg-secondary { background-color: #6c757d !important; } /* Rejected (Gray) */
        .badge.bg-primary { background-color: #007bff !important; } /* Borrowed (Blue) */
        .badge.bg-info { background-color: #17a2b8 !important; } /* Reserved/Approved (Cyan) */


        .detailed-items-cell .badge {
            margin-left: 5px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .detailed-items-cell .d-flex {
            line-height: 1.3;
            font-size: 0.85rem;
        }

        /* Print-specific styles */
        @media print {
            body, .main-content {
                margin: 0 !important;
                padding: 0 !important;
                background: white;
                width: 100%;
            }
            .sidebar, .page-header i, .btn-print, .mb-4 {
                display: none !important;
            }
            .content-area {
                box-shadow: none !important;
                border-radius: 0 !important;
                padding: 15px 0 !important;
            }
            .report-section {
                border: 1px solid #000;
                page-break-inside: avoid;
            }
            .report-section h3 {
                border-bottom: 1px solid #000;
            }
            table {
                font-size: 9pt;
            }
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
        <a class="nav-link" href="staff_dashboard.php">
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
        <a class="nav-link active" href="staff_report.php">
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
            <i class="fas fa-print fa-fw me-2 text-secondary"></i> Printable Reports Hub
        </h2>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <p class="text-muted mb-0">Report Date: <?= date('F j, Y, g:i a') ?></p>
            <button onclick="window.print()" class="btn btn-lg btn-danger btn-print">
                <i class="fas fa-print me-2"></i> Print All Reports
            </button>
        </div>

        <div class="report-section">
            <h3><i class="fas fa-clipboard-list me-2"></i> Transaction Status Summary</h3>
            <div class="row">
                <div class="col-md-3">
                    <div class="report-stat-box">Total Forms: <span class="float-end fw-bold text-dark"><?= $totalForms ?></span></div>
                </div>
                <div class="col-md-3">
                    <div class="report-stat-box">Pending Approval: <span class="float-end fw-bold text-warning"><?= $pendingForms ?></span></div>
                </div>
                <div class="col-md-3">
                    <div class="report-stat-box">Reserved (Approved): <span class="float-end fw-bold text-info"><?= $reservedForms ?></span></div>
                </div>
                <div class="col-md-3">
                    <div class="report-stat-box">Currently Borrowed: <span class="float-end fw-bold text-primary"><?= $borrowedForms ?></span></div>
                </div>
                <div class="col-md-3 mt-3">
                    <div class="report-stat-box">Overdue (Active): <span class="float-end fw-bold text-danger"><?= $overdueFormsCount ?></span></div>
                </div>
                <div class="col-md-3 mt-3">
                    <div class="report-stat-box">Successfully Returned: <span class="float-end fw-bold text-success"><?= $returnedForms ?></span></div>
                </div>
                <div class="col-md-3 mt-3">
                    <div class="report-stat-box">Damaged/Lost Forms: <span class="float-end fw-bold text-danger"><?= $damagedForms ?></span></div>
                </div>
            </div>
        </div>
        
        <div class="report-section">
            <h3><i class="fas fa-flask me-2"></i> Apparatus Inventory Stock Status</h3>
            <div class="row">
                <div class="col-md-4">
                    <div class="report-stat-box border-success">Total Inventory Units: <span class="float-end fw-bold text-dark"><?= $totalApparatusCount ?></span></div>
                </div>
                <div class="col-md-4">
                    <div class="report-stat-box border-info">Units Available for Borrowing: <span class="float-end fw-bold text-success"><?= $availableApparatusCount ?></span></div>
                </div>
                <div class="col-md-4">
                    <div class="report-stat-box border-danger">Units Unavailable (Damaged/Lost): <span class="float-end fw-bold text-danger"><?= $damagedApparatusCount + $lostApparatusCount ?></span></div>
                </div>
                <div class="col-12 mt-3">
                    <p class="text-muted small">*Note: Units marked Unavailable are not available for borrowing until their stock count is adjusted.</p>
                </div>
            </div>
        </div>

        <div class="report-section">
            <h3><i class="fas fa-exclamation-triangle me-2"></i> Overdue Active Forms (Past Expected Return)</h3>
            <div class="table-responsive">
                <table class="table table-striped table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Form ID</th>
                            <th>Student ID</th>
                            <th>Borrower Name</th>
                            <th>Expected Return</th>
                            <th>Items Borrowed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $overdueList = array_filter($allForms, fn($f) => 
                            ($f['status'] === 'borrowed' || $f['status'] === 'approved') && isOverdue($f['expected_return_date'])
                        );
                        if (!empty($overdueList)): 
                            foreach ($overdueList as $form): ?>
                                <tr>
                                    <td><?= htmlspecialchars($form['id']) ?></td>
                                    <td><?= htmlspecialchars($form['user_id']) ?></td>
                                    <td><?= htmlspecialchars($form['firstname'] . ' ' . $form['lastname']) ?></td>
                                    <td class="text-danger fw-bold"><?= htmlspecialchars($form['expected_return_date'] ?? 'N/A') ?></td>
                                    <td class="detailed-items-cell text-start"><?= getFormItemsText($form['id'], $transaction) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-muted text-center">No active forms are currently past their expected return date.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="report-section">
            <h3><i class="fas fa-people-carry me-2"></i> All Active Loans (Borrowed & Reserved)</h3>
            <div class="table-responsive">
                <table class="table table-striped table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Form ID</th>
                            <th>Student ID</th>
                            <th>Borrower Name</th>
                            <th>Status</th>
                            <th>Borrow Date</th>
                            <th>Expected Return</th>
                            <th>Actual Return</th>
                            <th>Items Borrowed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (!empty($activeForms)): 
                            foreach ($activeForms as $form): 
                                $status_class = ($form['status'] == 'borrowed') ? 'primary' : 'info';
                                $status_text = ($form['status'] == 'borrowed') ? 'Borrowed' : 'Reserved';
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($form['id']) ?></td>
                                    <td><?= htmlspecialchars($form['user_id']) ?></td>
                                    <td><?= htmlspecialchars($form['firstname'] . ' ' . $form['lastname']) ?></td>
                                    <td><span class="badge bg-<?= $status_class ?>"><?= $status_text ?></span></td>
                                    <td><?= htmlspecialchars($form['borrow_date'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($form['expected_return_date'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($form['actual_return_date'] ?? 'N/A') ?></td> 
                                    <td class="detailed-items-cell text-start"><?= getFormItemsText($form['id'], $transaction) ?></td> 
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-muted text-center">No items currently active (borrowed or reserved).</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="report-section">
            <h3><i class="fas fa-history me-2"></i> Detailed Transaction History (All Forms)</h3>
            <div class="table-responsive">
                <table class="table table-striped table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Form ID</th>
                            <th>Student ID</th>
                            <th>Borrower Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Borrow Date</th>
                            <th>Expected Return</th>
                            <th>Actual Return</th>
                            <th>Items Borrowed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (!empty($allForms)): 
                            foreach ($allForms as $form): ?>
                                <tr>
                                    <td><?= htmlspecialchars($form['id']) ?></td>
                                    <td><?= htmlspecialchars($form['user_id']) ?></td>
                                    <td><?= htmlspecialchars($form['firstname'] . ' ' . $form['lastname']) ?></td>
                                    <td><?= htmlspecialchars(ucfirst($form['form_type'])) ?></td>
                                    <td><?= getStatusBadge($form) ?></td> 
                                    <td><?= htmlspecialchars($form['borrow_date'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($form['expected_return_date'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($form['actual_return_date'] ?? '-') ?></td> 
                                    <td class="detailed-items-cell text-start"><?= getFormItemsText($form['id'], $transaction) ?></td> 
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-muted text-center">No transactions recorded.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Script to ensure the Reports link remains active
    document.addEventListener('DOMContentLoaded', () => {
        const links = document.querySelectorAll('.sidebar .nav-link');
        links.forEach(link => {
            link.classList.remove('active');
        });
        // Highlight the Reports link
        const reportsLink = document.querySelector('a[href="staff_report.php"]');
        if (reportsLink) {
            reportsLink.classList.add('active');
        }
    });
</script>
</body>
</html>