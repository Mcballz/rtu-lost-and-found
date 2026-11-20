
<?php

session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

/* =========================================================
   CHECK ADMIN
========================================================= */

$user_id = (int) $_SESSION["user_id"];

$sql = "SELECT role FROM users WHERE id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();

if (!$user || $user["role"] !== "admin") {
    die("Access denied. Admins only.");
}

/* =========================================================
   CSRF TOKEN
========================================================= */

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$delete_error = "";

/* =========================================================
   DELETE ITEM
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_item_id"])) {

    $delete_id = (int) $_POST["delete_item_id"];
    $submitted_token = $_POST["csrf_token"] ?? "";

    if (
        empty($submitted_token) ||
        !hash_equals($_SESSION["csrf_token"], $submitted_token)
    ) {
        $delete_error = "Invalid request. Please refresh the page and try again.";
    } elseif ($delete_id <= 0) {
        $delete_error = "Invalid item selected.";
    } else {

        $item_image = "";
        $item_type = "";
        $claim_images = [];

        try {

            $conn->begin_transaction();

            /* GET ITEM INFORMATION */

            $sql = "SELECT item_type, image FROM items WHERE id = ? FOR UPDATE";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $delete_id);
            $stmt->execute();

            $item_result = $stmt->get_result();
            $item_data = $item_result->fetch_assoc();

            $stmt->close();

            if (!$item_data) {
                throw new Exception("The selected item no longer exists.");
            }

            $item_type = $item_data["item_type"];
            $item_image = $item_data["image"] ?? "";

            /* GET CLAIM PROOF IMAGES */

            $sql = "SELECT proof_image FROM claims WHERE item_id = ?";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $delete_id);
            $stmt->execute();

            $claim_result = $stmt->get_result();

            while ($claim = $claim_result->fetch_assoc()) {
                if (!empty($claim["proof_image"])) {
                    $claim_images[] = $claim["proof_image"];
                }
            }

            $stmt->close();

            /* DELETE RELATED CLAIMS */

            $sql = "DELETE FROM claims WHERE item_id = ?";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $delete_id);
            $stmt->execute();
            $stmt->close();

            /* DELETE RELATED AI MATCHES */

            $sql = "
                DELETE FROM ai_matches
                WHERE lost_item_id = ?
                   OR found_item_id = ?
            ";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $delete_id, $delete_id);
            $stmt->execute();
            $stmt->close();

            /* DELETE ITEM */

            $sql = "DELETE FROM items WHERE id = ?";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $delete_id);
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                throw new Exception("The item could not be deleted.");
            }

            $stmt->close();

            /* COMMIT DATABASE CHANGES */

            $conn->commit();

            /* DELETE ITEM PHOTO */

            if (!empty($item_image)) {

                if ($item_type === "lost") {
                    $image_directory = "../uploads/lost/";
                } elseif ($item_type === "found") {
                    $image_directory = "../uploads/found/";
                } else {
                    $image_directory = "";
                }

                if ($image_directory !== "") {
                    $image_file = $image_directory . basename($item_image);

                    if (is_file($image_file)) {
                        @unlink($image_file);
                    }
                }
            }

            /* DELETE CLAIM PROOF IMAGES */

            foreach ($claim_images as $claim_image) {

                $claim_file = "../uploads/claims/" . basename($claim_image);

                if (is_file($claim_file)) {
                    @unlink($claim_file);
                }
            }

            /* REFRESH CSRF TOKEN */

            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

            header("Location: items.php?deleted=1");
            exit();

        } catch (Throwable $e) {

            $conn->rollback();

            error_log("Admin item deletion error: " . $e->getMessage());

            $delete_error = "Unable to delete this item. Please check the database relationships and try again.";
        }
    }
}

/* =========================================================
   GET ALL REPORTED ITEMS
========================================================= */

$sql = "
    SELECT
        items.*,
        users.name AS reporter_name
    FROM items
    JOIN users
        ON items.user_id = users.id
    ORDER BY items.created_at DESC
";

$result = $conn->query($sql);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">

<title>Manage Items - RTU Lost and Found</title>

<style>

/* =========================================================
   GLOBAL
========================================================= */

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
    background: #f3f6fb;
    color: #1f2937;
}

/* =========================================================
   TOP NAVBAR
========================================================= */

