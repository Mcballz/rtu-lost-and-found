
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

/* =========================================================
   SUPABASE CONNECTION
========================================================= */

require_once __DIR__ . "/config/supabase.php";

$user_id = (int) $_SESSION["user_id"];

$error = "";
$success = "";
$verification_code = "";

/* =========================================================
   HTML ESCAPE HELPER
========================================================= */

function e($value) {
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

/* =========================================================
   CURRENT USER
========================================================= */

$user_stmt = $supabase->prepare("
    SELECT
        id,
        student_id,
        name,
        email,
        role
    FROM users
    WHERE id = :user_id
    LIMIT 1
");

$user_stmt->execute([
    "user_id" => $user_id
]);

$current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_user) {

    session_unset();
    session_destroy();

    header("Location: login.php");
    exit();
}

/* =========================================================
   UNREAD MESSAGES
========================================================= */

$total_unread = 0;

try {

    $unread_stmt = $supabase->prepare("
        SELECT COUNT(*) AS total_unread
        FROM messages
        WHERE receiver_id = :user_id
        AND is_read = FALSE
    ");

    $unread_stmt->execute([
        "user_id" => $user_id
    ]);

    $unread_result = $unread_stmt->fetch(PDO::FETCH_ASSOC);

    $total_unread = (int) ($unread_result["total_unread"] ?? 0);

} catch (PDOException $e) {

    error_log("Unread messages error: " . $e->getMessage());

    $total_unread = 0;
}

/* =========================================================
   UNREAD AI MATCH NOTIFICATIONS
========================================================= */

$notification_unread = 0;

try {

    $notification_stmt = $supabase->prepare("
        SELECT COUNT(*) AS total
        FROM notifications
        WHERE user_id = :user_id
        AND is_read = FALSE
        AND type = 'ai_match'
    ");

    $notification_stmt->execute([
        "user_id" => $user_id
    ]);

    $notification_result = $notification_stmt->fetch(PDO::FETCH_ASSOC);

    $notification_unread = (int) ($notification_result["total"] ?? 0);

} catch (PDOException $e) {

    error_log("Notification count error: " . $e->getMessage());

    $notification_unread = 0;
}

/* =========================================================
   PROFILE INITIAL
========================================================= */

$name_for_initial = trim($current_user["name"] ?? "");

$profile_initial = strtoupper(
    substr($name_for_initial, 0, 1)
);

if ($profile_initial === "") {
    $profile_initial = "U";
}

/* =========================================================
   FORM SUBMISSION
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $item_name = trim($_POST["item_name"] ?? "");
    $category = trim($_POST["category"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $location = trim($_POST["location"] ?? "");
    $date_reported = $_POST["date_reported"] ?? "";

    /* =====================================================
       VALIDATION
    ===================================================== */

    if (
        $item_name === "" ||
        $category === "" ||
        $description === "" ||
        $location === "" ||
        $date_reported === ""
    ) {

        $error = "Please fill in all required fields.";

    } elseif (
        !isset($_FILES["image"]) ||
        $_FILES["image"]["error"] !== UPLOAD_ERR_OK
    ) {

        $error = "Please upload an image of the lost item.";

    } else {

        $image = $_FILES["image"];

        $allowed_extensions = [
            "jpg",
            "jpeg",
            "png",
            "webp"
        ];

        $extension = strtolower(
            pathinfo(
                $image["name"],
                PATHINFO_EXTENSION
            )
        );

        /* =================================================
           IMAGE VALIDATION
        ================================================= */

        if (!in_array($extension, $allowed_extensions, true)) {

            $error = "Invalid image format. Please use JPG, JPEG, PNG, or WEBP.";

        } elseif ($image["size"] > 10 * 1024 * 1024) {

            $error = "Image is too large. Maximum size is 10MB.";

        } else {

            $image_info = @getimagesize($image["tmp_name"]);

            if ($image_info === false) {

                $error = "The uploaded file is not a valid image.";

            } else {

                /* =========================================
                   UPLOAD FOLDER
                ========================================= */

                $upload_folder = __DIR__ . "/uploads/lost/";

                if (
                    !is_dir($upload_folder) &&
                    !mkdir($upload_folder, 0775, true) &&
                    !is_dir($upload_folder)
                ) {

                    $error = "Unable to create the image upload folder.";

                } else {

                    /* =====================================
                       IMAGE NAME
                    ===================================== */

                    $image_name = "lost_" .
                        bin2hex(random_bytes(6)) .
                        "." .
                        $extension;

                    $image_path = $upload_folder . $image_name;

                    /* =====================================
                       MOVE IMAGE
                    ===================================== */

                    if (!move_uploaded_file(
                        $image["tmp_name"],
                        $image_path
                    )) {

                        $error = "Failed to upload image.";

                    } else {

                        /* =================================
                           GENERATE VERIFICATION CODE
                        ================================= */

                        $verification_code = "RTU-LST-" .
                            strtoupper(bin2hex(random_bytes(8)));

                        /* =================================
                           INSERT LOST ITEM INTO SUPABASE
                        ================================= */

                        try {

                            $insert_stmt = $supabase->prepare("
                                INSERT INTO items
                                (
                                    user_id,
                                    item_type,
                                    item_name,
                                    category,
                                    description,
                                    location,
                                    date_reported,
                                    image,
                                    status,
                                    verification_code
                                )
                                VALUES
                                (
                                    :user_id,
                                    'lost',
                                    :item_name,
                                    :category,
                                    :description,
                                    :location,
                                    :date_reported,
                                    :image,
                                    'lost',
                                    :verification_code
                                )
                                RETURNING id
                            ");

                            $insert_stmt->execute([
                                "user_id" => $user_id,
                                "item_name" => $item_name,
                                "category" => $category,
                                "description" => $description,
                                "location" => $location,
                                "date_reported" => $date_reported,
                                "image" => $image_name,
                                "verification_code" => $verification_code
                            ]);

                            $new_item_id = $insert_stmt->fetchColumn();

                            if (!$new_item_id) {
                                throw new RuntimeException(
                                    "The lost item report was not saved."
                                );
                            }

                            /* =================================
                               SUCCESS
                            ================================= */

                            $success = "Lost item reported successfully.";

                            // Clear submitted form values after success.
                            $_POST = [];

                        } catch (Throwable $e) {

                            error_log(
                                "Lost item submission error: " . $e->getMessage()
                            );

                            $error = "Failed to save the lost item. Please try again.";

                            $verification_code = "";

                            // Remove uploaded image if database saving fails.
                            if (file_exists($image_path)) {
                                unlink($image_path);
                            }

                        }

                    }

                }

            }

        }

    }

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
    Report Lost Item - RTU Lost and Found
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

    min-height: 74px;

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

    padding:
        18px;

    display: flex;

    align-items: center;

    gap: 12px;

    background: white;

    border-bottom:
        1px solid #e5e7eb;
}

.profile-header-circle {

    width: 45px;

    height: 45px;

    min-width: 45px;

    border-radius: 50%;

    background:
        #0f2f5f;

    color: white;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 18px;

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

    font-size: 14px;

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
   NOTIFICATION BADGE
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

    max-width: 850px;

    margin:
        30px auto 50px;
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

    padding:
        30px;

    border-radius: 16px;

    margin-bottom: 25px;

    box-shadow:
        0 8px 25px
        rgba(
            15,
            47,
            95,
            0.20
        );

    border-bottom:
        4px solid #d4af37;
}

.page-header h1 {

    margin:
        0 0 8px;

    font-size: 28px;
}

.page-header p {

    margin: 0;

    color:
        #e5e7eb;

    font-size: 15px;
}

/* =========================================================
   FORM CARD
========================================================= */

.card {

    background:
        white;

    border-radius: 16px;

    padding: 30px;

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
}

/* =========================================================
   ALERTS
========================================================= */

.error {

    background:
        #fee2e2;

    color:
        #991b1b;

    border:
        1px solid #fecaca;

    padding:
        14px 16px;

    border-radius: 9px;

    margin-bottom: 22px;

    font-size: 14px;
}

.success {

    background:
        #ecfdf5;

    color:
        #166534;

    border:
        1px solid #bbf7d0;

    padding:
        14px 16px;

    border-radius: 9px;

    margin-bottom: 22px;

    font-size: 14px;
}

/* =========================================================
   VERIFICATION CODE
========================================================= */

.verification-box {

    margin-top: 15px;

    padding: 17px;

    background: white;

    border:
        1px solid #bbf7d0;

    border-radius: 9px;
}

.verification-title {

    display: block;

    color:
        #166534;

    font-size: 14px;

    font-weight: 700;

    margin-bottom: 10px;
}

.verification-code {

    display: block;

    color:
        #0f2f5f;

    font-size: 23px;

    font-weight: bold;

    letter-spacing: 2px;

    overflow-wrap: anywhere;

    margin-bottom: 12px;
}

.copy-code-button {

    padding:
        9px 15px;

    background:
        #0f2f5f;

    color: white;

    border: none;

    border-radius: 7px;

    cursor: pointer;

    font-weight: 600;

    font-size: 13px;
}

.copy-code-button:hover {

    background:
        #164a8a;
}

.verification-note {

    margin-top: 11px;

    color:
        #475569;

    font-size: 12px;

    line-height: 1.6;
}

/* =========================================================
   FORM
========================================================= */

.form-group {

    margin-bottom: 21px;
}

label {

    display: block;

    font-weight: bold;

    color:
        #334155;

    margin-bottom: 8px;

    font-size: 14px;
}

input,
select,
textarea {

    width: 100%;

    padding:
        13px 14px;

    border:
        1px solid #cbd5e1;

    border-radius: 9px;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    font-size: 14px;

    background: white;

    color:
        #1f2937;

    outline: none;

    transition: 0.2s;
}

input:focus,
select:focus,
textarea:focus {

    border-color:
        #2563eb;

    box-shadow:
        0 0 0 3px
        rgba(
            37,
            99,
            235,
            0.12
        );
}

textarea {

    resize: vertical;

    min-height: 120px;

    line-height: 1.5;
}

input[type="file"] {

    padding: 11px;

    background:
        #f8fafc;

    cursor: pointer;
}

.help-text {

    margin-top: 7px;

    font-size: 12px;

    color:
        #64748b;
}

/* =========================================================
   AI INFORMATION
========================================================= */

.ai-info {

    margin-top: 25px;

    padding: 20px;

    border-radius: 12px;

    background:
        linear-gradient(
            135deg,
            #eff6ff,
            #f8fafc
        );

    border:
        1px solid #bfdbfe;

    border-left:
        5px solid #2563eb;
}

.ai-info h3 {

    margin:
        0 0 10px;

    color:
        #164a8a;

    font-size: 17px;
}

.ai-info p {

    margin:
        7px 0;

    font-size: 13px;

    line-height: 1.6;

    color:
        #475569;
}

.ai-info strong {

    color:
        #12345b;
}

/* =========================================================
   SUBMIT BUTTON
========================================================= */

.submit-button {

    width: 100%;

    padding: 14px;

    border: none;

    border-radius: 9px;

    background:
        linear-gradient(
            135deg,
            #2563eb,
            #164a8a
        );

    color: white;

    font-weight: bold;

    font-size: 15px;

    cursor: pointer;

    border-bottom:
        3px solid #d4af37;

    transition: 0.2s;
}

.submit-button:hover {

    transform:
        translateY(-2px);

    box-shadow:
        0 7px 16px
        rgba(
            37,
            99,
            235,
            0.25
        );
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 700px) {

    .navbar {

        padding:
            9px 15px;
    }

    .brand-title {

        font-size: 18px;
    }

    .brand-logo {

        width: 40px;

        height: 40px;
    }

    .profile-name {

        display: none;
    }

    .profile-dropdown {

        width: 290px;

        right: -5px;
    }

    .container {

        width: 94%;

        margin:
            20px auto 35px;
    }

    .page-header {

        padding: 23px;
    }

    .page-header h1 {

        font-size: 23px;
    }

    .card {

        padding: 20px;
    }

    .verification-code {

        font-size: 19px;
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

                <?php echo e($current_user["name"]); ?>

            </span>

            <span class="profile-circle">

                <?php echo e($profile_initial); ?>

            </span>

            <span class="profile-arrow"></span>

        </button>

        <!-- PROFILE DROPDOWN -->

        <div
            class="profile-dropdown"
            id="profileDropdown"
        >

            <!-- PROFILE HEADER -->

            <div class="profile-header">

                <div class="profile-header-circle">

                    <?php echo e($profile_initial); ?>

                </div>

                <div class="profile-details">

                    <strong>

                        <?php echo e($current_user["name"]); ?>

                    </strong>

                    <span>

                        <?php echo e($current_user["email"]); ?>

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

                    <span class="dropdown-link-content">

                        <span class="dropdown-marker"></span>

                        Messages

                    </span>

                    <?php if ($total_unread > 0): ?>

                        <span class="dropdown-badge">

                            <?php echo $total_unread; ?>

                        </span>

                    <?php endif; ?>

                </a>

                <!-- NOTIFICATIONS -->

                <a
                    href="notifications.php"
                    class="dropdown-link"
                >

                    <span class="dropdown-link-content">

                        <span class="dropdown-marker"></span>

                        Notifications

                    </span>

                    <?php if ($notification_unread > 0): ?>

                        <span class="dropdown-badge">

                            <?php echo $notification_unread; ?>

                        </span>

                    <?php endif; ?>

                </a>

                <div class="dropdown-divider"></div>

                <!-- SIGN OUT -->

                <a
                    href="logout.php"
                    class="dropdown-link signout"
                >

                    <span class="dropdown-link-content">

                        <span class="dropdown-marker"></span>

                        Sign Out

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

    <!-- PAGE HEADER -->

    <div class="page-header">

        <h1>
            Report Lost Item
        </h1>

        <p>
            Report an item that you lost on the RTU campus.
        </p>

    </div>

    <!-- FORM CARD -->

    <div class="card">

        <!-- ERROR -->

        <?php if ($error !== ""): ?>

            <div class="error">

                <?php echo e($error); ?>

            </div>

        <?php endif; ?>

        <!-- SUCCESS -->

        <?php if ($success !== ""): ?>

            <div class="success">

                <?php echo e($success); ?>

                <div class="verification-box">

                    <span class="verification-title">
                        Your Item Verification Code
                    </span>

                    <span
                        class="verification-code"
                        id="verificationCode"
                    ><?php echo e($verification_code); ?></span>

                    <button
                        type="button"
                        class="copy-code-button"
                        onclick="copyVerificationCode()"
                    >
                        Copy Verification Code
                    </button>

                    <p class="verification-note">
                        Keep this code safe. You can also find it in My Reports.
                        Provide this code to an administrator when asking them
                        to look up your report. This code identifies your report
                        but does not serve as proof of item ownership.
                    </p>

                </div>

            </div>

            <br>

        <?php endif; ?>

        <!-- FORM -->

        <form
            method="POST"
            enctype="multipart/form-data"
        >

            <!-- ITEM NAME -->

            <div class="form-group">

                <label for="item_name">
                    Item Name
                </label>

                <input
                    type="text"
                    id="item_name"
                    name="item_name"
                    placeholder="Example: Black cellphone"
                    value="<?php echo e($_POST["item_name"] ?? ""); ?>"
                    required
                >

            </div>

            <!-- CATEGORY -->

            <div class="form-group">

                <label for="category">
                    Category
                </label>

                <select
                    id="category"
                    name="category"
                    required
                >

                    <option value="">
                        Select Category
                    </option>

                    <?php

                    $categories = [

                        "Wallet",
                        "Phone",
                        "Laptop",
                        "Tablet",
                        "ID / Identification Card",
                        "School Supplies",
                        "Bag",
                        "Clothing",
                        "Keys",
                        "Accessories",
                        "Electronic Device",
                        "Books",
                        "Documents",
                        "Other"

                    ];

                    foreach ($categories as $cat):

                    ?>

                        <option
                            value="<?php echo e($cat); ?>"
                            <?php

                            echo (
                                ($_POST["category"] ?? "") === $cat
                            )
                            ? "selected"
                            : "";

                            ?>
                        >

                            <?php echo e($cat); ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <!-- DESCRIPTION -->

            <div class="form-group">

                <label for="description">
                    Description
                </label>

                <textarea
                    id="description"
                    name="description"
                    placeholder="Describe the item, including color, design, brand, or other details."
                    required
                ><?php echo e($_POST["description"] ?? ""); ?></textarea>

            </div>

            <!-- LOCATION -->

            <div class="form-group">

                <label for="location">
                    Where did you lose it?
                </label>

                <input
                    type="text"
                    id="location"
                    name="location"
                    placeholder="Example: RTU Library"
                    value="<?php echo e($_POST["location"] ?? ""); ?>"
                    required
                >

            </div>

            <!-- DATE -->

            <div class="form-group">

                <label for="date_reported">
                    Date Lost
                </label>

                <input
                    type="date"
                    id="date_reported"
                    name="date_reported"
                    value="<?php echo e($_POST["date_reported"] ?? date("Y-m-d")); ?>"
                    required
                >

            </div>

            <!-- IMAGE -->

            <div class="form-group">

                <label for="image">
                    Item Image
                </label>

                <input
                    type="file"
                    id="image"
                    name="image"
                    accept=".jpg,.jpeg,.png,.webp"
                    required
                >

                <div class="help-text">

                    Upload a clear image of the lost item.
                    Maximum size: 10MB.

                </div>

            </div>

            <!-- AI INFORMATION -->

            <div class="ai-info">

                <h3>
                    AI Image Matching
                </h3>

                <p>
                    Your lost-item report will be saved successfully.
                    AI matching can be initiated separately through
                    the Find Matching Items feature in My Reports.
                    The system uses the newer hybrid AI matching process
                    to identify potentially matching lost and found items.
                </p>

            </div>

            <br>

            <!-- SUBMIT -->

            <button
                type="submit"
                class="submit-button"
            >

                Submit Lost Item

            </button>

        </form>

    </div>

</div>

<script>

/* =========================================================
   PROFILE DROPDOWN
========================================================= */

const profileButton =
    document.getElementById("profileButton");

const profileDropdown =
    document.getElementById("profileDropdown");

profileButton.addEventListener(
    "click",
    function(event) {

        event.stopPropagation();

        profileDropdown.classList.toggle("show");

        profileButton.classList.toggle("open");

    }
);

/* =========================================================
   CLOSE DROPDOWN WHEN CLICKING OUTSIDE
========================================================= */

document.addEventListener(
    "click",
    function(event) {

        if (
            !profileDropdown.contains(event.target) &&
            !profileButton.contains(event.target)
        ) {

            profileDropdown.classList.remove("show");

            profileButton.classList.remove("open");

        }

    }
);

/* =========================================================
   COPY VERIFICATION CODE
========================================================= */

function copyVerificationCode() {

    const codeElement =
        document.getElementById("verificationCode");

    if (!codeElement) {
        return;
    }

    const code = codeElement.textContent.trim();

    if (navigator.clipboard && window.isSecureContext) {

        navigator.clipboard.writeText(code)
            .then(function() {

                alert("Verification code copied successfully.");

            })
            .catch(function() {

                alert("Unable to copy the code. Please copy it manually.");

            });

    } else {

        const temporaryInput =
            document.createElement("textarea");

        temporaryInput.value = code;

        document.body.appendChild(temporaryInput);

        temporaryInput.select();

        try {

            document.execCommand("copy");

            alert("Verification code copied successfully.");

        } catch (error) {

            alert("Unable to copy the code. Please copy it manually.");

        }

        document.body.removeChild(temporaryInput);

    }

}

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
