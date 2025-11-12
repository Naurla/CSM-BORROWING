<?php
session_start();

// Check if user is already logged in
if (isset($_SESSION['user'])) {
    if ($_SESSION['user']['role'] == 'student') {
        header("Location: ../student/student_dashboard.php");
        exit;
    } else {
        header("Location: ../staff/staff_dashboard.php");
        exit;
    }
}

require_once "../classes/Login.php"; 
$login = new Login();

// NEW: Variables to hold specific errors and input value
$error_email = ""; 
$error_password = "";
$general_error = ""; 
$entered_email = ""; // To retain the email input

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $login->email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $login->password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);

    // Retain the email value regardless of login success/failure
    $entered_email = htmlspecialchars($login->email);

    if ($login->login()) {
        $_SESSION["user"] = $login->getUser();

        if ($_SESSION["user"]["role"] == "student") {
            header("Location: ../student/student_dashboard.php");
        } else {
            header("Location: ../staff/staff_dashboard.php");
        }
        exit;
    } else {
        // Capture the specific error reason
        $reason = $login->getErrorReason();

        if ($reason === 'user_not_found') {
            $error_email = "Account not found. Please check your email address.";
            $general_error = "❌ Login failed.";
        } elseif ($reason === 'incorrect_password') {
            $error_password = "Incorrect password. Please try again.";
            $general_error = "❌ Login failed.";
        } else {
            $general_error = "An unknown error occurred. Please try again.";
        }
    }
} else {
    // If it's a GET request, initialize email to empty
    $entered_email = "";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> CSM LABORATORY BORROWING APPARATUS SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-image: url('assets/images/login_bg.jpg'); 
            background-size: cover;
            background-position: center;
        }

        /* --- INCREASED CARD SIZE --- */
        .login-card {
            background: rgba(255, 255, 255, 0.98); 
            padding: 45px; 
            border-radius: 8px;
            box-shadow: 0 6px 30px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 500px; 
            text-align: center;
        }
        /* -------------------------- */

        .logo-container img {
            max-width: 130px; 
            height: auto;
            margin-bottom: 10px;
        }

        /* --- INCREASED MAIN TITLE FONT SIZE --- */
        .digital-education {
            font-size: 16px; 
            color: #b8312d;
            margin-bottom: 30px; 
            font-weight: bold;
            letter-spacing: 1px;
        }
        /* -------------------------------------- */

        
        .input-group {
            margin-bottom: 20px; 
            text-align: left;
        }

        /* --- INCREASED LABEL FONT SIZE --- */
        .input-group label {
            display: block;
            font-size: 16px; 
            color: #333; 
            margin-bottom: 7px;
            font-weight: bold;
        }
        /* --------------------------------- */

        
        .password-container {
            position: relative;
        }

        /* --- INCREASED INPUT FONT SIZE/PADDING --- */
        .input-field {
            width: 100%;
            padding: 12px; 
            border: 1px solid #aaa;
            border-radius: 4px;
            box-sizing: border-box; 
            font-size: 17px; 
            padding-right: 45px; 
        }
        /* --------------------------------------- */

        /* NEW: Error styling for input border */
        .input-field.error-border {
            border-color: #b8312d !important;
        }

        
        .toggle-password {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            cursor: pointer;
            color: #888;
            font-size: 18px; 
            z-index: 10;
        }
        
        .toggle-password:hover {
            color: #b8312d;
        }

        .btn-continue {
            width: 100%;
            padding: 14px; 
            background-color: #b8312d; 
            border: none;
            border-radius: 4px;
            color: white;
            font-weight: bold;
            font-size: 17px; 
            cursor: pointer;
            transition: background-color 0.3s;
            margin-top: 10px;
        }

        .btn-continue:hover {
            background-color: #a82e2a; 
        }

        
        /* --- INCREASED BOTTOM LINK FONT SIZE --- */
        .bottom-links-container {
            display: flex;
            justify-content: flex-end; 
            align-items: center;
            margin-top: 20px;
            font-size: 16px; 
        }
        /* --------------------------------------- */

        .bottom-links-container a {
            color: #b8312d; 
            text-decoration: none;
        }

        .bottom-links-container a:hover {
            text-decoration: underline;
        }
        
        
        /* Modified error display to align with specific errors */
        .general-error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-weight: bold;
        }
        .specific-error {
            color: #b8312d; 
            font-size: 14px;
            margin-top: 5px;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="logo-container">
        <img src="../wmsu_logo/wmsu.png" alt="Western Mindanao State University Logo"> 
    </div>
    
    <div class="digital-education">
        CSM LABORATORY BORROWING APPARATUS 
    </div>

    <form method="POST" action="">
        <?php if (!empty($general_error)): ?>
            <p class="general-error-message"><?= $general_error ?></p>
        <?php endif; ?>
        
        <div class="input-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" class="input-field <?= !empty($error_email) ? 'error-border' : '' ?>" placeholder="Enter your email" required 
                     value="<?= $entered_email ?>"> <?php if (!empty($error_email)): ?><p class="specific-error"><?= $error_email ?></p><?php endif; ?>
        </div>

        <div class="input-group">
            <label for="password">Password</label>
            <div class="password-container">
                <input type="password" id="password" name="password" class="input-field <?= !empty($error_password) ? 'error-border' : '' ?>" placeholder="Enter your password" required>
                <i class="fas fa-eye toggle-password" id="togglePassword"></i>
            </div>
            <?php if (!empty($error_password)): ?><p class="specific-error"><?= $error_password ?></p><?php endif; ?>
        </div>

        <button type="submit" name="login" class="btn-continue">Continue</button>
    </form>

    <div class="bottom-links-container">
        <a href="signup.php">Create an Account</a>
    </div>
</div>

<script>
    document.getElementById('togglePassword').addEventListener('click', function() {
        const passwordInput = document.getElementById('password');
        const icon = this;

        // Toggle the type attribute
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);

        // Toggle the icon (eye-slash means password is being viewed)
        icon.classList.toggle('fa-eye-slash');
        icon.classList.toggle('fa-eye');
    });
</script>

</body>
</html>