.navbar {
    width: 100%;
    min-height: 70px;
    background: linear-gradient(135deg, #0f2f5f, #164a8a);
    border-bottom: 4px solid #d4af37;
    display: flex;
    align-items: center;
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
    gap: 0px;
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
   ADMIN NAVIGATION
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
    max-width: 1200px;
    margin: 28px auto 50px;
}

/* =========================================================
   PAGE HEADER
========================================================= */

.page-header {
    background: #fff;
    padding: 25px;
    border-radius: 16px;
    margin-bottom: 25px;
    border-top: 4px solid #d4af37;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
}

.page-header h1 {
    color: #0f3d75;
    font-size: 27px;
    margin-bottom: 8px;
}

.page-header p {
    color: #64748b;
    font-size: 14px;
    line-height: 1.6;
}

/* =========================================================
   ALERT MESSAGES
========================================================= */

.alert {
    padding: 14px 18px;
    margin-bottom: 20px;
    border-radius: 9px;
    font-size: 14px;
    line-height: 1.5;
}

.alert-success {
    background: #dcfce7;
    border: 1px solid #86efac;
    color: #166534;
}

.alert-error {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
}

/* =========================================================
   ITEM CARD
========================================================= */

.item-card {
    background: #fff;
    padding: 25px;
    margin-bottom: 25px;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
    transition: 0.18s;
}

.item-card:hover {
    box-shadow: 0 7px 24px rgba(11,61,145,0.11);
}

.item-card h2 {
    color: #0f3d75;
    font-size: 21px;
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e7eb;
}

/* =========================================================
   ITEM INFORMATION
========================================================= */

.item-info {
    line-height: 1.7;
}

.item-info p {
    margin-bottom: 8px;
    font-size: 14px;
    color: #475569;
}

.item-info strong {
    color: #1f2937;
}

/* =========================================================
   BADGES
========================================================= */

.badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: bold;
    text-transform: capitalize;
}

.lost {
    background: #fee2e2;
    color: #b91c1c;
}

.found {
    background: #dcfce7;
    color: #15803d;
}

.claim_pending {
    background: #fef3c7;
    color: #92400e;
}

.returned {
    background: #dbeafe;
    color: #1d4ed8;
}

/* =========================================================
   ITEM PHOTO
========================================================= */

.item-photo {
    width: 200px;
    max-height: 200px;
    object-fit: contain;
    display: block;
    border: 1px solid #dbe2ea;
    border-radius: 10px;
    margin-top: 10px;
    background: #f8fafc;
}

/* =========================================================
   BUTTONS
========================================================= */

.action-buttons {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 15px;
}

.view-button,
.delete-button {
    display: inline-block;
    padding: 10px 15px;
    border: none;
    border-radius: 8px;
    color: #fff;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.18s;
    font-family: Arial, Helvetica, sans-serif;
}

.view-button {
    background: #0f3d75;
}

.view-button:hover {
    background: #174ea6;
    transform: translateY(-1px);
}

.delete-button {
    background: #b91c1c;
}

.delete-button:hover {
    background: #991b1b;
    transform: translateY(-1px);
}

/* =========================================================
   AI SECTION
========================================================= */

.ai-section {
    margin-top: 25px;
    padding: 22px;
    background: #f8fbff;
    border: 1px solid #cbdcf5;
    border-left: 5px solid #d4af37;
    border-radius: 12px;
}

.ai-section h3 {
    color: #0f3d75;
    font-size: 19px;
    margin-bottom: 8px;
}

.ai-section > p {
    color: #64748b;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 12px;
}

/* =========================================================
   AI MATCH
========================================================= */

.ai-match {
    background: #fff;
    padding: 20px;
    margin-top: 15px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 3px 12px rgba(0,0,0,0.05);
}

.ai-match h4 {
    color: #0f3d75;
    font-size: 17px;
    margin-bottom: 13px;
}

.ai-match p {
    color: #475569;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 8px;
}

.ai-match strong {
    color: #1f2937;
}

/* =========================================================
   AI SCORE
========================================================= */

.score {
    display: inline-block;
    margin: 12px 0;
    padding: 8px 12px;
    border-radius: 7px;
    background: #fff8dc;
    border: 1px solid #e8cf72;
    color: #92701c !important;
    font-size: 17px !important;
    font-weight: bold;
}

/* =========================================================
   MATCH PHOTO
========================================================= */

.match-photo {
    width: 150px;
    max-height: 150px;
    object-fit: contain;
    display: block;
    border: 1px solid #dbe2ea;
    border-radius: 8px;
    margin-top: 8px;
    background: #f8fafc;
}

/* =========================================================
   NO MATCH
========================================================= */

.ai-section .no-match {
    color: #64748b;
    font-size: 14px;
    margin-top: 15px;
}

/* =========================================================
   EMPTY
========================================================= */

