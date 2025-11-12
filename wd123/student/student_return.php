<?php
session_start();
require_once "../classes/Transaction.php";
require_once "../classes/Database.php";

if (!isset($_SESSION["user"]) || $_SESSION["user"]["role"] != "student") {
    header("Location: ../pages/login.php");
    exit();
}

$transaction = new Transaction();
$student_id = $_SESSION["user"]["id"];
$message = "";
$is_success = false;

// --- BAN CHECK for sidebar display ---
$isBanned = $transaction->isStudentBanned($student_id);
// -------------------------------------

// When student clicks “Return”
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["return"])) {
    $form_id = $_POST["form_id"];
    $remarks = $_POST["remarks"] ?? "";
    
    // Uses the internal method to fetch form data from the single borrow_forms table
    $form_data = $transaction->getBorrowFormById($form_id); 
    $borrowDate = $form_data['borrow_date'];

    if ($borrowDate <= date("Y-m-d")) {
        // Uses the internal method to mark as checking (BCNF-compliant method)
        if ($transaction->markAsChecking($form_id, $student_id, $remarks)) {
            $message = "Your return request (ID: $form_id) has been submitted and is pending staff verification.";
            $is_success = true;
        } else {
            // --- FIX: Improved Error Message Block ---
            $form_status_check = $transaction->getBorrowFormById($form_id);
            $current_status = $form_status_check['status'] ?? 'Unknown/Missing';
            
            // This message is clearer about why submission failed: either already returned/rejected, 
            // or the database transaction failed for unknown reasons.
            $message = "Failed to submit return request for ID $form_id. Current database status is **{$current_status}**. The item may be fully returned, rejected, or already pending check.";
            $is_success = false;
            // --- END FIX ---
        }
    } else {
        $message = "Cannot submit return yet. The borrow date ($borrowDate) for this request is in the future.";
        $is_success = false;
    }
}

