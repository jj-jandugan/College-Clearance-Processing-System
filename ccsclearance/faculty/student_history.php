<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'signer') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

require_once "../classes/Clearance.php";

if (isset($_GET['clearance_id']) && is_numeric($_GET['clearance_id'])) {
    $clearance_id = (int)$_GET['clearance_id'];

    $clearanceObj = new Clearance();

    try {
        $history = $clearanceObj->getRequiredSignersFullList($clearance_id);

        echo json_encode($history);

    } catch (Exception $e) {
        http_response_code(500);
        error_log("Database error: " . $e->getMessage());
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing clearance ID.']);
}

exit;
?>