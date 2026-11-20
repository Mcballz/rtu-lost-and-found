<?php

session_start();

/*
    Prevent browser caching
*/

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");


/*
    Check if user is logged in
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}


require_once "config/database.php";


/*
    Get search and filter values
*/

$search = "";
$type = "";
$category = "";

$result = null;


if (isset($_GET["search"])) {
    $search = trim($_GET["search"]);
}


if (isset($_GET["type"])) {
    $type = trim($_GET["type"]);
}


if (isset($_GET["category"])) {
    $category = trim($_GET["category"]);
}


/*
    Search items
*/

$sql = "
    SELECT
        id,
        item_type,
        item_name,
        category,
        description,
        location,
        date_reported,
        image,
        status
    FROM items
    WHERE 1=1
";


$params = [];

$types = "";


/*
    Search keyword
*/

if (!empty($search)) {

    $sql .= "
        AND (
            item_name LIKE ?
            OR category LIKE ?
            OR description LIKE ?
            OR location LIKE ?
        )
    ";

    $search_term = "%" . $search . "%";

    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;

    $types .= "ssss";

}


/*
    Filter by LOST or FOUND
*/

if (
    $type === "lost" ||
    $type === "found"
) {

    $sql .= "
        AND LOWER(TRIM(item_type)) = ?
    ";

    $params[] = strtolower($type);

    $types .= "s";

}


/*
    Filter by CATEGORY
*/

if (!empty($category)) {

    $sql .= "
        AND LOWER(TRIM(category)) = LOWER(TRIM(?))
    ";

    $params[] = $category;

    $types .= "s";

}


$sql .= "
    ORDER BY created_at DESC
";


$stmt = $conn->prepare($sql);


if (!empty($params)) {

    $stmt->bind_param(
        $types,
        ...$params
    );

}


$stmt->execute();

$result = $stmt->get_result();


/*
    Get current user's profile information
*/

$nav_user_id = (int) $_SESSION["user_id"];


