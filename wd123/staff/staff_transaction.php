<?php
session_start();
// Include the Transaction class (now BCNF-compliant)
require_once "../classes/Transaction.php";

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'staff') {
    header("Location: ../pages/login.php");
    exit;
}

$transaction = new Transaction();

// --- CRITICAL FIX 1: Read the desired filter and search term ---
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

// 1. Data Retrieval (Assuming getAllFormsFiltered accepts these parameters)
$transactions = $transaction->getAllFormsFiltered($filter, $search);

// Helper function for Item Status in cells (Moved here for self-contained execution)
function getFormItemsText($form_id, $transaction) {
    // We assume getFormItems returns the detailed unit-level status
    $items = $transaction->getFormItems($form_id); 
    if (empty($items)) return '<span class="text-muted">N/A</span>';
    $output = '';
    
    foreach ($items as $item) {
        $name = htmlspecialchars($item['name'] ?? 'Unknown');
        $item_status = strtolower($item['item_status']);
        $tag_class = $item_status;
        $tag_text = ucfirst(str_replace('_', ' ', $item_status));
        
        // --- MONOCHROME DAMAGE FIX APPLIED HERE (Item Status) ---
        if ($item_status === 'damaged') {
             $tag_class = 'damaged'; // Uses the new dark gray style
             $tag_text = 'Damaged';
        } elseif ($item_status === 'returned' && strtolower($item['form_status'] ?? '') === 'returned-late') {
             $tag_class = 'returned-late'; // Red for late return
             $tag_text = 'Returned (Late)';
        } elseif ($item_status === 'returned') {
             $tag_class = 'returned'; // Green for normal return
             $tag_text = 'Returned';
        }
        
        $output .= '<div class="d-flex align-items-center justify-content-between mb-1">';
        $output .= '    <span class="me-2">' . $name . ' (x' . ($item['quantity'] ?? 1) . ')</span>';
        $output .= '    <span class="status-tag ' . $tag_class . '">' . $tag_text . '</span>';
        $output .= '</div>';
    }
    return $output;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - Staff</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        
        :root {
            --msu-red: #b8312d;
            --msu-red-dark: #a82e2a; 
            --msu-blue: #007bff;
            --sidebar-width: 280px; 
            --student-logout-red: #dc3545; /* Added this definition for consistency */
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
            position: fixed; 
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2);
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

        .sidebar-header img { max-width: 90px; height: auto; margin-bottom: 15px; }
        .sidebar-header .title { font-size: 1.3rem; line-height: 1.1; }
        .sidebar-nav { flex-grow: 1; }
        .sidebar-nav .nav-link { color: white; padding: 15px 20px; font-size: 1rem; font-weight: 600; transition: background-color 0.2s; }
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
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        
        .page-header {
            color: #333; 
            border-bottom: 2px solid var(--msu-red);
            padding-bottom: 10px;
            margin-bottom: 25px;
            font-weight: 600;
        }
        
        
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-top: 20px;
        }
        .table thead th {
            background: var(--msu-red);
            color: white;
            font-weight: 600;
            vertical-align: middle;
            text-align: center;
            font-size: 0.9rem;
        }
        .table tbody td {
            vertical-align: middle;
            font-size: 0.9rem;
            text-align: center;
        }
        
        
        td.apparatus-list-cell {
            text-align: left;
            font-size: 0.85rem;
            line-height: 1.4;
        }
        
        /* --- STATUS TAGS & COLORS (PROFESSIONAL FIX) --- */
        .status-tag {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 700;
            text-transform: capitalize;
            font-size: 0.75rem;
            white-space: nowrap;
            margin-left: 5px; 
        }

        /* MONOCHROME FIX: Dark Gray/Black for Damaged (Form Status and Item Status) */
        .status-tag.damaged { 
            background-color: #343a40 !important; /* Dark Gray/Black */
            color: white !important; 
            font-weight: 800; 
        }

        /* Standard Colors (Adjusted to match Report theme: lighter backgrounds, dark text) */
        .status-tag.waiting_for_approval, .status-tag.pending { background-color: #ffc10730; color: #b8860b; }
        .status-tag.approved, .status-tag.borrowed, .status-tag.checking { background-color: #007bff30; color: #007bff; }
        .status-tag.rejected { background-color: #6c757d30; color: #6c757d; }
        .status-tag.returned { background-color: #28a74530; color: #28a745; }
        .status-tag.overdue, .status-tag.returned-late { background-color: #dc354530; color: #dc3545; border: 1px solid #dc3545; }
        
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
        <a class="nav-link active" href="staff_transaction.php">
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
            <i class="fas fa-list-alt fa-fw me-2 text-secondary"></i> All Transactions History
        </h2>

        <form method="GET" class="mb-3" id="transactionFilterForm">
            <div class="d-flex flex-wrap align-items-center justify-content-between">
                
                <div class="d-flex align-items-center mb-2 mb-md-0">
                    <label class="form-label me-2 mb-0 fw-bold text-secondary">Filter by Status:</label>
                    <select name="filter" id="statusFilter" class="form-select form-select-sm w-auto">
                        <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="waiting_for_approval" <?= $filter === 'waiting_for_approval' ? 'selected' : '' ?>>Waiting for Approval</option>
                        <option value="borrowed" <?= $filter === 'borrowed' ? 'selected' : '' ?>>Borrowed</option>
                        <option value="reserved" <?= $filter === 'reserved' ? 'selected' : '' ?>>Reserved</option>
                        <option value="returned" <?= $filter === 'returned' ? 'selected' : '' ?>>Returned</option>
                        <option value="overdue" <?= $filter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                        <option value="rejected" <?= $filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        <option value="damaged" <?= $filter === 'damaged' ? 'selected' : '' ?>>Damaged</option>
                    </select>
                </div>

                <div class="d-flex align-items-center">
                    <input type="text" name="search" class="form-control form-control-sm me-2" placeholder="Search student/apparatus..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-search"></i>
                    </button>
                </div>

            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th>Form ID</th>
                        <th>Student Details</th> <th>Type</th>
                        <th>Status</th>
                        <th>Borrow Date</th>
                        <th>Expected Return</th>
                        <th>Actual Return</th>
                        <th>Apparatus (Item & Status)</th> <th>Staff Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($transactions)): ?>
                        <?php foreach ($transactions as $trans): 
                            // Fetch the detailed, unit-level item list
                            // NOTE: We assume getFormItems returns the 'form_status' key for helper logic below
                            $detailed_items = $transaction->getFormItems($trans['id']); 
                            $clean_status = strtolower($trans['status']);
                            
                            // Determine the final status class and text for the main form status
                            $status_class = $clean_status;
                            $display_status_text = ucfirst(str_replace('_', ' ', $trans['status']));

                            // 1. Handle LATE RETURN: Overrides 'returned' class and text
                            if ($trans['status'] === 'returned' && (isset($trans['is_late_return']) && $trans['is_late_return'] == 1)) {
                                $status_class = 'returned-late'; // Custom class for coloring/border
                                $display_status_text = 'Returned (LATE)';
                            } 
                            // 2. Handle DAMAGED: Use the monochrome dark class
                            elseif ($trans['status'] === 'damaged') {
                                $status_class = 'damaged'; 
                            }
                        ?>
                        <tr>
                            <td class="fw-bold"><?= $trans['id'] ?></td>
                            <td class="text-start">
                                <strong><?= htmlspecialchars($trans['firstname'] ?? '') ?> <?= htmlspecialchars($trans['lastname'] ?? '') ?></strong>
                                <br>
                                <small class="text-muted">(ID: <?= htmlspecialchars($trans['user_id']) ?>)</small>
                            </td>
                            <td><?= ucfirst($trans['form_type']) ?></td>
                            
                            <td>
                                <span class="status-tag <?= $status_class ?>">
                                    <?= $display_status_text ?>
                                </span>
                            </td>
                            <td><?= $trans['borrow_date'] ?: '-' ?></td>
                            <td><?= $trans['expected_return_date'] ?: '-' ?></td>
                            <td><?= $trans['actual_return_date'] ?: '-' ?></td>
                            
                            <td class="apparatus-list-cell">
                                <?php 
                                // Pass the main form status to the helper function for item-level status logic
                                $form_status_for_helper = $trans['status'];
                                
                                foreach ($detailed_items as $it): 
                                    $name = htmlspecialchars($it['name'] ?? 'Unknown');
                                    $item_status = strtolower($it['item_status']);
                                    
                                    $tag_class = $item_status;
                                    $tag_text = ucfirst(str_replace('_', ' ', $item_status));
                                    
                                    // Apply MONOCHROME and custom coloring based on item status
                                    if ($item_status === 'damaged') {
                                         $tag_class = 'damaged'; 
                                         $tag_text = 'Damaged';
                                    } elseif ($item_status === 'returned') {
                                         // Check for LATE RETURN status consistency
                                         if ($form_status_for_helper === 'returned' && (isset($trans['is_late_return']) && $trans['is_late_return'] == 1)) {
                                             $tag_class = 'returned-late'; // Red for late return
                                             $tag_text = 'Returned (Late)';
                                         } else {
                                             $tag_class = 'returned'; // Green for normal return
                                             $tag_text = 'Returned';
                                         }
                                    }
                                ?>
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span class="me-2"><?= $name ?> (x<?= $it['quantity'] ?? 1 ?>)</span>
                                        <span class="status-tag <?= $tag_class ?>">
                                            <?= $tag_text ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td><?= htmlspecialchars($trans['staff_remarks'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="9" class="text-muted py-3">No transactions found matching the selected filter or search term.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Script to ensure the correct link remains active
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
        
        // --- FIX: JavaScript to submit the form when the filter changes, including the search term ---
        const statusFilter = document.getElementById('statusFilter');
        const form = document.getElementById('transactionFilterForm');

        if (statusFilter && form) {
            statusFilter.addEventListener('change', function() {
                // This submits the entire form, ensuring both 'filter' and 'search' inputs are sent via GET
                form.submit();
            });
        }
        // ------------------------------------------------------------------------------------------
    });
</script>
</body>
</html>