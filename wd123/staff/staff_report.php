<?php
session_start();
// Include the Transaction class (now BCNF-compliant)
require_once "../classes/Transaction.php";

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'staff') {
    header("Location: ../pages/login.php");
    exit;
}

$transaction = new Transaction();

// --- Determine Mode and Report Type ---
// FIXED DEFAULT: Set default to 'all' (Hub View) instead of 'detailed'
$mode = $_GET['mode'] ?? 'hub'; 
$report_view_type = $_GET['report_view_type'] ?? 'all'; 

// --- Helper Functions (No print mode separation needed anymore, CSS handles it) ---

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
    $items = $transaction->getFormItems($form_id); 
    if (empty($items)) return 'N/A';
    $output = '';
    
    // Default Hub View (We will simplify this for printing via CSS hiding)
    foreach ($items as $item) {
        $name = htmlspecialchars($item['name'] ?? 'Unknown');
        $item_status = strtolower($item['item_status'] ?? 'pending');
        $quantity = $item['quantity'] ?? 1;

        $tag_class = 'bg-secondary'; 
        $tag_text = ucfirst(str_replace('_', ' ', $item_status));
        
        if ($item_status === 'damaged') {
             $tag_class = 'bg-dark-monochrome'; 
             $tag_text = 'Damaged';
        } elseif ($item_status === 'returned') {
             $tag_class = 'bg-success'; 
             $tag_text = 'Returned';
        } elseif ($item_status === 'overdue') {
             $tag_class = 'bg-danger'; 
             $tag_text = 'Overdue';
        } elseif ($item_status === 'borrowed') {
             $tag_class = 'bg-primary'; 
        }
        
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
    // Hub view: HTML badge output
    $status = $form['status'];
    $clean_status = strtolower(str_replace(' ', '_', $status));
    $display_status = ucfirst(str_replace('_', ' ', $clean_status));
    
    $color_map = [
        'returned' => 'success', 'approved' => 'info', 'borrowed' => 'primary',
        'overdue' => 'danger', 'damaged' => 'dark-monochrome', 'rejected' => 'secondary',
        'waiting_for_approval' => 'warning'
    ];
    $color = $color_map[$clean_status] ?? 'secondary';
    
    if ($status === 'returned' && isset($form['is_late_return']) && $form['is_late_return'] == 1) {
        $color = 'danger'; 
        $display_status = 'Returned (LATE)';
    }

    return '<span class="badge bg-' . $color . '">' . $display_status . '</span>';
}


// --- 2. Data Retrieval and Filtering Logic (Unified) ---

$allApparatus = $transaction->getAllApparatus(); 
$allForms = $transaction->getAllForms(); 

