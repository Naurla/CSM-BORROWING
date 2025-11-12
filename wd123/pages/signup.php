<?php
session_start(); // Start session to store success flag
require_once "../classes/Student.php"; 

$errors = [];
$message = "";
$registration_successful = false; // Flag to control the success modal

// Initialize values from POST or empty string
$student_id = $_POST["student_id"] ?? '';
$firstname = $_POST["firstname"] ?? '';
$lastname = $_POST["lastname"] ?? '';
$course = $_POST["course"] ?? '';

// --- NEW: Capture country code and number separately ---
// CHANGED DEFAULT: Set to empty string to enforce selection of the placeholder option
$country_code = $_POST["country_code"] ?? ''; 
$contact_number = $_POST["contact_number"] ?? ''; 
$full_contact_number = ''; // Variable to store the combined number for database
$email = $_POST["email"] ?? '';
$password = $_POST["password"] ?? '';
$confirm_password = $_POST["confirm_password"] ?? '';


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Capture input values safely (re-trimmed)
    $student_id = trim($student_id);
    $firstname = trim($firstname);
    $lastname = trim($lastname);
    $course = trim($course);
    $contact_number = trim($contact_number);
    $country_code = trim($country_code); // Capture country code
    $email = trim($email);
    
    // Instantiate the class to use its methods
    $student = new Student();

    // --- VALIDATION LOGIC ---
    // Note: The logic below is the same as the user provided, but the final duplicate check is enhanced.
    if (empty($student_id)) {
        $errors["student_id"] = "Student ID is required.";
    } elseif (!preg_match("/^[0-9]{4}-[0-9]{5}$/", $student_id)) {
        $errors["student_id"] = "Student ID must follow the pattern YYYY-##### (e.g., 2024-01203).";
    }

    if (empty($firstname)) $errors["firstname"] = "First name is required.";
    if (empty($lastname)) $errors["lastname"] = "Last name is required.";
    if (empty($course)) $errors["course"] = "Course is required.";

    // --- MODIFIED CONTACT NUMBER VALIDATION ---
    if (empty($country_code)) {
        $errors["contact_number"] = "Please select a country code."; 
    } elseif (empty($contact_number)) {
        $errors["contact_number"] = "Contact number is required.";
    } else {
        // Concatenate the DYNAMIC country code with the number for DB storage
        $clean_number = preg_replace('/[^0-9]/', '', $contact_number);
        $full_contact_number = $country_code . $clean_number;
        
        if (strlen($clean_number) < 7 || strlen($clean_number) > 15) {
            $errors["contact_number"] = "Enter a valid number (7-15 digits after the country code).";
        }
    }
    // --- END MODIFIED CONTACT NUMBER VALIDATION ---

    if (empty($email)) {
        $errors["email"] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors["email"] = "Invalid email format.";
    }

    if (empty($password)) {
        $errors["password"] = "Password is required.";
    } elseif (strlen($password) < 8) {
        $errors["password"] = "Password must be at least 8 characters."; 
    }
    
    if ($password !== $confirm_password) $errors["confirm_password"] = "Passwords do not match.";
    
    
    if (empty($errors)) {
        
        // --- START NEW DUPLICATE CHECK LOGIC ---
        // NOTE: These methods must be defined in your Student.php class!
        $id_exists = $student->isStudentIdExist($student_id);
        $email_exists = $student->isEmailExist($email);

        if ($id_exists || $email_exists) {
            $message = "❌ Registration failed.";
            
            if ($id_exists) {
                // Specific error for Student ID
                $errors['student_id'] = "An account with this Student ID already exists.";
            }
            if ($email_exists) {
                // Specific error for Email
                $errors['email'] = "An account with this Email address already exists.";
            }

        } else {
            // --- END NEW DUPLICATE CHECK LOGIC ---
            
            // Register the student, using the FULL concatenated number
            $result = $student->registerStudent(
                $student_id, $firstname, $lastname, $course, $full_contact_number, $email, $password
            );

            if ($result) {
                // SUCCESS: Set flag and session to show modal after redirect
                $_SESSION['registration_success'] = true;
                // Redirect immediately to clear POST data and prevent re-submission
                header("Location: signup.php"); 
                exit;
            } else {
                $message = " Registration failed due to a database error. Please check server logs.";
            }
        }
    } else {
        $message = " Please correct the errors below.";
    }
}

