
<?php

session_start();

/* =========================================================
   PREVENT BROWSER CACHING
========================================================= */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

/* =========================================================
   CHECK LOGIN
========================================================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

$user_id = (int) $_SESSION["user_id"];

/* =========================================================
   DATABASE - SUPABASE PDO
========================================================= */

try {

    require_once __DIR__ . "/../config/supabase.php";

    if (!isset($supabase) || !($supabase instanceof PDO)) {
        throw new RuntimeException("Supabase PDO connection unavailable.");
    }

} catch (Throwable $e) {

    error_log("Admin view item connection error: " . $e->getMessage());

    http_response_code(500);
    exit("Database connection failed.");

}

/* =========================================================
   VERIFY ADMIN ROLE
========================================================= */

try {

    $role_stmt = $supabase->prepare("
        SELECT id, name, email, role
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");

    $role_stmt->execute([
        ":user_id" => $user_id
    ]);

    $admin = $role_stmt->fetch(PDO::FETCH_ASSOC);
    $role_stmt->closeCursor();

    if (!$admin || $admin["role"] !== "admin") {
        http_response_code(403);
        exit("Access denied. Administrators only.");
    }

} catch (Throwable $e) {

    error_log("Admin verification error: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to verify administrator account.");

}

/* =========================================================
   VALIDATE ITEM ID
========================================================= */

if (
    !isset($_GET["id"]) ||
    !ctype_digit((string) $_GET["id"]) ||
    (int) $_GET["id"] <= 0
) {
    header("Location: items.php");
    exit();
}

$item_id = (int) $_GET["id"];

/* =========================================================
   GET ITEM DETAILS
========================================================= */

try {

    $item_stmt = $supabase->prepare("
        SELECT
            items.*,
            users.name AS reporter_name,
            users.student_id AS reporter_student_id,
            users.email AS reporter_email
        FROM items
        INNER JOIN users
            ON items.user_id = users.id
        WHERE items.id = :item_id
        LIMIT 1
    ");

    $item_stmt->execute([
        ":item_id" => $item_id
    ]);

    $item = $item_stmt->fetch(PDO::FETCH_ASSOC);
    $item_stmt->closeCursor();

    if (!$item) {
        header("Location: items.php");
        exit();
    }

} catch (Throwable $e) {

    error_log("Admin item details error: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to load item details.");

}

/* =========================================================
   DISPLAY VALUES
========================================================= */

function e($value) {
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

$item_name = $item["item_name"] ?? "Unnamed Item";

$item_type = strtolower($item["item_type"] ?? "");

$item_status = ucwords(
    str_replace("_", " ", $item["status"] ?? "Unknown")
);

$is_lost = ($item_type === "lost");

$image_folder = $is_lost
    ? "../uploads/lost/"
    : "../uploads/found/";

$image_path = $image_folder . ($item["image"] ?? "");

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">

<title><?php echo e($item_name); ?> - Admin | RTU Lost and Found</title>

<style>

/* =========================================================
   GLOBAL
========================================================= */

:root {
    --blue-dark: #0f2f5f;
    --blue: #164a8a;
    --blue-light: #0b5cab;
    --gold: #d4af37;
    --background: #f3f6fb;
    --text: #1f2937;
    --muted: #64748b;
    --border: #dce2e9;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

html {
    scroll-behavior: smooth;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: var(--background);
    color: var(--text);
    min-height: 100vh;
}

/* =========================================================
   TOP NAVBAR - ADMIN DASHBOARD DESIGN
========================================================= */

.navbar {
    width: 100%;
    min-height: 70px;
    background: linear-gradient(135deg, #0f2f5f, #164a8a);
    border-bottom: 4px solid #d4af37;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 4%;
    box-shadow: 0 3px 12px rgba(0,0,0,0.12);
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
}

.brand-text {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.brand-title {
    color: #fff;
    font-size: 20px;
    font-weight: bold;
    white-space: nowrap;
}

.brand-subtitle {
    color: #f5c542;
    font-size: 11px;
    font-weight: bold;
    letter-spacing: 1px;
}

.brand-logo {
    width: 46px;
    height: 46px;
    object-fit: contain;
    flex-shrink: 0;
}

/* =========================================================
   ADMIN NAVIGATION - WHITE SECONDARY BAR
========================================================= */

.admin-nav {
    width: 100%;
    min-height: 65px;
    background: #fff;
    border-bottom: 1px solid #dce2e9;
    display: flex;
    align-items: center;
    padding: 0 4%;
    gap: 5px;
}

.admin-nav a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    padding: 0 15px;
    border-radius: 7px;
    color: #24364b;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: 0.18s;
}

.admin-nav a:hover {
    background: #f1f5f9;
    color: #0f3d75;
}

.admin-nav a.active {
    background: #edf3fa;
    color: #0f2f5f;
    border-bottom: 3px solid #d4af37;
}

/* =========================================================
   MAIN CONTAINER
========================================================= */

.container {
    width: 92%;
    max-width: 1050px;
    margin: 32px auto 55px;
}

/* =========================================================
   PAGE HEADING
========================================================= */

.page-heading {
    margin-bottom: 22px;
}

.page-heading h1 {
    color: #0f3d75;
    font-size: 25px;
    font-weight: bold;
    margin-bottom: 7px;
}

.page-heading p {
    color: #64748b;
    font-size: 13px;
    line-height: 1.6;
}

/* =========================================================
   REPORT CARD
========================================================= */

.item-card {
    background: #fff;
    border-radius: 15px;
    overflow: hidden;
    border: 1px solid var(--border);
    box-shadow: 0 7px 25px rgba(15, 47, 95, 0.09);
}

.item-image {
    width: 100%;
    max-height: 500px;
    object-fit: contain;
    display: block;
    padding: 15px;
    background: #eef2f7;
}

.no-image {
    height: 300px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #eef2f7;
    color: var(--muted);
    font-size: 16px;
}

.item-content {
    padding: 32px;
}

.item-content h2.item-name {
    color: var(--blue-dark);
    font-size: 29px;
    line-height: 1.3;
    overflow-wrap: anywhere;
    margin-bottom: 16px;
}

/* =========================================================
   BADGES
========================================================= */

.badges {
    display: flex;
    flex-wrap: wrap;
    gap: 9px;
    margin-bottom: 27px;
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 7px 13px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    letter-spacing: 0.3px;
}

.lost {
    background: #fee2e2;
    color: #b91c1c;
}

.found {
    background: #dcfce7;
    color: #166534;
}

.status {
    background: #e2e8f0;
    color: #475569;
}

/* =========================================================
   SECTION TITLES
========================================================= */

.section-title {
    color: var(--blue-dark);
    font-size: 18px;
    margin: 27px 0 15px;
    padding-bottom: 11px;
    border-bottom: 2px solid #e5eaf1;
}

/* =========================================================
   DETAILS GRID
========================================================= */

.details {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.detail {
    padding: 17px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    line-height: 1.6;
    overflow-wrap: anywhere;
    min-width: 0;
}

.detail strong {
    display: block;
    color: var(--blue);
    font-size: 13px;
    margin-bottom: 6px;
}

/* =========================================================
   DESCRIPTION
========================================================= */

.description {
    padding: 22px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-left: 5px solid var(--gold);
    border-radius: 10px;
    line-height: 1.8;
    overflow-wrap: anywhere;
}

.description strong {
    display: block;
    color: var(--blue);
    margin-bottom: 10px;
}

/* =========================================================
   REPORTER INFORMATION
========================================================= */

.reporter-card {
    padding: 20px;
    background: #f0f6ff;
    border: 1px solid #d6e6fa;
    border-radius: 10px;
    line-height: 1.9;
    overflow-wrap: anywhere;
}

.reporter-card strong {
    color: var(--blue-dark);
}

/* =========================================================
   FOOTER
========================================================= */

.page-footer {
    text-align: center;
    color: var(--muted);
    font-size: 12px;
    margin-top: 25px;
    padding: 15px 0;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 800px) {

    .admin-nav {
        overflow-x: auto;
        padding: 10px 3%;
        justify-content: flex-start;
    }

    .admin-nav a {
        flex-shrink: 0;
    }

    .container {
        width: 94%;
        margin: 25px auto 40px;
    }

}

@media (max-width: 600px) {

    .navbar {
        padding: 10px 4%;
    }

    .brand-title {
        font-size: 17px;
    }

    .brand-logo {
        width: 40px;
        height: 40px;
    }

    .admin-nav a {
        font-size: 12px;
        padding: 0 10px;
    }

    .details {
        grid-template-columns: 1fr;
    }

    .item-content {
        padding: 22px;
    }

    .item-content h2.item-name {
        font-size: 25px;
    }

    .page-heading h1 {
        font-size: 22px;
    }

}

@media (max-width: 400px) {

    .brand-title {
        font-size: 15px;
    }

    .brand-logo {
        width: 36px;
        height: 36px;
    }

    .brand-subtitle {
        font-size: 10px;
    }

    .container {
        width: 94%;
        margin-top: 20px;
    }

    .item-content {
        padding: 18px;
    }

    .item-image {
        max-height: 350px;
    }

}

/* =========================================================
   PRINT
========================================================= */

@media print {

    .navbar,
    .admin-nav {
        display: none !important;
    }

    body {
        background: white;
    }

    .item-card {
        box-shadow: none;
    }

    .container {
        width: 100%;
        margin: 0;
    }

}

</style>

</head>

<body>

<!-- =====================================================
     TOP NAVBAR
===================================================== -->

<div class="navbar">

    <a href="dashboard.php" class="brand">

        <div class="brand-text">

            <span class="brand-title">
                RTU Lost and Found
            </span>

            <span class="brand-subtitle">
                ADMINISTRATION
            </span>

        </div>

        <img
            src="../uploads/rtulogo.png"
            alt="RTU Logo"
            class="brand-logo"
        >

    </a>

</div>


<!-- =====================================================
     ADMIN NAVIGATION
===================================================== -->

<nav class="admin-nav">

    <a href="items.php" class="active">
        Item Verification
    </a>

    <a href="users.php">
        Users
    </a>

</nav>


<!-- =====================================================
     MAIN CONTENT
===================================================== -->

<main class="container">

    <div class="page-heading">

        <h1>Item Report Details</h1>

        <p>
            View the complete report information and reporter details
            submitted to the RTU Lost and Found system.
        </p>

    </div>


    <div class="item-card">

        <!-- ITEM IMAGE -->

        <?php if (!empty($item["image"])): ?>

            <img
                class="item-image"
                src="<?php echo e($image_path); ?>"
                alt="<?php echo e($item_name); ?>"
            >

        <?php else: ?>

            <div class="no-image">
                No Image Available
            </div>

        <?php endif; ?>


        <div class="item-content">

            <h2 class="item-name">
                <?php echo e($item_name); ?>
            </h2>


            <!-- ITEM STATUS -->

            <div class="badges">

                <?php if ($is_lost): ?>

                    <span class="badge lost">
                        LOST
                    </span>

                <?php else: ?>

                    <span class="badge found">
                        FOUND
                    </span>

                <?php endif; ?>

                <span class="badge status">
                    <?php echo e($item_status); ?>
                </span>

            </div>


            <!-- REPORT INFORMATION -->

            <h3 class="section-title">
                Report Information
            </h3>

            <div class="details">

                <div class="detail">
                    <strong>Report ID</strong>
                    <?php echo (int) $item["id"]; ?>
                </div>

                <div class="detail">
                    <strong>Verification Code</strong>

                    <?php
                    echo !empty($item["verification_code"])
                        ? e($item["verification_code"])
                        : "Not Available";
                    ?>

                </div>

                <div class="detail">
                    <strong>Item Name</strong>
                    <?php echo e($item_name); ?>
                </div>

                <div class="detail">
                    <strong>Item Type</strong>
                    <?php echo e(ucfirst($item_type)); ?>
                </div>

                <div class="detail">
                    <strong>Category</strong>
                    <?php echo e($item["category"] ?? "N/A"); ?>
                </div>

                <div class="detail">
                    <strong>Location</strong>
                    <?php echo e($item["location"] ?? "N/A"); ?>
                </div>

                <div class="detail">
                    <strong>Date Reported</strong>
                    <?php echo e($item["date_reported"] ?? "N/A"); ?>
                </div>

                <div class="detail">
                    <strong>Date Created</strong>
                    <?php echo e($item["created_at"] ?? "N/A"); ?>
                </div>

            </div>


            <!-- ITEM DESCRIPTION -->

            <h3 class="section-title">
                Item Description
            </h3>

            <div class="description">

                <strong>Description</strong>

                <?php if (!empty($item["description"])): ?>

                    <?php echo nl2br(e($item["description"])); ?>

                <?php else: ?>

                    No description provided.

                <?php endif; ?>

            </div>


            <!-- REPORTER INFORMATION -->

            <h3 class="section-title">
                Reporter Information
            </h3>

            <div class="reporter-card">

                <div>
                    <strong>Reported By:</strong>
                    <?php echo e($item["reporter_name"] ?? "N/A"); ?>
                </div>

                <div>
                    <strong>Student ID:</strong>
                    <?php echo e($item["reporter_student_id"] ?? "N/A"); ?>
                </div>

                <div>
                    <strong>Email:</strong>
                    <?php echo e($item["reporter_email"] ?? "N/A"); ?>
                </div>

            </div>

        </div>

    </div>


    <div class="page-footer">
        RTU Lost and Found Management System - Administrator Report View
    </div>

</main>

</body>
</html>
