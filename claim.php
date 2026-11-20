
<?php

session_start();

/* ==================================================
   PREVENT BROWSER CACHE
================================================== */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");


/* ==================================================
   LOGIN CHECK
================================================== */

if (!isset($_SESSION["user_id"])) {

    header("Location: login.php");

    exit();

}


/* ==================================================
   DATABASE
================================================== */

require_once "config/database.php";


$user_id = (int) $_SESSION["user_id"];

$message = "";
$message_type = "";


/* ==================================================
   GET CURRENT USER
================================================== */

$user_stmt = $conn->prepare("
    SELECT name, student_id, role
    FROM users
    WHERE id = ?
    LIMIT 1
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


/* ==================================================
   GET ITEM ID
================================================== */

if (
    !isset($_GET["id"]) ||
    !is_numeric($_GET["id"])
) {

    die("Invalid item.");

}


$item_id = (int) $_GET["id"];


/* ==================================================
   GET ITEM
================================================== */

$sql = "
    SELECT
        id,
        item_name,
        item_type,
        status,
        user_id
    FROM items
    WHERE id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "i",
    $item_id
);

$stmt->execute();

$result = $stmt->get_result();


/* ==================================================
   CHECK ITEM
================================================== */

if ($result->num_rows !== 1) {

    $stmt->close();

    die("Item not found.");

}


$item = $result->fetch_assoc();

$stmt->close();


/* ==================================================
   ONLY FOUND ITEMS CAN BE CLAIMED
================================================== */

if ($item["item_type"] !== "found") {

    die("Only found items can be claimed.");

}


/* ==================================================
   ITEM ALREADY RETURNED
================================================== */

if ($item["status"] === "returned") {

    die("This item has already been returned.");

}


/* ==================================================
   USER CANNOT CLAIM OWN ITEM
================================================== */

if ((int) $item["user_id"] === $user_id) {

    die("You cannot claim your own found item.");

}


