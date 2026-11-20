
<?php
session_start();

/* =========================================================
   PREVENT CACHE
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

$current_user_id = (int)$_SESSION["user_id"];

/* =========================================================
   CURRENT USER
========================================================= */

$user_stmt = $conn->prepare("
    SELECT id, name, student_id, email, role
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$user_stmt) {
    die("Unable to load user information: " . htmlspecialchars($conn->error));
}

$user_stmt->bind_param("i", $current_user_id);
$user_stmt->execute();

$current_user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

if (!$current_user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/* =========================================================
   IMAGE UPLOAD SETTINGS
========================================================= */

$message_upload_directory = __DIR__ . "/uploads/messages/";
$message_upload_path = "uploads/messages/";

$maximum_image_size = 5 * 1024 * 1024;

$allowed_image_types = [
    "image/jpeg" => "jpg",
    "image/png" => "png",
    "image/webp" => "webp"
];

if (!is_dir($message_upload_directory)) {
    mkdir($message_upload_directory, 0755, true);
}

/* =========================================================
   SEND MESSAGE / IMAGE
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["send_message"])) {

    $receiver_id = (int)($_POST["receiver_id"] ?? 0);
    $item_id = (int)($_POST["item_id"] ?? 0);
    $message = trim($_POST["message"] ?? "");

    $image_path = null;
    $uploaded_file = null;

    try {

        if (
            $receiver_id <= 0 ||
            $item_id <= 0 ||
            $receiver_id === $current_user_id
        ) {
            throw new Exception("Invalid recipient or item.");
        }

        /* Verify recipient */

        $check_receiver = $conn->prepare("
            SELECT id
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        if (!$check_receiver) {
            throw new Exception("Recipient query failed: " . $conn->error);
        }

        $check_receiver->bind_param("i", $receiver_id);
        $check_receiver->execute();

        $receiver_exists = $check_receiver->get_result()->num_rows > 0;
        $check_receiver->close();

        if (!$receiver_exists) {
            throw new Exception("Recipient account does not exist.");
        }

        /* Verify item */

        $check_item = $conn->prepare("
            SELECT id
            FROM items
            WHERE id = ?
            LIMIT 1
        ");

        if (!$check_item) {
            throw new Exception("Item query failed: " . $conn->error);
        }

        $check_item->bind_param("i", $item_id);
        $check_item->execute();

        $item_exists = $check_item->get_result()->num_rows > 0;
        $check_item->close();

        if (!$item_exists) {
            throw new Exception("The selected item does not exist.");
        }

        /* Check image attachment */

        $has_image = (
            isset($_FILES["message_image"]) &&
            $_FILES["message_image"]["error"] !== UPLOAD_ERR_NO_FILE
        );

        if ($has_image) {

            $file = $_FILES["message_image"];

            if ($file["error"] !== UPLOAD_ERR_OK) {
                throw new Exception("Image upload error: " . $file["error"]);
            }

            if ($file["size"] <= 0 || $file["size"] > $maximum_image_size) {
                throw new Exception("The image must be no larger than 5 MB.");
            }

            if (!is_uploaded_file($file["tmp_name"])) {
                throw new Exception("Invalid uploaded image.");
            }

            $file_info = finfo_open(FILEINFO_MIME_TYPE);

            if (!$file_info) {
                throw new Exception("Unable to verify image type.");
            }

            $mime_type = finfo_file($file_info, $file["tmp_name"]);
            finfo_close($file_info);

            if (!isset($allowed_image_types[$mime_type])) {
                throw new Exception("Only JPG, PNG, and WEBP images are allowed.");
            }

            if (getimagesize($file["tmp_name"]) === false) {
                throw new Exception("The uploaded file is not a valid image.");
            }

            $extension = $allowed_image_types[$mime_type];
            $filename = bin2hex(random_bytes(20)) . "." . $extension;
            $destination = $message_upload_directory . $filename;

            if (!move_uploaded_file($file["tmp_name"], $destination)) {
                throw new Exception("Unable to save the uploaded image.");
            }

            $uploaded_file = $destination;
            $image_path = $message_upload_path . $filename;
        }

        /* Prevent empty messages */

        if ($message === "" && $image_path === null) {
            throw new Exception("Please type a message or attach an image.");
        }

        /* Insert message */

        $send_stmt = $conn->prepare("
            INSERT INTO messages
            (
                sender_id,
                receiver_id,
                item_id,
                message,
                image_path,
                is_read
            )
            VALUES (?, ?, ?, ?, ?, 0)
        ");

        if (!$send_stmt) {
            throw new Exception("Message query preparation failed: " . $conn->error);
        }

        $send_stmt->bind_param(
            "iiiss",
            $current_user_id,
            $receiver_id,
            $item_id,
            $message,
            $image_path
        );

        if (!$send_stmt->execute()) {
            throw new Exception("Message insert failed: " . $send_stmt->error);
        }

        $send_stmt->close();

        $_SESSION["message_send_status"] = "success";

    } catch (Throwable $e) {

        error_log("RTU Messages Error: " . $e->getMessage());

        if ($uploaded_file !== null && file_exists($uploaded_file)) {
            unlink($uploaded_file);
        }

        $_SESSION["message_send_status"] = "error";
    }

    header(
        "Location: messages.php?user=" . $receiver_id .
        "&item=" . $item_id
    );
    exit();
}

/* =========================================================
   MESSAGE REACTION
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    (isset($_POST["reaction_submit"]) || isset($_POST["reaction"]))
) {

    $message_id = (int)($_POST["message_id"] ?? 0);
    $reaction = $_POST["reaction"] ?? "";

    $return_user = (int)($_POST["return_user"] ?? 0);
    $return_item = (int)($_POST["return_item"] ?? 0);

    $allowed_reactions = [
        "heart", "laugh", "like", "dislike", "wow", "sad"
    ];

    if ($message_id > 0 && in_array($reaction, $allowed_reactions, true)) {

        $message_check = $conn->prepare("
            SELECT id, sender_id, receiver_id
            FROM messages
            WHERE id = ?
            LIMIT 1
        ");

        if ($message_check) {

            $message_check->bind_param("i", $message_id);
            $message_check->execute();

            $message_data = $message_check->get_result()->fetch_assoc();
            $message_check->close();

            if (
                $message_data &&
                (
                    (int)$message_data["sender_id"] === $current_user_id ||
                    (int)$message_data["receiver_id"] === $current_user_id
                )
            ) {

                $existing_stmt = $conn->prepare("
                    SELECT id, reaction
                    FROM message_reactions
                    WHERE message_id = ?
                    AND user_id = ?
                    LIMIT 1
                ");

                if ($existing_stmt) {

                    $existing_stmt->bind_param(
                        "ii",
                        $message_id,
                        $current_user_id
                    );

                    $existing_stmt->execute();
                    $existing = $existing_stmt->get_result()->fetch_assoc();
                    $existing_stmt->close();

                    if ($existing) {

                        if ($existing["reaction"] === $reaction) {

                            $reaction_stmt = $conn->prepare("
                                DELETE FROM message_reactions
                                WHERE message_id = ?
                                AND user_id = ?
                            ");

                            if ($reaction_stmt) {
                                $reaction_stmt->bind_param(
                                    "ii",
                                    $message_id,
                                    $current_user_id
                                );
                            }

                        } else {

                            $reaction_stmt = $conn->prepare("
                                UPDATE message_reactions
                                SET reaction = ?
                                WHERE message_id = ?
                                AND user_id = ?
                            ");

                            if ($reaction_stmt) {
                                $reaction_stmt->bind_param(
                                    "sii",
                                    $reaction,
                                    $message_id,
                                    $current_user_id
                                );
                            }
                        }

                    } else {

                        $reaction_stmt = $conn->prepare("
                            INSERT INTO message_reactions
                            (message_id, user_id, reaction)
                            VALUES (?, ?, ?)
                        ");

                        if ($reaction_stmt) {
                            $reaction_stmt->bind_param(
                                "iis",
                                $message_id,
                                $current_user_id,
                                $reaction
                            );
                        }
                    }

                    if (isset($reaction_stmt) && $reaction_stmt) {
                        $reaction_stmt->execute();
                        $reaction_stmt->close();
                    }
                }
            }
        }
    }

    header(
        "Location: messages.php?user=" . $return_user .
        "&item=" . $return_item
    );
    exit();
}

/* =========================================================
   SELECTED CONVERSATION
========================================================= */

$selected_user = (int)($_GET["user"] ?? 0);
$selected_item = (int)($_GET["item"] ?? 0);

/* =========================================================
   CONVERSATIONS
========================================================= */

$conversations = [];

$conversations_stmt = $conn->prepare("
    SELECT
        m.item_id,

        CASE
            WHEN m.sender_id = ?
            THEN m.receiver_id
            ELSE m.sender_id
        END AS other_user_id,

        u.name AS other_user_name,
        i.item_name,
        i.item_type,

        CASE
            WHEN m.image_path IS NOT NULL
            AND m.image_path != ''
            AND (m.message IS NULL OR m.message = '')
            THEN 'Photo'
            ELSE m.message
        END AS latest_message,

        m.created_at AS latest_time

    FROM messages m

    INNER JOIN users u
        ON u.id = CASE
            WHEN m.sender_id = ?
            THEN m.receiver_id
            ELSE m.sender_id
        END

    INNER JOIN items i
        ON i.id = m.item_id

    WHERE m.id IN (
        SELECT MAX(m2.id)
        FROM messages m2
        WHERE m2.sender_id = ?
        OR m2.receiver_id = ?
        GROUP BY
            m2.item_id,
            CASE
                WHEN m2.sender_id = ?
                THEN m2.receiver_id
                ELSE m2.sender_id
            END
    )

    ORDER BY m.created_at DESC
");

if ($conversations_stmt) {

    $conversations_stmt->bind_param(
        "iiiii",
        $current_user_id,
        $current_user_id,
        $current_user_id,
        $current_user_id,
        $current_user_id
    );

    if ($conversations_stmt->execute()) {

        $conversations = $conversations_stmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);

    } else {
        error_log("RTU Conversations Error: " . $conversations_stmt->error);
    }

    $conversations_stmt->close();

} else {
    error_log("RTU Conversations Prepare Error: " . $conn->error);
}

/* =========================================================
   UNREAD COUNT PER CONVERSATION
========================================================= */

foreach ($conversations as &$conversation) {

    $other_user_id = (int)$conversation["other_user_id"];
    $conversation_item_id = (int)$conversation["item_id"];

    $unread_stmt = $conn->prepare("
        SELECT COUNT(*) AS unread_count
        FROM messages
        WHERE sender_id = ?
        AND receiver_id = ?
        AND item_id = ?
        AND is_read = 0
    ");

    $conversation["unread_count"] = 0;

    if ($unread_stmt) {

        $unread_stmt->bind_param(
            "iii",
            $other_user_id,
            $current_user_id,
            $conversation_item_id
        );

        $unread_stmt->execute();

        $unread_data = $unread_stmt->get_result()->fetch_assoc();

        $conversation["unread_count"] = (int)$unread_data["unread_count"];

        $unread_stmt->close();
    }
}

unset($conversation);

/* =========================================================
   SELECTED PERSON AND ITEM
========================================================= */

$selected_person = null;
$selected_item_data = null;

if ($selected_user > 0 && $selected_item > 0) {

    $person_stmt = $conn->prepare("
        SELECT id, name, student_id
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    if ($person_stmt) {
        $person_stmt->bind_param("i", $selected_user);
        $person_stmt->execute();
        $selected_person = $person_stmt->get_result()->fetch_assoc();
        $person_stmt->close();
    } else {
        error_log("RTU Selected Person Error: " . $conn->error);
    }

    /*
       Only select the columns needed by the chat.
       This avoids the chat failing because of an unnecessary
       or differently named image column.
    */

    $item_stmt = $conn->prepare("
        SELECT id, item_name, item_type
        FROM items
        WHERE id = ?
        LIMIT 1
    ");

    if ($item_stmt) {
        $item_stmt->bind_param("i", $selected_item);
        $item_stmt->execute();
        $selected_item_data = $item_stmt->get_result()->fetch_assoc();
        $item_stmt->close();
    } else {
        error_log("RTU Selected Item Error: " . $conn->error);
    }
}

/* =========================================================
   LOAD CHAT MESSAGES
========================================================= */

$chat_messages = [];
$messages_error = "";

if ($selected_user > 0 && $selected_item > 0) {

    /*
       Load messages using the two user IDs and the item ID.
       This query works for both the sender and receiver.
    */

    $messages_stmt = $conn->prepare("
        SELECT
            id,
            sender_id,
            receiver_id,
            message,
            image_path,
            is_read,
            created_at
        FROM messages
        WHERE item_id = ?
        AND (
            (sender_id = ? AND receiver_id = ?)
            OR
            (sender_id = ? AND receiver_id = ?)
        )
        ORDER BY id ASC
    ");

    if ($messages_stmt) {

        $messages_stmt->bind_param(
            "iiiii",
            $selected_item,
            $current_user_id,
            $selected_user,
            $selected_user,
            $current_user_id
        );

        if ($messages_stmt->execute()) {

            $messages_result = $messages_stmt->get_result();

            while ($row = $messages_result->fetch_assoc()) {
                $chat_messages[] = $row;
            }

        } else {

            $messages_error = $messages_stmt->error;

            error_log(
                "RTU Chat Load Error: " . $messages_error
            );
        }

        $messages_stmt->close();

    } else {

        $messages_error = $conn->error;

        error_log(
            "RTU Chat Prepare Error: " . $messages_error
        );
    }

    /* Mark incoming messages as read */

    $read_stmt = $conn->prepare("
        UPDATE messages
        SET is_read = 1
        WHERE sender_id = ?
        AND receiver_id = ?
        AND item_id = ?
        AND is_read = 0
    ");

    if ($read_stmt) {

        $read_stmt->bind_param(
            "iii",
            $selected_user,
            $current_user_id,
            $selected_item
        );

        $read_stmt->execute();
        $read_stmt->close();
    }
}

/* =========================================================
   TOTAL UNREAD MESSAGES
========================================================= */

$total_unread = 0;

$unread_stmt = $conn->prepare("
    SELECT COUNT(*) AS total_unread
    FROM messages
    WHERE receiver_id = ?
    AND is_read = 0
");

if ($unread_stmt) {

    $unread_stmt->bind_param("i", $current_user_id);
    $unread_stmt->execute();

    $unread_result = $unread_stmt->get_result()->fetch_assoc();
    $total_unread = (int)$unread_result["total_unread"];

    $unread_stmt->close();
}

/* =========================================================
   AI NOTIFICATION COUNT
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

    $notification_stmt->bind_param("i", $current_user_id);
    $notification_stmt->execute();

    $notification_result = $notification_stmt->get_result();

    if ($notification_result) {
        $notification_unread = (int)$notification_result->fetch_assoc()["total"];
    }

    $notification_stmt->close();
}

/* =========================================================
   PROFILE INITIAL
========================================================= */

$name_for_initial = trim($current_user["name"]);
$profile_initial = strtoupper(substr($name_for_initial, 0, 1));

?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Messages - RTU Lost and Found</title>

<style>

/* =========================================================
   GLOBAL
========================================================= */

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: auto;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f1f5f9;
    color: #172033;
}

/* =========================================================
   NAVBAR - PRESERVED
========================================================= */

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

.brand:hover {
    color: white;
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

/* =========================================================
   PAGE
========================================================= */

.page {
    width: 100%;
    max-width: 1280px;
    margin: 30px auto;
    padding: 0 20px;
}

.page-title {
    margin-bottom: 20px;
}

.page-title h1 {
    margin: 0 0 6px;
    font-size: 29px;
    color: #0b3d91;
}

.page-title p {
    margin: 0;
    color: #64748b;
    font-size: 14px;
}

/* =========================================================
   STATUS NOTICE
========================================================= */

.status-notice {
    padding: 13px 16px;
    margin-bottom: 16px;
    border-radius: 9px;
    font-size: 14px;
    font-weight: 600;
}

.status-notice.success {
    background: #ecfdf5;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.status-notice.error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

/* =========================================================
   MAIN MESSAGING BOX
========================================================= */

.messaging-container {
    width: 100%;
    height: 650px;
    background: white;
    border-radius: 14px;
    overflow: hidden;
    display: grid;
    grid-template-columns: 340px minmax(0, 1fr);
    border: 1px solid #dbe3ef;
    box-shadow: 0 6px 24px rgba(15,23,42,0.10);
}

/* =========================================================
   CONVERSATIONS
========================================================= */

.conversations {
    min-width: 0;
    background: #fff;
    border-right: 1px solid #dbe3ef;
    overflow-y: auto;
}

.conversations-header {
    padding: 18px 20px;
    background: linear-gradient(135deg, #f8fafc, #eef4fb);
    border-bottom: 3px solid #d4af37;
    position: sticky;
    top: 0;
    z-index: 5;
}

.conversations-header h2 {
    margin: 0;
    color: #0b3d91;
    font-size: 19px;
}

.conversation {
    display: block;
    color: inherit;
    text-decoration: none;
    padding: 15px 18px;
    border-bottom: 1px solid #edf1f5;
    transition: background 0.15s ease, border-left 0.15s ease;
}

.conversation:hover {
    background: #f8fafc;
    border-left: 4px solid #d4af37;
    padding-left: 14px;
}

.conversation.active {
    background: #eef5ff;
    border-left: 4px solid #0b3d91;
    padding-left: 14px;
}

.conversation-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.conversation-name {
    font-weight: 700;
    font-size: 14px;
    color: #172033;
}

.conversation-time {
    font-size: 10px;
    color: #94a3b8;
    white-space: nowrap;
}

.conversation-item {
    margin-top: 5px;
    color: #0b3d91;
    font-size: 12px;
    font-weight: 600;
}

.conversation-preview {
    margin-top: 6px;
    color: #64748b;
    font-size: 13px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.unread-badge {
    display: inline-block;
    margin-top: 7px;
    padding: 3px 8px;
    border-radius: 20px;
    background: #fff4cc;
    color: #946c00;
    font-size: 11px;
    font-weight: 700;
}

/* =========================================================
   CHAT
========================================================= */

.chat {
    min-width: 0;
    min-height: 0;
    display: flex;
    flex-direction: column;
    background: white;
}

.chat-header {
    flex-shrink: 0;
    min-height: 76px;
    padding: 14px 22px;
    border-bottom: 1px solid #dbe3ef;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    background: white;
}

.chat-person {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.avatar {
    width: 44px;
    height: 44px;
    flex-shrink: 0;
    border-radius: 50%;
    background: linear-gradient(135deg, #0b3d91, #1769d1);
    border: 2px solid #d4af37;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    font-weight: 800;
}

.chat-person h3 {
    margin: 0;
    color: #172033;
    font-size: 16px;
}

.chat-person p {
    margin: 3px 0 0;
    color: #64748b;
    font-size: 12px;
}

.item-link {
    display: inline-block;
    color: #0b3d91;
    background: #f0f6ff;
    border: 1px solid #c9dcf7;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
    padding: 8px 11px;
    border-radius: 8px;
}

.item-link:hover {
    background: #0b3d91;
    color: white;
}

/* =========================================================
   MESSAGE AREA
========================================================= */

.messages-area {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 25px;
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
}

.message-row {
    display: flex;
    width: 100%;
    margin-bottom: 18px;
    position: relative;
}

.message-row.mine {
    justify-content: flex-end;
}

.message-row.theirs {
    justify-content: flex-start;
}

.message-content {
    position: relative;
    max-width: 65%;
    min-width: 0;
}

.message-bubble {
    padding: 11px 14px;
    border-radius: 16px;
    font-size: 14px;
    line-height: 1.5;
    word-wrap: break-word;
    overflow-wrap: anywhere;
}

.mine .message-bubble {
    background: linear-gradient(135deg, #0b3d91, #1769d1);
    color: white;
    border-bottom-right-radius: 5px;
    box-shadow: 0 2px 6px rgba(11,61,145,0.20);
}

.theirs .message-bubble {
    background: white;
    color: #172033;
    border: 1px solid #dbe3ef;
    border-bottom-left-radius: 5px;
    box-shadow: 0 1px 4px rgba(15,23,42,0.05);
}

/* =========================================================
   MESSAGE IMAGES
========================================================= */

.message-image {
    display: block;
    max-width: 100%;
    width: auto;
    max-height: 280px;
    object-fit: contain;
    border-radius: 10px;
    cursor: pointer;
    margin-top: 5px;
}

.message-image:first-child {
    margin-top: 0;
}

.mine .message-image {
    border: 1px solid rgba(255,255,255,0.25);
}

.theirs .message-image {
    border: 1px solid #e2e8f0;
}

/* =========================================================
   IMAGE PREVIEW
========================================================= */

.image-preview-container {
    display: none;
    align-items: center;
    gap: 10px;
    padding: 10px 15px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
}

.image-preview-container.show {
    display: flex;
}

.image-preview {
    width: 65px;
    height: 65px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
}

.image-preview-info {
    flex: 1;
    min-width: 0;
    color: #475569;
    font-size: 12px;
    overflow-wrap: anywhere;
}

.remove-image-button {
    border: 1px solid #fecaca;
    background: #fff1f2;
    color: #b91c1c;
    border-radius: 7px;
    padding: 7px 10px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}

.remove-image-button:hover {
    background: #ffe4e6;
}

/* =========================================================
   MESSAGE TIME
========================================================= */

.message-time {
    margin-top: 4px;
    color: #94a3b8;
    font-size: 10px;
}

.mine .message-time {
    text-align: right;
}

.theirs .message-time {
    text-align: left;
}

/* =========================================================
   REACTIONS
========================================================= */

.reactions-display {
    display: flex;
    flex-wrap: wrap;
    gap: 3px;
    margin-top: 5px;
}

.mine .reactions-display {
    justify-content: flex-end;
}

.theirs .reactions-display {
    justify-content: flex-start;
}

.reaction-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 27px;
    height: 23px;
    padding: 2px 7px;
    background: white;
    border: 1px solid #d4dbe5;
    border-radius: 15px;
    font-size: 13px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}

.reaction-wrapper {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.15s ease;
    z-index: 20;
}

.theirs .reaction-wrapper {
    right: -45px;
}

.mine .reaction-wrapper {
    left: -45px;
}

.message-row:hover .reaction-wrapper {
    opacity: 1;
    pointer-events: auto;
}

.reaction-trigger {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 1px solid #d1d5db;
    background: white;
    cursor: pointer;
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 7px rgba(0,0,0,0.12);
}

.reaction-trigger:hover {
    transform: scale(1.08);
    background: #fff9e6;
    border-color: #d4af37;
}

.reaction-popup {
    position: absolute;
    bottom: 38px;
    background: white;
    border-radius: 25px;
    padding: 6px 8px;
    display: flex;
    gap: 3px;
    box-shadow: 0 5px 18px rgba(0,0,0,0.18);
    border: 1px solid #e5e7eb;
    opacity: 0;
    visibility: hidden;
    transform: scale(0.9);
    transform-origin: bottom center;
    transition: opacity 0.12s ease, transform 0.12s ease, visibility 0.12s ease;
    z-index: 30;
}

.theirs .reaction-popup {
    left: 0;
}

.mine .reaction-popup {
    right: 0;
}

.reaction-wrapper:hover .reaction-popup,
.reaction-wrapper.open .reaction-popup {
    opacity: 1;
    visibility: visible;
    transform: scale(1);
}

.reaction-form {
    margin: 0;
    padding: 0;
}

.reaction-button {
    border: none;
    background: transparent;
    cursor: pointer;
    font-size: 18px;
    width: 32px;
    height: 32px;
    padding: 0;
    border-radius: 50%;
}

.reaction-button:hover {
    background: #f3f4f6;
    transform: scale(1.15);
}

/* =========================================================
   MESSAGE FORM
========================================================= */

.message-form {
    flex-shrink: 0;
    border-top: 1px solid #dbe3ef;
    padding: 14px 18px;
    background: white;
    display: flex;
    align-items: flex-end;
    gap: 10px;
}

.message-form textarea {
    flex: 1;
    resize: none;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 11px 13px;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
    outline: none;
    min-height: 44px;
    max-height: 120px;
    color: #172033;
    background: #f8fafc;
}

.message-form textarea:focus {
    border-color: #0b3d91;
    background: white;
    box-shadow: 0 0 0 2px rgba(11,61,145,0.10);
}

.attach-button {
    flex-shrink: 0;
    width: 44px;
    height: 44px;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    color: #0b3d91;
    border-radius: 10px;
    font-size: 21px;
    font-weight: bold;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s, border-color 0.2s;
}

.attach-button:hover {
    background: #eff6ff;
    border-color: #0b3d91;
}

.attach-button.selected {
    background: #eaf2ff;
    border-color: #0b3d91;
}

.send-button {
    min-height: 44px;
    flex-shrink: 0;
    border: none;
    background: linear-gradient(135deg, #0b3d91, #1769d1);
    color: white;
    border-radius: 10px;
    padding: 0 20px;
    font-weight: 700;
    cursor: pointer;
}

.send-button:hover {
    background: linear-gradient(135deg, #082f70, #125bb9);
    transform: translateY(-1px);
}

.send-button:disabled {
    opacity: 0.65;
    cursor: not-allowed;
    transform: none;
}

/* =========================================================
   IMAGE LIGHTBOX
========================================================= */

.image-lightbox {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 5000;
    background: rgba(0,0,0,0.88);
    align-items: center;
    justify-content: center;
    padding: 30px;
}

.image-lightbox.show {
    display: flex;
}

.image-lightbox img {
    max-width: 95%;
    max-height: 90vh;
    object-fit: contain;
    border-radius: 8px;
}

.lightbox-close {
    position: absolute;
    top: 20px;
    right: 25px;
    width: 42px;
    height: 42px;
    border: none;
    border-radius: 50%;
    background: white;
    color: #172033;
    font-size: 26px;
    cursor: pointer;
}

/* =========================================================
   EMPTY STATES
========================================================= */

.no-conversations {
    padding: 45px 20px;
    text-align: center;
    color: #64748b;
    font-size: 14px;
}

.no-chat {
    flex: 1;
    min-height: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 40px;
    color: #64748b;
}

.no-chat h2 {
    color: #0b3d91;
    margin: 0 0 8px;
    font-size: 20px;
}

.no-chat p {
    margin: 0;
    font-size: 14px;
}

/* =========================================================
   SCROLLBAR
========================================================= */

.messages-area::-webkit-scrollbar,
.conversations::-webkit-scrollbar {
    width: 8px;
}

.messages-area::-webkit-scrollbar-track,
.conversations::-webkit-scrollbar-track {
    background: #eef2f7;
}

.messages-area::-webkit-scrollbar-thumb,
.conversations::-webkit-scrollbar-thumb {
    background: #b8c4d4;
    border-radius: 10px;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 900px) {
    .messaging-container {
        height: auto;
        min-height: 650px;
        grid-template-columns: 290px minmax(0, 1fr);
    }
}

@media (max-width: 700px) {
    .navbar {
        padding: 10px 15px;
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
        width: 290px;
    }

    .page {
        margin: 20px auto;
        padding: 0 10px;
    }

    .messaging-container {
        grid-template-columns: 1fr;
        height: auto;
    }

    .conversations {
        max-height: 260px;
        border-right: none;
        border-bottom: 1px solid #dbe3ef;
    }

    .chat {
        min-height: 550px;
    }

    .message-content {
        max-width: 80%;
    }

    .reaction-wrapper {
        display: none;
    }
}

@media (max-width: 400px) {
    .profile-dropdown {
        width: calc(100vw - 30px);
        right: 0;
    }
}

@media (max-width: 480px) {
    .profile-button {
        padding-right: 7px;
    }

    .page-title h1 {
        font-size: 24px;
    }

    .message-form {
        padding: 10px;
        gap: 6px;
    }

    .send-button {
        padding: 0 13px;
    }

    .messages-area {
        padding: 15px;
    }

    .attach-button {
        width: 40px;
        height: 44px;
    }

    .message-form textarea {
        min-width: 0;
    }

    .image-lightbox {
        padding: 15px;
    }
}

</style>
</head>

<body>

<!-- =======================================================
     NAVBAR - UNCHANGED
======================================================= -->

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
            aria-expanded="false"
            aria-controls="profileDropdown"
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
                        Messages
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
                        Notifications
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
                        Sign Out
                    </span>

                </a>

            </div>

        </div>

    </div>

</nav>

<!-- =======================================================
     PAGE
======================================================= -->

<div class="page">

    <div class="page-title">

        <h1>Messages</h1>

        <p>
            Contact other RTU users regarding lost and found items.
        </p>

    </div>

    <?php if (isset($_SESSION["message_send_status"])): ?>

        <?php
        $send_status = $_SESSION["message_send_status"];
        unset($_SESSION["message_send_status"]);
        ?>

        <div class="status-notice <?php echo $send_status === "success" ? "success" : "error"; ?>">

            <?php if ($send_status === "success"): ?>

                Message sent successfully.

            <?php else: ?>

                Message could not be sent. Check the PHP error log for the exact reason.

            <?php endif; ?>

        </div>

    <?php endif; ?>

    <div class="messaging-container">

        <!-- CONVERSATION LIST -->

        <div class="conversations">

            <div class="conversations-header">
                <h2>Conversations</h2>
            </div>

            <?php if (empty($conversations)): ?>

                <div class="no-conversations">
                    No conversations yet.
                </div>

            <?php else: ?>

                <?php foreach ($conversations as $conversation): ?>

                    <?php

                    $is_active =
                        (
                            (int)$selected_user ===
                            (int)$conversation["other_user_id"]
                        ) &&
                        (
                            (int)$selected_item ===
                            (int)$conversation["item_id"]
                        );

                    $latest_message = $conversation["latest_message"] ?? "";

                    $conversation_unread = (int)($conversation["unread_count"] ?? 0);

                    ?>

                    <a
                        class="conversation <?php echo $is_active ? "active" : ""; ?>"
                        href="messages.php?user=<?php echo (int)$conversation["other_user_id"]; ?>&item=<?php echo (int)$conversation["item_id"]; ?>"
                    >

                        <div class="conversation-top">

                            <div class="conversation-name">
                                <?php echo htmlspecialchars($conversation["other_user_name"]); ?>
                            </div>

                            <div class="conversation-time">
                                <?php
                                if (!empty($conversation["latest_time"])) {
                                    echo date(
                                        "M d, g:i A",
                                        strtotime($conversation["latest_time"])
                                    );
                                }
                                ?>
                            </div>

                        </div>

                        <div class="conversation-item">
                            <?php echo htmlspecialchars($conversation["item_name"]); ?>
                        </div>

                        <div class="conversation-preview">
                            <?php echo htmlspecialchars($latest_message); ?>
                        </div>

                        <?php if ($conversation_unread > 0): ?>

                            <div class="unread-badge">
                                <?php echo $conversation_unread; ?> new
                            </div>

                        <?php endif; ?>

                    </a>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

        <!-- CHAT PANEL -->

        <?php if ($selected_user > 0 && $selected_item > 0): ?>

            <div class="chat">

                <?php if ($selected_person && $selected_item_data): ?>

                    <div class="chat-header">

                        <div class="chat-person">

                            <div class="avatar">
                                <?php
                                echo htmlspecialchars(
                                    strtoupper(substr($selected_person["name"], 0, 1))
                                );
                                ?>
                            </div>

                            <div>

                                <h3>
                                    <?php echo htmlspecialchars($selected_person["name"]); ?>
                                </h3>

                                <p>
                                    <?php echo htmlspecialchars($selected_person["student_id"] ?? "RTU User"); ?>
                                </p>

                            </div>

                        </div>

                        <div>

                            <a
                                class="item-link"
                                href="item.php?id=<?php echo (int)$selected_item_data["id"]; ?>"
                            >
                                View Item
                            </a>

                        </div>

                    </div>

                <?php else: ?>

                    <div class="chat-header">
                        <div class="chat-person">
                            <div>
                                <h3>Conversation</h3>
                                <p>Item ID: <?php echo $selected_item; ?></p>
                            </div>
                        </div>
                    </div>

                <?php endif; ?>

                <!-- MESSAGE AREA -->

                <div class="messages-area" id="messagesArea">

                    <?php if (!empty($messages_error)): ?>

                        <div class="no-chat">
                            <div>
                                <h2>Unable to load messages</h2>
                                <p>
                                    A database error occurred. Check the PHP error log.
                                </p>
                            </div>
                        </div>

                    <?php elseif (empty($chat_messages)): ?>

                        <div class="no-chat">

                            <div>
                                <h2>Start a conversation</h2>
                                <p>
                                    Send a message or an image about this item.
                                </p>
                            </div>

                        </div>

                    <?php else: ?>

                        <?php foreach ($chat_messages as $msg): ?>

                            <?php

                            $is_mine =
                                (int)$msg["sender_id"] === $current_user_id;

                            $reaction_stmt = $conn->prepare("
                                SELECT
                                    mr.reaction,
                                    mr.user_id,
                                    u.name
                                FROM message_reactions mr
                                INNER JOIN users u
                                    ON u.id = mr.user_id
                                WHERE mr.message_id = ?
                                ORDER BY mr.created_at ASC
                            ");

                            $message_reactions = [];

                            if ($reaction_stmt) {

                                $reaction_stmt->bind_param("i", $msg["id"]);
                                $reaction_stmt->execute();

                                $message_reactions = $reaction_stmt
                                    ->get_result()
                                    ->fetch_all(MYSQLI_ASSOC);

                                $reaction_stmt->close();
                            }

                            ?>

                            <div class="message-row <?php echo $is_mine ? "mine" : "theirs"; ?>">

                                <div class="message-content">

                                    <div class="message-bubble">

                                        <?php if (!empty($msg["image_path"])): ?>

                                            <img
                                                src="<?php echo htmlspecialchars($msg["image_path"], ENT_QUOTES, "UTF-8"); ?>"
                                                alt="Shared image"
                                                class="message-image"
                                                onclick="openImage(this.src)"
                                                loading="lazy"
                                            >

                                        <?php endif; ?>

                                        <?php if (!empty($msg["message"])): ?>

                                            <div>
                                                <?php
                                                echo nl2br(
                                                    htmlspecialchars($msg["message"])
                                                );
                                                ?>
                                            </div>

                                        <?php endif; ?>

                                    </div>

                                    <div class="message-time">
                                        <?php
                                        echo date(
                                            "M d, g:i A",
                                            strtotime($msg["created_at"])
                                        );
                                        ?>
                                    </div>

                                    <?php if (!empty($message_reactions)): ?>

                                        <div class="reactions-display">

                                            <?php foreach ($message_reactions as $reaction_data): ?>

                                                <span
                                                    class="reaction-pill"
                                                    title="<?php echo htmlspecialchars($reaction_data["name"]); ?>"
                                                >

                                                    <?php

                                                    $reaction_symbols = [
                                                        "heart" => "❤️",
                                                        "laugh" => "😂",
                                                        "like" => "👍",
                                                        "dislike" => "👎",
                                                        "wow" => "😮",
                                                        "sad" => "😢"
                                                    ];

                                                    echo $reaction_symbols[$reaction_data["reaction"]] ?? "";

                                                    ?>

                                                </span>

                                            <?php endforeach; ?>

                                        </div>

                                    <?php endif; ?>

                                    <div class="reaction-wrapper">

                                        <button
                                            type="button"
                                            class="reaction-trigger"
                                            onclick="toggleReaction(this)"
                                            title="React"
                                        >
                                            +
                                        </button>

                                        <div class="reaction-popup">

                                            <?php

                                            $reaction_list = [
                                                "heart" => "❤️",
                                                "laugh" => "😂",
                                                "like" => "👍",
                                                "dislike" => "👎",
                                                "wow" => "😮",
                                                "sad" => "😢"
                                            ];

                                            ?>

                                            <?php foreach ($reaction_list as $reaction_key => $reaction_symbol): ?>

                                                <form method="POST" class="reaction-form">

                                                    <input
                                                        type="hidden"
                                                        name="message_id"
                                                        value="<?php echo (int)$msg["id"]; ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="reaction"
                                                        value="<?php echo htmlspecialchars($reaction_key, ENT_QUOTES, "UTF-8"); ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="return_user"
                                                        value="<?php echo $selected_user; ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="return_item"
                                                        value="<?php echo $selected_item; ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        name="reaction_submit"
                                                        class="reaction-button"
                                                        title="React"
                                                    >
                                                        <?php echo $reaction_symbol; ?>
                                                    </button>

                                                </form>

                                            <?php endforeach; ?>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

                <!-- IMAGE PREVIEW -->

                <div
                    class="image-preview-container"
                    id="imagePreviewContainer"
                >

                    <img
                        src=""
                        alt="Selected image preview"
                        class="image-preview"
                        id="imagePreview"
                    >

                    <div class="image-preview-info" id="imagePreviewInfo">
                        Selected image
                    </div>

                    <button
                        type="button"
                        class="remove-image-button"
                        id="removeImageButton"
                    >
                        Remove
                    </button>

                </div>

                <!-- SEND MESSAGE FORM -->

                <form
                    method="POST"
                    class="message-form"
                    enctype="multipart/form-data"
                    id="messageForm"
                >

                    <input
                        type="hidden"
                        name="receiver_id"
                        value="<?php echo $selected_user; ?>"
                    >

                    <input
                        type="hidden"
                        name="item_id"
                        value="<?php echo $selected_item; ?>"
                    >

                    <input
                        type="file"
                        name="message_image"
                        id="messageImage"
                        accept="image/jpeg,image/png,image/webp"
                        hidden
                    >

                    <button
                        type="button"
                        class="attach-button"
                        id="attachButton"
                        title="Attach an image"
                        aria-label="Attach an image"
                    >
                        +
                    </button>

                    <textarea
                        name="message"
                        id="messageInput"
                        placeholder="Type a message..."
                    ></textarea>

                    <button
                        type="submit"
                        name="send_message"
                        class="send-button"
                        id="sendButton"
                    >
                        Send
                    </button>

                </form>

            </div>

        <?php else: ?>

            <div class="chat">

                <div class="no-chat">

                    <div>
                        <h2>Select a conversation</h2>

                        <p>
                            Choose a conversation from the left to start messaging.
                        </p>
                    </div>

                </div>

            </div>

        <?php endif; ?>

    </div>

</div>

<!-- IMAGE LIGHTBOX -->

<div
    class="image-lightbox"
    id="imageLightbox"
    onclick="closeImage()"
>

    <button
        type="button"
        class="lightbox-close"
        onclick="closeImage()"
        aria-label="Close image"
    >
        &times;
    </button>

    <img
        src=""
        alt="Enlarged shared image"
        id="lightboxImage"
    >

</div>

<script>

/* =========================================================
   PROFILE DROPDOWN
========================================================= */

const profileButton = document.getElementById("profileButton");
const profileDropdown = document.getElementById("profileDropdown");

if (profileButton && profileDropdown) {

    profileButton.addEventListener("click", function(event) {

        event.stopPropagation();

        const isOpen = profileDropdown.classList.toggle("show");

        profileButton.classList.toggle("open", isOpen);

        profileButton.setAttribute(
            "aria-expanded",
            isOpen ? "true" : "false"
        );
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

/* =========================================================
   REACTION POPUP
========================================================= */

function toggleReaction(button) {

    const wrapper = button.parentElement;

    document.querySelectorAll(".reaction-wrapper.open").forEach(function(other) {

        if (other !== wrapper) {
            other.classList.remove("open");
        }
    });

    wrapper.classList.toggle("open");
}

document.addEventListener("click", function(event) {

    if (!event.target.closest(".reaction-wrapper")) {

        document.querySelectorAll(".reaction-wrapper.open").forEach(function(wrapper) {
            wrapper.classList.remove("open");
        });
    }
});

/* =========================================================
   SCROLL CHAT TO BOTTOM
========================================================= */

const messagesArea = document.getElementById("messagesArea");

if (messagesArea) {
    messagesArea.scrollTop = messagesArea.scrollHeight;
}

/* =========================================================
   MESSAGE TEXTAREA AND FORM
========================================================= */

const messageInput = document.getElementById("messageInput");
const messageForm = document.getElementById("messageForm");
const sendButton = document.getElementById("sendButton");

const messageImage = document.getElementById("messageImage");

if (messageInput && messageForm) {

    messageInput.addEventListener("input", function() {

        this.style.height = "44px";
        this.style.height = Math.min(this.scrollHeight, 120) + "px";
    });

    messageInput.addEventListener("keydown", function(event) {

        if (
            event.key === "Enter" &&
            !event.shiftKey &&
            !event.isComposing
        ) {

            event.preventDefault();

            if (messageForm.requestSubmit) {
                messageForm.requestSubmit(sendButton);
            } else {
                sendButton.click();
            }
        }
    });

    messageForm.addEventListener("submit", function(event) {

        const text = messageInput.value.trim();

        const selectedImage =
            messageImage &&
            messageImage.files &&
            messageImage.files.length > 0;

        if (text === "" && !selectedImage) {

            event.preventDefault();
            messageInput.focus();
            return;
        }

        if (sendButton) {
            sendButton.disabled = true;
            sendButton.textContent = "Sending...";
        }
    });
}

/* =========================================================
   IMAGE ATTACHMENT
========================================================= */

const attachButton = document.getElementById("attachButton");
const imagePreviewContainer = document.getElementById("imagePreviewContainer");
const imagePreview = document.getElementById("imagePreview");
const imagePreviewInfo = document.getElementById("imagePreviewInfo");
const removeImageButton = document.getElementById("removeImageButton");

if (messageImage && attachButton && imagePreviewContainer) {

    attachButton.addEventListener("click", function() {
        messageImage.click();
    });

    messageImage.addEventListener("change", function() {

        const file = this.files[0];

        if (!file) {
            return;
        }

        const allowedTypes = [
            "image/jpeg",
            "image/png",
            "image/webp"
        ];

        const maximumSize = 5 * 1024 * 1024;

        if (!allowedTypes.includes(file.type)) {

            alert("Please select a JPG, PNG, or WEBP image.");
            this.value = "";
            return;
        }

        if (file.size > maximumSize) {

            alert("The image must not exceed 5 MB.");
            this.value = "";
            return;
        }

        if (file.size === 0) {

            alert("The selected image is empty.");
            this.value = "";
            return;
        }

        const reader = new FileReader();

        reader.onload = function(event) {

            imagePreview.src = event.target.result;

            imagePreviewInfo.textContent =
                file.name +
                " (" +
                (file.size / 1024 / 1024).toFixed(2) +
                " MB)";

            imagePreviewContainer.classList.add("show");
            attachButton.classList.add("selected");
        };

        reader.readAsDataURL(file);
    });

    removeImageButton.addEventListener("click", function() {

        messageImage.value = "";
        imagePreview.src = "";

        imagePreviewContainer.classList.remove("show");
        attachButton.classList.remove("selected");
    });
}

/* =========================================================
   IMAGE LIGHTBOX
========================================================= */

function openImage(source) {

    const lightbox = document.getElementById("imageLightbox");
    const lightboxImage = document.getElementById("lightboxImage");

    if (!lightbox || !lightboxImage) {
        return;
    }

    lightboxImage.src = source;
    lightbox.classList.add("show");
}

function closeImage() {

    const lightbox = document.getElementById("imageLightbox");
    const lightboxImage = document.getElementById("lightboxImage");

    if (!lightbox || !lightboxImage) {
        return;
    }

    lightbox.classList.remove("show");
    lightboxImage.src = "";
}

document.addEventListener("keydown", function(event) {

    if (event.key === "Escape") {
        closeImage();
    }
});

</script>

</body>
</html>
