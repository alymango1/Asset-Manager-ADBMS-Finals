<?php
session_start();
include('../database/db.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized request.']);
    exit();
}

if (
    empty($_SESSION['csrf_token']) ||
    !isset($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit();
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$resourceName = trim((string)($_POST['resource_name'] ?? ''));
$category = trim((string)($_POST['category'] ?? ''));
$allowedCategories = ['IT Equipment', 'Classroom', 'Events Equipment'];

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid equipment id.']);
    exit();
}

if ($resourceName === '') {
    echo json_encode(['success' => false, 'message' => 'Resource name cannot be empty.']);
    exit();
}

if (!in_array($category, $allowedCategories, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid category selected.']);
    exit();
}

$resourceNameEscaped = mysqli_real_escape_string($conn, $resourceName);
$categoryEscaped = mysqli_real_escape_string($conn, $category);

$update = mysqli_query(
    $conn,
    "UPDATE equipments
     SET resource_name = '$resourceNameEscaped',
         categories = '$categoryEscaped'
     WHERE equipment_id = $id"
);

if (!$update) {
    echo json_encode(['success' => false, 'message' => 'Failed to update equipment.']);
    exit();
}

echo json_encode(['success' => true, 'message' => 'Equipment details updated successfully.']);
exit();