//Check for success flag after redirection
if (isset($_SESSION['registration_success']) && $_SESSION['registration_success']) {
    $registration_successful = true;
    unset($_SESSION['registration_success']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSM LABORATORY APPARATUS BORROWING - New Account</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --msu-red: #b8312d; 
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5; 
            margin: 0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
        }
        .header-section {
            display: flex;
            align-items: center;
            margin-bottom: 25px; 
        }
        .header-section img {
            max-width: 130px; 
            margin-right: 20px;
        }
        .header-section h1 {
            font-size: 38px; 
            color: var(--msu-red); 
            margin: 0;
            font-weight: normal;
        }
        
        .main-content {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.1); 
            padding: 30px; 
            width: 100%;
            max-width: 850px; 
        }
        
        .form-title {
            color: var(--msu-red); 
            font-size: 1.3em; 
            font-weight: bold;
            padding-bottom: 12px;
            border-bottom: 1px solid #eee;
            margin-bottom: 25px;
        }
        .form-group {
            margin-bottom: 20px; 
            display: flex;
            align-items: flex-start;
        }
        
        /* --- MODIFIED CONTACT FIELD STYLES --- */
        .label-container {
            flex: 0 0 250px; 
            padding-right: 15px;
            text-align: right;
            padding-top: 10px; 
            font-size: 16px; 
            color: #333;
            font-weight: bold;
        }
        .input-container {
            flex: 1;
            position: relative; 
            display: flex; /* Makes select and input align horizontally */
        }
        /* Style for the country code select */
        .country-code-select {
            padding: 10px 8px;
            border: 1px solid #ccc;
            border-right: none;
            background-color: #eee;
            border-radius: 4px 0 0 4px;
            font-weight: bold;
            color: #333;
            font-size: 16px;
            
            /* UI Improvement: Make the select slightly wider to fit country name */
            padding-right: 25px; 
            max-width: 150px; 
        }
        .contact-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 0 4px 4px 0 !important;
            box-sizing: border-box;
            font-size: 16px;
        }
        input:not(.form-check-input) {
            padding: 10px 12px; 
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 16px; 
            padding-right: 45px;
            width: 100%;
        }
        /* -------------------------------------- */
        
        
        .toggle-password {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            cursor: pointer;
            color: #888;
            font-size: 16px; 
            z-index: 10;
        }
        
        .toggle-password:hover {
            color: var(--msu-red);
        }

        .info-icon {
            color: var(--msu-red);
            margin-left: 8px; 
            cursor: help;
            font-size: 16px;
        }
        
        .error {
            color: var(--msu-red); 
            font-size: 13px;
            margin-top: -10px; 
            padding-left: 265px; 
            display: block;
            font-weight: bold;
        }
        .message-box {
            padding: 12px;
            margin: 15px 0;
            text-align: center;
            font-weight: bold;
            border-radius: 5px;
            margin-left: 265px; 
            font-size: 15px;
        }

        .message-box.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message-box.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-start;
            padding-left: 265px; 
            margin-top: 35px;
        }
        .form-actions button, .form-actions a button {
            padding: 10px 20px; 
            margin-right: 15px;
            border: none;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
        }

        .form-actions .btn-create {
            background-color: var(--msu-red); 
            color: white;
        }
        .form-actions .btn-create:hover {
            background-color: #a82e2a;
        }
        .form-actions .btn-cancel {
            background-color: #eee;
            color: #333;
            border: 1px solid #ccc;
        }
        .bottom-link-container {
            text-align: center;
            margin-top: 25px;
            font-size: 16px; 
        }
        .bottom-link-container a {
            color: var(--msu-red);
            text-decoration: none;
            font-weight: bold;
        }
        
        .modal-footer .btn-primary {
            background-color: var(--msu-red) !important;
            border-color: var(--msu-red) !important;
        }
    </style>
</head>
<body>

<div class="header-section">
    <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo"> 
    <h1>CSM LABORATORY APPARATUS BORROWING</h1>
</div>