// --- Get Filter Inputs ---
$apparatus_filter_id = $_GET['apparatus_id'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$status_filter = $_GET['status_filter'] ?? ''; // Status filter input
$form_type_filter = $_GET['form_type_filter'] ?? ''; // NEW Form Type filter input

// --- Filtering Logic (Applied to Detailed/Summary views only) ---
$filteredForms = $allForms; 

// 1. Apply Date Filter
if ($start_date) {
    $start_dt = new DateTime($start_date);
    $filteredForms = array_filter($filteredForms, fn($f) => (new DateTime($f['created_at']))->format('Y-m-d') >= $start_dt->format('Y-m-d'));
}
if ($end_date) {
    $end_dt = new DateTime($end_date);
    $filteredForms = array_filter($filteredForms, fn($f) => (new DateTime($f['created_at']))->format('Y-m-d') <= $end_dt->format('Y-m-d'));
}

// 2. Apply Apparatus Filter 
if ($apparatus_filter_id) {
    $apparatus_filter_id = (string)$apparatus_filter_id;
    $forms_with_apparatus = [];
    foreach ($filteredForms as $form) {
        $items = $transaction->getFormItems($form['id']);
        foreach ($items as $item) {
            if ((string)$item['apparatus_id'] === $apparatus_filter_id) {
                $forms_with_apparatus[] = $form;
                break;
            }
        }
    }
    $filteredForms = $forms_with_apparatus;
}

// 3. Apply Form Type Filter
if ($form_type_filter) {
    $form_type_filter = strtolower($form_type_filter);
    $filteredForms = array_filter($filteredForms, fn($f) => 
        // FIX: Use trim() to ensure leading/trailing spaces don't break the filter
        strtolower(trim($f['form_type'])) === $form_type_filter
    );
}

// 4. Apply Status Filter
if ($status_filter) {
    $status_filter = strtolower($status_filter);
    
    if ($status_filter === 'overdue') {
        $filteredForms = array_filter($filteredForms, fn($f) => 
            ($f['status'] === 'borrowed' || $f['status'] === 'approved') && isOverdue($f['expected_return_date'])
        );
    } elseif ($status_filter === 'late_returns') {
         $filteredForms = array_filter($filteredForms, fn($f) => 
             $f['status'] === 'returned' && ($f['is_late_return'] ?? 0) == 1
         );
    } elseif ($status_filter === 'returned') { 
         // Filter specifically for ON TIME returns (is_late_return == 0)
         $filteredForms = array_filter($filteredForms, fn($f) => 
             $f['status'] === 'returned' && ($f['is_late_return'] ?? 0) == 0
         );
    } elseif ($status_filter === 'borrowed_reserved') { 
        // All non-pending/non-rejected forms (All completed transactions + current active ones)
        $filteredForms = array_filter($filteredForms, fn($f) => 
            $f['status'] !== 'waiting_for_approval' && $f['status'] !== 'rejected'
        );
    } elseif ($status_filter !== 'all') {
        // General status filtering (Used for 'approved', 'borrowed', 'damaged', etc. if not specifically handled above)
        $filteredForms = array_filter($filteredForms, fn($f) => 
            strtolower(str_replace('_', ' ', $f['status'])) === strtolower(str_replace('_', ' ', $status_filter))
        );
    }
}

// --- Data Assignments for Hub View ---

$reportForms = $filteredForms; // The filtered set for the Detailed History Table

// Global Summaries (used in both Hub and Print Summary)
$totalForms = count($allForms);
$pendingForms = count(array_filter($allForms, fn($f) => $f['status'] === 'waiting_for_approval'));
$reservedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'approved'));
$borrowedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'borrowed'));
$returnedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'returned'));
$damagedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'damaged'));

// We still calculate the overdue list count for the Summary Box
$overdueFormsList = array_filter($allForms, fn($f) => 
    ($f['status'] === 'borrowed' || $f['status'] === 'approved') && isOverdue($f['expected_return_date'])
);
$overdueFormsCount = count($overdueFormsList);

