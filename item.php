
<?php

session_start();

require_once "config/database.php";

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
   GET CURRENT USER
========================================================= */

$user_stmt = $conn->prepare("
    SELECT
        id,
        student_id,
        name,
        email,
        role
    FROM users
    WHERE id = ?
    LIMIT 1
");

$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();

$user_result = $user_stmt->get_result();
$current_user = $user_result->fetch_assoc();

$user_stmt->close();


if (!$current_user) {
    session_destroy();
    header("Location: login.php");
    exit();
}


/* =========================================================
   GET UNREAD MESSAGES
========================================================= */

$total_unread = 0;

$unread_stmt = $conn->prepare("
    SELECT COUNT(*) AS total_unread
    FROM messages
    WHERE receiver_id = ?
    AND is_read = 0
");

if ($unread_stmt) {

    $unread_stmt->bind_param("i", $user_id);
    $unread_stmt->execute();

    $unread_result = $unread_stmt->get_result()->fetch_assoc();

    $total_unread = (int) $unread_result["total_unread"];

    $unread_stmt->close();
}


/* =========================================================
   GET UNREAD NOTIFICATIONS
========================================================= */

$notification_unread = 0;

$notification_stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE user_id = ?
    AND is_read = 0
    AND type = 'ai_match'
");

if ($notification_stmt) {

    $notification_stmt->bind_param("i", $user_id);
    $notification_stmt->execute();

    $notification_result = $notification_stmt->get_result()->fetch_assoc();

    $notification_unread = (int) $notification_result["total"];

    $notification_stmt->close();
}


/* =========================================================
   CHECK ITEM ID
========================================================= */

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    header("Location: search.php");
    exit();
}

$item_id = (int) $_GET["id"];


/* =========================================================
   GET ITEM
========================================================= */

$sql = "
    SELECT
        items.*,
        users.name AS reporter_name,
        users.student_id AS reporter_student_id
    FROM items
    JOIN users
        ON items.user_id = users.id
    WHERE items.id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

$stmt->bind_param("i", $item_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    $stmt->close();

    header("Location: search.php");
    exit();
}

$item = $result->fetch_assoc();

$stmt->close();


/* =========================================================
   CHECK USER CLAIM
========================================================= */

$user_claim = null;

$claim_sql = "
    SELECT
        id,
        status
    FROM claims
    WHERE item_id = ?
    AND user_id = ?
    ORDER BY created_at DESC
    LIMIT 1
";

$claim_stmt = $conn->prepare($claim_sql);

$claim_stmt->bind_param("ii", $item_id, $user_id);
$claim_stmt->execute();

$claim_result = $claim_stmt->get_result();

if ($claim_result->num_rows > 0) {
    $user_claim = $claim_result->fetch_assoc();
}

$claim_stmt->close();

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
    <?php echo htmlspecialchars($item["item_name"]); ?>
    - RTU Lost and Found
</title>


<style>

/* =========================================================
   ROOT
========================================================= */

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


/* =========================================================
   GLOBAL
========================================================= */

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


/* =========================================================
   NAVBAR - RTU DASHBOARD DESIGN
========================================================= */

.navbar {

    width: 100%;
    min-height: 70px;

    background: linear-gradient(
        135deg,
        #0f2f5f,
        #164a8a
    );

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


/* =========================================================
   BRAND
========================================================= */

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


/* =========================================================
   PROFILE CONTAINER
========================================================= */

.profile-container {

    position: relative;

}


/* =========================================================
   PROFILE BUTTON
========================================================= */

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


/* =========================================================
   PROFILE NAME
========================================================= */

.profile-name {

    font-size: 14px;
    font-weight: 600;

    max-width: 160px;

    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;

}


/* =========================================================
   PROFILE CIRCLE
========================================================= */

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


/* =========================================================
   PROFILE ARROW
========================================================= */

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


/* =========================================================
   PROFILE DROPDOWN
========================================================= */

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

    transition:
        opacity 0.2s,
        transform 0.2s,
        visibility 0.2s;

}

.profile-dropdown.show {

    opacity: 1;
    visibility: visible;

    transform: translateY(0);

}


/* =========================================================
   PROFILE HEADER
========================================================= */

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


/* =========================================================
   DROPDOWN LINKS
========================================================= */

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

    transition:
        background 0.2s,
        color 0.2s;

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


/* =========================================================
   DROPDOWN BADGE
========================================================= */

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


/* =========================================================
   SIGN OUT
========================================================= */

.signout {

    color: #b91c1c !important;

}

.signout:hover {

    background: #fef2f2 !important;
    color: #991b1b !important;

}


/* =========================================================
   MAIN CONTAINER
========================================================= */

.container {

    width: min(1050px, 92%);

    margin: 32px auto 55px;

}


/* =========================================================
   BACK BUTTON
========================================================= */

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


/* =========================================================
   ITEM CARD
========================================================= */

.item-card {

    background: white;

    border-radius: 16px;
    overflow: hidden;

    box-shadow: 0 7px 25px rgba(15, 47, 95, 0.10);

    border: 1px solid var(--border);

}


/* =========================================================
   ITEM IMAGE
========================================================= */

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


/* =========================================================
   ITEM CONTENT
========================================================= */

.item-content {

    padding: 30px;

}


.item-content h1 {

    margin: 0 0 15px;

    color: var(--rtu-blue-dark);
    font-size: 30px;

}


/* =========================================================
   BADGES
========================================================= */

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


/* =========================================================
   DETAILS
========================================================= */

.details {

    display: grid;
    grid-template-columns: 1fr 1fr;

    gap: 15px;
    margin-bottom: 25px;

}


.detail {

    padding: 16px;

    background: #f8fafc;

    border: 1px solid #e2e8f0;
    border-radius: 10px;

}


.detail strong {

    display: block;

    margin-bottom: 6px;

    color: var(--rtu-blue);
    font-size: 13px;

}


/* =========================================================
   DESCRIPTION
========================================================= */

.description {

    margin-top: 20px;

    padding: 20px;

    background: #f8fafc;

    border: 1px solid #e2e8f0;
    border-left: 5px solid var(--rtu-gold);

    border-radius: 10px;

    line-height: 1.7;

}


.description strong {

    color: var(--rtu-blue);

}


/* =========================================================
   SECTION
========================================================= */

.section {

    margin-top: 25px;

    background: white;

    padding: 28px;

    border-radius: 16px;

    box-shadow: 0 6px 22px rgba(15, 47, 95, 0.08);

    border: 1px solid var(--border);

}


.section h2 {

    margin: 0 0 12px;

    color: var(--rtu-blue-dark);
    font-size: 22px;

}


.section > p {

    color: var(--muted);
    line-height: 1.6;

}


/* =========================================================
   CLAIM BUTTON
========================================================= */

.claim-button {

    display: inline-block;

    margin-top: 15px;

    padding: 13px 23px;

    background: linear-gradient(
        135deg,
        var(--rtu-blue-light),
        var(--rtu-blue)
    );

    color: white;

    text-decoration: none;

    border-radius: 9px;

    font-weight: bold;

    border-bottom: 3px solid var(--rtu-gold);

    transition: 0.2s ease;

}


.claim-button:hover {

    transform: translateY(-2px);

    box-shadow: 0 7px 16px rgba(37, 99, 235, 0.22);

}


/* =========================================================
   NOTICE
========================================================= */

.notice {

    padding: 15px 17px;

    border-radius: 9px;

    background: #fff7e6;
    color: #92400e;

    border: 1px solid #fde68a;

    margin-top: 15px;

    line-height: 1.5;

}


/* =========================================================
   CLAIM STATUS
========================================================= */

.claim-approved {

    background: #ecfdf5;
    color: #166534;

    border: 1px solid #bbf7d0;

    padding: 15px 17px;

    border-radius: 9px;

    line-height: 1.5;

}


.claim-pending {

    background: #fff7e6;
    color: #92400e;

    border: 1px solid #fde68a;

    padding: 15px 17px;

    border-radius: 9px;

    line-height: 1.5;

}


.claim-rejected {

    background: #fef2f2;
    color: #b91c1c;

    border: 1px solid #fecaca;

    padding: 15px 17px;

    border-radius: 9px;

    line-height: 1.5;

}


/* =========================================================
   RESPONSIVE
========================================================= */

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
        padding: 21px;
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

}


