<?php
session_start();
require_once "../classes/Database.php";
require_once "../classes/Transaction.php";

// Use DateTime for date comparisons
$today = new DateTime(); 
$today->setTime(0, 0, 0); // Normalize $today to midnight for comparison with date-only fields

if (!isset($_SESSION["user"]) || $_SESSION["user"]["role"] != "staff") {
    header("Location: ../login_signup/login.php");
    exit();
}

$transaction = new Transaction();
$message = "";
$is_success = false; // Flag to check if the action was successful


if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $form_id = $_POST["form_id"];
    $remarks = $_POST["staff_remarks"] ?? ''; 

    if (isset($_POST["approve"])) {
        // --- CRITICAL FIX APPLIED HERE ---
        $result = $transaction->approveForm($form_id, $_SESSION["user"]["id"], $remarks);
        
        if ($result === true) {
            $message = "✅ Borrow request approved successfully! Items marked as borrowed.";
            $is_success = true;
        } elseif ($result === 'stock_mismatch_on_approval') {
            $message = "❌ Approval Failed: Stock was depleted before approval could be finalized. Please review the item availability.";
            $is_success = false;
        } else {
            // Catches general database rollback/failure (return false)
            $message = "❌ Approval Failed: A database error occurred during finalization.";
            $is_success = false;
        }
        // --- END CRITICAL FIX ---
        
    } elseif (isset($_POST["reject"])) {
        $transaction->rejectForm($form_id, $_SESSION["user"]["id"], $remarks);
        $message = "Borrow request rejected.";
        $is_success = true;
    } elseif (isset($_POST["approve_return"])) {
        // Action for on-time return 
        $result = $transaction->confirmReturn($form_id, $_SESSION["user"]["id"], $remarks);
        if ($result === true) {
            $message = "✅ Return verified and marked as returned.";
            $is_success = true;
        } else {
            $message = "❌ Failed to confirm return due to a database error.";
            $is_success = false;
        }

    } elseif (isset($_POST["confirm_late_return"])) {
        // NEW ACTION: Confirm late return (sets is_late_return = TRUE)
        $form_data = $transaction->getBorrowFormById($form_id); 
        
        if ($form_data) {
            $expected_return_date = new DateTime($form_data["expected_return_date"]);
            $expected_return_date->setTime(0, 0, 0); 
            
            if ($expected_return_date < $today) { 
                $result = $transaction->confirmLateReturn($form_id, $_SESSION["user"]["id"], $remarks);
                if ($result === true) {
                    $message = "✅ Late return confirmed and status finalized as RETURNED (Penalty Applied).";
                    $is_success = true;
                } else {
                    $message = "❌ Failed to confirm late return due to a database error.";
                    $is_success = false;
                }
            } else {
                $message = "❌ Error: Cannot manually mark as LATE RETURN before the expected return date.";
                $is_success = false;
            }
        } else {
             $message = "❌ Error: Form ID not found.";
             $is_success = false;
        }
    } elseif (isset($_POST["reject_return"])) {
        $message = "Return rejection not implemented in logic.";
        $is_success = false;
    } elseif (isset($_POST["mark_damaged"])) {
        // FIX: This now captures the specific UNIT ID selected by the staff
        $unit_id = $_POST["damaged_unit_id"] ?? null; 
        
        // FIX: Pass the specific UNIT ID to the Transaction method
        if ($unit_id) {
            $result = $transaction->markAsDamaged($form_id, $_SESSION["user"]["id"], $remarks, $unit_id);
            if ($result === true) {
                $message = "✅ Marked as returned with issues. Damaged unit status updated.";
                $is_success = true;
            } else {
                $message = "❌ Failed to mark as damaged due to a database error.";
                $is_success = false;
            }
        } else {
             // This case should ideally be caught by JS client-side validation now
             $message = "❌ Error: Please select a specific item unit to mark as damaged.";
             $is_success = false;
        }

    } elseif (isset($_POST["manually_mark_overdue"])) {
        // Action for marking a missing item as overdue (sets status = 'overdue')
        $form_data = $transaction->getBorrowFormById($form_id); 
        
        if ($form_data) {
            $expected_return_date = new DateTime($form_data["expected_return_date"]);
            $expected_return_date->setTime(0, 0, 0); 
            
            if ($expected_return_date < $today) {
                $result = $transaction->markAsOverdue($form_id, $_SESSION["user"]["id"], $remarks);
                if ($result === true) {
                    $message = "✅ Marked as overdue (Units restored & ban checked).";
                    $is_success = true;
                } else {
                    $message = "❌ Failed to mark as overdue due to a database error.";
                    $is_success = false;
                }
            } else {
                $message = "❌ Error: Cannot manually mark as overdue before the expected return date.";
                $is_success = false;
            }
        } else {
             $message = "❌ Error: Form ID not found.";
             $is_success = false;
        }
    }

    // Redirect to clear POST data and show the message via GET or Session 
    $_SESSION['status_message'] = $message;
    $_SESSION['is_success'] = $is_success;
    header("Location: staff_pending.php");
    exit;
}


