<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

require_once "../classes/Clearance.php";

$clearance_id = filter_input(INPUT_GET, 'cid', FILTER_VALIDATE_INT);

if (!$clearance_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing clearance ID.']);
    exit;
}

$clearanceObj = new Clearance();

try {
    $signatures = $clearanceObj->getClearanceSignaturesForAdmin($clearance_id);

    $student_info_query = "
        SELECT CONCAT(s.fName, ' ', s.lName) AS student_name
        FROM student s
        JOIN clearance c ON s.student_id = c.student_id
        WHERE c.clearance_id = :cid";
    $stmt = $clearanceObj->getConnection()->prepare($student_info_query);
    $stmt->bindParam(':cid', $clearance_id, PDO::PARAM_INT);
    $stmt->execute();
    $student_name = $stmt->fetchColumn() ?? 'N/A';

    echo json_encode([
        'clearance_id' => $clearance_id,
        'student_name' => $student_name,
        'signatures' => $signatures
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

exit;
?>