/* =========================================================
   PRINT
========================================================= */

@media print {

    .navbar,
    .back {
        display: none;
    }

}

</style>

</head>


<body>


<!-- =======================================================
     NAVBAR - RTU DASHBOARD DESIGN
======================================================= -->

<nav class="navbar">


    <!-- BRAND -->

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


    <!-- PROFILE -->

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
                <?php
                echo strtoupper(
                    substr($current_user["name"], 0, 1)
                );
                ?>
            </span>

            <span class="profile-arrow"></span>

        </button>


        <!-- PROFILE DROPDOWN -->

        <div class="profile-dropdown" id="profileDropdown">


            <!-- PROFILE HEADER -->

            <div class="profile-header">

                <div class="profile-header-circle">

                    <?php
                    echo strtoupper(
                        substr($current_user["name"], 0, 1)
                    );
                    ?>

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


            <!-- DROPDOWN LINKS -->

            <div class="dropdown-links">


                <!-- MESSAGES -->

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


                <!-- NOTIFICATIONS -->

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


                <!-- SIGN OUT -->

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


<!-- =======================================================
     MAIN
======================================================= -->

<div class="container">


    <!-- ITEM CARD -->

    <div class="item-card">


        <?php if (!empty($item["image"])): ?>

            <?php

            $image_folder =
                ($item["item_type"] === "lost")
                ? "uploads/lost/"
                : "uploads/found/";

            ?>


            <img
                class="item-image"
                src="<?php

                    echo $image_folder .
                        htmlspecialchars($item["image"]);

                ?>"
                alt="<?php

                    echo htmlspecialchars($item["item_name"]);

                ?>"
            >


        <?php else: ?>

            <div class="no-image">
                No Image Available
            </div>

        <?php endif; ?>


        <div class="item-content">


            <h1>

                <?php
                echo htmlspecialchars($item["item_name"]);
                ?>

            </h1>


            <!-- BADGES -->

            <div class="badges">


                <?php if ($item["item_type"] === "lost"): ?>

                    <span class="badge lost">
                        LOST
                    </span>

                <?php else: ?>

                    <span class="badge found">
                        FOUND
                    </span>

                <?php endif; ?>


                <span class="badge status">

                    <?php

                    echo htmlspecialchars(
                        ucwords(
                            str_replace(
                                "_",
                                " ",
                                $item["status"]
                            )
                        )
                    );

                    ?>

                </span>


            </div>


            <!-- DETAILS -->

            <div class="details">


                <div class="detail">

                    <strong>
                        Category
                    </strong>

                    <?php
                    echo htmlspecialchars($item["category"] ?? "N/A");
                    ?>

                </div>


                <div class="detail">

                    <strong>
                        Location
                    </strong>

                    <?php
                    echo htmlspecialchars($item["location"] ?? "N/A");
                    ?>

                </div>


                <div class="detail">

                    <strong>
                        Date Reported
                    </strong>

                    <?php
                    echo htmlspecialchars($item["date_reported"]);
                    ?>

                </div>


                <div class="detail">

                    <strong>
                        Reported By
                    </strong>

                    <?php
                    echo htmlspecialchars($item["reporter_name"]);
                    ?>

                </div>


            </div>


            <!-- DESCRIPTION -->

            <div class="description">

                <strong>
                    Description
                </strong>

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


    <!-- ===================================================
         CLAIM SECTION
    =================================================== -->

    <?php if ($item["item_type"] === "found"): ?>


        <div class="section">


            <h2>
                Claim This Item
            </h2>


            <p>

                If this item belongs to you,
                submit a claim with identifying
                information and proof.

            </p>


            <?php if ($item["status"] === "returned"): ?>


                <div class="notice">

                    This item has already been
                    returned to its owner.

                </div>


            <?php elseif ($item["user_id"] == $user_id): ?>


                <div class="notice">

                    You reported this item as found,
                    so you cannot claim it.

                </div>


            <?php elseif (
                $user_claim &&
                $user_claim["status"] === "pending"
            ): ?>


                <div class="claim-pending">

                    Your claim for this item is
                    currently pending administrator review.

                </div>


            <?php elseif (
                $user_claim &&
                $user_claim["status"] === "approved"
            ): ?>


                <div class="claim-approved">

                    Your claim has been approved.

                </div>


            <?php elseif (
                $user_claim &&
                $user_claim["status"] === "rejected"
            ): ?>


                <div class="claim-rejected">

                    Your previous claim was rejected.
                    You may submit another claim if necessary.


                    <br><br>


                    <a
                        class="claim-button"
                        href="claim.php?id=<?php
                            echo (int) $item["id"];
                        ?>"
                    >

                        Submit New Claim

                    </a>

                </div>


            <?php else: ?>


                <a
                    class="claim-button"
                    href="claim.php?id=<?php
                        echo (int) $item["id"];
                    ?>"
                >

                    Claim This Item

                </a>


            <?php endif; ?>


        </div>


    <?php endif; ?>


</div>


<script>

/* =========================================================
   PROFILE DROPDOWN CLICK SUPPORT
========================================================= */

const profileButton = document.getElementById("profileButton");
const profileDropdown = document.getElementById("profileDropdown");


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


/* =========================================================
   PREVENT BACK/FORWARD CACHE
========================================================= */

window.addEventListener(
    "pageshow",
    function(event) {

        if (event.persisted) {

            window.location.reload();

        }

    }
);

</script>


</body>

</html>