<div class="main-content">
    <div class="form-title">
        New Account
    </div>

    <?php if (!empty($message) && !$registration_successful): ?>
        <div class="message-box <?= strpos($message, 'successful') !== false ? 'success' : 'error' ?>">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        
        <div class="form-title">
            Choose your username and password
        </div>
        
        <div class="form-group">
            <div class="label-container">
                <label for="student_id">Student ID</label>
                <span class="info-icon">ⓘ</span>
            </div>
            <div class="input-container">
                <input type="text" id="student_id" name="student_id" value="<?= htmlspecialchars($student_id) ?>" placeholder="e.g., YYYY-#####" required>
            </div>
        </div>
        <?php if (isset($errors["student_id"])): ?><span class="error"><?= $errors["student_id"] ?></span><?php endif; ?>

      
        <div class="form-group">
            <div class="label-container">
                <label for="password">Password</label>
                <span class="info-icon">ⓘ</span>
            </div>
            <div class="input-container">
                <input type="password" id="password" name="password" required data-target="password">
                <i class="fas fa-eye toggle-password" data-target="password"></i>
            </div>
        </div>
        <?php if (isset($errors["password"])): ?><span class="error"><?= $errors["password"] ?></span><?php endif; ?>

        
        <div class="form-group">
            <div class="label-container">
                <label for="confirm_password">Confirm Password</label>
                <span class="info-icon">ⓘ</span>
            </div>
            <div class="input-container">
                <input type="password" id="confirm_password" name="confirm_password" required data-target="confirm_password">
                <i class="fas fa-eye toggle-password" data-target="confirm_password"></i>
            </div>
        </div>
        <?php if (isset($errors["confirm_password"])): ?><span class="error"><?= $errors["confirm_password"] ?></span><?php endif; ?>

        <div class="form-title" style="margin-top: 40px;">
            More details
        </div>

        <div class="form-group">
            <div class="label-container">
                <label for="email">Email address</label>
                <span class="info-icon">ⓘ</span>
            </div>
            <div class="input-container">
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
            </div>
        </div>
        <?php if (isset($errors["email"])): ?><span class="error"><?= $errors["email"] ?></span><?php endif; ?>
        
        <div class="form-group">
            <div class="label-container">
                <label for="firstname">First name</label>
                <span class="info-icon">ⓘ</span>
            </div>
            <div class="input-container">
                <input type="text" id="firstname" name="firstname" value="<?= htmlspecialchars($firstname) ?>" required>
            </div>
        </div>
        <?php if (isset($errors["firstname"])): ?><span class="error"><?= $errors["firstname"] ?></span><?php endif; ?>

        <div class="form-group">
            <div class="label-container">
                <label for="lastname">Last name</label>
                <span class="info-icon">ⓘ</span>
            </div>
            <div class="input-container">
                <input type="text" id="lastname" name="lastname" value="<?= htmlspecialchars($lastname) ?>" required>
            </div>
        </div>
        <?php if (isset($errors["lastname"])): ?><span class="error"><?= $errors["lastname"] ?></span><?php endif; ?>
        
        <div class="form-group">
            <div class="label-container">
                <label for="course">Course</label>
                <span class="info-icon">ⓘ</span>
            </div>
            <div class="input-container">
                <input type="text" id="course" name="course" value="<?= htmlspecialchars($course) ?>" required>
            </div>
        </div>
        <?php if (isset($errors["course"])): ?><span class="error"><?= $errors["course"] ?></span><?php endif; ?>

        <div class="form-group">
            <div class="label-container">
                <label for="contact_number">Contact Number</label>
                <span class="info-icon">ⓘ</span>
            </div>
            <div class="input-container">
                <select name="country_code" class="country-code-select">
                    <option value="" disabled <?= empty($country_code) ? 'selected' : '' ?>>Select Code</option> 
                    
                    <option value="+63" <?= $country_code === '+63' ? 'selected' : '' ?>>+63 (Philippines)</option>
                    <option value="+1" <?= $country_code === '+1' ? 'selected' : '' ?>>+1 (US/Canada)</option>
                    <option value="+44" <?= $country_code === '+44' ? 'selected' : '' ?>>+44 (UK)</option>
                    <option value="+81" <?= $country_code === '+81' ? 'selected' : '' ?>>+81 (Japan)</option>
                    <option value="+91" <?= $country_code === '+91' ? 'selected' : '' ?>>+91 (India)</option>
                </select>
                <input type="text" id="contact_number" name="contact_number" value="<?= htmlspecialchars($contact_number) ?>" 
                       class="contact-input" required>
            </div>
        </div>
        <?php if (isset($errors["contact_number"])): ?><span class="error"><?= $errors["contact_number"] ?></span><?php endif; ?>
        <div class="form-actions">
            
            <button type="submit" class="btn-create">
                Create my new account
            </button>
            <a href="login.php" style="text-decoration: none;">
                <button type="button" class="btn-cancel">
                    Cancel
                </button>
            </a>
        </div>
    </form>
    
    <div class="bottom-link-container">
        Already have an account? <a href="login.php">Login here</a>
    </div>
</div>

<div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="successModalLabel">
            <i class="fas fa-check-circle me-2"></i> Registration Successful!
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <p>Your account has been successfully created.</p>
        <p class="fw-bold">Please use your new credentials to log in.</p>
      </div>
      <div class="modal-footer justify-content-center">
        <a href="login.php" class="btn btn-primary">
            <i class="fas fa-sign-in-alt me-1"></i> Log in
        </a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // 1. Modal Logic
    document.addEventListener('DOMContentLoaded', function() {
        const isSuccess = <?php echo json_encode($registration_successful); ?>;
        
        if (isSuccess) {
            const successModal = new bootstrap.Modal(document.getElementById('successModal'));
            successModal.show();
        }

        // 2. Show Password Logic for multiple fields
        document.querySelectorAll('.toggle-password').forEach(icon => {
            icon.addEventListener('click', function() {
                // Get the ID of the target input from the data-target attribute
                const targetId = this.getAttribute('data-target');
                const passwordInput = document.getElementById(targetId);
                
                // Toggle the type attribute
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);

                // Toggle the icon (fa-eye-slash means password is being viewed)
                this.classList.toggle('fa-eye-slash');
                this.classList.toggle('fa-eye');
            });
        });
    });
</script>

</body>
</html>