<?php
session_start(); // Resume the current session
session_unset(); // Remove all session variables
session_destroy(); // Destroy the session completely

// Redirect user back to login page
header("Location: login.php");
exit;