// FIX: Fetch all relevant active/pending forms, including 'overdue' and 'checking'
$activeForms = $transaction->getStudentFormsByStatus($student_id, 'borrowed,approved,reserved,overdue,checking');
$today = date("Y-m-d"); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Initiate Apparatus Return</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    
    <style>
        /* Custom Variables and Base Layout (MSU Theme) */
        :root {
            --msu-red: #b8312d; 
            --msu-red-dark: #a82e2a; 
            --sidebar-width: 280px; 
            --bg-light: #f5f6fa;
            --danger-dark: #8b0000;
        }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f5f6fa; 
            padding: 0;
            margin: 0;
            display: flex;
            min-height: 100vh;
        }

        /* === SIDEBAR STYLES (Consistent Look) === */
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
            line-height: 1.15; /* Ensures consistent vertical spacing */
            color: #fff;
            border-bottom: 1px solid rgba(255, 255, 255, 0.4);
            margin-bottom: 20px;
        }
        .sidebar-header img { max-width: 90px; height: auto; margin-bottom: 15px; }
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
        /* Style for banned links (Visual only, allows navigation) */
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
        /* END SIDEBAR FIXES */
        
        /* === MAIN CONTENT STYLES === */
        .main-wrapper {
            margin-left: var(--sidebar-width); 
            padding: 20px;
            flex-grow: 1;
        }
        .container {
            max-width: none; /* MODIFIED: Removed fixed maximum width */
            width: 95%;     /* MODIFIED: Set to occupy most of the available space */
            margin: 0 auto;
            background: white;
            padding: 30px 40px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        h2 { 
            text-align: left; 
            color: #333; 
            margin-bottom: 25px;
            border-bottom: 2px solid var(--msu-red);
            padding-bottom: 10px;
            font-size: 2rem;
            font-weight: 600;
        }

        /* === UI IMPROVEMENT STYLES === */
        .form-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            padding: 0;
            list-style: none;
            margin-top: 20px;
        }
        
        .return-card {
            background-color: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 15px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            display: flex; 
            align-items: center; 
            gap: 15px; 
        }

        .return-card-checking {
            border-left: 5px solid #ffc107;
        }
        .return-card-borrowed, .return-card-approved { 
            border-left: 5px solid #007bff;
        }
        .return-card-overdue {
            border-left: 5px solid var(--danger-dark);
            background-color: #fff4f4;
        }

        /* Image styling */
        .apparatus-thumbnail {
            width: 70px; 
            height: 70px;
            object-fit: contain; 
            border-radius: 8px;
            border: 1px solid #eee;
            background-color: #fcfcfc;
            padding: 5px;
            flex-shrink: 0; 
        }

        .card-col-info { 
            flex-grow: 1; 
            text-align: left; 
        }
        .card-col-action { 
            flex-shrink: 0; 
            /* MODIFIED: Increased width to accommodate larger button */
            width: 400px; 
            display: flex; 
            align-items: center; 
            justify-content: flex-end; 
            gap: 10px;
        }

        .app-list { 
            font-size: 0.95rem; 
            margin-top: 5px;
            color: #555;
        }
        .date-info span {
            display: inline-block;
            margin-right: 15px;
            font-size: 0.85rem;
            color: #6c757d;
        }
        .date-info .expected-return {
            color: var(--danger-dark);
            font-weight: 600;
        }
        .date-info .expected-return.ok {
            color: #007bff; /* Blue for future/today */
        }


        /* Action elements within the card */
        /* MODIFIED: Stretch action items vertically */
        .action-container {
            display: flex;
            gap: 10px;
            align-items: stretch; 
            width: 100%;
            max-width: 450px;
        }
        .action-container textarea {
            flex-grow: 1;
            min-height: 40px;
            max-height: 40px;
            resize: none;
            font-size: 0.85rem;
            border-radius: 6px;
        }
        .btn-return { 
            width: 120px; 
            padding: 8px 12px; 
            background: #28a745; 
            color: white; 
            font-weight: 600;
            font-size: 0.9rem;
            border-radius: 6px;
            border: none;
        }
        .btn-return:hover:not(:disabled) { 
            background: #1e7e34; 
            color: white;
        }
        .btn-return:disabled { 
            background: #adb5bd; 
            cursor: not-allowed; 
        }
        
        .action-message-checking {
            display: inline-block;
            padding: 8px 15px;
            font-weight: 600;
            font-size: 0.85rem;
            border-radius: 6px;
            background: #ffc107;
            color: #333;
            flex-grow: 1; 
            text-align: center;
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
            <a href="student_return.php" class="nav-link active">
                <i class="fas fa-redo fa-fw me-2"></i> Initiate Return
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

<div class="main-wrapper">
    <div class="container">
        <h2><i class="fas fa-redo fa-fw me-2"></i> Initiate Apparatus Return</h2>
        <p class="lead text-start">Select items currently borrowed or reserved that you are ready to return to staff.</p>
        
        <?php if (!empty($message)): ?>
            <div id="status-alert" class="alert <?= $is_success ? 'alert-success' : 'alert-warning' ?> fade show" role="alert">
                <?= $is_success ? '<i class="fas fa-check-circle fa-fw"></i>' : '<i class="fas fa-exclamation-triangle fa-fw"></i>' ?>
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="form-list">
            <?php if (!empty($activeForms)): ?>
                <?php foreach ($activeForms as $form): 
                    $clean_status = strtolower($form["status"]);
                    $is_pending_check = ($clean_status === "checking"); 
                    $is_overdue = ($clean_status === "overdue"); // Check for overdue status
                    $is_borrow_date_reached = ($form["borrow_date"] <= $today);
                    // 🔑 NEW LOGIC: Check if the Expected Return Date has been reached
                    $is_expected_return_date_reached = ($form["expected_return_date"] <= $today);
                    
                    // --- Card Class Logic ---
                    if ($is_pending_check) {
                        $card_status_class = 'return-card-checking';
                    } elseif ($is_overdue) {
                        $card_status_class = 'return-card-overdue'; // Custom style for overdue
                    } else { 
                        $card_status_class = 'return-card-borrowed';
                    }

                    // --- IMAGE LOGIC (BCNF Compliant) ---
                    $apparatusListForForm = $transaction->getFormApparatus($form["id"]); 
                    $firstApparatus = $apparatusListForForm[0] ?? null;
                    $imageFile = $firstApparatus["image"] ?? 'default.jpg';
                    $imagePath = "../uploads/apparatus_images/" . $imageFile;
                    
                    if (!file_exists($imagePath) || is_dir($imagePath)) {
                        $imagePath = "../uploads/apparatus_images/default.jpg";
                    }
                    // --- END IMAGE LOGIC ---

                    // --- Action Content Logic (MODIFIED) ---
                    $action_content = '';
                    if ($is_pending_check) {
                        $action_content = '<span class="action-message-checking">
                                              <i class="fas fa-clock me-1"></i> Pending Staff Check
                                          </span>';
                    // 🔒 LOCK LOGIC: Disable return submission for reserved/approved forms until the expected return date.
                    } elseif (
                        (!$is_expected_return_date_reached) && 
                        ($clean_status === 'reserved' || $clean_status === 'approved')
                    ) {
                        // This block handles RESERVATIONS/APPROVED forms where the return is locked until the EXPECTED RETURN DATE
                        $action_content = '<span class="action-message-checking bg-info text-white">
                                              <i class="fas fa-lock me-1"></i> Return available on **' . htmlspecialchars($form["expected_return_date"]) . '**
                                          </span>';
                    } else {
                        // This block executes for 'borrowed', 'approved' (if return date reached), AND 'overdue' statuses.
                        
                        // FIX: Add overdue status check here, but still show the form
                        $overdue_warning = '';
                        if ($is_overdue) {
                            $overdue_warning = '<p class="text-danger fw-bold small mb-2"><i class="fas fa-exclamation-circle me-1"></i> **LATE RETURN:** This loan was marked overdue by staff. Submit now to finalize the return.</p>';
                        }
                        
                        // Form ready for return submission
                        $action_content = '
                            <form method="POST" class="action-container">
                                <input type="hidden" name="form_id" value="' . htmlspecialchars($form["id"]) . '">
                                ' . $overdue_warning . '
                                <textarea name="remarks" rows="2" class="form-control" placeholder="Optional notes for staff..."></textarea>
                                <button type="submit" name="return" class="btn btn-return">
                                    <i class="fas fa-paper-plane fa-fw"></i> Submit Return
                                </button>
                            </form>';
                    }
                    // --- END ACTION CONTENT LOGIC ---
                    
                    $expected_class = $is_overdue ? 'expected-return' : 'expected-return ok';
                ?>
                    <div class="return-card <?= $card_status_class ?>">
                        
                        
                        
                        <img src="<?= htmlspecialchars($imagePath) ?>" 
                            alt="Apparatus Image" 
                            class="apparatus-thumbnail">

                        <div class="card-col-info">
                            <h5 class="fw-bold mb-1" style="color: var(--msu-red);">
                                Request ID: <?= htmlspecialchars($form["id"]) ?> 
                                <small class="text-muted fw-normal">(<?= htmlspecialchars(ucfirst($form["form_type"])) ?>)</small>
                            </h5>
                            <p class="app-list mb-1">
                                <i class="fas fa-tools fa-fw me-1"></i> Items: **<?= htmlspecialchars($form["apparatus_list"]) ?>**
                            </p>
                            <div class="date-info">
                                <span><i class="fas fa-calendar-alt me-1"></i> Borrow: <?= htmlspecialchars($form["borrow_date"]) ?></span>
                                <span class="<?= $expected_class ?>"><i class="fas fa-clock me-1"></i> Expected: <?= htmlspecialchars($form["expected_return_date"]) ?></span>
                            </div>
                        </div>

                        <div class="card-col-action">
                            <?= $action_content ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-success text-center">
                    <i class="fas fa-check-circle me-2"></i> All borrowed or approved items have been returned or are pending staff verification.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- Auto-hide message functionality (for successful submissions) ---
    document.addEventListener('DOMContentLoaded', () => {
        const messageAlert = document.getElementById('status-alert');
        
        if (messageAlert && messageAlert.classList.contains('alert-success')) {
            setTimeout(() => {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(messageAlert);
                bsAlert.close();
            }, 3000); // 3 seconds delay
        }

        // --- Sidebar active link script (Consistent across all pages) ---
        const path = window.location.pathname.split('/').pop();
        const links = document.querySelectorAll('.sidebar .nav-link');
        
        links.forEach(link => {
            const linkPath = link.getAttribute('href').split('/').pop();
            
            // This ensures the link is active based on the current file name
            if (linkPath === 'student_dashboard.php') {
                link.classList.remove('active');
            } else if (linkPath === 'student_borrow.php') {
                link.classList.remove('active');
            } else if (linkPath === 'student_return.php') {
                link.classList.add('active');
            } else if (linkPath === 'student_transaction.php') {
                link.classList.remove('active');
            }
        });
    });
</script>
</body>
</html>