.empty {
    background: #fff;
    padding: 50px 30px;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    text-align: center;
    color: #64748b;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
}

.empty h3 {
    color: #0f3d75;
    margin-bottom: 8px;
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
        margin: 22px auto 40px;
    }

    .page-header {
        padding: 21px;
    }

    .page-header h1 {
        font-size: 23px;
    }

    .item-card {
        padding: 20px;
    }

}

@media (max-width: 600px) {

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

    .action-buttons {
        align-items: stretch;
        flex-direction: column;
    }

    .action-buttons a,
    .action-buttons button {
        text-align: center;
        width: 100%;
    }

}

/* =========================================================
   PRINT
========================================================= */

@media print {

    .navbar,
    .admin-nav,
    .action-buttons,
    .delete-button {
        display: none;
    }

}

</style>

</head>

<body>

<!-- =======================================================
     TOP NAVBAR
======================================================= -->

<div class="navbar">

    <a href="dashboard.php" class="brand">

        <div class="brand-text">
            <span class="brand-title">RTU Lost and Found</span>
            <span class="brand-subtitle">ADMINISTRATION</span>
        </div>

        <img
            src="../uploads/rtulogo.png"
            alt="RTU Logo"
            class="brand-logo"
        >

    </a>

</div>

<!-- =======================================================
     ADMIN NAVIGATION
======================================================= -->

<nav class="admin-nav">

    <a href="items.php" class="active">Manage Items</a>

    <a href="claims.php">Manage Claims</a>

    <a href="users.php">Users</a>

    <a href="ai_matches.php">AI Matches</a>

</nav>

<!-- =======================================================
     MAIN CONTENT
======================================================= -->

