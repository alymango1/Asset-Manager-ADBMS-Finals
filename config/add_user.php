<?php
include('../database/db.php');

if (isset($_POST['add_user'])) {

    $full_name = $_POST['full_name'];
    $username  = $_POST['username'];
    $password  = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role      = $_POST['role'];

    mysqli_query($conn, "INSERT INTO users (full_name, username, password, roles)
    VALUES ('$full_name', '$username', '$password', '$role')");

    header("Location: ../admin/users.php");
    exit();
}