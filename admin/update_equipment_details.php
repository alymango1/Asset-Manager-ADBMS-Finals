<?php
session_start();
include('../database/db.php');
date_default_timezone_set('Asia/Manila');
mysqli_query($conn, "SET time_zone = '+08:00'");

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

$id           = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$resourceName = trim((string)($_POST['resource_name'] ?? ''));
$category     = trim((string)($_POST['category'] ?? ''));
$admin_id     = (int) $_SESSION['user_id'];
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

// grab current values to compare later
$current = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT resource_name, categories FROM equipments WHERE equipment_id = $id"
));
if (!$current) {
    echo json_encode(['success' => false, 'message' => 'Equipment not found.']);
    exit();
}

$resourceNameEscaped = mysqli_real_escape_string($conn, $resourceName);
$categoryEscaped     = mysqli_real_escape_string($conn, $category);

$update = mysqli_query($conn,
    "UPDATE equipments
     SET resource_name = '$resourceNameEscaped',
         categories    = '$categoryEscaped'
     WHERE equipment_id = $id"
);

if (!$update) {
    echo json_encode(['success' => false, 'message' => 'Failed to update equipment.']);
    exit();
}

// log only the fields that changed
$changes = [];
if ($current['resource_name'] !== $resourceName) {
    $changes[] = ['resource_name', $current['resource_name'], $resourceName];
}
if ($current['categories'] !== $category) {
    $changes[] = ['categories', $current['categories'], $category];
}

if (!empty($changes)) {
    $log_stmt = mysqli_prepare($conn, "
        INSERT INTO equipment_transactions
            (action_type, equipment_id, performed_by,
             field_changed, old_value, new_value, action_date, remarks)
        VALUES ('equipment_edited', ?, ?, ?, ?, ?, NOW(), ?)
    ");
    foreach ($changes as [$field, $old, $new]) {
        $log_remarks = "Inline edit on equipment #$id: $field changed from \"$old\" to \"$new\"";
        mysqli_stmt_bind_param($log_stmt, 'iissss',
            $id, $admin_id, $field, $old, $new, $log_remarks);
        mysqli_stmt_execute($log_stmt);
    }
    mysqli_stmt_close($log_stmt);
}

// end log

echo json_encode(['success' => true, 'message' => 'Equipment details updated successfully.']);
exit();