
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
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION["user_id"];


/* =========================================================
   DATABASE - SUPABASE PDO
========================================================= */

try {

    require_once __DIR__ . "/config/supabase.php";

    if (!isset($supabase) || !($supabase instanceof PDO)) {
        throw new RuntimeException("Supabase PDO connection is unavailable.");
    }

} catch (Throwable $e) {

    error_log("Item.php connection error: " . $e->getMessage());

    http_response_code(500);
    exit("Database connection failed. Please check config/supabase.php.");
}


/* =========================================================
   CHECK ITEM ID
========================================================= */

if (
    !isset($_GET["id"]) ||
    !ctype_digit((string) $_GET["id"]) ||
    (int) $_GET["id"] <= 0
) {
    header("Location: search.php");
    exit();
}

$item_id = (int) $_GET["id"];


/* =========================================================
   INITIALIZE VARIABLES
========================================================= */

$current_user = null;
$item = null;
$user_claim = null;

$total_unread = 0;
$notification_unread = 0;


/* =========================================================
   GET DATABASE INFORMATION
========================================================= */

try {

    /* GET CURRENT USER */

    $user_stmt = $supabase->prepare("
        SELECT id, student_id, name, email, role
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");

    $user_stmt->execute([
        ":user_id" => $user_id
    ]);

    $current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);

    $user_stmt->closeCursor();

    if (!$current_user) {
        session_destroy();
        header("Location: login.php");
        exit();
    }


    /* GET UNREAD MESSAGES */

    $unread_stmt = $supabase->prepare("
        SELECT COUNT(*) AS total_unread
        FROM messages
        WHERE receiver_id = :user_id
        AND is_read = FALSE
    ");

    $unread_stmt->execute([
        ":user_id" => $user_id
    ]);

    $unread_result = $unread_stmt->fetch(PDO::FETCH_ASSOC);

    $total_unread = (int) ($unread_result["total_unread"] ?? 0);

    $unread_stmt->closeCursor();


    /* GET UNREAD NOTIFICATIONS */

    try {

        $notification_stmt = $supabase->prepare("
            SELECT COUNT(*) AS total
            FROM notifications
            WHERE user_id = :user_id
            AND is_read = FALSE
            AND type = 'ai_match'
        ");

        $notification_stmt->execute([
            ":user_id" => $user_id
        ]);

        $notification_result = $notification_stmt->fetch(PDO::FETCH_ASSOC);

        $notification_unread = (int) ($notification_result["total"] ?? 0);

        $notification_stmt->closeCursor();

    } catch (Throwable $e) {

        $notification_unread = 0;

        error_log("Notification error: " . $e->getMessage());
    }


    /* GET ITEM */

    $item_stmt = $supabase->prepare("
        SELECT
            items.*,
            users.name AS reporter_name,
            users.student_id AS reporter_student_id
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


    /* CHECK ITEM */

    if (!$item) {
        header("Location: search.php");
        exit();
    }


    /* GET USER CLAIM */

    $claim_stmt = $supabase->prepare("
        SELECT id, status
        FROM claims
        WHERE item_id = :item_id
        AND user_id = :user_id
        ORDER BY created_at DESC
        LIMIT 1
    ");

    $claim_stmt->execute([
        ":item_id" => $item_id,
        ":user_id" => $user_id
    ]);

    $user_claim = $claim_stmt->fetch(PDO::FETCH_ASSOC);

    $claim_stmt->closeCursor();


} catch (Throwable $e) {

    error_log("Item details error: " . $e->getMessage());

    http_response_code(500);

    exit("Unable to load item details. Please check your database configuration.");
}


/* =========================================================
   ITEM DISPLAY VALUES
========================================================= */

$item_name = $item["item_name"] ?? "Unnamed Item";

$item_status = ucwords(
    str_replace("_", " ", $item["status"] ?? "Unknown")
);

$is_found = (($item["item_type"] ?? "") === "found");

$is_own_item = ((int) $item["user_id"] === $user_id);

$is_returned = (($item["status"] ?? "") === "returned");

$claim_url = "claim.php?id=" . (int) $item["id"];

$profile_initial = strtoupper(
    substr(trim($current_user["name"] ?? ""), 0, 1)
);

if ($profile_initial === "") {
    $profile_initial = "U";
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">

<title>
    <?php echo htmlspecialchars($item_name); ?>
    - RTU Lost and Found
</title>

<style>

:root {
    --rtu-blue: #003b7a;
    --rtu-blue-dark: #002b5c;
    --rtu-blue-light: #0b5cab;
    --rtu-gold: #f4c430;
    --rtu-gold-dark: #c99d18;
    --background: #f4f7fb;
    --card: #ffffff;
    --text: #1e293b;
    --muted: #64748b;
    --border: #dbe3ee;
    --danger: #dc2626;
    --success: #15803d;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: var(--background);
    color: var(--text);
    min-height: 100vh;
}

/* NAVBAR */

.navbar {
    width: 100%;
    min-height: 70px;
    background: linear-gradient(135deg, #0f2f5f, #164a8a);
    color: white;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 28px;
    border-bottom: 4px solid #d4af37;
    box-shadow: 0 4px 15px rgba(15, 47, 95, 0.20);
    position: relative;
    z-index: 1000;
}

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: white;
    flex-shrink: 0;
}

.brand-title {
    font-size: 21px;
    font-weight: bold;
    white-space: nowrap;
}

.brand-logo {
    width: 46px;
    height: 46px;
    object-fit: contain;
    display: block;
}

.profile-container {
    position: relative;
}

.profile-button {
    display: flex;
    align-items: center;
    gap: 9px;
    background: transparent;
    border: none;
    color: white;
    cursor: pointer;
    padding: 5px 7px;
    border-radius: 30px;
    font-family: Arial, Helvetica, sans-serif;
    transition: background 0.2s;
}

.profile-button:hover {
    background: rgba(255, 255, 255, 0.12);
}

.profile-name {
    font-size: 14px;
    font-weight: 600;
    max-width: 160px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.profile-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #d4af37;
    color: #0f2f5f;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: bold;
    border: 2px solid rgba(255, 255, 255, 0.8);
}

.profile-arrow {
    width: 0;
    height: 0;
    border-left: 5px solid transparent;
    border-right: 5px solid transparent;
    border-top: 6px solid white;
    transition: transform 0.2s;
}

.profile-button.open .profile-arrow {
    transform: rotate(180deg);
}

.profile-dropdown {
    position: absolute;
    top: calc(100% + 12px);
    right: 0;
    width: 310px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 10px 35px rgba(0, 0, 0, 0.20);
    border: 1px solid #e2e8f0;
    overflow: hidden;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-8px);
    transition: opacity 0.2s, transform 0.2s, visibility 0.2s;
}

.profile-dropdown.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.profile-header {
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 13px;
    background: white;
    border-bottom: 1px solid #e5e7eb;
}

.profile-header-circle {
    width: 48px;
    height: 48px;
    min-width: 48px;
    border-radius: 50%;
    background: #0f2f5f;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
    font-weight: bold;
    border: 3px solid #d4af37;
}

.profile-details {
    min-width: 0;
}

.profile-details strong {
    display: block;
    color: #172554;
    font-size: 15px;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.profile-details span {
    display: block;
    color: #64748b;
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dropdown-links {
    padding: 8px;
}

.dropdown-link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 12px 13px;
    color: #334155;
    text-decoration: none;
    border-radius: 9px;
    font-size: 14px;
    font-weight: 600;
    transition: background 0.2s, color 0.2s;
}

.dropdown-link:hover {
    background: #eff6ff;
    color: #164a8a;
}

.dropdown-link-content {
    display: flex;
    align-items: center;
    gap: 10px;
}

.dropdown-marker {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #d4af37;
    flex-shrink: 0;
}

.dropdown-badge {
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 20px;
    background: #dc2626;
    color: white;
    font-size: 10px;
    font-weight: bold;
}

.signout {
    color: #b91c1c !important;
}

.signout:hover {
    background: #fef2f2 !important;
    color: #991b1b !important;
}

/* MAIN */

.container {
    width: min(1050px, 92%);
    margin: 32px auto 55px;
}

.back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 20px;
    color: var(--rtu-blue);
    text-decoration: none;
    font-weight: bold;
    font-size: 14px;
    padding: 9px 13px;
    border-radius: 8px;
    transition: 0.2s ease;
}

.back:hover {
    background: #e8f0fa;
    color: var(--rtu-blue-dark);
}

/* ITEM CARD */

.item-card {
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 7px 25px rgba(15, 47, 95, 0.10);
    border: 1px solid var(--border);
}

.item-image {
    width: 100%;
    max-height: 500px;
    object-fit: contain;
    background: #eef2f7;
    display: block;
    padding: 15px;
}

.no-image {
    height: 320px;
    display: flex;
    justify-content: center;
    align-items: center;
    background: #eef2f7;
    color: var(--muted);
    font-size: 18px;
}

.item-content {
    padding: 32px;
}

.item-content h1 {
    margin: 0 0 15px;
    color: var(--rtu-blue-dark);
    font-size: 30px;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

/* BADGES */

.badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 25px;
}

.badge {
    display: inline-block;
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

/* DETAILS */

.details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-bottom: 25px;
}

.detail {
    padding: 17px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.detail strong {
    display: block;
    margin-bottom: 7px;
    color: var(--rtu-blue);
    font-size: 13px;
}

/* DESCRIPTION */

.description {
    margin-top: 20px;
    padding: 22px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-left: 5px solid var(--rtu-gold);
    border-radius: 10px;
    line-height: 1.8;
    overflow-wrap: anywhere;
}

.description strong {
    color: var(--rtu-blue);
}

/* CLAIM SECTION */

.section {
    margin-top: 25px;
    background: white;
    padding: 30px;
    border-radius: 16px;
    box-shadow: 0 6px 22px rgba(15, 47, 95, 0.08);
    border: 1px solid var(--border);
}

.claim-section {
    position: relative;
    overflow: hidden;
}

.claim-section::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 5px;
    background: linear-gradient(90deg, var(--rtu-blue), var(--rtu-gold));
}

.section h2 {
    margin: 0 0 12px;
    color: var(--rtu-blue-dark);
    font-size: 23px;
}

.section-intro {
    color: var(--muted);
    line-height: 1.7;
    font-size: 14px;
}

/* CLAIM GUIDANCE */

.claim-guidance {
    margin-top: 22px;
    padding: 19px;
    background: #f0f6ff;
    border: 1px solid #d6e6fa;
    border-radius: 12px;
}

.claim-guidance h3 {
    color: var(--rtu-blue-dark);
    font-size: 15px;
    margin-bottom: 9px;
}

.claim-guidance p {
    color: #475569;
    font-size: 13px;
    line-height: 1.7;
}

/* CLAIM BUTTON */

.claim-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-top: 22px;
    min-height: 50px;
    padding: 14px 26px;
    background: linear-gradient(135deg, #164a8a, #0b5cab);
    color: white;
    text-decoration: none;
    border-radius: 10px;
    font-weight: bold;
    font-size: 15px;
    border: 1px solid #164a8a;
    border-bottom: 4px solid var(--rtu-gold);
    box-shadow: 0 5px 13px rgba(15, 47, 95, 0.16);
    transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
}

.claim-button:hover {
    background: linear-gradient(135deg, #0f3c75, #084b91);
    transform: translateY(-2px);
    box-shadow: 0 9px 20px rgba(15, 47, 95, 0.22);
}

.claim-button:focus-visible {
    outline: 3px solid rgba(244, 196, 48, 0.7);
    outline-offset: 3px;
}

.claim-button span {
    font-size: 20px;
    line-height: 1;
}

.claim-footnote {
    margin-top: 12px;
    color: #64748b;
    font-size: 12px;
    line-height: 1.6;
}

/* NOTICE */

.notice {
    padding: 17px 19px;
    border-radius: 10px;
    background: #fff7e6;
    color: #92400e;
    border: 1px solid #fde68a;
    margin-top: 18px;
    line-height: 1.7;
    font-size: 14px;
}

/* CLAIM STATUS */

.claim-approved,
.claim-pending,
.claim-rejected {
    margin-top: 20px;
    padding: 20px;
    border-radius: 11px;
    line-height: 1.7;
    font-size: 14px;
}

.claim-approved {
    background: #ecfdf5;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.claim-pending {
    background: #fff7e6;
    color: #92400e;
    border: 1px solid #fde68a;
}

.claim-rejected {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.claim-status-title {
    display: block;
    font-size: 16px;
    font-weight: bold;
    margin-bottom: 5px;
}

/* RESPONSIVE */

@media (max-width: 800px) {
    .navbar {
        padding: 12px 18px;
    }

    .brand-title {
        font-size: 18px;
    }

    .brand-logo {
        width: 42px;
        height: 42px;
    }

    .profile-name {
        max-width: 120px;
    }
}

@media (max-width: 700px) {
    .details {
        grid-template-columns: 1fr;
    }

    .item-content {
        padding: 21px;
    }

    .item-content h1 {
        font-size: 25px;
    }

    .section {
        padding: 23px 21px;
    }

    .claim-button {
        width: 100%;
    }
}

@media (max-width: 500px) {
    .navbar {
        padding: 10px 14px;
    }

    .brand-title {
        font-size: 16px;
    }

    .brand-logo {
        width: 38px;
        height: 38px;
    }

    .profile-name {
        display: none;
    }

    .profile-dropdown {
        width: calc(100vw - 28px);
        right: 0;
    }

    .item-image {
        max-height: 350px;
    }

    .container {
        width: 94%;
        margin-top: 22px;
    }

    .item-content {
        padding: 19px;
    }

    .section {
        padding: 22px 18px;
    }
}

/* PRINT */

@media print {
    .navbar,
    .back {
        display: none;
    }
}

</style>

</head>

<body>

<!-- NAVBAR -->

<nav class="navbar">

    <a href="dashboard.php" class="brand">

        <span class="brand-title">
            RTU Lost and Found
        </span>

        <img
            src="uploads/rtulogo.png"
            alt="RTU Logo"
            class="brand-logo"
        >

    </a>

    <div class="profile-container">

        <button
            type="button"
            class="profile-button"
            id="profileButton"
            aria-label="Open profile menu"
            aria-expanded="false"
        >

            <span class="profile-name">
                <?php echo htmlspecialchars($current_user["name"]); ?>
            </span>

            <span class="profile-circle">
                <?php echo htmlspecialchars($profile_initial); ?>
            </span>

            <span class="profile-arrow"></span>

        </button>

        <div class="profile-dropdown" id="profileDropdown">

            <div class="profile-header">

                <div class="profile-header-circle">
                    <?php echo htmlspecialchars($profile_initial); ?>
                </div>

                <div class="profile-details">

                    <strong>
                        <?php echo htmlspecialchars($current_user["name"]); ?>
                    </strong>

                    <span>
                        <?php echo htmlspecialchars($current_user["email"]); ?>
                    </span>

                </div>

            </div>

            <div class="dropdown-links">

                <a href="messages.php" class="dropdown-link">

                    <span class="dropdown-link-content">
                        <span class="dropdown-marker"></span>
                        <span>Messages</span>
                    </span>

                    <?php if ($total_unread > 0): ?>
                        <span class="dropdown-badge">
                            <?php echo $total_unread; ?>
                        </span>
                    <?php endif; ?>

                </a>

                <a href="notifications.php" class="dropdown-link">

                    <span class="dropdown-link-content">
                        <span class="dropdown-marker"></span>
                        <span>Notifications</span>
                    </span>

                    <?php if ($notification_unread > 0): ?>
                        <span class="dropdown-badge">
                            <?php echo $notification_unread; ?>
                        </span>
                    <?php endif; ?>

                </a>

                <a href="logout.php" class="dropdown-link signout">

                    <span class="dropdown-link-content">
                        <span class="dropdown-marker"></span>
                        <span>Sign Out</span>
                    </span>

                </a>

            </div>

        </div>

    </div>

</nav>


<!-- MAIN -->

<div class="container">

    <!-- ITEM CARD -->

    <div class="item-card">

        <?php if (!empty($item["image"])): ?>

            <?php

            $image_folder = (
                ($item["item_type"] ?? "") === "lost"
            ) ? "uploads/lost/" : "uploads/found/";

            ?>

            <img
                class="item-image"
                src="<?php echo htmlspecialchars($image_folder . $item["image"]); ?>"
                alt="<?php echo htmlspecialchars($item_name); ?>"
            >

        <?php else: ?>

            <div class="no-image">
                No Image Available
            </div>

        <?php endif; ?>


        <div class="item-content">

            <h1>
                <?php echo htmlspecialchars($item_name); ?>
            </h1>

            <div class="badges">

                <?php if (($item["item_type"] ?? "") === "lost"): ?>

                    <span class="badge lost">LOST</span>

                <?php else: ?>

                    <span class="badge found">FOUND</span>

                <?php endif; ?>

                <span class="badge status">
                    <?php echo htmlspecialchars($item_status); ?>
                </span>

            </div>


            <!-- DETAILS -->

            <div class="details">

                <div class="detail">
                    <strong>Category</strong>
                    <?php echo htmlspecialchars($item["category"] ?? "N/A"); ?>
                </div>

                <div class="detail">
                    <strong>Location</strong>
                    <?php echo htmlspecialchars($item["location"] ?? "N/A"); ?>
                </div>

                <div class="detail">
                    <strong>Date Reported</strong>
                    <?php echo htmlspecialchars($item["date_reported"] ?? "N/A"); ?>
                </div>

                <div class="detail">
                    <strong>Reported By</strong>
                    <?php echo htmlspecialchars($item["reporter_name"] ?? "N/A"); ?>
                </div>

            </div>


            <!-- DESCRIPTION -->

            <div class="description">

                <strong>Description</strong>

                <br><br>

                <?php

                if (!empty($item["description"])) {

                    echo nl2br(
                        htmlspecialchars($item["description"])
                    );

                } else {

                    echo "No description provided.";

                }

                ?>

            </div>

        </div>

    </div>


    <!-- CLAIM SECTION -->

    <?php if ($is_found): ?>

        <div class="section claim-section">

            <h2>Claim This Item</h2>

            <p class="section-intro">
                Think this item belongs to you?
                You can submit a claim so the administrator
                can verify your ownership.
            </p>


            <?php if ($is_returned): ?>

                <div class="notice">

                    <strong>Item Already Returned</strong>

                    <br>

                    This item has already been returned to its
                    rightful owner. New claims are no longer available.

                </div>


            <?php elseif ($is_own_item): ?>

                <div class="notice">

                    <strong>Your Own Report</strong>

                    <br>

                    You submitted this found-item report.
                    You cannot submit a claim for an item you reported.

                </div>


            <?php elseif (
                $user_claim &&
                $user_claim["status"] === "pending"
            ): ?>

                <div class="claim-pending">

                    <span class="claim-status-title">
                        Claim Under Review
                    </span>

                    Your claim has been submitted successfully
                    and is currently awaiting administrator review.

                    Please wait for an update regarding your claim.

                </div>


            <?php elseif (
                $user_claim &&
                $user_claim["status"] === "approved"
            ): ?>

                <div class="claim-approved">

                    <span class="claim-status-title">
                        Claim Approved
                    </span>

                    Your claim has been approved by the administrator.

                    Please check your notifications or messages
                    for further instructions about collecting the item.

                </div>


            <?php elseif (
                $user_claim &&
                $user_claim["status"] === "rejected"
            ): ?>

                <div class="claim-rejected">

                    <span class="claim-status-title">
                        Previous Claim Rejected
                    </span>

                    Your previous claim was not approved.

                    If you believe you can provide additional
                    identifying information or proof of ownership,
                    you may submit a new claim.

                    <br>

                    <a
                        class="claim-button"
                        href="<?php echo htmlspecialchars($claim_url); ?>"
                    >
                        <span>+</span>
                        Submit New Claim
                    </a>

                </div>


            <?php else: ?>

                <div class="claim-guidance">

                    <h3>Before submitting your claim</h3>

                    <p>
                        Prepare details that can help verify
                        ownership, such as identifying marks,
                        unique features, contents, or other
                        information that only the rightful owner
                        would know.
                    </p>

                </div>

                <a
                    class="claim-button"
                    href="<?php echo htmlspecialchars($claim_url); ?>"
                >
                    <span>+</span>
                    Claim This Item
                </a>

                <p class="claim-footnote">
                    Your claim will be reviewed by an administrator.
                    Providing accurate information helps verify
                    that the item belongs to you.
                </p>

            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>


<script>

/* PROFILE DROPDOWN */

const profileButton = document.getElementById("profileButton");
const profileDropdown = document.getElementById("profileDropdown");

if (profileButton && profileDropdown) {

    profileButton.addEventListener("click", function(event) {

        event.stopPropagation();

        profileDropdown.classList.toggle("show");
        profileButton.classList.toggle("open");

        const isExpanded = profileButton.classList.contains("open");

        profileButton.setAttribute("aria-expanded", isExpanded);

    });

    document.addEventListener("click", function(event) {

        if (
            !profileDropdown.contains(event.target) &&
            !profileButton.contains(event.target)
        ) {

            profileDropdown.classList.remove("show");
            profileButton.classList.remove("open");

            profileButton.setAttribute("aria-expanded", "false");

        }

    });

}


/* PREVENT BACK/FORWARD CACHE */

window.addEventListener("pageshow", function(event) {

    if (event.persisted) {
        window.location.reload();
    }

});

</script>

</body>
</html>
