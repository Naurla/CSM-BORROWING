<?php
session_start();
require_once "../classes/Transaction.php";

if (!isset($_SESSION["user"]) || $_SESSION["user"]["role"] != "student") {
    header("Location: ../pages/login.php");
    exit();
}

$transaction = new Transaction();

if (!isset($_GET["form_id"])) {
    http_response_code(400); 
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body><div class="container mt-5"><div class="alert alert-danger" role="alert">No form ID provided.</div><a href="student_dashboard.php" class="btn btn-secondary">Back to Dashboard</a></div></body></html>';
    exit();
}

$form_id = $_GET["form_id"];
// This call will now be defined in Transaction.php:
$form = $transaction->getBorrowFormById($form_id);

if (!$form) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body><div class="container mt-5"><div class="alert alert-danger" role="alert">Form not found.</div><a href="student_dashboard.php" class="btn btn-secondary">Back to Dashboard</a></div></body></html>';
    exit();
}

// Ensure form_type exists, default to 'Form' if not set
$form_type = isset($form["form_type"]) ? ucfirst($form["form_type"]) : 'Form';

// $items uses getBorrowFormItems, which fetches aggregated details via JOINs
$items = $transaction->getBorrowFormItems($form_id);

// --- NAVIGATION CONTEXT LOGIC FIX ---
$context = $_GET["context"] ?? '';

$back_url = 'student_dashboard.php'; // Default is Current Activity
$back_text = 'Back to Current Activity';

if ($context === 'history') {
    $back_url = 'student_transaction.php';
    $back_text = 'Back to Transaction History';
}
// ------------------------------------

