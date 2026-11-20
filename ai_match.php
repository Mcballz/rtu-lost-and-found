<?php

session_start();

/* =========================================================
   NO-CACHE / SESSION PROTECTION
========================================================= */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

require_once "config/database.php";

$user_id = (int) $_SESSION["user_id"];


/* =========================================================
   GET MATCH ID
========================================================= */

$match_id = 0;

if (
    isset($_GET["match_id"]) &&
    is_numeric($_GET["match_id"])
) {
    $match_id = (int) $_GET["match_id"];
}

if ($match_id <= 0) {
    header("Location: notifications.php");
    exit();
}


/* =========================================================
   CURRENT USER
========================================================= */

$user_stmt = $conn->prepare("
    SELECT name, role
    FROM users
    WHERE id = ?
");

$user_stmt->bind_param(
    "i",
    $user_id
);

$user_stmt->execute();

$current_user = $user_stmt
    ->get_result()
    ->fetch_assoc();

$user_stmt->close();


/* =========================================================
   GET AI MATCH DETAILS
========================================================= */

$match_stmt = $conn->prepare("
    SELECT

        a.id AS match_id,
        a.lost_item_id,
        a.found_item_id,
        a.similarity_score,

        lost.item_name AS lost_item_name,
        lost.category AS lost_category,
        lost.description AS lost_description,
        lost.location AS lost_location,
        lost.date_reported AS lost_date,
        lost.image AS lost_image,
        lost.status AS lost_status,
        lost.user_id AS lost_user_id,

        found.item_name AS found_item_name,
        found.category AS found_category,
        found.description AS found_description,
        found.location AS found_location,
        found.date_reported AS found_date,
        found.image AS found_image,
        found.status AS found_status,
        found.user_id AS found_user_id,

        lost_user.name AS lost_user_name,
        lost_user.student_id AS lost_student_id,

        found_user.name AS found_user_name,
        found_user.student_id AS found_student_id

    FROM ai_matches a

    INNER JOIN items lost
        ON a.lost_item_id = lost.id

    INNER JOIN items found
        ON a.found_item_id = found.id

    INNER JOIN users lost_user
        ON lost.user_id = lost_user.id

    INNER JOIN users found_user
        ON found.user_id = found_user.id

    WHERE a.id = ?

    LIMIT 1
");

$match_stmt->bind_param(
    "i",
    $match_id
);

$match_stmt->execute();

$match = $match_stmt
    ->get_result()
    ->fetch_assoc();

$match_stmt->close();


/* =========================================================
   MATCH NOT FOUND
========================================================= */

if (!$match) {
    header("Location: notifications.php");
    exit();
}


/* =========================================================
   SECURITY
   ONLY MATCH USERS CAN VIEW
========================================================= */

if (
    $user_id != $match["lost_user_id"] &&
    $user_id != $match["found_user_id"]
) {
    header("Location: notifications.php");
    exit();
}


/* =========================================================
   MARK AI NOTIFICATION READ
========================================================= */

$read_stmt = $conn->prepare("
    UPDATE notifications
    SET is_read = 1
    WHERE user_id = ?
    AND match_id = ?
");

$read_stmt->bind_param(
    "ii",
    $user_id,
    $match_id
);

$read_stmt->execute();
$read_stmt->close();


/* =========================================================
   DETERMINE OTHER USER
========================================================= */

if ($user_id == $match["lost_user_id"]) {

    $other_user_id = (int) $match["found_user_id"];
    $other_user_name = $match["found_user_name"];
    $other_role = "Finder";

    $message_item_id = (int) $match["found_item_id"];

} else {

    $other_user_id = (int) $match["lost_user_id"];
    $other_user_name = $match["lost_user_name"];
    $other_role = "Item Owner";

    $message_item_id = (int) $match["lost_item_id"];
}


/* =========================================================
   SIMILARITY
========================================================= */

$similarity = (float) $match["similarity_score"];


/* =========================================================
   SCORE LABEL
========================================================= */

if ($similarity >= 90) {

    $score_label = "Very High Similarity";

} elseif ($similarity >= 80) {

    $score_label = "High Similarity";

} else {

    $score_label = "Possible Match";
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<meta
    http-equiv="Cache-Control"
    content="no-cache, no-store, must-revalidate"
>

<meta
    http-equiv="Pragma"
    content="no-cache"
>

<meta
    http-equiv="Expires"
    content="0"
>

<title>
    AI Match Details - RTU Lost and Found
</title>


<style>

/* =========================================================
   GLOBAL
========================================================= */

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #f3f6fb;

    color: #1f2937;
}


/* =========================================================
   NAVBAR
========================================================= */

.navbar {

    background:
        linear-gradient(
            135deg,
            #0f2f5f,
            #164a8a
        );

    color: white;

    min-height: 70px;

    padding: 10px 30px;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 20px;

    border-bottom:
        4px solid #d4af37;

    box-shadow:
        0 4px 15px
        rgba(15, 47, 95, 0.20);

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

    color: white;

    white-space: nowrap;

}

.brand-logo {

    width: 46px;

    height: 46px;

    object-fit: contain;

    display: block;

}


/* =========================================================
   PROFILE DROPDOWN
========================================================= */

.profile {

    position: relative;

}

.profile-button {

    display: flex;

    align-items: center;

    gap: 9px;

    border: none;

    background: transparent;

    color: white;

    padding: 8px 11px;

    border-radius: 8px;

    cursor: pointer;

    font-size: 14px;

    font-weight: 600;

}

.profile-button:hover {

    background:
        rgba(255,255,255,0.12);

}

.profile-name {

    max-width: 180px;

    overflow: hidden;

    text-overflow: ellipsis;

    white-space: nowrap;

}

.profile-arrow {

    font-size: 11px;

    transition: transform 0.2s;

}


/* =========================================================
   DROPDOWN
========================================================= */

.profile-menu {

    display: none;

    position: absolute;

    right: 0;

    top: calc(100% + 9px);

    width: 210px;

    background: white;

    border-radius: 10px;

    box-shadow:
        0 10px 30px
        rgba(15, 47, 95, 0.20);

    border:
        1px solid #e5e7eb;

    overflow: hidden;

    z-index: 1000;

}

.profile:hover .profile-menu {

    display: block;

}

.profile-menu-header {

    padding: 14px 16px;

    background:
        linear-gradient(
            135deg,
            #0f2f5f,
            #164a8a
        );

    color: white;

}

.profile-menu-header strong {

    display: block;

    font-size: 14px;

    margin-bottom: 3px;

}

.profile-menu-header span {

    font-size: 12px;

    color: #dbeafe;

}

.profile-menu a {

    display: block;

    padding: 12px 16px;

    color: #334155;

    text-decoration: none;

    font-size: 14px;

    border-bottom:
        1px solid #f1f5f9;

}

.profile-menu a:hover {

    background: #f1f5f9;

    color: #164a8a;

}

.profile-menu .signout {

    color: #b91c1c;

    font-weight: 600;

}

.profile-menu .signout:hover {

    background: #fef2f2;

    color: #991b1b;

}


/* =========================================================
   MAIN CONTAINER
========================================================= */

.container {

    width: 92%;

    max-width: 1100px;

    margin: 35px auto 50px;

}


/* =========================================================
   PAGE HEADER
========================================================= */

.page-header {

    background:
        linear-gradient(
            135deg,
            #0f2f5f,
            #164a8a
        );

    color: white;

    padding: 28px;

    border-radius: 16px;

    margin-bottom: 25px;

    border-bottom:
        4px solid #d4af37;

    box-shadow:
        0 8px 25px
        rgba(15,47,95,0.20);

}

.page-header h1 {

    font-size: 28px;

    margin-bottom: 7px;

}

.page-header p {

    color: #e5e7eb;

    font-size: 14px;

}


/* =========================================================
   SCORE CARD
========================================================= */

.score-card {

    background: white;

    border-radius: 16px;

    padding: 28px;

    text-align: center;

    margin-bottom: 25px;

    border:
        1px solid #e5e7eb;

    border-top:
        4px solid #d4af37;

    box-shadow:
        0 5px 18px
        rgba(0,0,0,0.07);

}

.score-label {

    font-size: 13px;

    color: #64748b;

    margin-bottom: 7px;

}

.score {

    font-size: 48px;

    font-weight: bold;

    color: #164a8a;

}

.score-description {

    margin-top: 6px;

    font-weight: bold;

    color: #334155;

}


/* =========================================================
   ITEMS GRID
========================================================= */

.items-grid {

    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 22px;

}


/* =========================================================
   ITEM CARD
========================================================= */

.item-card {

    background: white;

    border-radius: 16px;

    overflow: hidden;

    border:
        1px solid #e5e7eb;

    box-shadow:
        0 5px 18px
        rgba(0,0,0,0.07);

}

.item-header {

    padding: 19px 21px;

    background:
        #f8fafc;

    border-bottom:
        1px solid #e5e7eb;

}

.item-header h2 {

    margin: 0;

    font-size: 20px;

    color: #172554;

}

.item-type {

    font-size: 12px;

    font-weight: bold;

    color: #164a8a;

    text-transform: uppercase;

    letter-spacing: 0.5px;

    margin-bottom: 6px;

}

.item-image-container {

    width: 100%;

    height: 300px;

    background: #eef2f7;

}

.item-image {

    width: 100%;

    height: 100%;

    object-fit: contain;

}

.no-image {

    height: 100%;

    display: flex;

    align-items: center;

    justify-content: center;

    color: #94a3b8;

}

.item-content {

    padding: 21px;

}

.detail {

    margin-bottom: 14px;

}

.detail-label {

    font-size: 12px;

    color: #64748b;

    margin-bottom: 4px;

    font-weight: 600;

}

.detail-value {

    font-size: 14px;

    color: #1f2937;

    line-height: 1.5;

}


/* =========================================================
   USER INFORMATION
========================================================= */

.user-box {

    margin-top: 19px;

    padding-top: 18px;

    border-top:
        1px solid #e5e7eb;

}

.user-name {

    font-weight: bold;

    color: #172554;

}

.user-role {

    font-size: 12px;

    color: #64748b;

    margin-top: 2px;

}


/* =========================================================
   MESSAGE SECTION
========================================================= */

.message-section {

    background: white;

    border-radius: 16px;

    padding: 27px;

    margin-top: 25px;

    border:
        1px solid #e5e7eb;

    border-top:
        4px solid #d4af37;

    text-align: center;

    box-shadow:
        0 5px 18px
        rgba(0,0,0,0.07);

}

.message-section h2 {

    margin-bottom: 8px;

    color: #172554;

    font-size: 21px;

}

.message-section p {

    color: #64748b;

    margin-bottom: 20px;

    line-height: 1.5;

}

.message-button {

    display: inline-block;

    text-decoration: none;

    background:
        linear-gradient(
            135deg,
            #2563eb,
            #164a8a
        );

    color: white;

    padding: 12px 25px;

    border-radius: 8px;

    font-weight: bold;

    border-bottom:
        3px solid #d4af37;

    transition: 0.2s;

}

.message-button:hover {

    transform: translateY(-2px);

    box-shadow:
        0 6px 15px
        rgba(37,99,235,0.22);

}


/* =========================================================
   BACK BUTTON
========================================================= */

.back-container {

    text-align: center;

    margin-top: 22px;

}

.back-button {

    display: inline-block;

    text-decoration: none;

    color: #164a8a;

    font-size: 14px;

    font-weight: 600;

}

.back-button:hover {

    color: #0f2f5f;

    text-decoration: underline;

}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 750px) {

    .navbar {

        padding: 12px 15px;

    }

    .brand-title {

        font-size: 18px;

    }

    .brand-logo {

        width: 40px;

        height: 40px;

    }

    .profile-name {

        max-width: 110px;

    }

    .items-grid {

        grid-template-columns: 1fr;

    }

    .container {

        width: 94%;

        margin:
            20px auto 35px;

    }

    .page-header {

        padding: 22px;

    }

    .page-header h1 {

        font-size: 23px;

    }

    .item-image-container {

        height: 250px;

    }

    .score {

        font-size: 40px;

    }

}

</style>

</head>


<body>


<!-- =======================================================
     NAVBAR
======================================================= -->

<div class="navbar">


    <!-- BRAND -->

    <a
        href="dashboard.php"
        class="brand"
    >

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

    <div class="profile">

        <button
            type="button"
            class="profile-button"
        >

            <span class="profile-name">

                <?php
                echo htmlspecialchars(
                    $current_user["name"] ?? "User"
                );
                ?>

            </span>

            <span class="profile-arrow">
                ▼
            </span>

        </button>


        <div class="profile-menu">


            <div class="profile-menu-header">

                <strong>

                    <?php
                    echo htmlspecialchars(
                        $current_user["name"] ?? "User"
                    );
                    ?>

                </strong>

                <span>

                    <?php
                    echo htmlspecialchars(
                        ucfirst(
                            $current_user["role"] ?? "user"
                        )
                    );
                    ?>

                </span>

            </div>


            <a href="messages.php">
                Messages
            </a>


            <a href="notifications.php">
                Notifications
            </a>


            <?php if (
                $current_user &&
                $current_user["role"] === "admin"
            ): ?>

                <a href="admin/dashboard.php">
                    Admin Dashboard
                </a>

            <?php endif; ?>


            <a
                href="logout.php"
                class="signout"
            >
                Sign Out
            </a>


        </div>

    </div>


</div>


<!-- =======================================================
     MAIN
======================================================= -->

<div class="container">


    <!-- PAGE HEADER -->

    <div class="page-header">

        <h1>
            AI Match Detected
        </h1>

        <p>
            The system found a possible match between these items.
        </p>

    </div>


    <!-- =====================================================
         SCORE
    ===================================================== -->

    <div class="score-card">

        <div class="score-label">
            AI Image Similarity
        </div>


        <div class="score">

            <?php

            echo number_format(
                $similarity,
                2
            );

            ?>%

        </div>


        <div class="score-description">

            <?php

            echo htmlspecialchars(
                $score_label
            );

            ?>

        </div>

    </div>


    <!-- =====================================================
         ITEMS
    ===================================================== -->

    <div class="items-grid">


        <!-- LOST ITEM -->

        <div class="item-card">


            <div class="item-header">

                <div class="item-type">
                    Lost Item
                </div>

                <h2>

                    <?php

                    echo htmlspecialchars(
                        $match["lost_item_name"]
                    );

                    ?>

                </h2>

            </div>


            <div class="item-image-container">

                <?php if (
                    !empty(
                        $match["lost_image"]
                    )
                ): ?>

                    <img
                        src="uploads/lost/<?php
                            echo htmlspecialchars(
                                $match["lost_image"]
                            );
                        ?>"
                        alt="Lost Item"
                        class="item-image"
                    >

                <?php else: ?>

                    <div class="no-image">
                        No Image
                    </div>

                <?php endif; ?>

            </div>


            <div class="item-content">


                <div class="detail">

                    <div class="detail-label">
                        Category
                    </div>

                    <div class="detail-value">

                        <?php

                        echo htmlspecialchars(
                            $match["lost_category"]
                        );

                        ?>

                    </div>

                </div>


                <div class="detail">

                    <div class="detail-label">
                        Description
                    </div>

                    <div class="detail-value">

                        <?php

                        echo nl2br(
                            htmlspecialchars(
                                $match["lost_description"]
                            )
                        );

                        ?>

                    </div>

                </div>


                <div class="detail">

                    <div class="detail-label">
                        Location
                    </div>

                    <div class="detail-value">

                        <?php

                        echo htmlspecialchars(
                            $match["lost_location"]
                        );

                        ?>

                    </div>

                </div>


                <div class="detail">

                    <div class="detail-label">
                        Date Lost
                    </div>

                    <div class="detail-value">

                        <?php

                        echo date(
                            "M d, Y",
                            strtotime(
                                $match["lost_date"]
                            )
                        );

                        ?>

                    </div>

                </div>


                <div class="user-box">

                    <div class="detail-label">
                        Reported By
                    </div>

                    <div class="user-name">

                        <?php

                        echo htmlspecialchars(
                            $match["lost_user_name"]
                        );

                        ?>

                    </div>

                    <div class="user-role">
                        Item Owner
                    </div>

                </div>


            </div>

        </div>


        <!-- FOUND ITEM -->

        <div class="item-card">


            <div class="item-header">

                <div class="item-type">
                    Found Item
                </div>

                <h2>

                    <?php

                    echo htmlspecialchars(
                        $match["found_item_name"]
                    );

                    ?>

                </h2>

            </div>


            <div class="item-image-container">

                <?php if (
                    !empty(
                        $match["found_image"]
                    )
                ): ?>

                    <img
                        src="uploads/found/<?php
                            echo htmlspecialchars(
                                $match["found_image"]
                            );
                        ?>"
                        alt="Found Item"
                        class="item-image"
                    >

                <?php else: ?>

                    <div class="no-image">
                        No Image
                    </div>

                <?php endif; ?>

            </div>


            <div class="item-content">


                <div class="detail">

                    <div class="detail-label">
                        Category
                    </div>

                    <div class="detail-value">

                        <?php

                        echo htmlspecialchars(
                            $match["found_category"]
                        );

                        ?>

                    </div>

                </div>


                <div class="detail">

                    <div class="detail-label">
                        Description
                    </div>

                    <div class="detail-value">

                        <?php

                        echo nl2br(
                            htmlspecialchars(
                                $match["found_description"]
                            )
                        );

                        ?>

                    </div>

                </div>


                <div class="detail">

                    <div class="detail-label">
                        Location
                    </div>

                    <div class="detail-value">

                        <?php

                        echo htmlspecialchars(
                            $match["found_location"]
                        );

                        ?>

                    </div>

                </div>


                <div class="detail">

                    <div class="detail-label">
                        Date Found
                    </div>

                    <div class="detail-value">

                        <?php

                        echo date(
                            "M d, Y",
                            strtotime(
                                $match["found_date"]
                            )
                        );

                        ?>

                    </div>

                </div>


                <div class="user-box">

                    <div class="detail-label">
                        Found By
                    </div>

                    <div class="user-name">

                        <?php

                        echo htmlspecialchars(
                            $match["found_user_name"]
                        );

                        ?>

                    </div>

                    <div class="user-role">
                        Finder
                    </div>

                </div>


            </div>

        </div>


    </div>


    <!-- =====================================================
         MESSAGE USER
    ===================================================== -->

    <div class="message-section">


        <h2>
            Does this look like your item?
        </h2>


        <p>

            You can contact

            <strong>

                <?php

                echo htmlspecialchars(
                    $other_user_name
                );

                ?>

            </strong>

            to discuss the possible match.

        </p>


        <a
            href="messages.php?user_id=<?php
                echo $other_user_id;
            ?>&item_id=<?php
                echo $message_item_id;
            ?>"
            class="message-button"
        >

            Message

            <?php

            echo htmlspecialchars(
                $other_role
            );

            ?>

        </a>


    </div>


    <!-- =====================================================
         BACK
    ===================================================== -->

    <div class="back-container">

        <a
            href="notifications.php"
            class="back-button"
        >

            Back to Notifications

        </a>

    </div>


</div>


<!-- =======================================================
     PREVENT BACK/FORWARD CACHE
======================================================= -->

<script>

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