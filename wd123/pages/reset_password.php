<?php
// File: pages/reset_password.php
session_start();
require_once '../classes/Login.php'; // Adjust path if necessary

$message = '';
$error = '';
$email_from_get = $_GET['email'] ?? ''; 
$token_from_get = $_GET['token'] ?? '';

// Check if email and token are present in the URL
if (empty($email_from_get) || empty($token_from_get)) {
    $error = "Invalid or missing reset link parameters.";
}

$login_handler = new Login();

$user_id = 0;
if (empty($error)) {
    // 1. Validate the token and get the user ID
    $user_id = $login_handler->validateResetToken($email_from_get, $token_from_get);
    if (!$user_id) {
        $error = "The password reset token is invalid or has expired.";
    }
}

// 2. Handle the new password submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($error)) {
    $new_password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($new_password) < 8) { // Basic validation
        $error = "Password must be at least 8 characters long.";
    } else {
        // Hash the new password
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update the password using the helper method from the Login class
        if ($login_handler->updateUserPassword($user_id, $new_hash)) {
            // Success! Invalidate the token to prevent reuse.
            $login_handler->deleteResetToken($token_from_get);
            $message = "Your password has been successfully reset. You can now log in.";
        } else {
            $error = "A database error occurred while updating your password.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        body {
            background-color: #f7f7f7;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            font-family: Arial, sans-serif;
        }
        .card {
            background-color: #ffffff;
            border-radius: 0.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 2.5rem;
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .logo {
            max-width: 100px;
            margin: 0 auto 1.5rem auto;
        }
        .header-text {
            color: #A32929;
            font-size: 1.25rem;
            font-weight: bold;
            margin-bottom: 2rem;
        }
        .input-group {
            margin-bottom: 1.5rem;
            text-align: left;
            position: relative; 
        }
        .input-group label {
            display: block;
            color: #333;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        .input-field {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #ccc;
            border-radius: 0.25rem;
            font-size: 1rem;
            box-sizing: border-box;
            padding-right: 2.5rem; 
        }
      
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 60%; 
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            z-index: 10;
        }
        .btn-primary {
            background-color: #A32929;
            color: #ffffff;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.25rem;
            font-size: 1rem;
            cursor: pointer;
            width: 100%;
            transition: background-color 0.2s ease;
        }
        .btn-primary:hover {
            background-color: #8C2222;
        }
        .link-text {
            color: #A32929;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .link-text:hover {
            text-decoration: underline;
        }
        .message-success {
            color: green;
            margin-bottom: 1rem;
        }
        .message-error {
            color: red;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="card">
        <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo" class="logo">
        <div class="header-text">CSM LABORATORY BORROWING APPARATUS</div>
        
        <h2 style="font-size: 1.5rem;">Reset Your Password</h2>
        
        <?php if ($error): ?>
            <p class="message-error"><?php echo $error; ?></p>
        <?php endif; ?>

        <?php if ($message): ?>
            <p class="message-success"><?php echo $message; ?></p>
            <p class="mt-4"><a href="login.php" class="link-text">Go to Login</a></p>
        <?php endif; ?>

        <?php if (!$message && empty($error)): ?>
        <form action="reset_password.php?email=<?php echo urlencode($email_from_get); ?>&token=<?php echo urlencode($token_from_get); ?>" method="POST">
            
            <div class="input-group">
                <label for="password">New Password:</label>
                <input type="password" id="password" name="password" class="input-field" required>
                <i class="fas fa-eye password-toggle" onclick="togglePasswordVisibility('password', this)"></i>
            </div>
            
            <div class="input-group">
                <label for="confirm_password">Confirm New Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" class="input-field" required>
                <i class="fas fa-eye password-toggle" onclick="togglePasswordVisibility('confirm_password', this)"></i>
            </div>
            
            <div>
                <button type="submit" class="btn-primary">Reset Password</button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <script>
        function togglePasswordVisibility(id, iconElement) {
            const input = document.getElementById(id);
            
            if (input.type === "password") {
                input.type = "text";
                iconElement.classList.remove('fa-eye');
                iconElement.classList.add('fa-eye-slash');
            } else {
                input.type = "password";
                iconElement.classList.remove('fa-eye-slash');
                iconElement.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>