
<?php

session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$user_id = (int) $_SESSION['user_id'];

/* CHECK CURRENT USER */

$stmt = $conn->prepare("
    SELECT id, name, student_id, email, role
    FROM users
    WHERE id = ?
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user || $user['role'] !== 'admin') {
    header("Location: ../dashboard.php");
    exit();
}

$name = $user['name'];

/* TOTAL ITEMS */

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM items
");
$total_items = (int) $result->fetch_assoc()['total'];

/* TOTAL LOST ITEMS */

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM items
    WHERE item_type = 'lost'
");
$total_lost = (int) $result->fetch_assoc()['total'];

/* TOTAL FOUND ITEMS */

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM items
    WHERE item_type = 'found'
");
$total_found = (int) $result->fetch_assoc()['total'];

/* PENDING CLAIMS */

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM claims
    WHERE status = 'pending'
");
$pending_claims = (int) $result->fetch_assoc()['total'];

/* AI MATCHES */

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM ai_matches
");
$ai_matches = (int) $result->fetch_assoc()['total'];

/* RECENT REPORTS */

$recent_items = $conn->query("
    SELECT
        id,
        item_type,
        item_name,
        category,
        location,
        date_reported,
        status
    FROM items
    ORDER BY created_at DESC
    LIMIT 5
");

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

/* MANAGEMENT LINKS */

.admin-links {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 25px;
}

.admin-link-card {
    display: block;
    padding: 19px 20px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #d4af37;
    border-radius: 12px;
    color: #0f3d75;
    text-decoration: none;
    font-size: 15px;
    font-weight: 700;
    box-shadow: 0 4px 16px rgba(0,0,0,0.05);
    transition: 0.18s;
}

.admin-link-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 22px rgba(11,61,145,0.10);
}

.admin-link-card span {
    display: block;
    margin-top: 6px;
    color: #64748b;
    font-size: 12px;
    font-weight: 400;
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
    min-width: 850px;
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

/* VIEW BUTTON */

.view-button {
    display: inline-block;
    padding: 7px 13px;
    background: #0f3d75;
    color: #fff;
    text-decoration: none;
    border-radius: 7px;
    font-size: 12px;
    font-weight: bold;
    transition: 0.18s;
}

.view-button:hover {
    background: #174ea6;
    transform: translateY(-1px);
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

/* RESPONSIVE */

@media (max-width: 1050px) {
    .stats {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 800px) {
    .admin-links {
        grid-template-columns: 1fr;
    }

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
    .admin-nav {
        display: none;
    }
}

</style>
</head>

<body>

<!-- TOP NAVBAR -->

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

<!-- ADMIN NAVIGATION -->

<nav class="admin-nav">

    <a href="items.php">Manage Items</a>
    <a href="claims.php">Manage Claims</a>
    <a href="users.php">Users</a>
    <a href="ai_matches.php">AI Matches</a>

</nav>

<!-- MAIN CONTENT -->

<div class="container">

    <div class="welcome">

        <h1>Admin Dashboard</h1>

        <p>
            Welcome,
            <?php echo htmlspecialchars($name); ?>.
            Manage the RTU Lost and Found system from this page.
        </p>

    </div>

    <!-- ADMIN MANAGEMENT LINKS -->

    <div class="admin-links">

        <a href="items.php" class="admin-link-card">
            Manage Items
            <span>Review and manage lost and found reports</span>
        </a>

        <a href="claims.php" class="admin-link-card">
            Manage Claims
            <span>Review submitted item claims</span>
        </a>

        <a href="users.php" class="admin-link-card">
            Manage Users
            <span>Manage registered system accounts</span>
        </a>

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

        <?php if ($recent_items && $recent_items->num_rows > 0): ?>

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
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php while ($item = $recent_items->fetch_assoc()): ?>

                            <?php

                            $badge_class = $item['item_type'];

                            if ($item['status'] === 'claim_pending') {
                                $badge_class = 'pending';
                            }

                            if ($item['status'] === 'returned') {
                                $badge_class = 'returned';
                            }

                            ?>

                            <tr>

                                <td>
                                    <?php echo htmlspecialchars($item['item_name']); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars(ucfirst($item['item_type'])); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($item['category']); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($item['location']); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($item['date_reported']); ?>
                                </td>

                                <td>
                                    <span class="badge <?php echo htmlspecialchars($badge_class); ?>">
                                        <?php
                                        echo htmlspecialchars(
                                            ucfirst(str_replace('_', ' ', $item['status']))
                                        );
                                        ?>
                                    </span>
                                </td>

                                <td>
                                    <a href="items.php" class="view-button">View</a>
                                </td>

                            </tr>

                        <?php endwhile; ?>

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
