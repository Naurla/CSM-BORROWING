<?php
session_start();
require_once "../classes/Student.php"; 
require_once "../classes/Transaction.php"; 

// 1. Redirect if not logged in or not a student
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    header("Location: ../pages/login.php");
    exit;
}

$student = new Student();
$transaction = new Transaction();
$user_id = $_SESSION['user']['id'];
$errors = [];
$message = "";

// --- Ban Check for Sidebar Logic ---
$isBanned = false; // Placeholder
// ------------------------------------

// Initialize variables with current session data
$current_data = [
    'student_id' => $_SESSION['user']['student_id'],
    'firstname' => $_SESSION['user']['firstname'],
    'lastname' => $_SESSION['user']['lastname'],
    'course' => $_SESSION['user']['course'],
    'email' => $_SESSION['user']['email'],
];

// 1. FETCH AND SPLIT CONTACT NUMBER FOR DISPLAY
// Default contact values
$contact_number_for_input = '';
$country_code_for_select = '+63';

// NOTE: Assuming getContactDetails is implemented in Student.php
$db_contact = $student->getContactDetails($user_id);

if ($db_contact && !empty($db_contact['contact_number'])) {
    $full_contact = $db_contact['contact_number'];
    
    if (preg_match('/^(\+\d+)(.*)$/', $full_contact, $matches)) {
        $country_code_for_select = $matches[1];
        $contact_number_for_input = $matches[2];
    } else {
        $contact_number_for_input = preg_replace('/[^0-9]/', '', $full_contact);
    }
}

// Set initial values for form fields
$current_data['contact_number'] = $contact_number_for_input;
$current_data['country_code'] = $country_code_for_select;


// Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $new_firstname = trim($_POST["firstname"]);
    $new_lastname = trim($_POST["lastname"]);
    $new_course = trim($_POST["course"]);
    $new_email = trim($_POST["email"]);
    $new_country_code = trim($_POST["country_code"]);
    $new_contact_number = trim($_POST["contact_number"]);
    
    // --- Validation ---
    if (empty($new_firstname)) $errors["firstname"] = "First name is required.";
    if (empty($new_lastname)) $errors["lastname"] = "Last name is required.";
    if (empty($new_course)) $errors["course"] = "Course is required.";

    if (empty($new_country_code)) {
        $errors["contact_number"] = "Please select a country code.";
    } elseif (empty($new_contact_number)) {
        $errors["contact_number"] = "Contact number is required.";
    } else {
        $clean_number = preg_replace('/[^0-9]/', '', $new_contact_number);
        if (strlen($clean_number) < 7 || strlen($clean_number) > 15) {
            $errors["contact_number"] = "Enter a valid number (7-15 digits after the country code).";
        }
    }

    if (empty($new_email)) {
        $errors["email"] = "Email is required.";
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $errors["email"] = "Invalid email format.";
    }
    
    // --- Duplicate Email Check (Only if email changed) ---
    if (empty($errors) && $new_email !== $_SESSION['user']['email']) {
        if ($student->isEmailExist($new_email)) {
            $errors['email'] = "This Email address is already registered to another account.";
        }
    }

    // 3. Process Update
    if (empty($errors)) {
        $full_contact = $new_country_code . $clean_number;
        
        $result = $student->updateStudentProfile(
            $user_id, $new_firstname, $new_lastname, $new_course, $full_contact, $new_email
        );
        
        if ($result) {
            $message = "✅ Profile successfully updated!";
            
            // 4. Update Session variables on success
            $_SESSION['user']['firstname'] = $new_firstname;
            $_SESSION['user']['lastname'] = $new_lastname;
            $_SESSION['user']['course'] = $new_course;
            $_SESSION['user']['email'] = $new_email;
            
            // Re-initialize local form variables with new data
            $current_data['firstname'] = $new_firstname;
            $current_data['lastname'] = $new_lastname;
            $current_data['course'] = $new_course;
            $current_data['email'] = $new_email;
            $current_data['contact_number'] = $new_contact_number;
            $current_data['country_code'] = $new_country_code;

        } else {
            $message = "❌ Error updating profile due to a database issue.";
        }
    } else {
        $message = "❌ Please correct the errors below.";
        
        // Retain user input in case of error
        $current_data['firstname'] = $new_firstname;
        $current_data['lastname'] = $new_lastname;
        $current_data['course'] = $new_course;
        $current_data['email'] = $new_email;
        $current_data['contact_number'] = $new_contact_number;
        $current_data['country_code'] = $new_country_code;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Student</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        /* --- COPYING STYLES FROM DASHBOARD --- */
        :root {
            --msu-red: #b8312d; 
            --msu-red-dark: #a82e2a;
            --sidebar-width: 280px; 
            --bg-light: #f5f6fa;
            --header-height: 60px;
            --danger-dark: #8b0000;
        }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: var(--bg-light); 
            min-height: 100vh;
            display: flex; 
            padding: 0;
            margin: 0;
        }

        /* --- Sidebar Styles (Unifying Look) --- */
        .sidebar { width: var(--sidebar-width); min-width: var(--sidebar-width); height: 100vh; background-color: var(--msu-red); color: white; padding: 0; position: fixed; top: 0; left: 0; display: flex; flex-direction: column; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2); z-index: 1050; }
        .sidebar-header { text-align: center; padding: 20px 15px; font-size: 1.2rem; font-weight: 700; line-height: 1.15; color: #fff; border-bottom: 1px solid rgba(255, 255, 255, 0.4); margin-bottom: 20px; }
        .sidebar-header img { max-width: 90px; height: auto; margin-bottom: 15px; }
        .sidebar-header .title { font-size: 1.3rem; line-height: 1.1; }
        .sidebar-nav { flex-grow: 1; }
        .sidebar .nav-link { color: white; padding: 15px 20px; font-weight: 600; transition: background-color 0.3s; border-left: none !important; }
        .sidebar .nav-link:hover { background-color: var(--msu-red-dark); }
        .sidebar .nav-link.active { background-color: var(--msu-red-dark); } 
        .sidebar .nav-link.banned { background-color: #5a2624; opacity: 0.8; cursor: pointer; pointer-events: auto; }
        .logout-link { margin-top: auto; border-top: 1px solid rgba(255, 255, 255, 0.1); }
        .logout-link .nav-link { background-color: #dc3545 !important; color: white !important; }
        .logout-link .nav-link:hover { background-color: var(--msu-red-dark) !important; }
        
        /* --- Top Header Bar Styles --- */
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
            justify-content: flex-end; 
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

        /* --- Main Content Styles (FULL COVERAGE) --- */
        .main-wrapper {
            margin-left: var(--sidebar-width); 
            padding: 0; 
            padding-top: var(--header-height); 
            flex-grow: 1;
        }
        .content-area {
            background: #fff; 
            border-radius: 0; 
            padding: 30px 40px;
            box-shadow: none; 
            max-width: none; 
            width: 100%; 
            margin: 0; 
            min-height: calc(100vh - var(--header-height)); 
        }
        .page-header {
            color: #333; 
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--msu-red);
            font-weight: 600;
            font-size: 2rem;
        }
        
        /* --- Form Styles (MAXIMIZED & PROPORTIONAL) --- */
        .form-container-wrapper {
            /* Sets overall size of the form block */
            max-width: 950px; /* Increased for better fill */
            margin: 0 auto; /* CENTERS THE ENTIRE BLOCK */
        }
        .form-group {
            display: flex;
            margin-bottom: 20px;
            align-items: flex-start;
        }
        .form-group label {
            /* Reduced label width to push input further left and maximize its size */
            flex: 0 0 120px; 
            padding-right: 20px;
            text-align: right;
            padding-top: 8px;
            font-weight: 600;
        }
        .form-control {
            /* Input width fills the rest of the available 950px block */
            flex: 1;
        }
        .form-actions {
            /* Adjusted for new label width */
            padding-left: 140px; /* 120px label + 20px padding-right */
            margin-top: 20px;
        }
        .error {
            color: var(--msu-red);
            font-size: 0.9rem;
            margin-top: 5px;
            padding-left: 140px; /* Adjusted to align under inputs */
            font-weight: 600;
            display: block;
        }
        
        /* Contact Field Specific Styles */
        .input-container {
            flex: 1;
            display: flex;
        }
        .country-code-select {
            padding: 8px 8px;
            border: 1px solid #ccc;
            border-right: none;
            background-color: #eee;
            border-radius: 4px 0 0 4px;
            font-weight: bold;
            font-size: 0.9rem;
            max-width: 150px; 
        }
        .contact-input {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 0 4px 4px 0 !important;
            box-sizing: border-box;
            font-size: 0.9rem;
            width: 100%;
        }

        .alert-custom {
            margin-bottom: 20px;
            padding: 10px;
            font-weight: bold;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo" class="img-fluid"> 
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
    <a href="student_edit.php" class="edit-profile-link" style="color: var(--msu-red-dark);">
        <i class="fas fa-user-edit me-1"></i> **Edit Profile (Active)**
    </a>
</header>

<div class="main-wrapper">
    <div class="content-area">
        <div class="form-container-wrapper">
            <h2 class="page-header">
                <i class="fas fa-user-edit fa-fw me-2 text-secondary"></i> Edit Profile Details
            </h2>
            
            <?php if (!empty($message)): ?>
                <div class="alert-custom <?= strpos($message, '✅') !== false ? 'alert-success' : 'alert-danger' ?>">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">

                <div class="form-group">
                    <label>Student ID</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($current_data['student_id']) ?>" disabled>
                </div>

                <div class="form-group">
                    <label for="firstname">First Name</label>
                    <input type="text" id="firstname" name="firstname" class="form-control" value="<?= htmlspecialchars($current_data['firstname']) ?>" required>
                </div>
                <?php if (isset($errors['firstname'])): ?><span class="error"><?= $errors['firstname'] ?></span><?php endif; ?>

                <div class="form-group">
                    <label for="lastname">Last Name</label>
                    <input type="text" id="lastname" name="lastname" class="form-control" value="<?= htmlspecialchars($current_data['lastname']) ?>" required>
                </div>
                <?php if (isset($errors['lastname'])): ?><span class="error"><?= $errors['lastname'] ?></span><?php endif; ?>

                <div class="form-group">
                    <label for="course">Course</label>
                    <input type="text" id="course" name="course" class="form-control" value="<?= htmlspecialchars($current_data['course']) ?>" required>
                </div>
                <?php if (isset($errors['course'])): ?><span class="error"><?= $errors['course'] ?></span><?php endif; ?>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($current_data['email']) ?>" required>
                </div>
                <?php if (isset($errors['email'])): ?><span class="error"><?= $errors['email'] ?></span><?php endif; ?>

                <div class="form-group">
                    <label for="contact_number">Contact Number</label>
                    <div class="input-container">
                        <select name="country_code" class="country-code-select">
                            <option value="+63" <?= $current_data['country_code'] === '+63' ? 'selected' : '' ?>>+63 (Philippines)</option>
                            <option value="+1" <?= $current_data['country_code'] === '+1' ? 'selected' : '' ?>>+1 (US/Canada)</option>
                            <option value="+44" <?= $current_data['country_code'] === '+44' ? 'selected' : '' ?>>+44 (UK)</option>
                            <option value="+81" <?= $current_data['country_code'] === '+81' ? 'selected' : '' ?>>+81 (Japan)</option>
                            <option value="+91" <?= $current_data['country_code'] === '+91' ? 'selected' : '' ?>>+91 (India)</option>
                            </select>
                        <input type="text" id="contact_number" name="contact_number" class="contact-input" value="<?= htmlspecialchars($current_data['contact_number']) ?>" required>
                    </div>
                </div>
                <?php if (isset($errors['contact_number'])): ?><span class="error"><?= $errors['contact_number'] ?></span><?php endif; ?>

                <div class="form-actions">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-save me-2"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Script to ensure the Edit Profile link is highlighted when active
    document.addEventListener('DOMContentLoaded', () => {
        // Remove active class from all sidebar links 
        const links = document.querySelectorAll('.sidebar .nav-link');
        links.forEach(link => {
            link.classList.remove('active');
        });
    });
</script>

</body>
</html>