$nav_stmt = $conn->prepare("
    SELECT
        name,
        email,
        role
    FROM users
    WHERE id = ?
    LIMIT 1
");


$nav_stmt->bind_param(
    "i",
    $nav_user_id
);


$nav_stmt->execute();


$nav_result = $nav_stmt->get_result();


$nav_user = $nav_result->fetch_assoc();


$nav_stmt->close();


/*
    Profile information
*/

$profile_name = !empty($nav_user["name"])
    ? $nav_user["name"]
    : "User";


$profile_email = !empty($nav_user["email"])
    ? $nav_user["email"]
    : "";


/*
    Get first letter of user's name
*/

$profile_initial = strtoupper(
    substr(
        trim($profile_name),
        0,
        1
    )
);


if (empty($profile_initial)) {
    $profile_initial = "U";
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
    Search Items - RTU Lost and Found
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
    font-family: Arial, Helvetica, sans-serif;
    background: #f3f6fb;
    color: #1f2937;
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
    min-width: 40px;

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
   DROPDOWN PROFILE HEADER
========================================================= */

.profile-header {
    padding: 20px;

    display: flex;
    align-items: center;
    gap: 13px;

    background: white;

    border-bottom: 1px solid #e5e7eb;
}


/* =========================================================
   DROPDOWN PROFILE CIRCLE
========================================================= */

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


/* =========================================================
   PROFILE INFORMATION
========================================================= */

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


/* =========================================================
   DROPDOWN MARKER
========================================================= */

.dropdown-marker {
    width: 7px;
    height: 7px;

    border-radius: 50%;

    background: #d4af37;

    flex-shrink: 0;
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
    width: 92%;
    max-width: 1200px;

    margin: 28px auto 50px;
}


/* =========================================================
   PAGE HEADER
========================================================= */

.header {
    background: linear-gradient(
        135deg,
        #0f2f5f,
        #164a8a
    );

    color: white;

    padding: 30px;

    border-radius: 16px;

    margin-bottom: 25px;

    box-shadow: 0 8px 25px rgba(15, 47, 95, 0.20);

    border-bottom: 4px solid #d4af37;
}


.header h1 {
    margin: 0 0 8px 0;
    font-size: 28px;
    color: #ffffff;
}


.header p {
    margin: 0;
    color: #e5e7eb;
    font-size: 15px;
}


/* =========================================================
   SEARCH AREA
========================================================= */

.search-box {
    background: white;

    padding: 24px;

    border-radius: 16px;

    margin-bottom: 28px;

    box-shadow: 0 5px 18px rgba(0, 0, 0, 0.08);

    border: 1px solid #e5e7eb;
}


.search-form {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 12px;
}


.search-form input,
.search-form select {
    width: 100%;

    padding: 13px 14px;

    border: 1px solid #cbd5e1;
    border-radius: 9px;

    font-size: 14px;

    background: #ffffff;
    color: #1f2937;

    transition: 0.2s;

    cursor: pointer;
}


.search-form input {
    cursor: text;
}


.search-form input::placeholder {
    color: #9ca3af;
}


.search-form input:focus,
.search-form select:focus {
    outline: none;

    border-color: #2563eb;

    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}


/* =========================================================
   SEARCH BUTTON
========================================================= */

.search-button {
    padding: 13px 22px;

    border: none;
    border-radius: 9px;

    background: linear-gradient(
        135deg,
        #2563eb,
        #164a8a
    );

    color: white;

    font-weight: bold;
    cursor: pointer;

    transition: 0.2s;

    border-bottom: 3px solid #d4af37;
}


.search-button:hover {
    transform: translateY(-2px);

    box-shadow: 0 6px 15px rgba(37, 99, 235, 0.25);
}


/* =========================================================
   FILTER SELECT HIGHLIGHT
========================================================= */

.search-form select.filtered {
    border-color: #d4af37;
    background: #fffdf2;
    font-weight: bold;
}


/* =========================================================
   RESULTS HEADER
========================================================= */

.results-header {
    display: flex;
    justify-content: space-between;
    align-items: center;

    margin-bottom: 18px;
    padding: 0 3px;
}


.results-header h2 {
    margin: 0;
    color: #12345b;
    font-size: 23px;
}


.results-count {
    color: #64748b;
    font-size: 14px;

    background: white;

    padding: 7px 12px;

    border-radius: 20px;

    border: 1px solid #e2e8f0;
}


/* =========================================================
   ITEM GRID
========================================================= */

.items {
    display: grid;

    grid-template-columns: repeat(3, 1fr);

    gap: 22px;
}


/* =========================================================
   ITEM CARD
========================================================= */

.item-card {
    background: white;

    border-radius: 16px;

    overflow: hidden;

    box-shadow: 0 5px 18px rgba(0, 0, 0, 0.08);

    border: 1px solid #e5e7eb;

    transition: 0.25s;

    position: relative;
}


.item-card:hover {
    transform: translateY(-5px);

    box-shadow: 0 10px 28px rgba(15, 47, 95, 0.15);

    border-color: #d4af37;
}


/* =========================================================
   ITEM IMAGE
========================================================= */

.item-image {
    width: 100%;
    height: 215px;

    object-fit: cover;
    display: block;

    background: #e5e7eb;
}


.no-image {
    height: 215px;

    display: flex;
    align-items: center;
    justify-content: center;

    background: linear-gradient(
        135deg,
        #e5e7eb,
        #f1f5f9
    );

    color: #64748b;
    font-size: 14px;
}


/* =========================================================
   ITEM CONTENT
========================================================= */

.item-content {
    padding: 21px;
}


.item-content h3 {
    margin: 0 0 12px 0;

    color: #12345b;
    font-size: 20px;

    line-height: 1.3;
}


/* =========================================================
   BADGES
========================================================= */

.badges {
    display: flex;
    flex-wrap: wrap;

    gap: 7px;

    margin-bottom: 16px;
}


.badge {
    padding: 6px 11px;

    border-radius: 20px;

    font-size: 11px;
    font-weight: bold;

    letter-spacing: 0.3px;
}


/* =========================================================
   LOST
========================================================= */

.lost-badge {
    background: #fee2e2;
    color: #b91c1c;

    border: 1px solid #fecaca;
}


/* =========================================================
   FOUND
========================================================= */

.found-badge {
    background: #dbeafe;
    color: #1d4ed8;

    border: 1px solid #bfdbfe;
}


/* =========================================================
   STATUS
========================================================= */

.status-badge {
    background: #fff8dc;
    color: #92701c;

    border: 1px solid #e8cf72;
}


/* =========================================================
   ITEM INFORMATION
========================================================= */

.item-info {
    font-size: 14px;

    line-height: 1.7;

    color: #64748b;

    margin-bottom: 17px;
}


.item-info > div {
    padding: 5px 0;

    border-bottom: 1px solid #f1f5f9;
}


.item-info > div:last-child {
    border-bottom: none;
}


.item-info strong {
    color: #334155;
}


/* =========================================================
   VIEW BUTTON
========================================================= */

.view-button {
    display: block;

    text-align: center;

    padding: 11px;

    background: linear-gradient(
        135deg,
        #2563eb,
        #164a8a
    );

    color: white;

    text-decoration: none;

    border-radius: 9px;

    font-weight: bold;
    font-size: 14px;

    border-bottom: 3px solid #d4af37;

    transition: 0.2s;
}


.view-button:hover {
    transform: translateY(-2px);

    background: linear-gradient(
        135deg,
        #1d4ed8,
        #123d73
    );

    box-shadow: 0 5px 14px rgba(37, 99, 235, 0.25);
}


/* =========================================================
   NO RESULTS
========================================================= */

.no-results {
    background: white;

    padding: 55px 30px;

    text-align: center;

    border-radius: 16px;

    color: #64748b;

    box-shadow: 0 5px 18px rgba(0, 0, 0, 0.08);

    border: 1px solid #e5e7eb;
}


.no-results h3 {
    color: #12345b;

    margin-bottom: 8px;

    font-size: 21px;
}


.no-results p {
    font-size: 14px;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 900px) {

    .search-form {
        grid-template-columns: 1fr 1fr;
    }

    .items {
        grid-template-columns: repeat(2, 1fr);
    }

}


@media (max-width: 800px) {

    .navbar {
        padding: 10px 18px;
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

    .container {
        width: 94%;
        margin: 20px auto;
    }

    .header {
        padding: 23px;
    }

    .header h1 {
        font-size: 23px;
    }

    .search-form {
        grid-template-columns: 1fr;
    }

    .items {
        grid-template-columns: 1fr;
    }

    .results-header {
        align-items: flex-start;
        gap: 10px;
        flex-direction: column;
    }

    .item-image,
    .no-image {
        height: 200px;
    }

}

</style>

</head>


<body>


<!-- =======================================================
     NAVIGATION
======================================================= -->

<nav class="navbar">


    <!-- BRAND: TITLE FIRST, LOGO AFTER TITLE -->

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
            aria-label="Open profile menu"
            aria-expanded="false"
        >

            <span class="profile-name">

                <?php
                echo htmlspecialchars($profile_name);
                ?>

            </span>


            <span class="profile-circle">

                <?php
                echo htmlspecialchars($profile_initial);
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
                    echo htmlspecialchars($profile_initial);
                    ?>

                </div>


                <div class="profile-details">

                    <strong>

                        <?php
                        echo htmlspecialchars($profile_name);
                        ?>

                    </strong>


                    <span>

                        <?php
                        echo htmlspecialchars($profile_email);
                        ?>

                    </span>

                </div>

            </div>


            <!-- DROPDOWN LINKS -->

            <div class="dropdown-links">

                <a
                    href="messages.php"
                    class="dropdown-link"
                >

                    <span class="dropdown-link-content">

                        <span class="dropdown-marker"></span>

                        <span>Messages</span>

                    </span>

                </a>


                <a
                    href="notifications.php"
                    class="dropdown-link"
                >

                    <span class="dropdown-link-content">

                        <span class="dropdown-marker"></span>

                        <span>Notifications</span>

                    </span>

                </a>


                <a
                    href="logout.php"
                    class="dropdown-link signout"
                >

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
     MAIN CONTAINER
======================================================= -->

<div class="container">


    <!-- PAGE HEADER -->

    <div class="header">

        <h1>
            Search Lost & Found Items
        </h1>

        <p>
            Search for reported lost and found items in the RTU system.
        </p>

    </div>



    <!-- SEARCH BOX -->

    <div class="search-box">

        <form
            method="GET"
            class="search-form"
            id="searchForm"
        >

            <!-- SEARCH -->

            <input
                type="text"
                name="search"
                placeholder="Search item, description, or location..."
                value="<?php echo htmlspecialchars($search); ?>"
            >


            <!-- TYPE -->

            <select
                name="type"
                id="typeFilter"
                onchange="applyFilter()"
            >

                <option value="">
                    All Types
                </option>


                <option
                    value="lost"
                    <?php echo ($type === "lost") ? "selected" : ""; ?>
                >
                    Lost Items
                </option>


                <option
                    value="found"
                    <?php echo ($type === "found") ? "selected" : ""; ?>
                >
                    Found Items
                </option>

            </select>



            <!-- CATEGORY -->

            <select
                name="category"
                id="categoryFilter"
                onchange="applyFilter()"
            >

                <option value="">
                    All Categories
                </option>


                <option
                    value="Wallet"
                    <?php echo ($category === "Wallet") ? "selected" : ""; ?>
                >
                    Wallet
                </option>


                <option
                    value="Phone"
                    <?php echo ($category === "Phone") ? "selected" : ""; ?>
                >
                    Phone
                </option>


                <option
                    value="Laptop"
                    <?php echo ($category === "Laptop") ? "selected" : ""; ?>
                >
                    Laptop
                </option>


                <option
                    value="Tablet"
                    <?php echo ($category === "Tablet") ? "selected" : ""; ?>
                >
                    Tablet
                </option>


                <option
                    value="ID / Identification Card"
                    <?php echo ($category === "ID / Identification Card") ? "selected" : ""; ?>
                >
                    ID / Identification Card
                </option>


                <option
                    value="School Supplies"
                    <?php echo ($category === "School Supplies") ? "selected" : ""; ?>
                >
                    School Supplies
                </option>


                <option
                    value="Bag"
                    <?php echo ($category === "Bag") ? "selected" : ""; ?>
                >
                    Bag
                </option>


                <option
                    value="Clothing"
                    <?php echo ($category === "Clothing") ? "selected" : ""; ?>
                >
                    Clothing
                </option>


                <option
                    value="Keys"
                    <?php echo ($category === "Keys") ? "selected" : ""; ?>
                >
                    Keys
                </option>


                <option
                    value="Accessories"
                    <?php echo ($category === "Accessories") ? "selected" : ""; ?>
                >
                    Accessories
                </option>


                <option
                    value="Electronic Device"
                    <?php echo ($category === "Electronic Device") ? "selected" : ""; ?>
                >
                    Electronic Device
                </option>


                <option
                    value="Books"
                    <?php echo ($category === "Books") ? "selected" : ""; ?>
                >
                    Books
                </option>


                <option
                    value="Documents"
                    <?php echo ($category === "Documents") ? "selected" : ""; ?>
                >
                    Documents
                </option>


                <option
                    value="Other"
                    <?php echo ($category === "Other") ? "selected" : ""; ?>
                >
                    Other
                </option>

            </select>



            <!-- SEARCH BUTTON -->

            <button
                type="submit"
                class="search-button"
            >
                Search
            </button>

        </form>

    </div>



    <!-- RESULTS HEADER -->

    <div class="results-header">

        <h2>

            <?php

            echo empty($search)
                && empty($type)
                && empty($category)

                ? "All Reports"

                : "Search Results";

            ?>

        </h2>


        <span class="results-count">

            <?php

            echo $result
                ? $result->num_rows . " item(s)"
                : "0 items";

            ?>

        </span>

    </div>



    <!-- RESULTS -->

    <?php if (
        $result &&
        $result->num_rows > 0
    ): ?>

        <div class="items">

            <?php while (
                $item = $result->fetch_assoc()
            ): ?>

                <div class="item-card">


                    <!-- ITEM IMAGE -->

                    <?php if (!empty($item["image"])): ?>

                        <?php

                        $image_folder = (

                            strtolower(
                                trim($item["item_type"])
                            ) === "lost"

                        )
                            ? "uploads/lost/"
                            : "uploads/found/";

                        ?>


                        <img
                            class="item-image"
                            src="<?php
                                echo htmlspecialchars(
                                    $image_folder . $item["image"]
                                );
                            ?>"
                            alt="<?php
                                echo htmlspecialchars($item["item_name"]);
                            ?>"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
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



                    <!-- ITEM CONTENT -->

                    <div class="item-content">


                        <h3>

                            <?php
                            echo htmlspecialchars($item["item_name"]);
                            ?>

                        </h3>



                        <!-- BADGES -->

                        <div class="badges">

                            <?php if (
                                strtolower(
                                    trim($item["item_type"])
                                ) === "lost"
                            ): ?>

                                <span class="badge lost-badge">
                                    LOST
                                </span>

                            <?php else: ?>

                                <span class="badge found-badge">
                                    FOUND
                                </span>

                            <?php endif; ?>


                            <!-- STATUS -->

                            <span class="badge status-badge">

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



                        <!-- ITEM INFORMATION -->

                        <div class="item-info">


                            <div>

                                <strong>
                                    Category:
                                </strong>

                                <?php
                                echo htmlspecialchars(
                                    $item["category"] ?? "N/A"
                                );
                                ?>

                            </div>


                            <div>

                                <strong>
                                    Location:
                                </strong>

                                <?php
                                echo htmlspecialchars(
                                    $item["location"] ?? "N/A"
                                );
                                ?>

                            </div>


                            <div>

                                <strong>
                                    Date:
                                </strong>

                                <?php
                                echo htmlspecialchars($item["date_reported"]);
                                ?>

                            </div>


                            <?php if (!empty($item["description"])): ?>

                                <div>

                                    <strong>
                                        Description:
                                    </strong>

                                    <?php

                                    $description = $item["description"];

                                    if (strlen($description) > 100) {

                                        $description = substr(
                                            $description,
                                            0,
                                            100
                                        ) . "...";

                                    }

                                    echo htmlspecialchars($description);

                                    ?>

                                </div>

                            <?php endif; ?>


                        </div>



                        <!-- VIEW ITEM -->

                        <a
                            class="view-button"
                            href="item.php?id=<?php echo (int) $item["id"]; ?>"
                        >
                            View Item
                        </a>


                    </div>


                </div>


            <?php endwhile; ?>

        </div>


    <?php else: ?>


        <!-- NO RESULTS -->

        <div class="no-results">

            <h3>
                No matching items found
            </h3>

            <p>
                Try using a different keyword or changing the filters.
            </p>

        </div>


    <?php endif; ?>


</div>



<!-- =======================================================
     JAVASCRIPT
======================================================= -->

<script>

/*
    Search filter
*/

function applyFilter() {

    const form = document.getElementById("searchForm");

    form.submit();

}


/*
    Profile dropdown
*/

const profileButton = document.getElementById("profileButton");

const profileDropdown = document.getElementById("profileDropdown");


profileButton.addEventListener("click", function(event) {

    event.stopPropagation();

    profileDropdown.classList.toggle("show");

    profileButton.classList.toggle("open");

    profileButton.setAttribute(
        "aria-expanded",
        profileButton.classList.contains("open")
    );

});


/*
    Close dropdown when clicking outside
*/

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


/*
    Highlight active filters
*/

document.addEventListener("DOMContentLoaded", function() {

    const typeFilter = document.getElementById("typeFilter");

    const categoryFilter = document.getElementById("categoryFilter");


    if (typeFilter.value !== "") {

        typeFilter.classList.add("filtered");

    }


    if (categoryFilter.value !== "") {

        categoryFilter.classList.add("filtered");

    }

});


/*
    Prevent browser back/forward cache
*/

window.addEventListener("pageshow", function(event) {

    if (event.persisted) {

        window.location.reload();

    }

});

</script>


</body>

</html>