<div class="container">

    <!-- PAGE HEADER -->

    <div class="page-header">

        <h1>Manage Lost and Found Items</h1>

        <p>
            View all reported lost and found items and review possible AI matches.
        </p>

    </div>

    <!-- SUCCESS MESSAGE -->

    <?php if (isset($_GET["deleted"]) && $_GET["deleted"] === "1"): ?>

        <div class="alert alert-success">
            The item has been permanently removed from the system.
        </div>

    <?php endif; ?>

    <!-- ERROR MESSAGE -->

    <?php if (!empty($delete_error)): ?>

        <div class="alert alert-error">
            <?php echo htmlspecialchars($delete_error); ?>
        </div>

    <?php endif; ?>

    <!-- ITEMS -->

    <?php if ($result && $result->num_rows > 0): ?>

        <?php while ($item = $result->fetch_assoc()): ?>

            <div class="item-card">

                <!-- ITEM NAME -->

                <h2>
                    <?php
                    echo htmlspecialchars($item["item_name"]);
                    ?>
                </h2>

                <!-- ITEM INFORMATION -->

                <div class="item-info">

                    <p>
                        <strong>Item ID:</strong>
                        <?php echo (int) $item["id"]; ?>
                    </p>

                    <p>
                        <strong>Type:</strong>
                        <?php
                        echo htmlspecialchars(
                            ucfirst($item["item_type"])
                        );
                        ?>
                    </p>

                    <p>
                        <strong>Category:</strong>
                        <?php
                        echo htmlspecialchars($item["category"]);
                        ?>
                    </p>

                    <p>
                        <strong>Description:</strong>
                        <?php
                        echo nl2br(
                            htmlspecialchars($item["description"])
                        );
                        ?>
                    </p>

                    <p>
                        <strong>Location:</strong>
                        <?php
                        echo htmlspecialchars($item["location"]);
                        ?>
                    </p>

                    <p>
                        <strong>Date Reported:</strong>
                        <?php
                        echo htmlspecialchars($item["date_reported"]);
                        ?>
                    </p>

                    <p>
                        <strong>Reported By:</strong>
                        <?php
                        echo htmlspecialchars($item["reporter_name"]);
                        ?>
                    </p>

                    <p>
                        <strong>Status:</strong>

                        <span class="badge <?php echo htmlspecialchars($item["status"]); ?>">
                            <?php
                            echo htmlspecialchars(
                                ucfirst(
                                    str_replace(
                                        "_",
                                        " ",
                                        $item["status"]
                                    )
                                )
                            );
                            ?>
                        </span>
                    </p>

                </div>

                <!-- ITEM PHOTO -->

                <?php if (!empty($item["image"])): ?>

                    <?php

                    if ($item["item_type"] === "lost") {
                        $image_path = "../uploads/lost/" . basename($item["image"]);
                    } else {
                        $image_path = "../uploads/found/" . basename($item["image"]);
                    }

                    ?>

                    <p style="margin-top: 15px;">
                        <strong>Photo:</strong>
                    </p>

                    <img
                        src="<?php echo htmlspecialchars($image_path); ?>"
                        alt="Item Photo"
                        class="item-photo"
                    >

                <?php endif; ?>

                <!-- ITEM ACTIONS -->

                <div class="action-buttons">

                    <a
                        class="view-button"
                        href="../item.php?id=<?php echo (int) $item["id"]; ?>"
                    >
                        View Item
                    </a>

                    <form
                        method="POST"
                        action="items.php"
                        onsubmit="return confirm('Are you sure you want to permanently delete this item? This action cannot be undone.');"
                    >

                        <input
                            type="hidden"
                            name="delete_item_id"
                            value="<?php echo (int) $item["id"]; ?>"
                        >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                        >

                        <button
                            type="submit"
                            class="delete-button"
                        >
                            Remove Item
                        </button>

                    </form>

                </div>

                <!-- AI MATCH SECTION -->

                <?php if ($item["item_type"] === "found"): ?>

                    <div class="ai-section">

                        <h3>AI Possible Matches</h3>

                        <p>
                            The system compares the found item's image with reported lost item images.
                        </p>

                        <?php

                        $match_sql = "
                            SELECT
                                ai_matches.similarity_score,

                                lost.id AS lost_id,
                                lost.item_name AS lost_name,
                                lost.category AS lost_category,
                                lost.description AS lost_description,
                                lost.image AS lost_image,

                                found.id AS found_id,
                                found.item_name AS found_name

                            FROM ai_matches

                            JOIN items AS lost
                                ON ai_matches.lost_item_id = lost.id

                            JOIN items AS found
                                ON ai_matches.found_item_id = found.id

                            WHERE ai_matches.found_item_id = ?

                            ORDER BY ai_matches.similarity_score DESC
                        ";

                        $match_stmt = $conn->prepare($match_sql);

                        $match_stmt->bind_param("i", $item["id"]);

                        $match_stmt->execute();

                        $match_result = $match_stmt->get_result();

                        ?>

                        <?php if ($match_result->num_rows > 0): ?>

                            <?php while ($match = $match_result->fetch_assoc()): ?>

                                <div class="ai-match">

                                    <h4>AI Match Found</h4>

                                    <p>
                                        <strong>Lost Item:</strong>
                                        <?php
                                        echo htmlspecialchars($match["lost_name"]);
                                        ?>
                                    </p>

                                    <p>
                                        <strong>Found Item:</strong>
                                        <?php
                                        echo htmlspecialchars($match["found_name"]);
                                        ?>
                                    </p>

                                    <p class="score">
                                        AI Similarity:
                                        <?php
                                        echo number_format(
                                            $match["similarity_score"],
                                            2
                                        );
                                        ?>%
                                    </p>

                                    <p>
                                        <strong>Category:</strong>
                                        <?php
                                        echo htmlspecialchars($match["lost_category"]);
                                        ?>
                                    </p>

                                    <?php if (!empty($match["lost_description"])): ?>

                                        <p>
                                            <strong>Lost Item Description:</strong>
                                            <?php
                                            echo nl2br(
                                                htmlspecialchars($match["lost_description"])
                                            );
                                            ?>
                                        </p>

                                    <?php endif; ?>

                                    <?php if (!empty($match["lost_image"])): ?>

                                        <p>
                                            <strong>Lost Item Photo:</strong>
                                        </p>

                                        <img
                                            src="../uploads/lost/<?php echo htmlspecialchars(basename($match["lost_image"])); ?>"
                                            alt="Lost Item Photo"
                                            class="match-photo"
                                        >

                                    <?php endif; ?>

                                    <br>

                                    <a
                                        class="view-button"
                                        href="../item.php?id=<?php echo (int) $match["lost_id"]; ?>"
                                    >
                                        View Lost Item
                                    </a>

                                </div>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <p class="no-match">
                                No possible AI matches found for this item.
                            </p>

                        <?php endif; ?>

                        <?php $match_stmt->close(); ?>

                    </div>

                <?php endif; ?>

            </div>

        <?php endwhile; ?>

    <?php else: ?>

        <div class="empty">

            <h3>No Items Reported</h3>

            <p>
                No lost or found items have been reported yet.
            </p>

        </div>

    <?php endif; ?>

</div>

<script>

/* PREVENT BACK/FORWARD CACHE */

window.addEventListener("pageshow", function(event) {

    if (event.persisted) {
        window.location.reload();
    }

});

</script>

</body>
</html>