/* ==================================================
   PROCESS CLAIM
================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $claim_description = trim(
        $_POST["claim_description"] ?? ""
    );


    /* ==================================================
       VALIDATE DESCRIPTION
    ================================================== */

    if ($claim_description === "") {

        $message =
            "Please explain why this item belongs to you.";

        $message_type = "error";

    } else {

        $proof_image = null;


        /* ==================================================
           PROOF IMAGE
        ================================================== */

        if (
            isset($_FILES["proof_image"]) &&
            $_FILES["proof_image"]["error"] !== UPLOAD_ERR_NO_FILE
        ) {

            if (
                $_FILES["proof_image"]["error"] !== UPLOAD_ERR_OK
            ) {

                $message =
                    "There was a problem uploading the proof image.";

                $message_type = "error";

            } else {

                $upload_folder = "uploads/claims/";


                /* ==================================================
                   CREATE UPLOAD FOLDER
                ================================================== */

                if (!is_dir($upload_folder)) {

                    if (
                        !mkdir(
                            $upload_folder,
                            0777,
                            true
                        )
                    ) {

                        $message =
                            "Failed to create the proof image folder.";

                        $message_type = "error";
                    }
                }


                /* ==================================================
                   VALIDATE IMAGE
                ================================================== */

                if ($message === "") {

                    $file_extension = strtolower(
                        pathinfo(
                            $_FILES["proof_image"]["name"],
                            PATHINFO_EXTENSION
                        )
                    );


                    $allowed_extensions = [
                        "jpg",
                        "jpeg",
                        "png",
                        "webp"
                    ];


                    if (
                        !in_array(
                            $file_extension,
                            $allowed_extensions,
                            true
                        )
                    ) {

                        $message =
                            "Invalid proof image type. Please use JPG, JPEG, PNG, or WEBP.";

                        $message_type = "error";

                    } elseif (
                        $_FILES["proof_image"]["size"] >
                        10 * 1024 * 1024
                    ) {

                        $message =
                            "Proof image is too large. Maximum size is 10MB.";

                        $message_type = "error";

                    } else {

                        /* ==================================================
                           UNIQUE FILE NAME
                        ================================================== */

                        $proof_image =
                            "claim_" .
                            bin2hex(
                                random_bytes(6)
                            ) .
                            "." .
                            $file_extension;


                        $target_file =
                            $upload_folder .
                            $proof_image;


                        /* ==================================================
                           MOVE IMAGE
                        ================================================== */

                        if (
                            !move_uploaded_file(
                                $_FILES["proof_image"]["tmp_name"],
                                $target_file
                            )
                        ) {

                            $message =
                                "Failed to upload proof image.";

                            $message_type = "error";

                            $proof_image = null;
                        }
                    }
                }
            }
        }


        /* ==================================================
           INSERT CLAIM
        ================================================== */

        if ($message === "") {

            $sql = "
                INSERT INTO claims
                (
                    item_id,
                    user_id,
                    claim_description,
                    proof_image,
                    status
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    'pending'
                )
            ";


            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                "iiss",
                $item_id,
                $user_id,
                $claim_description,
                $proof_image
            );


            if ($stmt->execute()) {

                /* ==================================================
                   CHANGE ITEM STATUS
                ================================================== */

                $update_sql = "
                    UPDATE items
                    SET status = 'claim_pending'
                    WHERE id = ?
                ";


                $update_stmt = $conn->prepare(
                    $update_sql
                );


                $update_stmt->bind_param(
                    "i",
                    $item_id
                );


                $update_stmt->execute();

                $update_stmt->close();


                $message =
                    "Your claim has been submitted successfully. Please wait for administrator review.";

                $message_type = "success";


                /* ==================================================
                   PREVENT DUPLICATE SUBMISSION ON REFRESH
                ================================================== */

                $_POST = [];

            } else {

                $message =
                    "Something went wrong while submitting your claim.";

                $message_type = "error";
            }


            $stmt->close();
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

<title>
    Claim Item - RTU Lost and Found
</title>


<style>

/* ==================================================
   GLOBAL
================================================== */

* {

    box-sizing: border-box;

}


body {

    margin: 0;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background:
        #f4f7fb;

    color:
        #1f2937;

}


/* ==================================================
   NAVBAR
================================================== */

.navbar {

    width: 100%;

    min-height: 70px;

    background:
        linear-gradient(135deg, #0f2f5f, #164a8a);

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding:
        10px 28px;

    border-bottom:
        4px solid #d4af37;

    box-shadow:
        0 4px 15px rgba(15, 47, 95, 0.20);

    position: relative;

    z-index: 1000;

}


/* ==================================================
   BRAND
================================================== */

.brand {

    display: flex;

    align-items: center;

    gap: 10px;

    text-decoration: none;

    color: white;

    flex-shrink: 0;

}


/* ==================================================
   BRAND TITLE
================================================== */

.brand-title {

    font-size: 20px;

    font-weight: 700;

    letter-spacing: 0.2px;

    white-space: nowrap;

}


/* ==================================================
   RTU LOGO
   TITLE FIRST, LOGO AFTER TITLE
================================================== */

.brand-logo {

    width: 46px;

    height: 46px;

    object-fit: contain;

    display: block;

    flex-shrink: 0;

}


/* ==================================================
   PROFILE AREA
================================================== */

.profile-wrapper {

    position: relative;

}


/* ==================================================
   PROFILE BUTTON
================================================== */

.profile-button {

    border: none;

    background: transparent;

    color: white;

    display: flex;

    align-items: center;

    gap: 10px;

    cursor: pointer;

    padding: 5px 7px;

    border-radius: 20px;

    font-family: inherit;

    transition:
        background 0.2s ease,
        box-shadow 0.2s ease;

}


/* ==================================================
   PROFILE HOVER
================================================== */

.profile-button:hover {

    background:
        rgba(255, 255, 255, 0.08);

    box-shadow:
        0 0 0 1px rgba(255, 255, 255, 0.08);

}


/* ==================================================
   PROFILE NAME
================================================== */

.profile-name {

    font-size: 14px;

    font-weight: 600;

    max-width: 150px;

    overflow: hidden;

    white-space: nowrap;

    text-overflow: ellipsis;

}


/* ==================================================
   PROFILE AVATAR
================================================== */

.profile-avatar {

    width: 38px;

    height: 38px;

    border-radius: 50%;

    background: #d4af37;

    color: #0f2f5f;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 15px;

    font-weight: 700;

    border: 2px solid #ffffff;

    flex-shrink: 0;

}


/* ==================================================
   PROFILE ARROW
================================================== */

.profile-arrow {

    font-size: 11px;

    color: white;

    margin-left: 1px;

    display: inline-block;

    transition:
        transform 0.2s ease;

}


/* ==================================================
   ARROW ROTATION WHEN OPEN
================================================== */

.profile-button.active .profile-arrow {

    transform:
        rotate(180deg);

}


/* ==================================================
   DROPDOWN
================================================== */

.profile-dropdown {

    position: absolute;

    top: calc(100% + 10px);

    right: 0;

    width: 310px;

    background: white;

    border-radius: 12px;

    box-shadow:
        0 12px 35px rgba(0, 0, 0, 0.16);

    border: 1px solid #e5e7eb;

    overflow: hidden;

    display: none;

}


/* ==================================================
   DROPDOWN SHOW
================================================== */

.profile-dropdown.show {

    display: block;

}


/* ==================================================
   DROPDOWN USER
================================================== */

.dropdown-user {

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 18px;

    border-bottom: 1px solid #e5e7eb;

    background: #f8fafc;

}


/* ==================================================
   DROPDOWN AVATAR
================================================== */

.dropdown-avatar {

    width: 42px;

    height: 42px;

    min-width: 42px;

    border-radius: 50%;

    background: #d4af37;

    color: #0f2f5f;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 16px;

    font-weight: 700;

    border: 2px solid white;

}


/* ==================================================
   DROPDOWN USER DETAILS
================================================== */

.dropdown-user-details {

    min-width: 0;

}


/* ==================================================
   DROPDOWN USER NAME
================================================== */

.dropdown-user-name {

    font-weight: 700;

    color: #0f2f5f;

    font-size: 14px;

    overflow-wrap: anywhere;

}


/* ==================================================
   DROPDOWN USER ROLE
================================================== */

.dropdown-user-role {

    font-size: 12px;

    color: #6b7280;

    margin-top: 4px;

}


/* ==================================================
   DROPDOWN LINKS
================================================== */

.profile-dropdown a {

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 13px 18px;

    text-decoration: none;

    color: #374151;

    font-size: 14px;

    font-weight: 500;

    transition:
        background 0.2s ease,
        color 0.2s ease;

}


.profile-dropdown a:hover {

    background: #f1f5f9;

    color: #0f2f5f;

}


/* ==================================================
   DROPDOWN LINK CONTENT
================================================== */

.dropdown-link-content {

    display: flex;

    align-items: center;

    gap: 11px;

}


/* ==================================================
   DROPDOWN MARKER
================================================== */

.dropdown-marker {

    width: 7px;

    height: 7px;

    border-radius: 50%;

    background: #d4af37;

    flex-shrink: 0;

}


/* ==================================================
   SIGN OUT
================================================== */

.profile-dropdown .signout {

    color: #b91c1c;

    border-top: 1px solid #e5e7eb;

}


.profile-dropdown .signout .dropdown-marker {

    background: #b91c1c;

}


.profile-dropdown .signout:hover {

    background: #fef2f2;

    color: #991b1b;

}


/* ==================================================
   MAIN CONTAINER
================================================== */

.container {

    max-width: 850px;

    margin: 45px auto;

    padding: 0 20px;

}


/* ==================================================
   CARD
================================================== */

.card {

    background: white;

    border-radius: 14px;

    padding: 32px;

    border: 1px solid #e5e7eb;

    box-shadow:
        0 5px 20px rgba(0, 0, 0, 0.07);

}


/* ==================================================
   PAGE HEADER
================================================== */

.page-header {

    margin-bottom: 28px;

    border-bottom: 1px solid #e5e7eb;

    padding-bottom: 20px;

}


.page-header h1 {

    margin: 0 0 8px;

    color: #0b4178;

    font-size: 28px;

}


.page-header p {

    margin: 0;

    color: #6b7280;

    font-size: 14px;

    line-height: 1.5;

}


/* ==================================================
   ITEM INFORMATION
================================================== */

.item-information {

    background: #f8fafc;

    border-left: 4px solid #f4c430;

    padding: 16px 18px;

    border-radius: 8px;

    margin-bottom: 25px;

}


.item-label {

    color: #6b7280;

    font-size: 12px;

    margin-bottom: 5px;

    font-weight: 600;

}


.item-name {

    color: #0b4178;

    font-size: 20px;

    font-weight: bold;

}


/* ==================================================
   ALERT
================================================== */

.alert {

    padding: 14px 16px;

    border-radius: 8px;

    margin-bottom: 22px;

    font-size: 14px;

    line-height: 1.5;

}


.alert-success {

    background: #ecfdf5;

    color: #166534;

    border: 1px solid #bbf7d0;

}


.alert-error {

    background: #fef2f2;

    color: #991b1b;

    border: 1px solid #fecaca;

}


/* ==================================================
   FORM
================================================== */

.form-group {

    margin-bottom: 22px;

}


.form-label {

    display: block;

    font-weight: 600;

    color: #374151;

    font-size: 14px;

    margin-bottom: 8px;

}


.required {

    color: #b91c1c;

}


textarea {

    width: 100%;

    min-height: 150px;

    resize: vertical;

    border: 1px solid #d1d5db;

    border-radius: 8px;

    padding: 13px;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    font-size: 14px;

    color: #1f2937;

    outline: none;

    transition:
        border-color 0.2s,
        box-shadow 0.2s;

}


textarea:focus {

    border-color: #2563eb;

    box-shadow:
        0 0 0 3px rgba(37, 99, 235, 0.1);

}


textarea::placeholder {

    color: #9ca3af;

}


/* ==================================================
   FILE INPUT
================================================== */

.file-input-wrapper {

    border: 1px dashed #cbd5e1;

    background: #f8fafc;

    border-radius: 8px;

    padding: 16px;

}


input[type="file"] {

    width: 100%;

    font-size: 13px;

    color: #374151;

}


.help-text {

    margin-top: 7px;

    font-size: 12px;

    color: #6b7280;

}


/* ==================================================
   INFORMATION BOX
================================================== */

.info-box {

    background: #eff6ff;

    border: 1px solid #bfdbfe;

    border-radius: 8px;

    padding: 15px 17px;

    margin-bottom: 25px;

    color: #1e3a8a;

    font-size: 13px;

    line-height: 1.55;

}


/* ==================================================
   BUTTON
================================================== */

.button-row {

    display: flex;

    gap: 12px;

    align-items: center;

}


.submit-button {

    flex: 1;

    border: none;

    border-radius: 8px;

    padding: 13px 20px;

    background: #0b5cab;

    color: white;

    font-size: 14px;

    font-weight: bold;

    cursor: pointer;

    transition: background 0.2s;

}


.submit-button:hover {

    background: #084b8a;

}


.back-button {

    text-decoration: none;

    text-align: center;

    padding: 12px 20px;

    border: 1px solid #d1d5db;

    border-radius: 8px;

    color: #374151;

    background: white;

    font-size: 14px;

    font-weight: 600;

    transition: 0.2s;

}


.back-button:hover {

    background: #f8fafc;

    border-color: #9ca3af;

}


/* ==================================================
   SUCCESS ACTION
================================================== */

.success-actions {

    margin-top: 20px;

    text-align: center;

}


.success-actions a {

    display: inline-block;

    text-decoration: none;

    background: #0b5cab;

    color: white;

    padding: 11px 22px;

    border-radius: 8px;

    font-size: 14px;

    font-weight: bold;

}


.success-actions a:hover {

    background: #084b8a;

}


/* ==================================================
   RESPONSIVE
================================================== */

@media (max-width: 700px) {

    .navbar {

        padding: 10px 15px;

        min-height: 66px;

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


    .profile-arrow {

        display: none;

    }


    .profile-dropdown {

        width: 290px;

    }


    .container {

        margin: 25px auto;

        padding: 0 14px;

    }


    .card {

        padding: 22px;

    }


    .page-header h1 {

        font-size: 24px;

    }


    .button-row {

        flex-direction: column-reverse;

    }


    .submit-button,
    .back-button {

        width: 100%;

    }

}


@media (max-width: 400px) {

    .brand-title {

        font-size: 16px;

    }


    .brand-logo {

        width: 38px;

        height: 38px;

    }


    .profile-dropdown {

        width: calc(100vw - 30px);

    }

}

</style>

</head>


<body>


<!-- ==================================================
     NAVBAR
================================================== -->

<nav class="navbar">


    <!-- ==================================================
         BRAND
         TITLE FIRST, LOGO AFTER TITLE
    ================================================== -->

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


    <!-- ==================================================
         PROFILE
    ================================================== -->

    <div class="profile-wrapper">


        <button
            type="button"
            class="profile-button"
            id="profileButton"
            aria-expanded="false"
            aria-controls="profileDropdown"
        >

            <div class="profile-name">

                <?php

                echo htmlspecialchars(
                    $current_user["name"] ?? "User"
                );

                ?>

            </div>


            <div class="profile-avatar">

                <?php

                $name_for_initial =
                    trim(
                        $current_user["name"] ?? "User"
                    );

                echo htmlspecialchars(
                    strtoupper(
                        substr(
                            $name_for_initial,
                            0,
                            1
                        )
                    )
                );

                ?>

            </div>


            <div class="profile-arrow">
                ▼
            </div>


        </button>


        <!-- ==================================================
             PROFILE DROPDOWN
        ================================================== -->

        <div
            class="profile-dropdown"
            id="profileDropdown"
        >


            <!-- USER INFORMATION -->

            <div class="dropdown-user">


                <div class="dropdown-avatar">

                    <?php

                    echo htmlspecialchars(
                        strtoupper(
                            substr(
                                trim($current_user["name"] ?? "User"),
                                0,
                                1
                            )
                        )
                    );

                    ?>

                </div>


                <div class="dropdown-user-details">


                    <div class="dropdown-user-name">

                        <?php

                        echo htmlspecialchars(
                            $current_user["name"] ?? "User"
                        );

                        ?>

                    </div>


                    <div class="dropdown-user-role">

                        <?php

                        echo (
                            ($current_user["role"] ?? "") === "admin"
                        )
                        ? "Administrator"
                        : "RTU User";

                        ?>

                    </div>


                </div>


            </div>


            <!-- MESSAGES -->

            <a href="messages.php">

                <span class="dropdown-link-content">

                    <span class="dropdown-marker"></span>

                    Messages

                </span>

            </a>


            <!-- NOTIFICATIONS -->

            <a href="notifications.php">

                <span class="dropdown-link-content">

                    <span class="dropdown-marker"></span>

                    Notifications

                </span>

            </a>


            <!-- SIGN OUT -->

            <a
                href="logout.php"
                class="signout"
            >

                <span class="dropdown-link-content">

                    <span class="dropdown-marker"></span>

                    Sign Out

                </span>

            </a>


        </div>


    </div>


</nav>


<!-- ==================================================
     MAIN
================================================== -->

<main class="container">


<div class="card">


    <!-- ==================================================
         PAGE HEADER
    ================================================== -->

    <div class="page-header">


        <h1>
            Claim Found Item
        </h1>


        <p>
            Submit information that can help verify that this found item belongs to you.
        </p>


    </div>


    <!-- ==================================================
         ITEM
    ================================================== -->

    <div class="item-information">


        <div class="item-label">
            ITEM BEING CLAIMED
        </div>


        <div class="item-name">

            <?php

            echo htmlspecialchars(
                $item["item_name"]
            );

            ?>

        </div>


    </div>


    <!-- ==================================================
         MESSAGE
    ================================================== -->

    <?php if ($message !== ""): ?>


        <div
            class="alert
            <?php

            echo $message_type === "success"
                ? "alert-success"
                : "alert-error";

            ?>"
        >

            <?php

            echo htmlspecialchars(
                $message
            );

            ?>

        </div>


    <?php endif; ?>


    <?php if ($message_type !== "success"): ?>


        <!-- ==================================================
             INFORMATION
        ================================================== -->

        <div class="info-box">

            Provide specific details that can help verify ownership,
            such as unique markings, contents, identifying features,
            or other information that may not be visible in the item image.

        </div>


        <!-- ==================================================
             FORM
        ================================================== -->

        <form
            method="POST"
            enctype="multipart/form-data"
        >


            <!-- CLAIM DESCRIPTION -->

            <div class="form-group">


                <label
                    for="claim_description"
                    class="form-label"
                >

                    Why do you believe this item belongs to you?

                    <span class="required">
                        *
                    </span>

                </label>


                <textarea
                    id="claim_description"
                    name="claim_description"
                    placeholder="Describe identifying details, markings, contents, or other information that can prove ownership."
                    required
                ><?php

                echo htmlspecialchars(
                    $_POST["claim_description"] ?? ""
                );

                ?></textarea>


            </div>


            <!-- PROOF IMAGE -->

            <div class="form-group">


                <label
                    for="proof_image"
                    class="form-label"
                >

                    Proof Image

                </label>


                <div class="file-input-wrapper">


                    <input
                        type="file"
                        id="proof_image"
                        name="proof_image"
                        accept=".jpg,.jpeg,.png,.webp"
                    >


                    <div class="help-text">

                        Optional. Upload a photo that can help prove ownership.
                        Accepted formats: JPG, JPEG, PNG, and WEBP.
                        Maximum size: 10MB.

                    </div>


                </div>


            </div>


            <!-- BUTTONS -->

            <div class="button-row">


                <a
                    href="item.php?id=<?php echo $item_id; ?>"
                    class="back-button"
                >

                    Back to Item

                </a>


                <button
                    type="submit"
                    class="submit-button"
                >

                    Submit Claim

                </button>


            </div>


        </form>


    <?php else: ?>


        <!-- ==================================================
             SUCCESS ACTION
        ================================================== -->

        <div class="success-actions">


            <a
                href="item.php?id=<?php echo $item_id; ?>"
            >

                Return to Item

            </a>


        </div>


    <?php endif; ?>


</div>


</main>


<!-- ==================================================
     PROFILE DROPDOWN SCRIPT
================================================== -->

<script>

const profileButton =
    document.getElementById("profileButton");

const profileDropdown =
    document.getElementById("profileDropdown");


profileButton.addEventListener(
    "click",
    function(event) {

        event.stopPropagation();

        const isOpen =
            profileDropdown.classList.contains("show");


        profileDropdown.classList.toggle(
            "show"
        );


        profileButton.classList.toggle(
            "active",
            !isOpen
        );


        profileButton.setAttribute(
            "aria-expanded",
            !isOpen ? "true" : "false"
        );

    }
);


document.addEventListener(
    "click",
    function(event) {

        if (
            !profileDropdown.contains(event.target) &&
            !profileButton.contains(event.target)
        ) {

            profileDropdown.classList.remove(
                "show"
            );

            profileButton.classList.remove(
                "active"
            );

            profileButton.setAttribute(
                "aria-expanded",
                "false"
            );

        }

    }
);


document.addEventListener(
    "keydown",
    function(event) {

        if (event.key === "Escape") {

            profileDropdown.classList.remove(
                "show"
            );

            profileButton.classList.remove(
                "active"
            );

            profileButton.setAttribute(
                "aria-expanded",
                "false"
            );

        }

    }
);

</script>


</body>

</html>
