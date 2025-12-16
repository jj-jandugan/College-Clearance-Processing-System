<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

require_once "../classes/Database.php";
require_once "../classes/Clearance.php";

$clearance_id = filter_input(INPUT_GET, 'cid', FILTER_VALIDATE_INT);
if (!$clearance_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Error: Clearance ID required.']);
    exit;
}

$clearanceObj = new Clearance();

$data = $clearanceObj->getCertificateData($clearance_id);

if (!$data || $data['clearance_status'] !== 'CLEARED') {
    http_response_code(404);
    echo json_encode([
        'clearance_status' => $data['clearance_status'] ?? 'NOT FOUND',
        'error' => 'Certificate not found or clearance is not fully approved.'
    ]);
    exit;
}

if (isset($data['date_issued'])) {
    $data['date_issued'] = date('F j, Y', strtotime($data['date_issued']));
}

echo json_encode($data);

exit;
?>