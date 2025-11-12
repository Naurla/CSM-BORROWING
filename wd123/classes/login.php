<?php
// classes/Login.php (MODIFIED)
require_once "Database.php";

class Login extends Database {
    public $email;
    public $password;
    private $user;
    private $error_reason; // <--- ADDED: To store the specific error reason

    // Helper method to update the user's password hash in the database
    public function updateUserPassword($userId, $newHashedPassword) {
        $conn = $this->connect();
        $stmt = $conn->prepare("UPDATE users SET password = :password WHERE id = :id");
        return $stmt->execute([
            ":password" => $newHashedPassword,
            ":id" => $userId
        ]);
    }

    public function login() {
        $conn = $this->connect();
        // Step 1: Fetch the user record
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([":email" => $this->email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $this->error_reason = 'user_not_found'; // <--- SET ERROR REASON
            return false;
        }

        $stored_password = $user['password'];
        $success = false;

        // --- MIGRATION LOGIN LOGIC ---
        // A. Attempt 1: Check against secure hash (for new/migrated users)
        if (password_verify($this->password, $stored_password)) {
            $success = true;
        } 
        // B. Attempt 2: Check against legacy/plain-text password (migration step)
        else if (strpos($stored_password, '$') !== 0 && $this->password === $stored_password) {
            
            $success = true;

            // CRITICAL: Immediately re-hash the password and save it back to the database.
            $new_hash = password_hash($this->password, PASSWORD_DEFAULT);
            $this->updateUserPassword($user['id'], $new_hash);
            
            error_log("Password migrated for user ID: " . $user['id']);

        } else {
            // Failure: Password does not match hash OR plain-text
            $this->error_reason = 'incorrect_password'; // <--- SET ERROR REASON
            $success = false;
        }

        // --- END MIGRATION LOGIN LOGIC ---

        if ($success) {
            // Populate user data and return true for successful login
            $this->user = [
                "id" => $user['id'],
                "firstname" => $user['firstname'],
                "lastname" => $user['lastname'],
                "email" => $user['email'],
                "role" => $user['role'],
                "student_id" => $user['student_id'] ?? null,
                "course" => $user['course'] ?? null
            ];
            return true;
        }

        return false;
    }

    public function getUser() {
        return $this->user;
    }
    
    // <--- ADDED: Getter for the specific error reason --->
    public function getErrorReason() {
        return $this->error_reason;
    }

    // ✅ FIXED: Uses MySQL's DATE_ADD function to correctly calculate expiration time.
    public function forgotPasswordAndGetLink($email) {
        $conn = $this->connect();
        
        // 1. Fetch the user record
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([":email" => $email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return false; // User not found
        }

        $userId = $user['id'];
        
        // 2. Generate and store the token
        $token = bin2hex(random_bytes(32)); 
        
        // Delete any old tokens
        $conn->prepare("DELETE FROM password_resets WHERE user_id = :user_id")->execute([':user_id' => $userId]);

        // Insert the new token, using MySQL's DATE_ADD(NOW(), INTERVAL 1 HOUR)
        $stmt_insert = $conn->prepare("
            INSERT INTO password_resets (user_id, token, expires_at) 
            VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 1 HOUR))
        ");
        
        $success = $stmt_insert->execute([
            ":user_id" => $userId, 
            ":token" => $token 
        ]);

        if ($success) {
            // Return the reset link for display/testing
            $reset_link = 'http://localhost/wd123/pages/reset_password.php?email=' . urlencode($email) . '&token=' . $token;
            
            return $reset_link; 
        }

        return false; // Failed to save token to DB
    }

    // ✅ ADDED: Validates the token from the reset link
    public function validateResetToken($email, $token) {
        $conn = $this->connect();
        
        // Check for a valid token that hasn't expired and matches the email's user
        $sql = "SELECT r.user_id FROM password_resets r
                JOIN users u ON r.user_id = u.id
                WHERE u.email = :email 
                AND r.token = :token 
                AND r.expires_at > NOW()";
                
        $stmt = $conn->prepare($sql);
        $stmt->execute([":email" => $email, ":token" => $token]);
        $result = $stmt->fetch();

        return $result ? $result['user_id'] : false;
    }

    // ✅ ADDED: Deletes the token after a successful password reset
    public function deleteResetToken($token) {
        $conn = $this->connect();
        $stmt = $conn->prepare("DELETE FROM password_resets WHERE token = :token");
        return $stmt->execute([":token" => $token]);
    }
}
?>