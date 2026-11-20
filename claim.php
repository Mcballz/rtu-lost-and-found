
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

$user_id = (int) $_SESSION["user_id"];


/* ==================================================
   DATABASE - SUPABASE PDO
================================================== */

try {

    require_once __DIR__ . "/config/supabase.php";

    if (!isset($supabase) || !($supabase instanceof PDO)) {
        throw new RuntimeException("Supabase connection unavailable.");
    }

} catch (Throwable $e) {

    error_log("Claim database connection error: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to connect to the database. Please try again later.");
}


/* ==================================================
   INITIALIZE VARIABLES
================================================== */

$message = "";
$message_type = "";

$current_user = null;
$item = null;

$total_unread = 0;
$notification_unread = 0;

$uploaded_file_path = null;
$claim_verification_code = null;


/* ==================================================
   GET CURRENT USER
================================================== */

try {

    $user_stmt = $supabase->prepare("
        SELECT name, student_id, email, role
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


    /* ==================================================
       GET UNREAD MESSAGES
    ================================================== */

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


    /* ==================================================
       GET UNREAD NOTIFICATIONS
    ================================================== */

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

        error_log("Claim notification error: " . $e->getMessage());
    }

} catch (Throwable $e) {

    error_log("Claim user information error: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to load your account information.");
}


/* ==================================================
   GET ITEM ID
================================================== */

if (
    !isset($_GET["id"]) ||
    !ctype_digit((string) $_GET["id"]) ||
    (int) $_GET["id"] <= 0
) {
    http_response_code(400);
    exit("Invalid item.");
}

$item_id = (int) $_GET["id"];


/* ==================================================
   GET ITEM
================================================== */

