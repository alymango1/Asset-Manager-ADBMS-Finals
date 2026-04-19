<?php
include('../database/db.php');

if (isset($_GET['id'])) {

    $id = $_GET['id'];

    // OPTIONAL SAFETY: prevent deleting last admin
    $checkAdmin = mysqli_query($conn, "SELECT roles FROM users WHERE user_id=$id");
    $data = mysqli_fetch_assoc($checkAdmin);

    if ($data && $data['roles'] == 'admin') {

        $countAdmins = mysqli_fetch_assoc(
            mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE roles='admin'")
        )['total'];

        if ($countAdmins <= 1) {
            echo "<script>alert('Cannot delete the last admin!'); window.location='users.php';</script>";
            exit();
        }
    }

    mysqli_query($conn, "DELETE FROM users WHERE user_id=$id");

    header("Location: ../admin/users.php");
    exit();
}