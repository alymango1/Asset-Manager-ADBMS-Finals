<?php
/**
 * reserve_item.php
 *
 * This page is no longer used as a standalone UI.
 * The reservation modal is now handled inline on reservation.php.
 *
 * If this page is opened directly (for example from a bookmark), redirect safely.
 */
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header("Location: login.php");
    exit();
}

// If an equipment ID was passed, send them to the reservation page
// so they can use the modal there
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
header("Location: reservation.php" . ($id ? "?highlight=$id" : ""));
exit();