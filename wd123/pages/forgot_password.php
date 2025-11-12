<?php
// pages/forgot_password.php
session_start();
// The path below assumes your 'Login.php' is in classes/ relative to the parent directory.
require_once '../classes/Login.php'; 

$message = '';
$error = '';
$reset_link = ''; // Variable to store the generated link for testing

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and validate email input
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $login_handler = new Login();
        
        $result = $login_handler->forgotPasswordAndGetLink($email);

        if (is_string($result)) {
            // Success: The result is the actual reset link
            $reset_link = $result; 
            // The message for successful generation (for testing)
            $message = "If an account with that email exists, a password reset link has been generated.";
            
        } else {
             // Generic message for security (prevents email enumeration)
             $message = "If an account with that email exists, a password reset link has been generated.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - CSM Borrowing</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        
        
        .login-card {
            background-color: #fff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }

        .logo {
            width: 80px;
            margin-bottom: 15px;
        }
        .app-title {
            color: #8B0000; 
            font-size: 1.2em;
            font-weight: bold;
            line-height: 1.4;
            margin-bottom: 25px;
        }

      
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }
        input[type="email"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            box-sizing: border-box;
        }

        
        .btn-submit {
            width: 100%;
            padding: 10px;
            background-color: #8B0000;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.3s;
        }
        .btn-submit:hover {
            background-color: #6a0000;
        }

        
        .alert {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .alert-error {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        .reset-link-box {
            background: #fff3cd; 
            border: 1px dashed #ffc107;
            padding: 15px;
            margin-top: 20px;
            text-align: left;
            word-wrap: break-word;
            font-size: 0.9em;
        }
        
       
        .back-link {
            display: block;
            margin-top: 20px;
            color: #8B0000;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo" class="logo"> 
        
        <div class="app-title">
            CSM LABORATORY<br>
            BORROWING APPARATUS
        </div>

        <h2 style="font-size: 1.5em; margin-bottom: 10px;">Forgot Your Password?</h2>
        <p style="margin-bottom: 25px; font-size: 0.95em;">Enter your email address and we'll send you a link to reset your password.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>

        <form action="forgot_password.php" method="POST">
            <div class="form-group">
                <label for="email">Email Address:</label>
                <input type="email" id="email" name="email" required placeholder="Enter your email"
                       value="<?php echo htmlspecialchars($email ?? ''); ?>">
            </div>
            
            <button type="submit" class="btn-submit">Send Reset Link</button>
        </form>
        
        <?php if ($reset_link): ?>
            <div class="reset-link-box">
                <strong>FOR NOW:</strong> 
                <p style="margin: 5px 0 0 0;">Click the link to continue:</p>
                <a href="<?php echo htmlspecialchars($reset_link); ?>" target="_blank" style="color: #007bff; text-decoration: underline;">
                    <?php echo htmlspecialchars(substr($reset_link, 0, 50) . '...'); // Display a truncated link for aesthetics ?>
                </a>
            </div>
        <?php endif; ?>

        <a href="login.php" class="back-link">Back to Login</a>
    </div>
</body>
</html>