
<?php

session_start();

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
    header("Location: ../login.php");
    exit();
}


/* =========================================================
   SUPABASE CONNECTION
========================================================= */

try {
    require_once __DIR__ . "/../config/supabase.php";

    if (!isset($supabase) || !($supabase instanceof PDO)) {
        throw new RuntimeException("Supabase connection unavailable.");
    }

} catch (Throwable $e) {

    error_log("Admin claims connection error: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to connect to the database.");
}


/* =========================================================
   VERIFY ADMIN ROLE
========================================================= */

$user_id = (int) $_SESSION["user_id"];

try {

    $stmt = $supabase->prepare("
        SELECT role
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");

    $stmt->execute([
        ":user_id" => $user_id
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    if (!$user || $user["role"] !== "admin") {
        http_response_code(403);
        exit("Access denied. Admins only.");
    }

} catch (Throwable $e) {

    error_log("Admin role verification error: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to verify administrator access.");
}


/* =========================================================
   CSRF TOKEN
========================================================= */

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["csrf_token"];


/* =========================================================
   APPROVE / REJECT CLAIM
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $claim_id = filter_var(
        $_POST["claim_id"] ?? null,
        FILTER_VALIDATE_INT
    );

    $action = $_POST["action"] ?? "";

    $submitted_token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($submitted_token) ||
        !hash_equals($csrf_token, $submitted_token)
    ) {

        $_SESSION["claim_message"] =
            "Invalid security token. Please refresh and try again.";

        header("Location: claims.php");
        exit();
    }

    if (
        !$claim_id ||
        $claim_id <= 0 ||
        !in_array($action, ["approve", "reject"], true)
    ) {

        $_SESSION["claim_message"] =
            "Invalid claim action.";

        header("Location: claims.php");
        exit();
    }

    try {

        $supabase->beginTransaction();

        $stmt = $supabase->prepare("
            SELECT id, item_id
            FROM claims
            WHERE id = :claim_id
            AND status = 'pending'
            FOR UPDATE
        ");

        $stmt->execute([
            ":claim_id" => $claim_id
        ]);

        $claim = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$claim) {
            throw new RuntimeException(
                "This claim is no longer pending or does not exist."
            );
        }

        $item_id = (int) $claim["item_id"];

        if ($action === "approve") {

            $stmt = $supabase->prepare("
                UPDATE claims
                SET status = 'approved'
                WHERE id = :claim_id
                AND status = 'pending'
            ");

            $stmt->execute([
                ":claim_id" => $claim_id
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    "Unable to approve this claim."
                );
            }

            $stmt = $supabase->prepare("
                UPDATE items
                SET status = 'returned'
                WHERE id = :item_id
            ");

            $stmt->execute([
                ":item_id" => $item_id
            ]);

            $_SESSION["claim_message"] =
                "Claim approved successfully.";

        } else {

            $stmt = $supabase->prepare("
                UPDATE claims
                SET status = 'rejected'
                WHERE id = :claim_id
                AND status = 'pending'
            ");

            $stmt->execute([
                ":claim_id" => $claim_id
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    "Unable to reject this claim."
                );
            }

            $stmt = $supabase->prepare("
                UPDATE items
                SET status = 'found'
                WHERE id = :item_id
            ");

            $stmt->execute([
                ":item_id" => $item_id
            ]);

            $_SESSION["claim_message"] =
                "Claim rejected successfully.";
        }

        $supabase->commit();

    } catch (Throwable $e) {

        if ($supabase->inTransaction()) {
            $supabase->rollBack();
        }

        error_log("Admin claim action error: " . $e->getMessage());

        $_SESSION["claim_message"] =
            $e instanceof RuntimeException
                ? $e->getMessage()
                : "Unable to process this claim. Please try again.";
    }

    header("Location: claims.php");
    exit();
}


/* =========================================================
   GET ALL CLAIMS
========================================================= */

$claims = [];
$database_error = false;

try {

    $stmt = $supabase->query("
        SELECT
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

        INNER JOIN items
            ON claims.item_id = items.id

        INNER JOIN users
            ON claims.user_id = users.id

        ORDER BY claims.created_at DESC
    ");

    $claims = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log("Admin claims retrieval error: " . $e->getMessage());

    $database_error = true;
    $claims = [];
}


/* =========================================================
   ESCAPE OUTPUT HELPER
========================================================= */

function claimEscape($value) {
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

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

/* GLOBAL */

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


/* TOP NAVBAR */

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


/* ADMIN NAVIGATION */

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


/* MAIN CONTAINER */

.container {
    width: 92%;
    max-width: 1200px;
    margin: 28px auto 50px;
}


/* PAGE HEADER */

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


/* CLAIM CARD */

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


/* CLAIM INFORMATION */

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


/* CLAIM VERIFICATION CODE */

.verification-box {
    margin-top: 18px;
    padding: 18px;
    background: #f8fafc;
    border: 1px solid #dbe3ee;
    border-left: 4px solid #d4af37;
    border-radius: 9px;
}

.verification-title {
    color: #0f2f5f;
    font-size: 13px;
    font-weight: bold;
    margin-bottom: 10px;
}

.verification-row {
    display: flex;
    align-items: center;
    gap: 10px;
}

.verification-code {
    flex: 1;
    padding: 12px;
    background: white;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    color: #0b4178;
    font-size: 17px;
    font-weight: bold;
    letter-spacing: 1px;
    overflow-wrap: anywhere;
}

.copy-button {
    border: none;
    background: #0b5cab;
    color: white;
    padding: 12px 17px;
    border-radius: 7px;
    cursor: pointer;
    font-size: 13px;
    font-weight: bold;
    transition: 0.2s;
}

.copy-button:hover {
    background: #084b8a;
}

.copy-feedback {
    min-height: 17px;
    margin-top: 8px;
    font-size: 12px;
    color: #166534;
}


/* INFORMATION SECTIONS */

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


/* STATUS BADGES */

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


/* PROOF IMAGE */

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


/* ACTION BUTTONS */

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

.action-form {
    display: inline;
}


/* EMPTY STATE */

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


/* ALERT MESSAGES */

.alert {
    padding: 14px 18px;
    margin-bottom: 20px;
    border-radius: 9px;
    font-size: 14px;
}

.alert.error {
    color: #991b1b;
    background: #fee2e2;
    border: 1px solid #fecaca;
}

.alert.success {
    color: #166534;
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
}


/* RESPONSIVE */

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
        overflow-x: auto;
    }

    .admin-nav a {
        flex-shrink: 0;
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

    .button,
    .action-form {
        width: 100%;
    }

    .verification-row {
        flex-direction: column;
        align-items: stretch;
    }

    .verification-code,
    .copy-button {
        width: 100%;
        text-align: center;
    }

}


/* PRINT */

@media print {

    .navbar,
    .admin-nav,
    .actions,
    .copy-button {
        display: none;
    }

}

</style>

</head>

<body>


<!-- TOP NAVIGATION -->

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


<!-- ADMIN NAVIGATION -->

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


<!-- MAIN CONTENT -->

<div class="container">

    <div class="page-header">

        <h1>Manage Claims</h1>

        <p>
            Review and manage claims submitted for found items.
        </p>

    </div>


    <?php if (isset($_SESSION["claim_message"])): ?>

        <?php
        $claim_message = $_SESSION["claim_message"];
        unset($_SESSION["claim_message"]);
        ?>

        <div class="alert <?php
            echo (
                strpos(strtolower($claim_message), "successfully") !== false
            ) ? "success" : "error";
        ?>">
            <?php echo claimEscape($claim_message); ?>
        </div>

    <?php endif; ?>


    <?php if ($database_error): ?>

        <div class="alert error">
            Unable to load claims. Please check your database connection and try again.
        </div>

    <?php elseif (!empty($claims)): ?>

        <?php foreach ($claims as $claim): ?>

            <div class="claim-card">

                <h2>
                    <?php echo claimEscape($claim["item_name"]); ?>
                </h2>


                <div class="claim-info">

                    <p>
                        <strong>Claim ID:</strong>
                        <?php echo (int) $claim["id"]; ?>
                    </p>

                    <p>
                        <strong>Category:</strong>
                        <?php echo claimEscape($claim["category"]); ?>
                    </p>

                    <p>
                        <strong>Location:</strong>
                        <?php echo claimEscape($claim["location"]); ?>
                    </p>

                    <p>
                        <strong>Date Reported:</strong>
                        <?php echo claimEscape($claim["date_reported"]); ?>
                    </p>

                    <p>
                        <strong>Claim Status:</strong>

                        <span class="badge <?php echo claimEscape($claim["status"]); ?>">
                            <?php echo claimEscape(ucfirst($claim["status"])); ?>
                        </span>

                    </p>

                </div>


                <!-- CLAIM VERIFICATION CODE -->

                <div class="verification-box">

                    <div class="verification-title">
                        CLAIM VERIFICATION CODE
                    </div>

                    <?php if (!empty($claim["verification_code"])): ?>

                        <div class="verification-row">

                            <div
                                class="verification-code"
                                id="claim-code-<?php echo (int) $claim["id"]; ?>"
                            ><?php echo claimEscape($claim["verification_code"]); ?></div>

                            <button
                                type="button"
                                class="copy-button"
                                onclick="copyClaimCode(<?php echo (int) $claim["id"]; ?>)"
                            >
                                Copy Code
                            </button>

                        </div>

                        <div
                            class="copy-feedback"
                            id="copy-feedback-<?php echo (int) $claim["id"]; ?>"
                        ></div>

                    <?php else: ?>

                        <p>
                            No verification code is available for this claim.
                        </p>

                    <?php endif; ?>

                </div>


                <!-- CLAIMANT INFORMATION -->

                <div class="info-section">

                    <h3>Claimant Information</h3>

                    <p>
                        <strong>Name:</strong>
                        <?php echo claimEscape($claim["name"]); ?>
                    </p>

                    <p>
                        <strong>Student ID:</strong>
                        <?php echo claimEscape($claim["student_id"]); ?>
                    </p>

                    <p>
                        <strong>Email:</strong>
                        <?php echo claimEscape($claim["email"]); ?>
                    </p>

                </div>


                <!-- FOUND ITEM INFORMATION -->

                <div class="info-section">

                    <h3>Found Item Description</h3>

                    <p>
                        <?php
                        echo nl2br(
                            claimEscape($claim["item_description"])
                        );
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
                                claimEscape($claim["claim_description"])
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
                            src="../uploads/claims/<?php
                                echo rawurlencode(
                                    basename((string) $claim["proof_image"])
                                );
                            ?>"
                            alt="Claim Proof"
                            class="proof-image"
                            loading="lazy"
                        >

                    </div>

                <?php endif; ?>


                <!-- ACTIONS -->

                <div class="actions">

                    <?php if ($claim["status"] === "pending"): ?>

                        <form method="POST" class="action-form"
                              onsubmit="return confirm('Are you sure you want to approve this claim?');">

                            <input type="hidden" name="csrf_token"
                                   value="<?php echo claimEscape($csrf_token); ?>">

                            <input type="hidden" name="claim_id"
                                   value="<?php echo (int) $claim["id"]; ?>">

                            <input type="hidden" name="action" value="approve">

                            <button type="submit" class="button approve">
                                Approve
                            </button>

                        </form>


                        <form method="POST" class="action-form"
                              onsubmit="return confirm('Are you sure you want to reject this claim?');">

                            <input type="hidden" name="csrf_token"
                                   value="<?php echo claimEscape($csrf_token); ?>">

                            <input type="hidden" name="claim_id"
                                   value="<?php echo (int) $claim["id"]; ?>">

                            <input type="hidden" name="action" value="reject">

                            <button type="submit" class="button reject">
                                Reject
                            </button>

                        </form>

                    <?php endif; ?>


                    <a
                        href="../item.php?id=<?php echo (int) $claim["item_id"]; ?>"
                        class="button view"
                    >
                        View Item
                    </a>

                </div>

            </div>

        <?php endforeach; ?>

    <?php else: ?>

        <div class="empty">
            <p>No claims have been submitted yet.</p>
        </div>

    <?php endif; ?>

</div>


<script>

/* COPY CLAIM VERIFICATION CODE */

function copyClaimCode(claimId) {

    const codeElement = document.getElementById("claim-code-" + claimId);
    const feedback = document.getElementById("copy-feedback-" + claimId);

    if (!codeElement || !feedback) {
        return;
    }

    const code = codeElement.textContent.trim();

    if (navigator.clipboard && window.isSecureContext) {

        navigator.clipboard.writeText(code)
            .then(function() {
                feedback.textContent = "Claim verification code copied.";
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
            ? "Claim verification code copied."
            : "Please copy the code manually.";

    } catch (error) {

        feedback.textContent = "Please copy the code manually.";

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