try {

    $stmt = $supabase->prepare("
        SELECT
            id,
            item_name,
            item_type,
            status,
            user_id
        FROM items
        WHERE id = :item_id
        LIMIT 1
    ");

    $stmt->execute([
        ":item_id" => $item_id
    ]);

    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt->closeCursor();

    if (!$item) {
        http_response_code(404);
        exit("Item not found.");
    }

} catch (Throwable $e) {

    error_log("Claim item lookup error: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to load the selected item.");
}


/* ==================================================
   ONLY FOUND ITEMS CAN BE CLAIMED
================================================== */

if ($item["item_type"] !== "found") {
    http_response_code(403);
    exit("Only found items can be claimed.");
}


/* ==================================================
   ITEM ALREADY RETURNED
================================================== */

if ($item["status"] === "returned") {
    http_response_code(403);
    exit("This item has already been returned.");
}


/* ==================================================
   USER CANNOT CLAIM OWN ITEM
================================================== */

if ((int) $item["user_id"] === $user_id) {
    http_response_code(403);
    exit("You cannot claim your own found item.");
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

        $message = "Please explain why this item belongs to you.";
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

            if ($_FILES["proof_image"]["error"] !== UPLOAD_ERR_OK) {

                $message = "There was a problem uploading the proof image.";
                $message_type = "error";

            } else {

                $upload_folder = __DIR__ . "/uploads/claims/";


                if (!is_dir($upload_folder)) {

                    if (!mkdir($upload_folder, 0775, true)) {

                        $message = "Failed to create the proof image folder.";
                        $message_type = "error";

                    }

                }


                /* VALIDATE IMAGE */

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

                    $max_file_size = 10 * 1024 * 1024;


                    if (!in_array($file_extension, $allowed_extensions, true)) {

                        $message = "Invalid proof image type. Please use JPG, JPEG, PNG, or WEBP.";
                        $message_type = "error";

                    } elseif ($_FILES["proof_image"]["size"] > $max_file_size) {

                        $message = "Proof image is too large. Maximum size is 10MB.";
                        $message_type = "error";

                    } else {

                        $image_info = @getimagesize(
                            $_FILES["proof_image"]["tmp_name"]
                        );

                        $allowed_mime_types = [
                            "image/jpeg",
                            "image/png",
                            "image/webp"
                        ];

                        if (
                            $image_info === false ||
                            !in_array(
                                $image_info["mime"],
                                $allowed_mime_types,
                                true
                            )
                        ) {

                            $message = "The uploaded file is not a valid image.";
                            $message_type = "error";

                        } else {

                            $proof_image =
                                "claim_" .
                                bin2hex(random_bytes(6)) .
                                "." .
                                $file_extension;

                            $uploaded_file_path = $upload_folder . $proof_image;


                            if (
                                !move_uploaded_file(
                                    $_FILES["proof_image"]["tmp_name"],
                                    $uploaded_file_path
                                )
                            ) {

                                $message = "Failed to upload proof image.";
                                $message_type = "error";

                                $proof_image = null;
                                $uploaded_file_path = null;

                            }

                        }

                    }

                }

            }

        }


        /* ==================================================
           INSERT CLAIM AND UPDATE ITEM
           USING TRANSACTION
        ================================================== */

        if ($message === "") {

            try {

                $supabase->beginTransaction();


                /* RECHECK ITEM STATUS */

                $check_stmt = $supabase->prepare("
                    SELECT id, item_type, status, user_id
                    FROM items
                    WHERE id = :item_id
                    FOR UPDATE
                ");

                $check_stmt->execute([
                    ":item_id" => $item_id
                ]);

                $latest_item = $check_stmt->fetch(PDO::FETCH_ASSOC);

                $check_stmt->closeCursor();


                if (!$latest_item) {
                    throw new RuntimeException("Item no longer exists.");
                }

                if ($latest_item["item_type"] !== "found") {
                    throw new RuntimeException("Only found items can be claimed.");
                }

                if ($latest_item["status"] === "returned") {
                    throw new RuntimeException("This item has already been returned.");
                }

                if ((int) $latest_item["user_id"] === $user_id) {
                    throw new RuntimeException("You cannot claim your own item.");
                }


                /* CHECK EXISTING PENDING OR APPROVED CLAIM */

                $existing_stmt = $supabase->prepare("
                    SELECT id
                    FROM claims
                    WHERE item_id = :item_id
                    AND user_id = :user_id
                    AND status IN ('pending', 'approved')
                    LIMIT 1
                ");

                $existing_stmt->execute([
                    ":item_id" => $item_id,
                    ":user_id" => $user_id
                ]);

                $existing_claim = $existing_stmt->fetch(PDO::FETCH_ASSOC);

                $existing_stmt->closeCursor();

                if ($existing_claim) {
                    throw new RuntimeException(
                        "You already have a pending or approved claim for this item."
                    );
                }


                /* GENERATE UNIQUE CLAIM VERIFICATION CODE */

                $claim_verification_code = null;

                $code_stmt = $supabase->prepare("
                    SELECT id
                    FROM claims
                    WHERE verification_code = :verification_code
                    LIMIT 1
                ");

                for ($attempt = 0; $attempt < 10; $attempt++) {

                    $generated_code =
                        "RTU-CLM-" .
                        strtoupper(bin2hex(random_bytes(4)));

                    $code_stmt->execute([
                        ":verification_code" => $generated_code
                    ]);

                    $existing_code = $code_stmt->fetch(PDO::FETCH_ASSOC);

                    $code_stmt->closeCursor();

                    if (!$existing_code) {
                        $claim_verification_code = $generated_code;
                        break;
                    }

                }

                if ($claim_verification_code === null) {
                    throw new RuntimeException(
                        "Unable to generate a claim verification code. Please try again."
                    );
                }


                /* INSERT CLAIM */

                $insert_stmt = $supabase->prepare("
                    INSERT INTO claims
                    (
                        item_id,
                        user_id,
                        claim_description,
                        proof_image,
                        status,
                        verification_code
                    )
                    VALUES
                    (
                        :item_id,
                        :user_id,
                        :claim_description,
                        :proof_image,
                        'pending',
                        :verification_code
                    )
                    RETURNING id
                ");

                $insert_stmt->execute([
                    ":item_id" => $item_id,
                    ":user_id" => $user_id,
                    ":claim_description" => $claim_description,
                    ":proof_image" => $proof_image,
                    ":verification_code" => $claim_verification_code
                ]);

                $claim_id = $insert_stmt->fetchColumn();


                /* UPDATE ITEM STATUS */

                $update_stmt = $supabase->prepare("
                    UPDATE items
                    SET status = 'claim_pending'
                    WHERE id = :item_id
                ");

                $update_stmt->execute([
                    ":item_id" => $item_id
                ]);


                /* COMMIT */

                $supabase->commit();

                $message = "Your claim has been submitted successfully. Please keep your claim verification code and provide it to the administrator when requested.";

                $message_type = "success";

                $_POST = [];

                $uploaded_file_path = null;

            } catch (Throwable $e) {

                if ($supabase->inTransaction()) {
                    $supabase->rollBack();
                }

                if (
                    $uploaded_file_path !== null &&
                    is_file($uploaded_file_path)
                ) {
                    unlink($uploaded_file_path);
                }

                $uploaded_file_path = null;

                $claim_verification_code = null;

                error_log("Claim submission error: " . $e->getMessage());

                $message = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : "Something went wrong while submitting your claim.";

                $message_type = "error";

            }

        }

    }

}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Claim Item - RTU Lost and Found</title>

<style>

/* ROOT */

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
}

/* GLOBAL */

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

/* BRAND */

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

/* PROFILE */

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

/* PROFILE DROPDOWN */

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

/* PROFILE HEADER */

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

/* DROPDOWN LINKS */

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

/* MAIN CONTAINER */

.container {
    max-width: 850px;
    margin: 45px auto;
    padding: 0 20px;
}

/* CARD */

.card {
    background: white;
    border-radius: 14px;
    padding: 32px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.07);
}

/* PAGE HEADER */

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

/* ITEM INFORMATION */

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

/* ALERT */

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

/* CLAIM VERIFICATION CODE */

.claim-code-box {
    background: #f8fafc;
    border: 1px solid #dbe3ee;
    border-left: 4px solid #d4af37;
    border-radius: 10px;
    padding: 20px;
    margin-top: 20px;
}

.claim-code-title {
    color: #0f2f5f;
    font-size: 14px;
    font-weight: bold;
    margin-bottom: 8px;
}

.claim-code-description {
    color: #64748b;
    font-size: 13px;
    line-height: 1.5;
    margin-bottom: 13px;
}

.claim-code-row {
    display: flex;
    align-items: center;
    gap: 10px;
}

.claim-code-value {
    flex: 1;
    background: white;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    padding: 13px 12px;
    color: #0b4178;
    font-size: 18px;
    font-weight: bold;
    letter-spacing: 1px;
    text-align: center;
    overflow-wrap: anywhere;
}

.copy-code-button {
    border: none;
    border-radius: 7px;
    padding: 13px 17px;
    background: #0b5cab;
    color: white;
    font-size: 13px;
    font-weight: bold;
    cursor: pointer;
    transition: background 0.2s;
}

.copy-code-button:hover {
    background: #084b8a;
}

.copy-feedback {
    color: #166534;
    font-size: 12px;
    margin-top: 9px;
    min-height: 15px;
}

/* FORM */

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
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
    color: #1f2937;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}

textarea:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

textarea::placeholder {
    color: #9ca3af;
}

/* FILE INPUT */

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

/* INFORMATION BOX */

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

/* BUTTON */

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

/* SUCCESS ACTION */

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
    .profile-name {
        display: none;
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

    .claim-code-row {
        flex-direction: column;
    }

    .claim-code-value,
    .copy-code-button {
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

    .profile-dropdown {
        width: calc(100vw - 28px);
        right: 0;
    }
}

/* PRINT */

@media print {
    .navbar {
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
                <?php
                echo htmlspecialchars(
                    strtoupper(substr($current_user["name"], 0, 1))
                );
                ?>
            </span>

            <span class="profile-arrow"></span>

        </button>

        <div class="profile-dropdown" id="profileDropdown">

            <div class="profile-header">

                <div class="profile-header-circle">
                    <?php
                    echo htmlspecialchars(
                        strtoupper(substr($current_user["name"], 0, 1))
                    );
                    ?>
                </div>

                <div class="profile-details">

                    <strong>
                        <?php echo htmlspecialchars($current_user["name"]); ?>
                    </strong>

                    <span>
                        <?php echo htmlspecialchars($current_user["email"] ?? ""); ?>
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

<main class="container">

    <div class="card">

        <div class="page-header">

            <h1>
                Claim Found Item
            </h1>

            <p>
                Submit information that can help verify that this found item belongs to you.
            </p>

        </div>


        <div class="item-information">

            <div class="item-label">
                ITEM BEING CLAIMED
            </div>

            <div class="item-name">
                <?php echo htmlspecialchars($item["item_name"]); ?>
            </div>

        </div>


        <?php if ($message !== ""): ?>

            <div class="alert <?php echo $message_type === "success" ? "alert-success" : "alert-error"; ?>">

                <?php echo htmlspecialchars($message); ?>

            </div>

        <?php endif; ?>


        <?php if ($message_type === "success" && $claim_verification_code !== null): ?>

            <div class="claim-code-box">

                <div class="claim-code-title">
                    YOUR CLAIM VERIFICATION CODE
                </div>

                <div class="claim-code-description">
                    Keep this code safe. Provide it to the administrator when following up on your claim.
                </div>

                <div class="claim-code-row">

                    <div
                        class="claim-code-value"
                        id="claimVerificationCode"
                    ><?php echo htmlspecialchars($claim_verification_code); ?></div>

                    <button
                        type="button"
                        class="copy-code-button"
                        onclick="copyClaimCode()"
                    >
                        Copy Code
                    </button>

                </div>

                <div class="copy-feedback" id="copyFeedback"></div>

            </div>

        <?php endif; ?>


        <?php if ($message_type !== "success"): ?>

            <div class="info-box">

                Provide specific details that can help verify ownership,
                such as unique markings, contents, identifying features,
                or other information that may not be visible in the item image.

            </div>


            <form
                method="POST"
                enctype="multipart/form-data"
            >

                <div class="form-group">

                    <label
                        for="claim_description"
                        class="form-label"
                    >

                        Why do you believe this item belongs to you?

                        <span class="required">*</span>

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

            <div class="success-actions">

                <a href="item.php?id=<?php echo $item_id; ?>">
                    Return to Item
                </a>

            </div>

        <?php endif; ?>

    </div>

</main>


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


    document.addEventListener("keydown", function(event) {

        if (event.key === "Escape") {

            profileDropdown.classList.remove("show");
            profileButton.classList.remove("open");

            profileButton.setAttribute("aria-expanded", "false");

        }

    });

}


/* COPY CLAIM VERIFICATION CODE */

function copyClaimCode() {

    const codeElement = document.getElementById("claimVerificationCode");
    const feedback = document.getElementById("copyFeedback");

    if (!codeElement || !feedback) {
        return;
    }

    const code = codeElement.textContent.trim();

    if (navigator.clipboard && window.isSecureContext) {

        navigator.clipboard.writeText(code)
            .then(function() {
                feedback.textContent = "Claim code copied successfully.";
            })
            .catch(function() {
                fallbackCopyClaimCode(code, feedback);
            });

    } else {

        fallbackCopyClaimCode(code, feedback);

    }

}


function fallbackCopyClaimCode(code, feedback) {

    const temporaryInput = document.createElement("textarea");

    temporaryInput.value = code;
    temporaryInput.style.position = "fixed";
    temporaryInput.style.opacity = "0";

    document.body.appendChild(temporaryInput);

    temporaryInput.select();

    try {

        const copied = document.execCommand("copy");

        feedback.textContent = copied
            ? "Claim code copied successfully."
            : "Please copy your claim code manually.";

    } catch (error) {

        feedback.textContent = "Please copy your claim code manually.";

    }

    document.body.removeChild(temporaryInput);

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