$totalApparatusCount = 0; 
$availableApparatusCount = 0;
$damagedApparatusCount = 0;
$lostApparatusCount = 0;
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
        /* --- General and Sidebar CSS (Retained) --- */
        :root {
            --msu-red: #b8312d;
            --msu-red-dark: #a82e2a;
            --sidebar-width: 280px;
            --student-logout-red: #dc3545; 
        }

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
        .sidebar-header { text-align: center; padding: 20px 15px; font-size: 1.2rem; font-weight: 700; line-height: 1.2; color: #fff; border-bottom: 1px solid rgba(255, 255, 255, 0.4); margin-bottom: 20px; }
        .sidebar-header img { max-width: 90px; height: auto; margin-bottom: 15px; }
        .sidebar-header .title { font-size: 1.3rem; line-height: 1.1; }
        .sidebar-nav { flex-grow: 1; }
        .sidebar-nav .nav-link { color: white; padding: 15px 20px; font-size: 1rem; font-weight: 600; transition: background-color 0.2s; border-left: none !important; }
        .sidebar-nav .nav-link:hover { background-color: var(--msu-red-dark); }
        .sidebar-nav .nav-link.active { background-color: var(--msu-red-dark); }
        
        .logout-link { margin-top: auto; border-top: 1px solid rgba(255, 255, 255, 0.1); width: 100%; background-color: var(--msu-red); }
        .logout-link .nav-link { display: flex; align-items: center; justify-content: flex-start; background-color: var(--student-logout-red) !important; color: white !important; padding: 15px 20px; border-radius: 0; text-decoration: none; font-weight: 600; font-size: 1rem; transition: background 0.3s; }
        .logout-link .nav-link:hover { background-color: #c82333 !important; }

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
        
        /* --- Report Specific Styles (Retained) --- */
        .report-section {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            background: #fff;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1); /* Retained for Hub View */
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
        .report-stat-box p { margin: 0; font-size: 1rem; font-weight: 500; }
        .table thead th {
            background-color: var(--msu-red);
            color: white;
            font-weight: 600;
            vertical-align: middle;
        }
        
        /* FIX FOR CUT OFF ITEMS: Ensure vertical alignment is correct and content wraps */
        .table tbody td { 
            vertical-align: top; /* Ensure content starts at the top of the cell */
            padding-top: 8px;    /* Give a little breathing room */
        }
        
        .detailed-items-cell {
            white-space: normal !important; /* Allow content to wrap */
            word-break: break-word;        /* Allow content to break lines */
            overflow: visible;             /* Ensure nothing is hidden */
        }
        
        .badge.bg-dark-monochrome { background-color: #343a40 !important; color: white !important; } 
        .badge.bg-success { background-color: #28a745 !important; } 
        .badge.bg-warning { background-color: #ffc107 !important; color: #343a40 !important; } 
        .badge.bg-danger { background-color: #dc3545 !important; } 
        .badge.bg-secondary { background-color: #6c757d !important; } 
        .badge.bg-primary { background-color: #007bff !important; } 
        .badge.bg-info { background-color: #17a2b8 !important; } 
        .detailed-items-cell .badge { margin-left: 5px; font-size: 0.75rem; font-weight: 700; }
        .detailed-items-cell .d-flex { 
            line-height: 1.3; 
            font-size: 0.85rem; 
            /* Added min-width and flex-shrink to ensure item name gets priority space */
            min-height: 1.5em; /* Minimum height for a single line of item text */
        }
        .detailed-items-cell .d-flex span:first-child {
             flex-shrink: 1;
             min-width: 50%;
        }

        /* NEW: Print Header initially hidden */
        .print-header {
            display: none; 
            padding-bottom: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #333; 
            text-align: center;
        }
        .print-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-top: 5px;
            margin-bottom: 5px;
            color: #000;
        }
        .print-header p {
            font-size: 0.8rem;
            margin: 0;
            color: #555;
        }
        .print-header .logo { 
            font-size: 1.1rem;
            font-weight: bold;
            display: block;
        }

        /* --- FINAL PRINT LOGIC: Use CSS to hide sections based on URL parameter --- */
        @media print {
            /* 1. Page and General Reset */
            body, .main-content {
                margin: 0 !important;
                padding: 0 !important;
                background: white !important; /* Force white background for cost-effective printing */
                width: 100%;
                color: #000; /* Ensure black text for maximum contrast */
            }
            
            /* Hide ALL non-report elements */
            .sidebar, .page-header, .filter-form, .print-summary-footer {
                display: none !important;
            }

            /* 2. Professional Print Header (NEW) */
            @page {
                size: A4 portrait;
                margin: 1cm;
            }
            
            /* Add a clean, consistent header for every report print */
            .print-header {
                display: block !important;
                padding-bottom: 10px;
                margin-bottom: 20px;
                border-bottom: 2px solid #333; /* Dark border for professional look */
                text-align: center;
            }
            
            /* 3. Report Section Styling */
            .report-section {
                display: none; /* Default hide */
                border: none !important;
                box-shadow: none !important;
                padding: 0;
                margin: 0;
            }
            .report-section h3 {
                color: #333 !important; /* Use dark color, not MSU-red */
                border-bottom: 1px solid #ccc !important;
                padding-bottom: 5px;
                margin-bottom: 15px;
                font-size: 1.2rem;
                font-weight: 600;
                page-break-after: avoid; /* Keep title with its content */
            }
            
            /* 4. Table Styling for Print */
            .table {
                border-collapse: collapse !important;
                width: 100%;
                margin-bottom: 20px;
                font-size: 0.8rem; /* Smaller font for dense data */
                table-layout: fixed; /* NEW: Force fixed layout for column stability */
            }
            /* Set column widths for better printing */
            .table th:nth-child(1), .table td:nth-child(1) { width: 5%; } /* Form ID */
            .table th:nth-child(2), .table td:nth-child(2) { width: 7%; } /* Student ID */
            .table th:nth-child(3), .table td:nth-child(3) { width: 12%; } /* Borrower Name */
            .table th:nth-child(4), .table td:nth-child(4) { width: 7%; } /* Type */
            .table th:nth-child(5), .table td:nth-child(5) { width: 8%; } /* Status */
            .table th:nth-child(6), .table td:nth-child(6) { width: 8%; } /* Borrow Date */
            .table th:nth-child(7), .table td:nth-child(7) { width: 9%; } /* Expected Return */
            .table th:nth-child(8), .table td:nth-child(8) { width: 9%; } /* Actual Return */
            .table th:nth-child(9), .table td:nth-child(9) { width: 35%; } /* Items Borrowed - MOST SPACE */


            .table thead th {
                background-color: #eee !important; /* Light background for header */
                color: #000 !important;
                border: 1px solid #000 !important;
                padding: 6px !important;
                font-weight: 700 !important;
            }
            .table tbody tr:nth-child(odd) {
                background-color: #f9f9f9 !important; /* Light stripe */
            }
            .table tbody td {
                border: 1px solid #ccc !important;
                padding: 6px !important;
                color: #000 !important;
                vertical-align: top !important; /* Ensure vertical alignment is correct */
            }
            
            /* 5. Stat Box Styling (Summary/Inventory) */
            .report-stat-box {
                background: #f0f0f0 !important;
                border-left: 5px solid #000 !important; /* Black border for cleaner look */
                border: 1px solid #ccc !important;
                padding: 8px 12px !important;
                border-radius: 0 !important; /* Square corners for print */
                page-break-inside: avoid;
            }
            .report-stat-box span {
                font-size: 1rem !important;
                font-weight: 700 !important;
                color: #000 !important; /* Black for high contrast */
            }
            
            /* 6. Detail Item Badges (Use simple text/color) */
            .detailed-items-cell .badge {
                display: inline-block !important;
                background-color: #ccc !important;
                color: #000 !important;
                border: 1px solid #000;
                padding: 2px 5px !important;
                font-size: 0.7rem !important;
                font-weight: 600 !important;
                line-height: 1;
                border-radius: 3px;
                margin-top: 2px;
            }

            /* Borrower status badge */
            .table tbody td .badge {
                background-color: #000 !important;
                color: white !important;
                font-size: 0.75rem !important;
                padding: 3px 6px !important;
                border-radius: 3px;
            }
            
            /* Ensure overdue text is red for warning, but other colors become black/white */
            .text-danger {
                color: #c82333 !important; /* Retain a subtle red for warning */
            }
            .text-warning, .text-info, .text-primary, .text-success, .text-dark {
                color: #000 !important;
            }
            
            /* 7. Conditional Section Display */
            body[data-print-view="summary"] .print-summary,
            body[data-print-view="inventory"] .print-inventory,
            body[data-print-view="detailed"] .print-detailed,
            body[data-print-view="all"] .print-target {
                display: block !important;
            }
            
            /* The Overdue Active Forms section is REMOVED from the HTML now */
        }
    </style>
</head>
<body data-print-view="<?= htmlspecialchars($report_view_type) ?>">

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
        
        <div class="print-header">
            <div class="logo">WMSU CSM LABORATORY APPARATUS BORROWING SYSTEM</div>
            <h1>
            <?php 
                // Dynamically set the main report title based on the selected view type
                if ($report_view_type === 'summary') echo 'Transaction Status Summary Report';
                elseif ($report_view_type === 'inventory') echo 'Apparatus Inventory Stock Report';
                elseif ($report_view_type === 'detailed') echo 'Detailed Transaction History Report';
                else echo 'All Reports Hub View';
            ?>
            </h1>
            <p>Generated by Staff: <?= date('F j, Y, g:i a') ?></p>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-4 print-summary-footer">
            <p class="text-muted mb-0">Report Date: <?= date('F j, Y, g:i a') ?></p>
            <button onclick="handlePrint()" class="btn btn-lg btn-danger btn-print" id="main-print-button">
                <i class="fas fa-print me-2"></i> Print Selected Report
            </button>
        </div>

        <div class="report-section filter-form mb-4">
            <h3><i class="fas fa-filter me-2"></i> Filter Report Data</h3>
            <form method="GET" action="staff_report.php" class="row g-3 align-items-end" id="report-filter-form">
                
                <div class="col-md-3">
                    <label for="report_view_type_select" class="form-label">**Select Report View Type**</label>
                    <select name="report_view_type" id="report_view_type_select" class="form-select">
                        <option value="all" <?= ($report_view_type === 'all') ? 'selected' : '' ?>>Print: All Sections (Hub View)</option>
                        <option value="summary" <?= ($report_view_type === 'summary') ? 'selected' : '' ?>>Print: Transaction Summary Only</option>
                        <option value="inventory" <?= ($report_view_type === 'inventory') ? 'selected' : '' ?>>Print: Apparatus Inventory Only</option>
                        <option value="detailed" <?= ($report_view_type === 'detailed') ? 'selected' : '' ?>>Filter & Print: Detailed History</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="apparatus_id" class="form-label">Specific Apparatus</label>
                    <select name="apparatus_id" id="apparatus_id" class="form-select">
                        <option value="">-- All Apparatus --</option>
                        <?php foreach ($allApparatus as $app): ?>
                            <option 
                                value="<?= htmlspecialchars($app['id']) ?>"
                                <?= ((string)$apparatus_filter_id === (string)$app['id']) ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($app['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="start_date" class="form-label">Start Date (Form Created)</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" 
                            value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="col-md-3">
                    <label for="end_date" class="form-label">End Date (Form Created)</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" 
                            value="<?= htmlspecialchars($end_date) ?>">
                </div>
                
                <div class="col-md-3 mt-3">
                    <label for="form_type_filter" class="form-label">Filter by Form Type</label>
                    <select name="form_type_filter" id="form_type_filter" class="form-select">
                        <option value="">-- All Form Types --</option>
                        <option value="borrow" <?= (strtolower($form_type_filter) === 'borrow') ? 'selected' : '' ?>>Direct Borrow</option>
                        <option value="reserved" <?= (strtolower($form_type_filter) === 'reserved') ? 'selected' : '' ?>>Reservation Request</option>
                    </select>
                </div>
                
                <div class="col-md-3 mt-3">
                    <label for="status_filter" class="form-label">Filter by Status</label>
                    <select name="status_filter" id="status_filter" class="form-select">
                        <option value="">-- All Statuses --</option>
                        <option value="waiting_for_approval" <?= ($status_filter === 'waiting_for_approval') ? 'selected' : '' ?>>Pending Approval</option>
                        <option value="approved" <?= ($status_filter === 'approved') ? 'selected' : '' ?>>Reserved (Approved)</option>
                        <option value="borrowed" <?= ($status_filter === 'borrowed') ? 'selected' : '' ?>>Currently Borrowed</option>
                        <option value="borrowed_reserved" <?= ($status_filter === 'borrowed_reserved') ? 'selected' : '' ?>>All Completed/Active Forms (Exclude Pending/Rejected)</option>
                        <option value="overdue" <?= ($status_filter === 'overdue') ? 'selected' : '' ?>>** Overdue **</option>
                        <option value="returned" <?= ($status_filter === 'returned') ? 'selected' : '' ?>>Returned (On Time)</option>
                        <option value="late_returns" <?= ($status_filter === 'late_returns') ? 'selected' : '' ?>>Returned (LATE)</option>
                        <option value="damaged" <?= ($status_filter === 'damaged') ? 'selected' : '' ?>>Damaged/Lost</option>
                        <option value="rejected" <?= ($status_filter === 'rejected') ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>

                <div class="col-md-6 mt-3 d-flex align-items-end justify-content-end">
                    <button type="submit" class="btn btn-primary me-2">Apply Filters</button>
                    <a href="staff_report.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
            <p class="text-muted small mt-2 mb-0">Note: Filters apply immediately to the **Detailed Transaction History** table below, and are applied when **Detailed History** is selected for printing.</p>
        </div>
        
        <div class="report-section print-summary print-target" id="report-summary">
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
        
        <div class="report-section print-inventory print-target" id="report-inventory">
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

        <div class="report-section print-detailed print-target" id="report-detailed-table">
            <h3><i class="fas fa-history me-2"></i> Detailed Transaction History (Filtered: <?= count($reportForms) ?> Forms)</h3>
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
                        if (!empty($reportForms)): 
                            foreach ($reportForms as $form): ?>
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
                            <tr><td colspan="9" class="text-muted text-center">No transactions match the current filter criteria.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // FINAL JAVASCRIPT LOGIC FOR DYNAMIC PRINTING (Same Page)
    function handlePrint() {
        // Step 1: Get the current selected report view type
        const viewType = document.getElementById('report_view_type_select').value;
        
        // Step 2: Use the view type to set a data attribute on the body tag
        document.body.setAttribute('data-print-view', viewType);
        
        // Step 3: Trigger the standard browser print dialog
        window.print();
        
        // Step 4: IMPORTANT: Reset the view after printing/canceling, preserving filters.
        
        // Get the current URL search parameters
        let currentUrl = new URL(window.location.href);
        
        // Ensure the report_view_type parameter reflects the currently selected option
        currentUrl.searchParams.set('report_view_type', viewType);

        setTimeout(() => {
             document.body.removeAttribute('data-print-view');
             
             // Reload to the current URL, which preserves filters and re-renders the selected report view type
             // The PHP logic will load the page state correctly based on the URL parameters.
             window.location.href = currentUrl.toString();
        }, 100); 
    }

    // Function to update the main button text and hide/show sections on selection change
    function updateHubView() {
        const select = document.getElementById('report_view_type_select');
        const viewType = select.value;
        const printButton = document.getElementById('main-print-button');

        // Update button text
        if (viewType === 'all') {
            printButton.innerHTML = '<i class="fas fa-print me-2"></i> Print All Reports (Screen View)';
        } else if (viewType === 'summary') {
            printButton.innerHTML = '<i class="fas fa-print me-2"></i> Print Transaction Summary';
        } else if (viewType === 'inventory') {
            printButton.innerHTML = '<i class="fas fa-print me-2"></i> Print Apparatus Inventory';
        } else if (viewType === 'detailed') {
            printButton.innerHTML = '<i class="fas fa-print me-2"></i> Print Filtered History';
        }

        // Dynamically hide/show sections in the Hub View
        const isSummaryView = (viewType === 'all' || viewType === 'summary');
        const isInventoryView = (viewType === 'all' || viewType === 'inventory');
        const isHistoryView = (viewType === 'all' || viewType === 'detailed');

        document.getElementById('report-summary').style.display = isSummaryView ? 'block' : 'none';
        document.getElementById('report-inventory').style.display = isInventoryView ? 'block' : 'none';
        
        // Detailed History visibility
        document.getElementById('report-detailed-table').style.display = isHistoryView ? 'block' : 'none';
        
        // NOTE: #report-overdue visibility check removed as the element is physically deleted.
    }


    document.addEventListener('DOMContentLoaded', () => {
        // Highlight active link
        const links = document.querySelectorAll('.sidebar .nav-link');
        links.forEach(link => {
            link.classList.remove('active');
        });
        const reportsLink = document.querySelector('a[href="staff_report.php"]');
        if (reportsLink) {
            reportsLink.classList.add('active');
        }
        
        // Set initial view state based on the URL parameter (which PHP handles)
        updateHubView();

        // Attach event listener for dynamic changes
        const select = document.getElementById('report_view_type_select');
        select.addEventListener('change', updateHubView);
    });
</script>
</body>
</html>
