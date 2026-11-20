
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

/* Check if logged-in user is an admin */

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


/* Approve claim */

if (isset($_GET["approve"])) {

    $claim_id = (int) $_GET["approve"];

    $stmt = $conn->prepare("
        SELECT item_id
        FROM claims
        WHERE id = ?
        AND status = 'pending'
    ");

    $stmt->bind_param("i", $claim_id);
    $stmt->execute();

    $claim_result = $stmt->get_result();
    $claim = $claim_result->fetch_assoc();

    $stmt->close();

    if ($claim) {

        $item_id = (int) $claim["item_id"];

        $stmt = $conn->prepare("
            UPDATE claims
            SET status = 'approved'
            WHERE id = ?
        ");

        $stmt->bind_param("i", $claim_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE items
            SET status = 'returned'
            WHERE id = ?
        ");

        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: claims.php");
    exit();
}


/* Reject claim */

if (isset($_GET["reject"])) {

    $claim_id = (int) $_GET["reject"];

    $stmt = $conn->prepare("
        SELECT item_id
        FROM claims
        WHERE id = ?
        AND status = 'pending'
    ");

    $stmt->bind_param("i", $claim_id);
    $stmt->execute();

    $claim_result = $stmt->get_result();
    $claim = $claim_result->fetch_assoc();

    $stmt->close();

    if ($claim) {

        $item_id = (int) $claim["item_id"];

        $stmt = $conn->prepare("
            UPDATE claims
            SET status = 'rejected'
            WHERE id = ?
        ");

        $stmt->bind_param("i", $claim_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE items
            SET status = 'found'
            WHERE id = ?
        ");

        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: claims.php");
    exit();
}


/* Get all claims */

$sql = "SELECT
            claims.*,
            items.item_name,
            items.category,
            items.description AS item_description,
            items.location,
            items.date_reported,
            users.name,
            users.student_id,
            users.email

        FROM claims

        JOIN items
            ON claims.item_id = items.id

        JOIN users
            ON claims.user_id = users.id

        ORDER BY claims.created_at DESC";

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

<title>Manage Claims - RTU Lost and Found</title>

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
    line-height: 1.6;
}

a {
    text-decoration: none;
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
    line-height: 1.1;
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
    background: white;
    padding: 25px 28px;
    border-radius: 16px;
    margin-bottom: 25px;
    border-top: 4px solid #d4af37;
    box-shadow: 0 3px 14px rgba(0, 0, 0, 0.07);
}

.page-header h1 {
    color: #0f2f5f;
    font-size: 27px;
    margin-bottom: 5px;
}

.page-header p {
    color: #6b7280;
    font-size: 14px;
}


/* =========================================================
   CLAIM CARD
========================================================= */

.claim-card {
    background: white;
    padding: 26px;
    margin-bottom: 22px;

    border-radius: 14px;
    border: 1px solid #e5e7eb;
    border-top: 3px solid #d4af37;

    box-shadow: 0 3px 14px rgba(0, 0, 0, 0.06);

    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.claim-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 7px 20px rgba(0, 0, 0, 0.09);
}

.claim-card h2 {
    color: #0f2f5f;
    font-size: 21px;
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e7eb;
}


/* =========================================================
   CLAIM INFORMATION
========================================================= */

.claim-info {
    line-height: 1.8;
}

.claim-info p {
    margin-bottom: 6px;
    font-size: 14px;
}

.claim-info strong,
.info-section strong {
    color: #263445;
}


/* =========================================================
   INFORMATION SECTIONS
========================================================= */

.info-section {
    margin-top: 18px;
    padding: 19px;

    background: #f8fafc;
    border-radius: 9px;

    border: 1px solid #e2e8f0;
    border-left: 4px solid #164a8a;
}

.info-section h3 {
    color: #0f2f5f;
    font-size: 16px;
    margin-bottom: 10px;
}

.info-section p {
    color: #374151;
    font-size: 14px;
    margin-bottom: 5px;
    overflow-wrap: anywhere;
}


/* =========================================================
   STATUS BADGES
========================================================= */

.badge {
    display: inline-block;
    padding: 5px 11px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    text-transform: capitalize;
}

.pending {
    background: #fff4cc;
    color: #8a5a00;
    border: 1px solid #f5c242;
}

.approved {
    background: #e5f6eb;
    color: #176b35;
    border: 1px solid #9bd5ae;
}

.rejected {
    background: #fde8e7;
    color: #a11a14;
    border: 1px solid #efaaa5;
}


/* =========================================================
   PROOF IMAGE
========================================================= */

.proof-image {
    width: 220px;
    max-height: 220px;
    object-fit: contain;
    display: block;
    margin-top: 10px;

    background: white;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 5px;
}


/* =========================================================
   ACTION BUTTONS
========================================================= */

.actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;

    margin-top: 22px;
    padding-top: 20px;

    border-top: 1px solid #e5e7eb;
}

.button {
    display: inline-block;
    padding: 10px 17px;
    border-radius: 7px;
    border: none;

    color: white;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-align: center;

    transition: 0.2s ease;
}

.approve {
    background: #16803c;
}

.approve:hover {
    background: #126b32;
}

.reject {
    background: #b42318;
}

.reject:hover {
    background: #8f1c13;
}

.view {
    background: #0f2f5f;
}

.view:hover {
    background: #164a8a;
}


/* =========================================================
   EMPTY STATE
========================================================= */

.empty {
    background: white;
    padding: 45px 30px;
    border-radius: 14px;
    text-align: center;
    color: #6b7280;

    border: 1px solid #e5e7eb;
    border-top: 3px solid #d4af37;

    box-shadow: 0 3px 14px rgba(0, 0, 0, 0.06);
}

.empty p {
    font-size: 15px;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 850px) {

    .navbar {
        padding: 10px 4%;
    }

    .admin-nav {
        padding: 10px 4%;
    }

    .admin-nav a {
        font-size: 13px;
        padding: 8px 10px;
    }

    .container {
        width: 94%;
        margin-top: 24px;
    }

    .page-header,
    .claim-card {
        padding: 22px;
    }
}

@media (max-width: 600px) {

    .brand-title {
        font-size: 18px;
    }

    .brand-logo {
        width: 42px;
        height: 42px;
    }

    .admin-nav {
        gap: 5px;
    }

    .admin-nav a {
        padding: 8px 9px;
        font-size: 12px;
    }

    .page-header h1 {
        font-size: 23px;
    }

    .claim-card {
        padding: 18px;
    }

    .proof-image {
        width: 100%;
        max-width: 300px;
    }

    .actions {
        flex-direction: column;
    }

    .button {
        width: 100%;
    }
}


/* =========================================================
   PRINT
========================================================= */

@media print {

    .navbar,
    .admin-nav,
    .actions {
        display: none;
    }

}

</style>

</head>

<body>


<!-- =========================================================
     TOP NAVIGATION
========================================================= -->

<header class="navbar">

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

</header>


<!-- =========================================================
     ADMIN NAVIGATION
========================================================= -->

<nav class="admin-nav">

    <a href="items.php">
        Manage Items
    </a>

    <a href="claims.php" class="active">
        Manage Claims
    </a>

    <a href="users.php">
        Users
    </a>

    <a href="ai_matches.php">
        AI Matches
    </a>

</nav>


<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<div class="container">

    <div class="page-header">

        <h1>Manage Claims</h1>

        <p>
            Review and manage claims submitted for found items.
        </p>

    </div>


    <?php if ($result && $result->num_rows > 0): ?>

        <?php while ($claim = $result->fetch_assoc()): ?>

            <div class="claim-card">

                <h2>
                    <?php
                    echo htmlspecialchars($claim["item_name"]);
                    ?>
                </h2>


                <div class="claim-info">

                    <p>
                        <strong>Claim ID:</strong>
                        <?php echo (int) $claim["id"]; ?>
                    </p>

                    <p>
                        <strong>Category:</strong>
                        <?php echo htmlspecialchars($claim["category"]); ?>
                    </p>

                    <p>
                        <strong>Location:</strong>
                        <?php echo htmlspecialchars($claim["location"]); ?>
                    </p>

                    <p>
                        <strong>Date Reported:</strong>
                        <?php echo htmlspecialchars($claim["date_reported"]); ?>
                    </p>

                    <p>
                        <strong>Claim Status:</strong>

                        <span class="badge <?php echo htmlspecialchars($claim["status"]); ?>">
                            <?php
                            echo htmlspecialchars(ucfirst($claim["status"]));
                            ?>
                        </span>
                    </p>

                </div>


                <!-- CLAIMANT INFORMATION -->

                <div class="info-section">

                    <h3>Claimant Information</h3>

                    <p>
                        <strong>Name:</strong>
                        <?php echo htmlspecialchars($claim["name"]); ?>
                    </p>

                    <p>
                        <strong>Student ID:</strong>
                        <?php echo htmlspecialchars($claim["student_id"]); ?>
                    </p>

                    <p>
                        <strong>Email:</strong>
                        <?php echo htmlspecialchars($claim["email"]); ?>
                    </p>

                </div>


                <!-- FOUND ITEM INFORMATION -->

                <div class="info-section">

                    <h3>Found Item Description</h3>

                    <p>
                        <?php
                        echo nl2br(htmlspecialchars($claim["item_description"]));
                        ?>
                    </p>

                </div>


                <!-- CLAIM EXPLANATION -->

                <div class="info-section">

                    <h3>Claim Explanation</h3>

                    <p>
                        <?php

                        if (!empty($claim["claim_description"])) {

                            echo nl2br(
                                htmlspecialchars($claim["claim_description"])
                            );

                        } else {

                            echo "No claim explanation provided.";

                        }

                        ?>
                    </p>

                </div>


                <!-- PROOF IMAGE -->

                <?php if (!empty($claim["proof_image"])): ?>

                    <div class="info-section">

                        <h3>Proof Image</h3>

                        <img
                            src="../uploads/claims/<?php echo htmlspecialchars($claim["proof_image"]); ?>"
                            alt="Claim Proof"
                            class="proof-image"
                        >

                    </div>

                <?php endif; ?>


                <!-- ACTIONS -->

                <?php if ($claim["status"] === "pending"): ?>

                    <div class="actions">

                        <a
                            href="claims.php?approve=<?php echo (int) $claim["id"]; ?>"
                            class="button approve"
                            onclick="return confirm('Are you sure you want to approve this claim?');"
                        >
                            Approve
                        </a>

                        <a
                            href="claims.php?reject=<?php echo (int) $claim["id"]; ?>"
                            class="button reject"
                            onclick="return confirm('Are you sure you want to reject this claim?');"
                        >
                            Reject
                        </a>

                        <a
                            href="../item.php?id=<?php echo (int) $claim["item_id"]; ?>"
                            class="button view"
                        >
                            View Item
                        </a>

                    </div>

                <?php else: ?>

                    <div class="actions">

                        <a
                            href="../item.php?id=<?php echo (int) $claim["item_id"]; ?>"
                            class="button view"
                        >
                            View Item
                        </a>

                    </div>

                <?php endif; ?>

            </div>

        <?php endwhile; ?>

    <?php else: ?>

        <div class="empty">
            <p>No claims have been submitted yet.</p>
        </div>

    <?php endif; ?>

</div>


<script>

/* Prevent back/forward cache */

window.addEventListener("pageshow", function (event) {

    if (event.persisted) {
        window.location.reload();
    }

});

</script>


</body>
</html>