// Define the Web URL base path for the browser (assumes 'wd123' is the folder under htdocs)
// This must match your web server setup.
$baseURL = "/wd123/uploads/apparatus_images/"; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View <?= $form_type ?> #<?= htmlspecialchars($form["id"]) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        
        :root {
            --msu-red: #b8312d; 
            --msu-red-dark: #a82e2a; 
            --primary-blue: #007bff;
        }

        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #e9ecef; 
            min-height: 100vh;
            padding: 20px;
            display: flex;
            justify-content: center; /* Center the container horizontally */
            align-items: flex-start; /* Align container to the top */
        }
        

        /* MODIFIED: Stretched Container */
        .container {
            background: #fff; 
            border-radius: 12px; 
            padding: 40px;
            max-width: 95%; /* Use 95% of the viewport width */
            width: 95%;
            margin: 20px auto; /* Centers the content */
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        /* END MODIFIED */

      
        .page-header {
            color: var(--msu-red); 
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid var(--msu-red);
            font-weight: 600;
        }
        .page-header i {
            margin-right: 10px;
        }

    
        .form-details-grid {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .detail-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 5px;
            display: block;
        }
        .detail-value {
            font-size: 1rem;
            color: #212529;
            word-wrap: break-word; 
        }
        .detail-item {
            padding: 10px 0;
            border-bottom: 1px dotted #e9ecef;
        }
        .detail-item:last-child {
            border-bottom: none;
        }
    
        .status-tag {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 15px;
            font-weight: 700;
            text-transform: capitalize;
            font-size: 0.85rem;
            line-height: 1.2;
        }

        .status-tag.waiting_for_approval { background-color: #ffc10740; color: #ffc107; } 
        .status-tag.approved { background-color: #19875440; color: #198754; } 
        .status-tag.rejected { background-color: #dc354540; color: #dc3545; } 
        .status-tag.borrowed { background-color: #0d6efd40; color: #0d6efd; } 
        .status-tag.returned { background-color: #6c757d40; color: #6c757d; }
        .status-tag.overdue { background-color: #dc354540; color: #dc3545; } 
        /* Match dark status styles if available in other views */
        .status-tag.damaged, .status-tag.overdue { background-color: var(--msu-red); color: white; }

    
        .table {
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
        }
        .table tbody td {
            vertical-align: middle;
            font-size: 0.95rem;
            text-align: center;
        }
        .table-image-cell {
            width: 80px;
        }

        
        .btn-msu-red {
            background-color: var(--msu-red); 
            border-color: var(--msu-red);
            color: #fff;
            padding: 10px 25px;
            font-weight: 600;
            transition: background-color 0.2s, border-color 0.2s;
        }
        .btn-msu-red:hover {
            background-color: var(--msu-red-dark);
            border-color: var(--msu-red-dark);
            color: #fff;
        }
    </style>
</head>
<body>

<div class="container">
    <h2 class="page-header">
        <i class="fas fa-file-invoice fa-fw"></i> 
        Form #<?= htmlspecialchars($form["id"]) ?> - <?= $form_type ?>
    </h2>

    <div class="form-details-grid">
        <div class="row g-3">
            <div class="col-md-3 col-sm-6">
                <span class="detail-label"><i class="fas fa-bookmark fa-fw me-1"></i> Status</span>
                <span class="status-tag <?= htmlspecialchars($form["status"]) ?>">
                    <?= htmlspecialchars(str_replace('_', ' ', $form["status"])) ?>
                </span>
            </div>
            <div class="col-md-3 col-sm-6">
                <span class="detail-label"><i class="fas fa-calendar-day fa-fw me-1"></i> Borrow Date</span>
                <span class="detail-value"><?= htmlspecialchars($form["borrow_date"] ?? '-') ?></span>
            </div>
            <div class="col-md-3 col-sm-6">
                <span class="detail-label"><i class="fas fa-clock fa-fw me-1"></i> Expected Return</span>
                <span class="detail-value"><?= htmlspecialchars($form["expected_return_date"] ?? '-') ?></span>
            </div>
            <div class="col-md-3 col-sm-6">
                <span class="detail-label"><i class="fas fa-calendar-check fa-fw me-1"></i> Actual Return</span>
                <span class="detail-value"><?= htmlspecialchars($form["actual_return_date"] ?? '-') ?></span>
            </div>
            <div class="col-12 mt-3 pt-3 border-top">
                <span class="detail-label"><i class="fas fa-comment-dots fa-fw me-1"></i> Remarks</span>
                <p class="detail-value mb-0"><?= htmlspecialchars($form["staff_remarks"] ?? 'No remarks provided.') ?></p>
            </div>
        </div>
    </div>
    
    <h4 class="mb-3 text-secondary"><i class="fas fa-boxes fa-fw me-2"></i> Borrowed Items</h4>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th class="table-image-cell">Image</th>
                <th class="text-start">Apparatus Name</th>
                <th>Type</th>
                <th>Size</th>
                <th>Material</th>
                <th>Quantity</th> <th>Item Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!empty($items)): ?>
                <?php foreach ($items as $item): 
                    // Server-side check for robust fallback path
                    // This path must be correct relative to the executing PHP file (student_view_items.php)
                    $serverPath = __DIR__ . "/../uploads/apparatus_images/" . ($item["image"] ?? 'default.jpg');
                    
                    // The URL the browser sees (using the file name fetched from the aggregated items)
                    $imageURL = $baseURL . ($item["image"] ?? 'default.jpg');

                    // Check if file exists using PHP's file system
                    if (!file_exists($serverPath) || is_dir($serverPath)) {
                        // Fallback URL: Use the correct path for the default image.
                        $imageURL = $baseURL . "default.jpg";
                    }
                ?>
                    <tr>
                        <td class="table-image-cell">
                            <img src="<?= htmlspecialchars($imageURL) ?>" 
                                alt="<?= htmlspecialchars($item["name"] ?? 'N/A') ?>" 
                                class="img-fluid rounded"
                                style="width: 50px; height: 50px; object-fit: cover; border: 1px solid #ddd;">
                        </td>
                        <td class="text-start fw-bold"><?= htmlspecialchars($item["name"]) ?></td>
                        <td><?= htmlspecialchars($item["apparatus_type"]) ?></td>
                        <td><?= htmlspecialchars($item["size"]) ?></td>
                        <td><?= htmlspecialchars($item["material"]) ?></td>
                        <td><?= htmlspecialchars($item["quantity"] ?? 1) ?></td> <td>
                            <span class="status-tag <?= htmlspecialchars($item["item_status"]) ?>">
                                <?= htmlspecialchars(str_replace('_', ' ', $item["item_status"])) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" class="text-muted py-4">No items found for this form.</td></tr> 
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="text-center pt-4">
        <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-msu-red shadow-sm">
            <i class="fas fa-arrow-left fa-fw"></i> <?= htmlspecialchars($back_text) ?>
        </a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>