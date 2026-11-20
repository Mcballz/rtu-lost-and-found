
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
   CHECK ADMIN LOGIN
========================================================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}


/* =========================================================
   SUPABASE CONNECTION
========================================================= */

require_once __DIR__ . "/../config/supabase.php";


/* =========================================================
   VERIFY ADMIN ROLE
========================================================= */

$user_id = (int) $_SESSION["user_id"];

try {

    $stmt = $supabase->prepare("
        SELECT id, role
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");

    $stmt->execute([
        ":user_id" => $user_id
    ]);

    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_user || $current_user["role"] !== "admin") {
        header("Location: ../dashboard.php");
        exit();
    }


    /* =====================================================
       GET AI MATCHES
    ===================================================== */

    $sql = "
        SELECT
            am.id,
            am.similarity_score,
            am.created_at,

            li.id AS lost_id,
            li.item_name AS lost_item_name,
            li.image AS lost_image,

            fi.id AS found_id,
            fi.item_name AS found_item_name,
            fi.image AS found_image

        FROM ai_matches am

        INNER JOIN items li
            ON am.lost_item_id = li.id

        INNER JOIN items fi
            ON am.found_item_id = fi.id

        ORDER BY am.similarity_score DESC
    ";

    $stmt = $supabase->query($sql);

    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log("Admin AI Matches Error: " . $e->getMessage());

    $database_error = true;
    $matches = [];

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

<title>AI Possible Matches - RTU Lost and Found</title>

<style>

/* GLOBAL */

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f4f6f9;
    color: #263445;
    line-height: 1.6;
}

a {
    text-decoration: none;
    color: inherit;
}

/* BLUE MAIN NAVBAR */

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
    margin: 30px auto 55px;
}

/* PAGE HEADER */

.page-header {
    background: #ffffff;
    border: 1px solid #e8edf2;
    border-top: 4px solid #d4af37;
    border-radius: 14px;
    padding: 25px 28px;
    margin-bottom: 22px;
    box-shadow: 0 3px 14px rgba(20, 40, 70, 0.05);
}

.page-header h1 {
    color: #123c70;
    font-size: 26px;
    line-height: 1.3;
    margin-bottom: 7px;
}

.page-header p {
    color: #718096;
    font-size: 14px;
    max-width: 800px;
}

/* SECTION HEADING */

.section-heading {
    display: flex;
    align-items: center;
    gap: 11px;
    color: #173b6d;
    font-size: 17px;
    font-weight: 700;
    margin: 28px 0 16px;
}

.section-heading::before {
    content: "";
    width: 4px;
    height: 21px;
    background: #d4af37;
    border-radius: 3px;
}

/* AI NOTICE */

.notice {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 17px 20px;
    margin-bottom: 25px;
    background: #fffaf0;
    border: 1px solid #f0dfae;
    border-left: 4px solid #d4af37;
    border-radius: 10px;
}

.notice-label {
    flex-shrink: 0;
    color: #8a6810;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding-top: 2px;
}

.notice p {
    color: #6c5a2a;
    font-size: 13px;
}

/* MATCH CARD */

.match-card {
    background: #ffffff;
    border: 1px solid #e5eaf0;
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 22px;
    box-shadow: 0 3px 13px rgba(20, 40, 70, 0.05);
    transition: box-shadow 0.2s ease, transform 0.2s ease;
}

.match-card:hover {
    box-shadow: 0 7px 20px rgba(20, 40, 70, 0.09);
    transform: translateY(-2px);
}

.match-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    padding-bottom: 18px;
    border-bottom: 1px solid #edf0f4;
}

.match-title {
    color: #173b6d;
    font-size: 19px;
    font-weight: 700;
}

.match-date {
    display: block;
    color: #8995a5;
    font-size: 12px;
    margin-top: 4px;
}

/* SIMILARITY SCORE */

.score-box {
    min-width: 145px;
    padding: 12px 17px;
    text-align: center;
    background: #f4f7fb;
    border: 1px solid #e0e7f0;
    border-radius: 10px;
}

