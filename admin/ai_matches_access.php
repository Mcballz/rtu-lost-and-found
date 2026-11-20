
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

require_once __DIR__ . "/../config/supabase.php";

$user_id = (int) $_SESSION["user_id"];

try {
    $stmt = $supabase->prepare("
        SELECT role FROM users
        WHERE id = :user_id
        LIMIT 1
    ");
    $stmt->execute([":user_id" => $user_id]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_user || $current_user["role"] !== "admin") {
        header("Location: ../dashboard.php");
        exit();
    }

} catch (PDOException $e) {
    error_log("AI Access Role Error: " . $e->getMessage());
    exit("Unable to verify administrator access.");
}

if (
    isset($_SESSION["ai_matches_verified_user"]) &&
    (int) $_SESSION["ai_matches_verified_user"] === $user_id
) {
    header("Location: ai_matches.php");
    exit();
}

if (empty($_SESSION["ai_access_csrf"])) {
    $_SESSION["ai_access_csrf"] = bin2hex(random_bytes(32));
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $csrf = $_POST["csrf_token"] ?? "";

    if (
        !is_string($csrf) ||
        !hash_equals($_SESSION["ai_access_csrf"], $csrf)
    ) {
        $error = "Invalid request. Please refresh the page and try again.";

    } else {

        $access_code = $_POST["access_code"] ?? "";

        if (!is_string($access_code) || $access_code === "") {
            $error = "Please enter the access code.";

        } else {

            try {
                $stmt = $supabase->prepare("
                    SELECT password_hash
                    FROM admin_access_codes
                    WHERE access_key = :access_key
                    LIMIT 1
                ");

                $stmt->execute([
                    ":access_key" => "ai_matches"
                ]);

                $access_record = $stmt->fetch(PDO::FETCH_ASSOC);

                if (
                    $access_record &&
                    password_verify(
                        $access_code,
                        $access_record["password_hash"]
                    )
                ) {
                    session_regenerate_id(true);

                    $_SESSION["ai_matches_verified_user"] = $user_id;

                    unset($_SESSION["ai_access_csrf"]);

                    header("Location: ai_matches.php");
                    exit();

                } else {
                    $error = "Incorrect access code. Please try again.";
                }

            } catch (PDOException $e) {
                error_log("AI Access Verification Error: " . $e->getMessage());
                $error = "Unable to verify the access code. Please try again.";
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
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">

<title>AI Matches Access - RTU Lost and Found</title>

<style>
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
}

.brand-text {
    display: flex;
    flex-direction: column;
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
}

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
}

.admin-nav a:hover,
.admin-nav a.active {
    background: #edf3fa;
    color: #0f2f5f;
}

.admin-nav a.active {
    border-bottom: 3px solid #d4af37;
}

.container {
    width: 92%;
    max-width: 1200px;
    margin: 40px auto 55px;
}

.access-card {
    width: 100%;
    max-width: 480px;
    margin: 55px auto;
    background: #fff;
    border: 1px solid #e5eaf0;
    border-top: 4px solid #d4af37;
    border-radius: 14px;
    padding: 35px;
    box-shadow: 0 3px 14px rgba(20,40,70,0.06);
}

.access-card h1 {
    color: #123c70;
    font-size: 24px;
    margin-bottom: 8px;
}

.access-card p {
    color: #718096;
    font-size: 14px;
    margin-bottom: 24px;
}

.access-card label {
    display: block;
    color: #35465c;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 8px;
}

.access-card input {
    width: 100%;
    height: 46px;
    padding: 0 13px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font-size: 15px;
    outline: none;
}

.access-card input:focus {
    border-color: #164a8a;
    box-shadow: 0 0 0 3px rgba(22,74,138,0.10);
}

.submit-button {
    width: 100%;
    min-height: 45px;
    margin-top: 20px;
    border: none;
    border-radius: 7px;
    background: #123c70;
    color: white;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
}

.submit-button:hover {
    background: #1b518e;
}

.error-message {
    background: #fee2e2;
    border: 1px solid #fecaca;
    color: #991b1b;
    padding: 11px 13px;
    border-radius: 7px;
    font-size: 13px;
    margin-bottom: 18px;
}

.page-footer {
    text-align: center;
    color: #8a96a5;
    font-size: 11px;
    padding: 20px 10px;
}

@media (max-width: 600px) {
    .brand-title {
        font-size: 17px;
    }

    .brand-subtitle {
        font-size: 9px;
    }

    .brand-logo {
        width: 41px;
        height: 41px;
    }

    .admin-nav {
        overflow-x: auto;
        padding: 0 3%;
    }

    .admin-nav a {
        flex-shrink: 0;
        white-space: nowrap;
        padding: 0 12px;
        font-size: 11px;
    }

    .access-card {
        padding: 25px 20px;
        margin: 35px auto;
    }
}
</style>
</head>

<body>

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

<nav class="admin-nav">
    <a href="items.php">Manage Items</a>
    <a href="claims.php">Manage Claims</a>
    <a href="users.php">Users</a>
    <a href="ai_matches_access.php" class="active">AI Matches</a>
</nav>

<main class="container">

    <section class="access-card">

        <h1>AI Matches Access</h1>

        <p>
            Enter the administrator access code to view
            AI-generated possible item matches.
        </p>

        <?php if ($error !== ""): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo htmlspecialchars(
                    $_SESSION["ai_access_csrf"],
                    ENT_QUOTES,
                    "UTF-8"
                ); ?>"
            >

            <label for="access_code">Access Code</label>

            <input
                type="password"
                id="access_code"
                name="access_code"
                placeholder="Enter AI Matches access code"
                required
                autocomplete="off"
                autofocus
            >

            <button type="submit" class="submit-button">
                Verify Access
            </button>

        </form>

    </section>

    <footer class="page-footer">
        RTU Lost and Found Administration
    </footer>

</main>

<script>
window.addEventListener("pageshow", function(event) {
    if (event.persisted) {
        window.location.reload();
    }
});
</script>

</body>
</html>
