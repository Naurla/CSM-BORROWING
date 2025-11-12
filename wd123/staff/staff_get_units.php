<?php
session_start();
require_once "../classes/Transaction.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'staff') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

$type_id = filter_input(INPUT_GET, 'type_id', FILTER_VALIDATE_INT);

if (!$type_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid apparatus type ID.']);
    exit;
}

$transaction = new Transaction();
// Uses the new getUnitsByType method from Transaction.php
$units = $transaction->getUnitsByType($type_id); 

if ($units === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed.']);
    exit;
}

// Success response
echo json_encode(['units' => $units]);
?>