.score-label {
    display: block;
    color: #7b8798;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.7px;
}

.score-value {
    display: block;
    color: #123c70;
    font-size: 27px;
    font-weight: 700;
    line-height: 1.25;
    margin-top: 3px;
}

/* MATCH CLASSIFICATION */

.match-level {
    display: inline-block;
    margin-top: 9px;
    padding: 5px 11px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

.very-high {
    background: #e8f5ec;
    color: #176b35;
    border: 1px solid #b8dfc4;
}

.high {
    background: #e9f1fb;
    color: #16477f;
    border: 1px solid #c4d7ef;
}

.possible {
    background: #fff5d9;
    color: #8a5a00;
    border: 1px solid #f0dfae;
}

/* MATCH INFORMATION */

.match-info {
    background: #f8fafc;
    border: 1px solid #e7edf4;
    border-radius: 10px;
    padding: 17px 19px;
    margin-top: 20px;
}

.match-info h3 {
    color: #173b6d;
    font-size: 14px;
    margin-bottom: 10px;
}

.match-info-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.info-item span {
    display: block;
    color: #8490a0;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 3px;
}

.info-item strong {
    display: block;
    color: #35465c;
    font-size: 13px;
    overflow-wrap: anywhere;
}

/* ITEM COMPARISON */

.comparison-heading {
    color: #173b6d;
    font-size: 15px;
    font-weight: 700;
    margin: 23px 0 13px;
}

.comparison {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.item-panel {
    min-width: 0;
    padding: 19px;
    background: #fbfcfe;
    border: 1px solid #e5eaf0;
    border-radius: 11px;
    text-align: center;
}

.item-panel.lost-panel {
    border-top: 4px solid #b42318;
}

.item-panel.found-panel {
    border-top: 4px solid #16803c;
}

.item-type-label {
    display: inline-block;
    color: #64748b;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    margin-bottom: 7px;
}

.item-panel h3 {
    color: #173b6d;
    font-size: 16px;
    margin-bottom: 5px;
}

.item-name {
    color: #36465a;
    font-size: 15px;
    font-weight: 700;
    margin-bottom: 14px;
    overflow-wrap: anywhere;
}

/* FULL-SIZE ITEM IMAGES */

.item-image,
.no-image {
    width: 100%;
    max-width: 320px;
    min-height: 180px;
    max-height: 450px;
    margin: 0 auto 13px;
    border-radius: 9px;
}

.item-image {
    display: block;
    height: auto;
    object-fit: contain;
    object-position: center;
    background: #ffffff;
    border: 1px solid #e0e6ed;
    padding: 7px;
}

.no-image {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #8490a0;
    font-size: 13px;
    background: #f0f3f7;
    border: 1px dashed #cbd5e1;
}

.item-id {
    color: #8490a0;
    font-size: 12px;
    margin-bottom: 12px;
}

/* VIEW BUTTON */

.view-button {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    min-height: 38px;
    padding: 9px 16px;
    color: #ffffff;
    background: #123c70;
    border: 1px solid #123c70;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 700;
    transition: 0.2s ease;
}

.view-button:hover {
    background: #1b518e;
    border-color: #1b518e;
}

/* EMPTY STATE */

.empty {
    background: #ffffff;
    border: 1px solid #e5eaf0;
    border-radius: 14px;
    padding: 55px 25px;
    text-align: center;
    box-shadow: 0 3px 13px rgba(20, 40, 70, 0.05);
}

.empty h2 {
    color: #173b6d;
    font-size: 19px;
    margin-bottom: 7px;
}

.empty p {
    color: #8490a0;
    font-size: 13px;
}

/* DATABASE ERROR */

.database-error {
    background: #fee2e2;
    border: 1px solid #fecaca;
    border-radius: 10px;
    padding: 15px 18px;
    margin-bottom: 20px;
    color: #991b1b;
    font-size: 13px;
}

/* FOOTER */

.page-footer {
    text-align: center;
    color: #8a96a5;
    font-size: 11px;
    padding: 20px 10px 0;
}

/* RESPONSIVE */

@media (max-width: 850px) {

    .navbar {
        padding: 8px 4%;
    }

    .admin-nav {
        overflow-x: auto;
    }

    .admin-nav a {
        flex-shrink: 0;
    }

    .container {
        width: 94%;
        margin-top: 24px;
    }

    .match-info-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

}

@media (max-width: 600px) {

    .navbar {
        min-height: 65px;
        padding: 8px 4%;
    }

    .brand-logo {
        width: 41px;
        height: 41px;
    }

    .brand-title {
        font-size: 17px;
    }

    .brand-subtitle {
        font-size: 9px;
    }

    .admin-nav {
        min-height: 55px;
        padding: 0 3%;
        overflow-x: auto;
    }

    .admin-nav a {
        min-height: 38px;
        padding: 0 12px;
        font-size: 11px;
        white-space: nowrap;
    }

    .container {
        width: 93%;
        margin-top: 20px;
    }

    .page-header {
        padding: 21px;
    }

    .page-header h1 {
        font-size: 22px;
    }

    .notice {
        flex-direction: column;
        gap: 5px;
        padding: 15px;
    }

    .match-card {
        padding: 17px;
    }

    .match-card-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .score-box {
        width: 100%;
    }

    .comparison {
        grid-template-columns: 1fr;
    }

    .match-info-grid {
        grid-template-columns: 1fr;
        gap: 11px;
    }

    .item-image,
    .no-image {
        max-width: 100%;
    }

}

</style>
</head>

<body>

<!-- BLUE MAIN NAVBAR -->

<header class="navbar">

    <a href="dashboard.php" class="brand">

        <div class="brand-text">
            <div class="brand-title">RTU Lost and Found</div>
            <div class="brand-subtitle">ADMINISTRATION</div>
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

    <a href="items.php">Manage Items</a>

    <a href="claims.php">Manage Claims</a>

    <a href="users.php">Users</a>

    <a href="ai_matches.php" class="active">AI Matches</a>

</nav>

<!-- MAIN CONTENT -->

<main class="container">

    <section class="page-header">

        <h1>AI Possible Matches</h1>

        <p>
            Review possible matches between reported lost items
            and found items based on their image similarity.
        </p>

    </section>

    <?php if (!empty($database_error)): ?>

        <div class="database-error">
            Unable to load AI matches. Please check your database connection and try again.
        </div>

    <?php endif; ?>

    <section class="notice">

        <div class="notice-label">
            Administrator Notice
        </div>

        <p>
            The AI-generated results are suggestions only.
            Administrators should manually compare the item information
            and images before making any decision.
        </p>

    </section>

    <h2 class="section-heading">
        Generated Match Results
    </h2>

    <?php if (!empty($matches)): ?>

        <?php foreach ($matches as $match): ?>

            <?php

            $score = (float) $match["similarity_score"];

            if ($score >= 80) {
                $level = "Very High Similarity";
                $level_class = "very-high";
            } elseif ($score >= 70) {
                $level = "High Similarity";
                $level_class = "high";
            } else {
                $level = "Possible Match";
                $level_class = "possible";
            }

            ?>

            <article class="match-card">

                <div class="match-card-header">

                    <div>

                        <h2 class="match-title">
                            Possible Item Match
                        </h2>

                        <span class="match-date">
                            Generated:
                            <?php
                            echo htmlspecialchars(
                                (string) ($match["created_at"] ?? ""),
                                ENT_QUOTES,
                                "UTF-8"
                            );
                            ?>
                        </span>

                        <span class="match-level <?php echo $level_class; ?>">
                            <?php echo htmlspecialchars($level, ENT_QUOTES, "UTF-8"); ?>
                        </span>

                    </div>

                    <div class="score-box">

                        <span class="score-label">
                            Similarity Score
                        </span>

                        <span class="score-value">
                            <?php echo number_format($score, 2); ?>%
                        </span>

                    </div>

                </div>

                <!-- MATCH INFORMATION -->

                <div class="match-info">

                    <h3>Match Information</h3>

                    <div class="match-info-grid">

                        <div class="info-item">

                            <span>Lost Item</span>

                            <strong>
                                <?php
                                echo htmlspecialchars(
                                    (string) ($match["lost_item_name"] ?? ""),
                                    ENT_QUOTES,
                                    "UTF-8"
                                );
                                ?>
                            </strong>

                        </div>

                        <div class="info-item">

                            <span>Found Item</span>

                            <strong>
                                <?php
                                echo htmlspecialchars(
                                    (string) ($match["found_item_name"] ?? ""),
                                    ENT_QUOTES,
                                    "UTF-8"
                                );
                                ?>
                            </strong>

                        </div>

                        <div class="info-item">

                            <span>Match Record</span>

                            <strong>
                                #<?php echo (int) $match["id"]; ?>
                            </strong>

                        </div>

                    </div>

                </div>

                <!-- ITEM COMPARISON -->

                <h3 class="comparison-heading">
                    Item Comparison
                </h3>

                <div class="comparison">

                    <!-- LOST ITEM -->

                    <section class="item-panel lost-panel">

                        <span class="item-type-label">
                            Lost Report
                        </span>

                        <h3>Lost Item</h3>

                        <p class="item-name">
                            <?php
                            echo htmlspecialchars(
                                (string) ($match["lost_item_name"] ?? ""),
                                ENT_QUOTES,
                                "UTF-8"
                            );
                            ?>
                        </p>

                        <?php if (!empty($match["lost_image"])): ?>

                            <img
                                src="../uploads/lost/<?php
                                    echo rawurlencode(
                                        basename((string) $match["lost_image"])
                                    );
                                ?>"
                                alt="Lost Item"
                                class="item-image"
                                loading="lazy"
                            >

                        <?php else: ?>

                            <div class="no-image">
                                No image available
                            </div>

                        <?php endif; ?>

                        <p class="item-id">
                            Item ID:
                            <?php echo (int) $match["lost_id"]; ?>
                        </p>

                        <a
                            href="../item.php?id=<?php echo (int) $match["lost_id"]; ?>"
                            class="view-button"
                        >
                            View Lost Item
                        </a>

                    </section>

                    <!-- FOUND ITEM -->

                    <section class="item-panel found-panel">

                        <span class="item-type-label">
                            Found Report
                        </span>

                        <h3>Found Item</h3>

                        <p class="item-name">
                            <?php
                            echo htmlspecialchars(
                                (string) ($match["found_item_name"] ?? ""),
                                ENT_QUOTES,
                                "UTF-8"
                            );
                            ?>
                        </p>

                        <?php if (!empty($match["found_image"])): ?>

                            <img
                                src="../uploads/found/<?php
                                    echo rawurlencode(
                                        basename((string) $match["found_image"])
                                    );
                                ?>"
                                alt="Found Item"
                                class="item-image"
                                loading="lazy"
                            >

                        <?php else: ?>

                            <div class="no-image">
                                No image available
                            </div>

                        <?php endif; ?>

                        <p class="item-id">
                            Item ID:
                            <?php echo (int) $match["found_id"]; ?>
                        </p>

                        <a
                            href="../item.php?id=<?php echo (int) $match["found_id"]; ?>"
                            class="view-button"
                        >
                            View Found Item
                        </a>

                    </section>

                </div>

            </article>

        <?php endforeach; ?>

    <?php else: ?>

        <section class="empty">

            <h2>No AI Matches Found</h2>

            <p>
                There are currently no generated AI matches to review.
            </p>

        </section>

    <?php endif; ?>

    <footer class="page-footer">
        RTU Lost and Found Administration
    </footer>

</main>

<script>

/* PREVENT BACK/FORWARD CACHE */

window.addEventListener("pageshow", function (event) {

    if (event.persisted) {
        window.location.reload();
    }

});

/* =========================================================
   REQUIRE AI MATCH ACCESS CODE
========================================================= */

if (
    !isset($_SESSION["ai_matches_verified_user"]) ||
    (int) $_SESSION["ai_matches_verified_user"] !== $user_id
) {
    header("Location: ai_matches_access.php");
    exit();
}

</script>

</body>
</html>
