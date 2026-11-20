
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

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

/* =========================================================
   SUPABASE CONNECTION
========================================================= */

require_once __DIR__ . "/../config/supabase.php";

$user_id = (int) $_SESSION['user_id'];

$database_error = false;
$name = "Administrator";

$total_items = 0;
$total_lost = 0;
$total_found = 0;
$pending_claims = 0;
$ai_matches = 0;
$recent_items = [];

try {

    /* =====================================================
       CHECK ADMIN ACCOUNT
    ===================================================== */

    $stmt = $supabase->prepare("
        SELECT id, name, role
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");

    $stmt->execute([
        ':user_id' => $user_id
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user['role'] !== 'admin') {
        header("Location: ../dashboard.php");
        exit();
    }

    $name = $user['name'];


    /* =====================================================
       DASHBOARD STATISTICS
    ===================================================== */

    $stmt = $supabase->query("
        SELECT
            COUNT(*) AS total_items,
            COUNT(*) FILTER (WHERE item_type = 'lost') AS total_lost,
            COUNT(*) FILTER (WHERE item_type = 'found') AS total_found
        FROM items
    ");

    $statistics = $stmt->fetch(PDO::FETCH_ASSOC);

    $total_items = (int) $statistics['total_items'];
    $total_lost = (int) $statistics['total_lost'];
    $total_found = (int) $statistics['total_found'];


    /* =====================================================
       PENDING CLAIMS
    ===================================================== */

    $stmt = $supabase->query("
        SELECT COUNT(*)
        FROM claims
        WHERE status = 'pending'
    ");

    $pending_claims = (int) $stmt->fetchColumn();


    /* =====================================================
       AI MATCHES
    ===================================================== */

    $stmt = $supabase->query("
        SELECT COUNT(*)
        FROM ai_matches
    ");

    $ai_matches = (int) $stmt->fetchColumn();


    /* =====================================================
       RECENT REPORTS WITH VERIFICATION CODES
    ===================================================== */

    $stmt = $supabase->query("
        SELECT
            id,
            item_type,
            item_name,
            category,
            location,
            date_reported,
            status,
            verification_code
        FROM items
        ORDER BY created_at DESC
        LIMIT 5
    ");

    $recent_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log("Admin Dashboard Database Error: " . $e->getMessage());

    $database_error = true;
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

<title>Admin Dashboard - RTU Lost and Found</title>

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
    min-height: 100vh;
}

/* TOP NAVBAR */

.navbar {
    width: 100%;
    min-height: 70px;
    background: linear-gradient(135deg, #0f2f5f, #164a8a);
    border-bottom: 4px solid #d4af37;
    display: flex;
    align-items: center;
    justify-content: space-between;
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

/* MAIN CONTAINER */

.container {
    width: 92%;
    max-width: 1200px;
    margin: 28px auto 50px;
}

/* WELCOME */

.welcome {
    background: #fff;
    padding: 25px;
    border-radius: 16px;
    border-top: 4px solid #d4af37;
    margin-bottom: 25px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
}

.welcome h1 {
    color: #0f3d75;
    font-size: 26px;
    margin-bottom: 8px;
}

.welcome p {
    color: #64748b;
    font-size: 14px;
    line-height: 1.6;
}

/* STATISTICS */

.stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}

.stat-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 23px 18px;
    text-align: center;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
    transition: 0.18s;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 22px rgba(11,61,145,0.11);
}

.stat-card h3 {
    color: #0f3d75;
    font-size: 30px;
    margin-bottom: 7px;
}

.stat-card p {
    color: #64748b;
    font-size: 13px;
    font-weight: 600;
}

/* RECENT REPORTS SECTION */

.section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
}

.section h2 {
    color: #0f3d75;
    font-size: 21px;
    margin-bottom: 20px;
    padding-left: 12px;
    border-left: 4px solid #d4af37;
}

/* TABLE */

.table-container {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 950px;
}

thead {
    background: #0f3d75;
    color: #fff;
}

th {
    padding: 13px 12px;
    text-align: left;
    font-size: 12px;
    font-weight: bold;
    white-space: nowrap;
}

td {
    padding: 14px 12px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
    color: #475569;
    vertical-align: middle;
}

tbody tr:hover {
    background: #f8fbff;
}

/* STATUS BADGES */

.badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    white-space: nowrap;
}

.badge.lost {
    background: #fee2e2;
    color: #b91c1c;
}

.badge.found {
    background: #dcfce7;
    color: #166534;
}

.badge.pending {
    background: #fef3c7;
    color: #92400e;
}

.badge.returned {
    background: #dbeafe;
    color: #1d4ed8;
}

/* VERIFICATION CODE */

.verification-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 210px;
}

.verification-code {
    display: inline-block;
    padding: 7px 9px;
    background: #edf3fa;
    border: 1px solid #d5e1ef;
    border-radius: 6px;
    color: #0f3d75;
    font-size: 11px;
    font-weight: bold;
    letter-spacing: 0.3px;
    white-space: nowrap;
}

.copy-button {
    padding: 7px 10px;
    background: #0f3d75;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: 11px;
    font-weight: bold;
    cursor: pointer;
    transition: 0.18s;
    white-space: nowrap;
}

.copy-button:hover {
    background: #174ea6;
}

.copy-button:disabled {
    opacity: 0.7;
    cursor: default;
}

.no-code {
    color: #94a3b8;
    font-size: 12px;
    font-style: italic;
}

/* EMPTY STATE */

.empty {
    padding: 40px 20px;
    text-align: center;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    color: #64748b;
    font-size: 14px;
}

/* DATABASE ERROR */

.database-error {
    padding: 15px 18px;
    margin-bottom: 20px;
    background: #fee2e2;
    border: 1px solid #fecaca;
    border-radius: 10px;
    color: #991b1b;
    font-size: 13px;
}

/* COPY FEEDBACK */

.copy-feedback {
    position: fixed;
    bottom: 25px;
    right: 25px;
    padding: 12px 18px;
    background: #0f3d75;
    color: #fff;
    border-radius: 8px;
    font-size: 13px;
    font-weight: bold;
    box-shadow: 0 4px 14px rgba(0,0,0,0.15);
    display: none;
    z-index: 1000;
}

/* RESPONSIVE */

@media (max-width: 1050px) {
    .stats {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

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
}

@media (max-width: 600px) {

    .navbar {
        padding: 10px 4%;
    }

    .brand-title {
        font-size: 17px;
    }

    .brand-logo {
        width: 40px;
        height: 40px;
    }

    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .welcome {
        padding: 21px;
    }

    .welcome h1 {
        font-size: 24px;
    }

    .section {
        padding: 18px;
    }

    .admin-nav a {
        font-size: 12px;
        padding: 0 10px;
    }
}

@media (max-width: 400px) {
    .stats {
        grid-template-columns: 1fr;
    }
}

/* PRINT */

@media print {
    .navbar,
    .admin-nav,
    .copy-button,
    .copy-feedback {
        display: none !important;
    }
}

</style>
</head>

<body>

<!-- TOP NAVBAR - PRESERVED -->

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

<!-- ADMIN NAVIGATION - PRESERVED -->

<nav class="admin-nav">

    <a href="items.php">Item Verification</a>
    <a href="users.php">Users</a>

</nav>

<!-- MAIN CONTENT -->

<div class="container">

    <?php if ($database_error): ?>

        <div class="database-error">
            Unable to load dashboard information. Please check your database connection and try again.
        </div>

    <?php endif; ?>


    <!-- WELCOME -->

    <div class="welcome">

        <h1>Admin Dashboard</h1>

        <p>
            Welcome,
            <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>.
            Manage the RTU Lost and Found system from this page.
        </p>

    </div>


    <!-- STATISTICS -->

    <div class="stats">

        <div class="stat-card">
            <h3><?php echo $total_items; ?></h3>
            <p>Total Items</p>
        </div>

        <div class="stat-card">
            <h3><?php echo $total_lost; ?></h3>
            <p>Lost Items</p>
        </div>

        <div class="stat-card">
            <h3><?php echo $total_found; ?></h3>
            <p>Found Items</p>
        </div>

        <div class="stat-card">
            <h3><?php echo $pending_claims; ?></h3>
            <p>Pending Claims</p>
        </div>

        <div class="stat-card">
            <h3><?php echo $ai_matches; ?></h3>
            <p>AI Matches</p>
        </div>

    </div>


    <!-- RECENT REPORTS -->

    <div class="section">

        <h2>Recent Lost &amp; Found Reports</h2>

        <?php if (!empty($recent_items)): ?>

            <div class="table-container">

                <table>

                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Location</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Verification Code</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($recent_items as $item): ?>

                            <?php

                            $badge_class = $item['item_type'];

                            if ($item['status'] === 'claim_pending') {
                                $badge_class = 'pending';
                            }

                            if ($item['status'] === 'returned') {
                                $badge_class = 'returned';
                            }

                            $verification_code = trim(
                                (string) ($item['verification_code'] ?? '')
                            );

                            ?>

                            <tr>

                                <td>
                                    <?php echo htmlspecialchars((string) $item['item_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars(ucfirst((string) $item['item_type']), ENT_QUOTES, 'UTF-8'); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars((string) $item['category'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars((string) $item['location'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars((string) $item['date_reported'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>

                                <td>

                                    <span class="badge <?php echo htmlspecialchars($badge_class, ENT_QUOTES, 'UTF-8'); ?>">

                                        <?php
                                        echo htmlspecialchars(
                                            ucfirst(str_replace('_', ' ', (string) $item['status'])),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        );
                                        ?>

                                    </span>

                                </td>

                                <td>

                                    <?php if ($verification_code !== ''): ?>

                                        <div class="verification-wrapper">

                                            <span class="verification-code">
                                                <?php echo htmlspecialchars($verification_code, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>

                                            <button
                                                type="button"
                                                class="copy-button"
                                                data-code="<?php echo htmlspecialchars($verification_code, ENT_QUOTES, 'UTF-8'); ?>"
                                                onclick="copyVerificationCode(this)"
                                            >
                                                Copy
                                            </button>

                                        </div>

                                    <?php else: ?>

                                        <span class="no-code">
                                            Not available
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="empty">
                No reports found.
            </div>

        <?php endif; ?>

    </div>

</div>

<!-- COPY FEEDBACK -->

<div class="copy-feedback" id="copyFeedback">
    Verification code copied.
</div>

<script>

/* =========================================================
   COPY VERIFICATION CODE
========================================================= */

async function copyVerificationCode(button) {

    const code = button.getAttribute("data-code");

    if (!code) {
        return;
    }

    try {

        if (navigator.clipboard && window.isSecureContext) {

            await navigator.clipboard.writeText(code);

        } else {

            const temporaryInput = document.createElement("textarea");

            temporaryInput.value = code;
            temporaryInput.style.position = "fixed";
            temporaryInput.style.opacity = "0";

            document.body.appendChild(temporaryInput);

            temporaryInput.select();

            const copied = document.execCommand("copy");

            document.body.removeChild(temporaryInput);

            if (!copied) {
                throw new Error("Copy operation failed.");
            }
        }

        const originalText = button.textContent;

        button.textContent = "Copied";
        button.disabled = true;

        showCopyFeedback();

        setTimeout(function() {
            button.textContent = originalText;
            button.disabled = false;
        }, 1500);

    } catch (error) {

        alert("Unable to copy the verification code. Please copy it manually.");

    }
}


/* =========================================================
   COPY FEEDBACK
========================================================= */

function showCopyFeedback() {

    const feedback = document.getElementById("copyFeedback");

    feedback.style.display = "block";

    clearTimeout(window.copyFeedbackTimeout);

    window.copyFeedbackTimeout = setTimeout(function() {
        feedback.style.display = "none";
    }, 2000);

}


/* =========================================================
   PREVENT BACK/FORWARD CACHE
========================================================= */

window.addEventListener("pageshow", function(event) {

    if (event.persisted) {
        window.location.reload();
    }

});

</script>

</body>
</html>