if (isset($_SESSION['status_message'])) {
    $message = $_SESSION['status_message'];
    $is_success = $_SESSION['is_success'] ?? false;
    unset($_SESSION['status_message']);
    unset($_SESSION['is_success']);
}

// Fetch all pending or checking forms
$pendingForms = $transaction->getPendingForms();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Pending Forms</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>

        :root {
            --msu-red: #b8312d; 
            --msu-red-dark: #a82e2a; 
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
            border-bottom: 2px solid var(--msu-red);
            padding-bottom: 10px;
            margin-bottom: 25px;
            font-weight: 600;
        }
        
        
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
        
        
        td form {
            margin: 0;
            padding: 0;
            /* Changed to inline-block to prevent button row breaking */
            display: inline-block; 
        }
        textarea, select {
            width: 95%;
            margin: 5px 0;
            resize: none;
            font-size: 0.85rem;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
        td.remarks-cell {
            min-width: 180px; 
            text-align: left;
        }
        td.actions-cell {
            min-width: 150px;
        }
        
        
        .btn-group-vertical {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-top: 5px;
        }
        .btn {
            padding: 6px 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            color: white;
            transition: background 0.2s;
        }
        .btn.approve { background: #28a745; }
        .btn.reject { background: #dc3545; }
        .btn.return { background: #17a2b8; }
        .btn.warning { background: #ffc107; color: black; }
        .btn.secondary { background: #6c757d; }

        .btn.approve:hover { background: #1e7e34; }
        .btn.reject:hover { background: #c82333; }
        .btn.return:hover { background: #138496; }
        .btn.warning:hover { background: #e0a800; }
        .btn.secondary:hover { background: #5a6268; }

        .status-tag {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 700;
            text-transform: capitalize;
            font-size: 0.75rem;
        }
        .status-tag.waiting_for_approval { background-color: #ffc10740; color: #b8860b; }
        .status-tag.checking { background-color: #007bff30; color: #0056b3; }
        
        
        .alert {
            opacity: 1;
            transition: opacity 0.5s ease-out;
        }
        .alert.hidden {
            opacity: 0;
            height: 0;
            padding-top: 0;
            padding-bottom: 0;
            margin-bottom: 0;
            border: none;
        }
        
        /* Modal Custom Style for Warning */
        #lateReturnModal .modal-header, #requiredUnitSelectModal .modal-header {
            background-color: #ffc107; /* Warning yellow */
            color: #333;
            border-bottom: none;
        }
        #lateReturnModal .modal-title, #requiredUnitSelectModal .modal-title {
            font-weight: bold;
        }
        #lateReturnModal .modal-body, #requiredUnitSelectModal .modal-body {
            color: #666;
        }
        #lateReturnModal .btn-danger, #requiredUnitSelectModal .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
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
        <a class="nav-link active" href="staff_pending.php">
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
            <i class="fas fa-hourglass-half fa-fw me-2 text-secondary"></i> Pending Requests & Returns
        </h2>

        <?php if (!empty($message)): ?>
            <div id="status-alert" class="alert <?= $is_success ? 'alert-success' : ((strpos($message, '❌') !== false) ? 'alert-danger' : 'alert-warning') ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th>Form ID</th>
                        <th>Student Details</th> 
                        <th>Apparatus (First Item)</th>
                        <th><i class="fas fa-info-circle"></i> Status</th>
                        <th>Borrow Date</th>
                        <th>Expected Return</th>
                        <th>Actual Return</th>
                        <th>Student Remarks</th> <th>Staff Remarks</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($pendingForms)): ?>
                    <?php foreach ($pendingForms as $form): 
                        $clean_status = strtolower($form["status"]);
                        $display_status = ucfirst(str_replace('_', ' ', $clean_status));
                        
                        // Fetch the full form details to get the student's remarks 
                        // Note: This fetches the full row, including the staff_remarks column (used to store student remarks in 'checking' status)
                        $full_form_data = $transaction->getBorrowFormById($form["id"]); // Assuming getBorrowFormById is the correct method
                        
                        // FIX 1: Display student remarks by pulling from the staff_remarks column (since there is no student_remarks column).
                        $student_remarks = ($clean_status === 'checking') ? 
                                                     ($full_form_data['staff_remarks'] ?? '-') : 
                                                     'N/A';
                        
                        // Fetch unit-level items for the "Mark Damaged" dropdown
                        $items = $transaction->getTransactionItems($form["id"]);
                        
                        // Check if item is overdue (for button logic)
                        $today_dt = new DateTime();
                        $today_dt->setTime(0, 0, 0); // *** CRITICAL: Normalized $today to midnight ***

                        $expected_return = new DateTime($form["expected_return_date"]);
                        $expected_return->setTime(0, 0, 0); // Normalized Expected Return Date to midnight
                        
                        // CRITICAL: The item is past due if expected date is BEFORE today.
                        $is_currently_overdue = ($expected_return < $today_dt);
                        
                        // NEW LOGIC: Is the item past the 1-day grace period? (i.e., today is >= Expected Return Date + 2 days)
                        $grace_period_end_date = (clone $expected_return)->modify('+1 day'); 
                        $is_ban_eligible_now = ($today_dt > $grace_period_end_date); // True if today is strictly AFTER the grace period end (2 days past due)


                    ?>
                        <tr>
                            <td><?= htmlspecialchars($form["id"]) ?></td>
                            <td class="text-start">
                                <strong><?= htmlspecialchars($form["firstname"] ?? '') ?> <?= htmlspecialchars($form["lastname"] ?? '') ?></strong>
                                <br>
                                <small class="text-muted">(ID: <?= htmlspecialchars($form["borrower_id"]) ?>)</small>
                            </td>
                            <td><?= htmlspecialchars($form["apparatus_list"] ?? '-') ?></td> 
                            <td>
                                <span class="status-tag <?= $clean_status ?>">
                                    <?= $display_status ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($form["borrow_date"] ?? '-') ?></td>
                            <td><?= htmlspecialchars($form["expected_return_date"] ?? '-') ?></td>
                            <td><?= htmlspecialchars($form["actual_return_date"] ?? '-') ?></td>
                            
                            <td class="remarks-cell text-start">
                                <?= htmlspecialchars($student_remarks) ?>
                            </td>

                            <form method="POST" class="pending-form" data-form-id="<?= htmlspecialchars($form["id"]) ?>">
                                <td class="remarks-cell">
                                    <?php if ($clean_status == "checking" || $clean_status == "waiting_for_approval"): ?>
                                             <textarea name="staff_remarks" rows="2" placeholder="Enter staff remarks..."></textarea>
                                    <?php else: ?>
                                             -
                                    <?php endif; ?>
                                    <input type="hidden" name="form_id" value="<?= htmlspecialchars($form["id"]) ?>">
                                    <input type="hidden" name="action_type" value=""> 
                                    
                                    <?php if ($clean_status == "checking"): ?>
                                        <div class="mt-2 text-start">
                                            <label for="damaged_unit_id_<?= $form['id'] ?>" class="fw-bold mb-1">Mark Damaged Unit:</label>
                                            <select name="damaged_unit_id" id="damaged_unit_id_<?= $form['id'] ?>" class="form-select-sm">
                                                <option value="">-- None / All Good --</option>
                                                <?php
                                                // FIX: Iterate through unit-level items. The Transaction method now returns 'unit_id' and 'name'.
                                                foreach ($items as $item):
                                                ?>
                                                    <option value="<?= htmlspecialchars($item["unit_id"]) ?>">
                                                        <?= htmlspecialchars($item["name"]) ?> (Unit ID: <?= htmlspecialchars($item["unit_id"]) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="actions-cell">
                                    <div class="btn-group-vertical">
                                    <?php if ($clean_status == "waiting_for_approval"): ?>
                                        <button type="submit" name="approve" class="btn approve">Approve</button>
                                        <button type="submit" name="reject" class="btn reject">Reject</button>

                                    <?php elseif ($clean_status == "checking"): ?>
                                            
                                        <?php if ($is_currently_overdue): ?>
                                            <button type="button" 
                                                    class="btn secondary late-return-btn" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#lateReturnModal"
                                                    data-form-id="<?= htmlspecialchars($form["id"]) ?>">
                                                    Confirm LATE Return
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" name="approve_return" class="btn approve">Mark Returned (Good)</button>
                                        <?php endif; ?>

                                        <button type="submit" name="mark_damaged" id="mark_damaged_btn_<?= $form['id'] ?>" class="btn warning mark-damaged-btn">Returned with Issues</button>

                                    <?php elseif ($clean_status == "borrowed" && $is_ban_eligible_now): ?>
                                        <button type="button" 
                                                class="btn reject overdue-btn"
                                                data-bs-toggle="modal" 
                                                data-bs-target="#overdueModal"
                                                data-form-id="<?= htmlspecialchars($form["id"]) ?>">
                                                Manually Mark OVERDUE
                                        </button>
                                            
                                    <?php else: ?>
                                        <button type="button" class="btn secondary" disabled>No Action Needed</button>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </form>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="10">No pending or checking forms found.</td></tr> <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="lateReturnModal" tabindex="-1" aria-labelledby="lateReturnModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lateReturnModalLabel"><i class="fas fa-exclamation-triangle me-2"></i> WARNING: LATE RETURN</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                This item is **LATE**. Confirming LATE RETURN will:
                <ul>
                    <li>Mark the item status as **RETURNED**.</li>
                    <li>**Clear** any existing student bans related to this transaction.</li>
                    <li>Set the internal **LATE RETURN flag** for penalty tracking.</li>
                </ul>
                <p class="text-danger fw-bold mb-0">Please ensure the items are accounted for before proceeding.</p>
                <input type="hidden" id="modal_late_return_form_id">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmLateReturnBtn">Confirm LATE RETURN</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="overdueModal" tabindex="-1" aria-labelledby="overdueModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="overdueModalLabel"><i class="fas fa-exclamation-circle me-2"></i> MANUAL OVERDUE WARNING</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                You are about to **MANUALLY MARK** this transaction as **OVERDUE**. This action:
                <ul>
                    <li>Applies a **ban** to the borrowing student.</li>
                    <li>**Restores units** to the inventory (assuming loss/missing).</li>
                    <li>Is typically used ONLY for **missing items** that were not returned past the due date.</li>
                </ul>
                <p class="text-danger fw-bold mb-0">Use this action with extreme caution.</p>
                <input type="hidden" id="modal_overdue_form_id">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmOverdueBtn">Mark as OVERDUE</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="requiredUnitSelectModal" tabindex="-1" aria-labelledby="requiredUnitSelectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="requiredUnitSelectModalLabel"><i class="fas fa-hand-paper me-2"></i> Action Required</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <p class="fw-bold text-danger">You must select a specific apparatus unit from the dropdown to mark as 'Returned with Issues'.</p>
                <p class="text-muted">If all returned items are in good condition, please use the 'Mark Returned (Good)' button.</p>
                <input type="hidden" id="form_to_submit_after_error_fix">
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-warning" data-bs-dismiss="modal">OK, I Understand</button>
            </div>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- Auto-hide message functionality ---
        const messageAlert = document.getElementById('status-alert');
        if (messageAlert && messageAlert.classList.contains('alert-success')) {
            setTimeout(() => {
                messageAlert.remove(); 
            }, 2000); 
        }

        // --- Sidebar active link script ---
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
        
        // --- IMPORTANT: FORM SUBMISSION VALIDATION (Mark Damaged) ---
        // Create an instance of the modal
        const requiredSelectModal = new bootstrap.Modal(document.getElementById('requiredUnitSelectModal'));

        document.querySelectorAll('form.pending-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                // Find the submitted button
                const submittingButton = e.submitter;
                
                // Check if the submitted button is the 'mark_damaged' button
                if (submittingButton && submittingButton.name === 'mark_damaged') {
                    const formId = this.getAttribute('data-form-id');
                    const damagedUnitSelect = document.getElementById(`damaged_unit_id_${formId}`);
                    
                    if (damagedUnitSelect.value === "" || damagedUnitSelect.value === null) {
                        e.preventDefault(); // Stop the form submission
                        
                        // Show the dedicated error modal
                        requiredSelectModal.show();
                    }
                    // If a unit is selected (value is not "" or null), the form proceeds normally
                }
            });
        });
        
        // --- Modal Logic for LATE RETURN ---
        const lateReturnModal = document.getElementById('lateReturnModal');
        if (lateReturnModal) {
            lateReturnModal.addEventListener('show.bs.modal', function (event) {
                // Button that triggered the modal
                const button = event.relatedTarget;
                const formId = button.getAttribute('data-form-id');
                
                // Set the form ID in the hidden input of the modal
                const modalFormIdInput = lateReturnModal.querySelector('#modal_late_return_form_id');
                modalFormIdInput.value = formId;
            });
            
            document.getElementById('confirmLateReturnBtn').addEventListener('click', function() {
                const formId = document.getElementById('modal_late_return_form_id').value;
                
                // Find the correct form to submit based on the form ID
                const formToSubmit = document.querySelector(`form.pending-form[data-form-id="${formId}"]`);
                
                if (formToSubmit) {
                    // Add the action button's name as a hidden input to the form before submitting
                    const lateReturnActionInput = document.createElement('input');
                    lateReturnActionInput.type = 'hidden';
                    lateReturnActionInput.name = 'confirm_late_return';
                    lateReturnActionInput.value = '1';
                    formToSubmit.appendChild(lateReturnActionInput);
                    
                    formToSubmit.submit();
                }
            });
        }
        
        // --- Modal Logic for MANUAL OVERDUE ---
        const overdueModal = document.getElementById('overdueModal');
        if (overdueModal) {
            overdueModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const formId = button.getAttribute('data-form-id');
                
                const modalFormIdInput = overdueModal.querySelector('#modal_overdue_form_id');
                modalFormIdInput.value = formId;
            });
            
            document.getElementById('confirmOverdueBtn').addEventListener('click', function() {
                const formId = document.getElementById('modal_overdue_form_id').value;
                
                // Find the correct form to submit based on the form ID
                const formToSubmit = document.querySelector(`form.pending-form[data-form-id="${formId}"]`);
                
                if (formToSubmit) {
                    // Add the action button's name as a hidden input to the form before submitting
                    const overdueActionInput = document.createElement('input');
                    overdueActionInput.type = 'hidden';
                    overdueActionInput.name = 'manually_mark_overdue';
                    overdueActionInput.value = '1';
                    formToSubmit.appendChild(overdueActionInput);
                    
                    formToSubmit.submit();
                }
            });
        }
    });
</script>
</body>
</html>