<?php

session_start();

/* =========================================================
   PREVENT BACK/FORWARD CACHE AFTER LOGOUT
========================================================= */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");


/* =========================================================
   LOGIN CHECK
========================================================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}


require_once "config/database.php";

$current_user_id = (int) $_SESSION["user_id"];


/* =========================================================
   CURRENT USER
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

$user_stmt->bind_param(
    "i",
    $current_user_id
);

$user_stmt->execute();

$current_user =
    $user_stmt
    ->get_result()
    ->fetch_assoc();

$user_stmt->close();


if (!$current_user) {

    session_unset();
    session_destroy();

    header("Location: login.php");
    exit();

}


/* =========================================================
   USER REPORT COUNT
========================================================= */

$my_reports = 0;

$reports_stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM items
    WHERE user_id = ?
");

if ($reports_stmt) {

    $reports_stmt->bind_param(
        "i",
        $current_user_id
    );

    $reports_stmt->execute();

    $reports_result =
        $reports_stmt
        ->get_result()
        ->fetch_assoc();

    $my_reports =
        (int) $reports_result["total"];

    $reports_stmt->close();
}


/* =========================================================
   UNREAD MESSAGES
========================================================= */

$total_unread = 0;

$unread_stmt = $conn->prepare("
    SELECT COUNT(*) AS total_unread
    FROM messages
    WHERE receiver_id = ?
    AND is_read = 0
");

if ($unread_stmt) {

    $unread_stmt->bind_param(
        "i",
        $current_user_id
    );

    $unread_stmt->execute();

    $unread_result =
        $unread_stmt
        ->get_result()
        ->fetch_assoc();

    $total_unread =
        (int) $unread_result["total_unread"];

    $unread_stmt->close();
}



/* =========================================================
   RECENT ITEMS
========================================================= */

$recent_items = [];

$recent_stmt = $conn->prepare("
    SELECT
        id,
        item_name,
        item_type,
        category,
        location,
        description,
        image,
        status,
        created_at
    FROM items
    ORDER BY created_at DESC
    LIMIT 6
");

if ($recent_stmt) {

    $recent_stmt->execute();

    $recent_items =
        $recent_stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

    $recent_stmt->close();
}


/* =========================================================
   PROFILE INITIAL
========================================================= */

$name_for_initial =
    trim($current_user["name"]);

$profile_initial =
    strtoupper(
        substr(
            $name_for_initial,
            0,
            1
        )
    );

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
    Dashboard - RTU Lost and Found
</title>


<style>

/* =========================================================
   GLOBAL
========================================================= */

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {

    margin: 0;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background:
        #f3f6fb;

    color:
        #1f2937;
}


/* =========================================================
   NAVBAR
========================================================= */

.navbar {

    width: 100%;

    min-height: 70px;

    background:
        linear-gradient(
            135deg,
            #0f2f5f,
            #164a8a
        );

    color: white;

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding:
        10px 28px;

    border-bottom:
        4px solid #d4af37;

    box-shadow:
        0 4px 15px
        rgba(
            15,
            47,
            95,
            0.20
        );

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
   PROFILE
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

    padding:
        5px 7px;

    border-radius:
        30px;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    transition:
        background 0.2s;
}

.profile-button:hover {

    background:
        rgba(
            255,
            255,
            255,
            0.12
        );
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

    background:
        #d4af37;

    color:
        #0f2f5f;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 16px;

    font-weight: bold;

    border:
        2px solid
        rgba(
            255,
            255,
            255,
            0.8
        );
}


/* =========================================================
   ARROW
========================================================= */

.profile-arrow {

    width: 0;

    height: 0;

    border-left:
        5px solid transparent;

    border-right:
        5px solid transparent;

    border-top:
        6px solid white;

    transition:
        transform 0.2s;
}

.profile-button.open .profile-arrow {

    transform:
        rotate(180deg);
}


/* =========================================================
   PROFILE DROPDOWN
========================================================= */

.profile-dropdown {

    position: absolute;

    top:
        calc(100% + 12px);

    right: 0;

    width: 310px;

    background: white;

    border-radius: 16px;

    box-shadow:
        0 10px 35px
        rgba(
            0,
            0,
            0,
            0.20
        );

    border:
        1px solid #e2e8f0;

    overflow: hidden;

    opacity: 0;

    visibility: hidden;

    transform:
        translateY(-8px);

    transition:
        opacity 0.2s,
        transform 0.2s,
        visibility 0.2s;
}

.profile-dropdown.show {

    opacity: 1;

    visibility: visible;

    transform:
        translateY(0);
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

    border-bottom:
        1px solid #e5e7eb;
}

.profile-header-circle {

    width: 48px;

    height: 48px;

    min-width: 48px;

    border-radius: 50%;

    background:
        #0f2f5f;

    color: white;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 19px;

    font-weight: bold;

    border:
        3px solid #d4af37;
}

.profile-details {

    min-width: 0;
}

.profile-details strong {

    display: block;

    color:
        #172554;

    font-size: 15px;

    margin-bottom: 4px;

    white-space: nowrap;

    overflow: hidden;

    text-overflow: ellipsis;
}

.profile-details span {

    display: block;

    color:
        #64748b;

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

    padding:
        12px 13px;

    color:
        #334155;

    text-decoration: none;

    border-radius: 9px;

    font-size: 14px;

    font-weight: 600;

    transition:
        background 0.2s,
        color 0.2s;
}

.dropdown-link:hover {

    background:
        #eff6ff;

    color:
        #164a8a;
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

    background:
        #d4af37;

    flex-shrink: 0;
}


/* =========================================================
   BADGE
========================================================= */

.dropdown-badge {

    min-width: 20px;

    height: 20px;

    padding:
        0 6px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border-radius: 20px;

    background:
        #dc2626;

    color: white;

    font-size: 10px;

    font-weight: bold;
}


/* =========================================================
   DIVIDER
========================================================= */

.dropdown-divider {

    height: 1px;

    background:
        #e5e7eb;

    margin:
        5px 12px;
}


/* =========================================================
   SIGN OUT
========================================================= */

.signout {

    color:
        #b91c1c !important; 
}

.signout:hover {

    background:
        #fef2f2 !important;

    color:
        #991b1b !important;
}


/* =========================================================
   MAIN CONTAINER
========================================================= */

.container {

    width: 92%;

    max-width: 1200px;

    margin:
        28px auto 50px;
}


/* =========================================================
   WELCOME CARD
========================================================= */

.welcome-card {

    background: white;

    border-radius: 16px;

    padding: 25px;

    margin-bottom: 22px;

    border-top:
        4px solid #d4af37;

    box-shadow:
        0 5px 18px
        rgba(
            0,
            0,
            0,
            0.08
        );
}

.welcome-card h1 {

    margin:
        0 0 8px;

    color:
        #0f2f5f;

    font-size: 26px;
}

.welcome-card p {

    margin:
        4px 0;

    color:
        #64748b;

    font-size: 14px;
}


/* =========================================================
   SECTION TITLE
========================================================= */

.section-title {

    display: flex;

    align-items: center;

    gap: 10px;

    margin:
        25px 0 13px;
}

.section-title::before {

    content: "";

    width: 4px;

    height: 24px;

    background:
        #d4af37;

    border-radius: 5px;
}

.section-title h2 {

    margin: 0;

    color:
        #0f2f5f;

    font-size: 20px;
}


/* =========================================================
   QUICK ACTIONS
========================================================= */

.quick-actions {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 14px;
}

.action-card {

    background: white;

    border-radius: 14px;

    padding:
        24px 15px;

    text-align: center;

    text-decoration: none;

    color:
        #1f2937;

    border-top:
        3px solid #d4af37;

    box-shadow:
        0 5px 18px
        rgba(
            0,
            0,
            0,
            0.08
        );

    transition:
        transform 0.2s,
        box-shadow 0.2s;

    min-height: 115px;

    display: flex;

    flex-direction: column;

    justify-content: center;
}

.action-card:hover {

    transform:
        translateY(-4px);

    box-shadow:
        0 9px 24px
        rgba(
            15,
            47,
            95,
            0.15
        );
}

.action-card h3 {

    margin:
        0 0 7px;

    color:
        #0f2f5f;

    font-size: 16px;
}

.action-card p {

    margin: 0;

    color:
        #64748b;

    font-size: 12px;

    line-height: 1.4;
}


/* =========================================================
   ITEMS GRID
========================================================= */

.items-grid {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 20px;
}


/* =========================================================
   ITEM CARD
========================================================= */

.item-card {

    background: white;

    border-radius: 14px;

    overflow: hidden;

    box-shadow:
        0 5px 18px
        rgba(
            0,
            0,
            0,
            0.08
        );

    border:
        1px solid #e5e7eb;

    transition:
        transform 0.2s,
        box-shadow 0.2s,
        border-color 0.2s;
}

.item-card:hover {

    transform:
        translateY(-4px);

    box-shadow:
        0 10px 28px
        rgba(
            15,
            47,
            95,
            0.15
        );

    border-color:
        #d4af37;
}


/* =========================================================
   ITEM IMAGE
========================================================= */

.item-image {

    width: 100%;

    height: 215px;

    object-fit: cover;

    display: block;

    background:
        #e5e7eb;
}


/* =========================================================
   NO IMAGE
========================================================= */

.no-image {

    width: 100%;

    height: 215px;

    display: flex;

    align-items: center;

    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #e5e7eb,
            #f1f5f9
        );

    color:
        #64748b;

    font-size: 14px;
}


/* =========================================================
   ITEM CONTENT
========================================================= */

.item-content {

    padding: 17px;
}

.item-type {

    display: inline-block;

    padding:
        5px 10px;

    border-radius: 20px;

    font-size: 10px;

    font-weight: bold;

    margin-bottom: 8px;
}

.item-type.lost {

    background:
        #fee2e2;

    color:
        #b91c1c;
}

.item-type.found {

    background:
        #dcfce7;

    color:
        #15803d;
}

.item-content h3 {

    margin:
        0 0 8px;

    color:
        #0f2f5f;

    font-size: 18px;
}

.item-info {

    font-size: 13px;

    color:
        #64748b;

    line-height: 1.65;
}

.item-info strong {

    color:
        #334155;
}


/* =========================================================
   VIEW BUTTON
========================================================= */

.view-button {

    display: inline-block;

    margin-top: 12px;

    padding:
        9px 14px;

    background:
        linear-gradient(
            135deg,
            #2563eb,
            #164a8a
        );

    color: white;

    text-decoration: none;

    border-radius: 8px;

    font-size: 12px;

    font-weight: bold;

    border-bottom:
        3px solid #d4af37;

    transition: 0.2s;
}

.view-button:hover {

    background:
        linear-gradient(
            135deg,
            #1d4ed8,
            #123d73
        );

    transform:
        translateY(-2px);
}


/* =========================================================
   EMPTY
========================================================= */

.empty {

    background: white;

    border-radius: 14px;

    padding:
        45px 20px;

    text-align: center;

    color:
        #64748b;

    box-shadow:
        0 5px 18px
        rgba(
            0,
            0,
            0,
            0.08
        );
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 900px) {

    .quick-actions {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .items-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }
}


@media (max-width: 700px) {

    .navbar {

        padding:
            10px 15px;
    }

    .brand-title {

        font-size: 18px;
    }

    .brand-logo {

        width: 42px;

        height: 42px;
    }

    .profile-name {

        display: none;
    }

    .profile-dropdown {

        right: -5px;

        width: 290px;
    }

    .quick-actions {

        grid-template-columns:
            1fr;
    }

    .items-grid {

        grid-template-columns:
            1fr;
    }

    .container {

        width: 94%;
    }
}


@media (max-width: 400px) {

    .profile-dropdown {

        width:
            calc(100vw - 30px);

        right: -5px;
    }
}

</style>

</head>


<body>


<!-- =======================================================
     NAVBAR
======================================================= -->

<nav class="navbar">


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

    <div class="profile-container">

        <button
            type="button"
            class="profile-button"
            id="profileButton"
        >

            <span class="profile-name">

                <?php
                echo htmlspecialchars(
                    $current_user["name"]
                );
                ?>

            </span>


            <span class="profile-circle">

                <?php
                echo htmlspecialchars(
                    $profile_initial
                );
                ?>

            </span>


            <span class="profile-arrow"></span>

        </button>


        <!-- PROFILE DROPDOWN -->

        <div
            class="profile-dropdown"
            id="profileDropdown"
        >


            <!-- PROFILE INFORMATION -->

            <div class="profile-header">

                <div class="profile-header-circle">

                    <?php
                    echo htmlspecialchars(
                        $profile_initial
                    );
                    ?>

                </div>


                <div class="profile-details">

                    <strong>

                        <?php
                        echo htmlspecialchars(
                            $current_user["name"]
                        );
                        ?>

                    </strong>

                    <span>

                        <?php
                        echo htmlspecialchars(
                            $current_user["email"]
                        );
                        ?>

                    </span>

                </div>

            </div>


            <!-- DROPDOWN LINKS -->

            <div class="dropdown-links">


                <!-- MESSAGES -->

                <a
                    href="messages.php"
                    class="dropdown-link"
                >

                    <span
                        class="dropdown-link-content"
                    >

                        <span
                            class="dropdown-marker"
                        ></span>

                        Messages

                    </span>


                    <?php if ($total_unread > 0): ?>

                        <span
                            class="dropdown-badge"
                        >

                            <?php
                            echo $total_unread;
                            ?>

                        </span>

                    <?php endif; ?>

                </a>


                <!-- SIGN OUT -->

                <a
                    href="logout.php"
                    class="dropdown-link signout"
                >

                    <span
                        class="dropdown-link-content"
                    >

                        <span
                            class="dropdown-marker"
                        ></span>

                        Sign Out

                    </span>

                </a>


            </div>

        </div>

    </div>

</nav>


<!-- =======================================================
     MAIN CONTENT
======================================================= -->

<div class="container">


    <!-- WELCOME -->

    <div class="welcome-card">

        <h1>

            Welcome,
            <?php
            echo htmlspecialchars(
                $current_user["name"]
            );
            ?>!

        </h1>


        <p>

            Student ID:
            <?php
            echo htmlspecialchars(
                $current_user["student_id"]
            );
            ?>

        </p>


        <p>

            Email:
            <?php
            echo htmlspecialchars(
                $current_user["email"]
            );
            ?>

        </p>

    </div>


    <!-- QUICK ACTIONS -->

    <div class="section-title">

        <h2>
            Quick Actions
        </h2>

    </div>


    <div class="quick-actions">


        <!-- REPORT LOST -->

        <a
            href="lost_item.php"
            class="action-card"
        >

            <h3>
                Report Lost
            </h3>

            <p>
                Report an item that you lost.
            </p>

        </a>


        <!-- REPORT FOUND -->

        <a
            href="found_item.php"
            class="action-card"
        >

            <h3>
                Report Found
            </h3>

            <p>
                Report an item that you found.
            </p>

        </a>


        <!-- SEARCH -->

        <a
            href="search.php"
            class="action-card"
        >

            <h3>
                Search Items
            </h3>

            <p>
                Search reported lost and found items.
            </p>

        </a>


        <!-- MY REPORTS -->

        <a
            href="my_reports.php"
            class="action-card"
        >

            <h3>
                My Reports
            </h3>

            <p>
                View your reports and claims.
            </p>

        </a>


    </div>


    <!-- RECENT REPORTS -->

    <div class="section-title">

        <h2>
            Recent Lost &amp; Found Reports
        </h2>

    </div>


    <?php if (empty($recent_items)): ?>

        <div class="empty">

            No lost or found reports yet.

        </div>

    <?php else: ?>


        <div class="items-grid">


            <?php foreach (
                $recent_items
                as $item
            ): ?>


                <div class="item-card">


                    <!-- IMAGE -->

                    <?php if (
                        !empty($item["image"])
                    ): ?>


                        <?php

                        if (
                            $item["item_type"]
                            === "lost"
                        ) {

                            $image_path =
                                "uploads/lost/" .
                                $item["image"];

                        } else {

                            $image_path =
                                "uploads/found/" .
                                $item["image"];

                        }

                        ?>


                        <img
                            class="item-image"
                            src="<?php
                                echo htmlspecialchars(
                                    $image_path
                                );
                            ?>"
                            alt="<?php
                                echo htmlspecialchars(
                                    $item["item_name"]
                                );
                            ?>"
                            onerror="
                                this.style.display='none';
                                this.nextElementSibling.style.display='flex';
                            "
                        >


                        <div
                            class="no-image"
                            style="display:none;"
                        >

                            Image unavailable

                        </div>


                    <?php else: ?>


                        <div class="no-image">

                            No Image Available

                        </div>


                    <?php endif; ?>


                    <!-- ITEM DETAILS -->

                    <div class="item-content">


                        <?php if (
                            $item["item_type"]
                            === "lost"
                        ): ?>

                            <span
                                class="item-type lost"
                            >
                                Lost
                            </span>

                        <?php else: ?>

                            <span
                                class="item-type found"
                            >
                                Found
                            </span>

                        <?php endif; ?>


                        <h3>

                            <?php
                            echo htmlspecialchars(
                                $item["item_name"]
                            );
                            ?>

                        </h3>


                        <div class="item-info">

                            <strong>
                                Type:
                            </strong>

                            <?php
                            echo htmlspecialchars(
                                ucfirst(
                                    $item["item_type"]
                                )
                            );
                            ?>

                            <br>


                            <strong>
                                Category:
                            </strong>

                            <?php
                            echo htmlspecialchars(
                                $item["category"]
                            );
                            ?>

                            <br>


                            <strong>
                                Location:
                            </strong>

                            <?php
                            echo htmlspecialchars(
                                $item["location"]
                            );
                            ?>

                        </div>


                        <a
                            href="item.php?id=<?php
                                echo (int)
                                    $item["id"];
                            ?>"
                            class="view-button"
                        >

                            View Item

                        </a>


                    </div>

                </div>


            <?php endforeach; ?>


        </div>


    <?php endif; ?>


</div>


<script>

/* =========================================================
   PROFILE DROPDOWN
========================================================= */

const profileButton =
    document.getElementById(
        "profileButton"
    );

const profileDropdown =
    document.getElementById(
        "profileDropdown"
    );


profileButton.addEventListener(
    "click",
    function(event) {

        event.stopPropagation();

        profileDropdown.classList.toggle(
            "show"
        );

        profileButton.classList.toggle(
            "open"
        );

    }
);


/* =========================================================
   CLOSE DROPDOWN WHEN CLICKING OUTSIDE
========================================================= */

document.addEventListener(
    "click",
    function(event) {

        if (
            !profileDropdown.contains(
                event.target
            ) &&
            !profileButton.contains(
                event.target
            )
        ) {

            profileDropdown.classList.remove(
                "show"
            );

            profileButton.classList.remove(
                "open"
            );

        }

    }
);


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