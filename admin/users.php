
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
   AUTHENTICATION
========================================================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . "/../config/supabase.php";

$user_id = (int) $_SESSION["user_id"];

/* =========================================================
   CHECK ADMIN
========================================================= */

$stmt = $supabase->prepare("
    SELECT role
    FROM users
    WHERE id = :user_id
");

$stmt->execute([
    "user_id" => $user_id
]);

$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_user || $current_user["role"] !== "admin") {
    http_response_code(403);
    die("Access denied. Admins only.");
}

/* =========================================================
   CSRF TOKEN
========================================================= */

if (empty($_SESSION["users_csrf"])) {
    $_SESSION["users_csrf"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["users_csrf"];

/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function redirectUsers($student_id = "")
{
    $url = "users.php";

    if ($student_id !== "") {
        $url .= "?" . http_build_query([
            "student_id" => $student_id
        ]);
    }

    header("Location: " . $url);
    exit();
}

function verifyUsersCsrf()
{
    $submitted = $_POST["csrf_token"] ?? "";
    $stored = $_SESSION["users_csrf"] ?? "";

    if (
        $stored === "" ||
        !is_string($submitted) ||
        !hash_equals($stored, $submitted)
    ) {
        http_response_code(403);
        die("Invalid security token. Please refresh the page and try again.");
    }
}

/* =========================================================
   SESSION NOTIFICATIONS
========================================================= */

$users_error = $_SESSION["users_error"] ?? "";
$users_success = $_SESSION["users_success"] ?? "";

unset($_SESSION["users_error"], $_SESSION["users_success"]);

/* =========================================================
   DELETE USER
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_user"])) {

    verifyUsersCsrf();

    $delete_id = (int) ($_POST["delete_user_id"] ?? 0);
    $search_id = trim($_POST["search_student_id"] ?? "");

    if ($delete_id <= 0 || $delete_id === $user_id) {

        $_SESSION["users_error"] = "You cannot delete your own administrator account.";

    } else {

        try {

            $stmt = $supabase->prepare("
                DELETE FROM users
                WHERE id = :user_id
            ");

            $stmt->execute([
                "user_id" => $delete_id
            ]);

            $_SESSION["users_success"] = "User account deleted successfully.";

        } catch (Throwable $e) {

            error_log("Admin user deletion error: " . $e->getMessage());

            $_SESSION["users_error"] =
                "Unable to delete this account. It may still be connected to other records.";

        }

    }

    redirectUsers($search_id);
}

/* =========================================================
   UPDATE USER INFORMATION
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_user"])) {

    verifyUsersCsrf();

    $edit_id = (int) ($_POST["user_id"] ?? 0);

    $name = trim($_POST["name"] ?? "");
    $student_id = trim($_POST["student_id"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $role = $_POST["role"] ?? "";
    $status = $_POST["status"] ?? "";

    $search_id = trim($_POST["search_student_id"] ?? "");

    if (
        $edit_id <= 0 ||
        $name === "" ||
        $student_id === "" ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !in_array($role, ["student", "admin"], true) ||
        !in_array($status, ["active", "inactive"], true)
    ) {

        $_SESSION["users_error"] = "Invalid information. Please check all fields.";

        redirectUsers($search_id);

    }

    // Prevent accidental removal of the current administrator's own admin role.
    if ($edit_id === $user_id && $role !== "admin") {

        $_SESSION["users_error"] =
            "You cannot remove your own administrator privileges.";

        redirectUsers($search_id);

    }

    try {

        // Check duplicate Student ID.
        $stmt = $supabase->prepare("
            SELECT id
            FROM users
            WHERE student_id = :student_id
            AND id <> :user_id
            LIMIT 1
        ");

        $stmt->execute([
            "student_id" => $student_id,
            "user_id" => $edit_id
        ]);

        if ($stmt->fetch()) {

            $_SESSION["users_error"] =
                "That Student ID is already registered.";

            redirectUsers($search_id);

        }

        // Check duplicate email.
        $stmt = $supabase->prepare("
            SELECT id
            FROM users
            WHERE LOWER(email) = LOWER(:email)
            AND id <> :user_id
            LIMIT 1
        ");

        $stmt->execute([
            "email" => $email,
            "user_id" => $edit_id
        ]);

        if ($stmt->fetch()) {

            $_SESSION["users_error"] =
                "That email address is already registered.";

            redirectUsers($search_id);

        }

        // Update account.
        $stmt = $supabase->prepare("
            UPDATE users
            SET
                name = :name,
                student_id = :student_id,
                email = :email,
                role = :role,
                status = :status
            WHERE id = :user_id
        ");

        $stmt->execute([
            "name" => $name,
            "student_id" => $student_id,
            "email" => $email,
            "role" => $role,
            "status" => $status,
            "user_id" => $edit_id
        ]);

        $_SESSION["users_success"] =
            "User information updated successfully.";

        redirectUsers($student_id);

    } catch (Throwable $e) {

        error_log("Admin user update error: " . $e->getMessage());

        $_SESSION["users_error"] =
            "Unable to update user information. Please try again.";

        redirectUsers($search_id);

    }
}

/* =========================================================
   CHANGE PASSWORD
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["change_password"])) {

    verifyUsersCsrf();

    $password_user_id = (int) ($_POST["password_user_id"] ?? 0);

    $new_password = $_POST["new_password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    $search_id = trim($_POST["search_student_id"] ?? "");

    if ($password_user_id <= 0 || $password_user_id === $user_id) {

        $_SESSION["users_error"] =
            "Invalid password change request.";

        redirectUsers($search_id);

    }

    if (strlen($new_password) < 8) {

        $_SESSION["users_error"] =
            "The new password must contain at least 8 characters.";

        redirectUsers($search_id);

    }

    if ($new_password !== $confirm_password) {

        $_SESSION["users_error"] =
            "The new password and confirmation password do not match.";

        redirectUsers($search_id);

    }

    try {

        // Verify that the target user exists.
        $stmt = $supabase->prepare("
            SELECT id
            FROM users
            WHERE id = :user_id
        ");

        $stmt->execute([
            "user_id" => $password_user_id
        ]);

        if (!$stmt->fetch()) {

            $_SESSION["users_error"] =
                "The selected user could not be found.";

            redirectUsers($search_id);

        }

        $hashed_password = password_hash(
            $new_password,
            PASSWORD_DEFAULT
        );

        $stmt = $supabase->prepare("
            UPDATE users
            SET password = :password
            WHERE id = :user_id
        ");

        $stmt->execute([
            "password" => $hashed_password,
            "user_id" => $password_user_id
        ]);

        $_SESSION["users_success"] =
            "User password changed successfully.";

    } catch (Throwable $e) {

        error_log("Admin password change error: " . $e->getMessage());

        $_SESSION["users_error"] =
            "Unable to change the user's password.";

    }

    redirectUsers($search_id);
}

/* =========================================================
   SEARCH USER BY STUDENT ID
========================================================= */

$search_student_id = trim($_GET["student_id"] ?? "");

$found_user = null;
$edit_mode = isset($_GET["edit"]);

if ($search_student_id !== "") {

    try {

        $stmt = $supabase->prepare("
            SELECT
                id,
                student_id,
                name,
                email,
                role,
                status,
                created_at
            FROM users
            WHERE student_id = :student_id
            LIMIT 1
        ");

        $stmt->execute([
            "student_id" => $search_student_id
        ]);

        $found_user = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {

        error_log("Student ID search error: " . $e->getMessage());

        $users_error = "Unable to search for this student right now.";

    }

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

<title>Manage Users - RTU Lost and Found</title>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f3f6fb;
    color: #1f2937;
}

/* NAVBAR */

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
}

.brand-title {
    color: #fff;
    font-size: 20px;
    font-weight: bold;
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

/* MAIN */

.container {
    width: 92%;
    max-width: 1100px;
    margin: 28px auto 50px;
}

/* PAGE HEADER */

.page-header {
    background: white;
    padding: 25px 28px;
    border-radius: 16px;
    margin-bottom: 25px;
    border-top: 4px solid #d4af37;
    box-shadow: 0 3px 14px rgba(0,0,0,0.07);
}

.page-header h1 {
    color: #0f2f5f;
    font-size: 27px;
    margin-bottom: 5px;
}

.page-header p {
    color: #6b7280;
    font-size: 14px;
    line-height: 1.6;
}

/* CARDS */

.search-card,
.profile-card,
.form-card {
    background: white;
    padding: 26px;
    border-radius: 14px;
    border-top: 3px solid #d4af37;
    box-shadow: 0 3px 14px rgba(0,0,0,0.06);
    margin-bottom: 25px;
}

.search-card h2,
.profile-card h2,
.form-card h2 {
    color: #0f2f5f;
    font-size: 21px;
    margin-bottom: 18px;
}

/* SEARCH */

.search-form {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.search-form input {
    flex: 1;
    min-width: 220px;
    padding: 13px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 15px;
}

.search-form input:focus {
    outline: none;
    border-color: #0f2f5f;
    box-shadow: 0 0 0 3px rgba(15,47,95,0.10);
}

.search-button {
    background: #0f2f5f;
    padding: 13px 24px;
}

.search-button:hover {
    background: #164a8a;
}

/* PROFILE */

.profile-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}

.profile-heading h2 {
    margin-bottom: 5px;
}

.profile-heading p {
    color: #64748b;
    font-size: 13px;
}

.profile-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.profile-field {
    padding: 16px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 9px;
}

.profile-field label {
    display: block;
    color: #64748b;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    margin-bottom: 7px;
}

.profile-field p {
    color: #1e293b;
    font-size: 14px;
    font-weight: 600;
    overflow-wrap: anywhere;
}

/* FORM FIELDS */

.form-group {
    margin-bottom: 17px;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: bold;
    color: #334155;
    margin-bottom: 7px;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: white;
    color: #1f2937;
    font-size: 14px;
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: #0f2f5f;
    box-shadow: 0 0 0 3px rgba(15,47,95,0.10);
}

/* BUTTONS */

button,
.button {
    display: inline-block;
    border: none;
    padding: 10px 15px;
    border-radius: 8px;
    color: white;
    text-decoration: none;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    text-align: center;
    transition: 0.18s ease;
}

button:hover,
.button:hover {
    transform: translateY(-1px);
}

.search-button,
.save,
.edit {
    background: #0f2f5f;
}

.search-button:hover,
.save:hover,
.edit:hover {
    background: #164a8a;
}

.cancel {
    background: #64748b;
}

.cancel:hover {
    background: #475569;
}

.password {
    background: #6d28d9;
}

.password:hover {
    background: #5b21b6;
}

.delete {
    background: #dc2626;
}

.delete:hover {
    background: #b91c1c;
}

.form-actions,
.profile-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 20px;
}

/* STATUS */

.status {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
}

.status-active {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
}

.status-inactive {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.role {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: bold;
}

.role-admin {
    background: #fff4c7;
    color: #8a6b00;
    border: 1px solid #e6cc68;
}

.role-student {
    background: #e8f1ff;
    color: #0b3d91;
    border: 1px solid #c5d9f5;
}

/* PASSWORD CARD */

.password-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 20px;
    border-radius: 10px;
    margin-top: 20px;
}

.password-card h3 {
    color: #334155;
    font-size: 16px;
    margin-bottom: 16px;
}

/* ALERTS */

.alert {
    padding: 14px 18px;
    margin-bottom: 20px;
    border-radius: 9px;
    font-size: 14px;
    line-height: 1.5;
}

.alert-error {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
}

.alert-success {
    background: #dcfce7;
    border: 1px solid #86efac;
    color: #166534;
}

/* EMPTY STATE */

.empty {
    padding: 35px 25px;
    text-align: center;
    color: #64748b;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    font-size: 14px;
}

.empty h3 {
    color: #334155;
    margin-bottom: 8px;
    font-size: 17px;
}

/* RESPONSIVE */

@media (max-width: 650px) {

    .navbar {
        padding: 12px 4%;
    }

    .brand-title {
        font-size: 18px;
    }

    .brand-logo {
        width: 42px;
        height: 42px;
    }

    .admin-nav {
        padding: 10px 4%;
    }

    .container {
        width: 94%;
    }

    .page-header,
    .search-card,
    .profile-card,
    .form-card {
        padding: 20px;
    }

    .page-header h1 {
        font-size: 23px;
    }

    .profile-grid {
        grid-template-columns: 1fr;
    }

    .search-form {
        flex-direction: column;
    }

    .search-form input,
    .search-form button {
        width: 100%;
    }

    .form-actions,
    .profile-actions {
        flex-direction: column;
    }

    .form-actions button,
    .form-actions a,
    .profile-actions button,
    .profile-actions a {
        width: 100%;
    }
}

@media print {
    .navbar,
    .admin-nav,
    .search-card,
    .form-card,
    .profile-actions {
        display: none;
    }

    body {
        background: white;
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

<nav class="admin-nav">

    <a href="items.php">Manage Items</a>
    <a href="users.php" class="active">Users</a>

</nav>

<main class="container">

    <section class="page-header">

        <h1>Manage Users</h1>

        <p>
            Search for registered student accounts using their Student ID.
            View profiles and manage account information.
        </p>

    </section>

    <?php if ($users_error !== ""): ?>

        <div class="alert alert-error">
            <?php echo htmlspecialchars($users_error); ?>
        </div>

    <?php endif; ?>

    <?php if ($users_success !== ""): ?>

        <div class="alert alert-success">
            <?php echo htmlspecialchars($users_success); ?>
        </div>

    <?php endif; ?>

    <!-- STUDENT ID SEARCH -->

    <section class="search-card">

        <h2>Search Student Account</h2>

        <form method="GET" action="users.php" class="search-form">

            <input
                type="text"
                name="student_id"
                placeholder="Enter Student ID"
                value="<?php echo htmlspecialchars($search_student_id); ?>"
                required
                autocomplete="off"
            >

            <button type="submit" class="search-button">
                Search Student
            </button>

        </form>

    </section>

    <!-- SEARCH RESULT -->

    <?php if ($search_student_id !== "" && !$found_user): ?>

        <section class="profile-card">

            <div class="empty">

                <h3>No Student Found</h3>

                No account matches Student ID:
                <strong>
                    <?php echo htmlspecialchars($search_student_id); ?>
                </strong>

                <p style="margin-top: 10px;">
                    Please check the Student ID and search again.
                </p>

            </div>

        </section>

    <?php endif; ?>

    <?php if ($found_user): ?>

        <?php if ($edit_mode): ?>

            <!-- EDIT PROFILE -->

            <section class="form-card">

                <h2>Edit User Information</h2>

                <form method="POST" action="users.php">

                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <input type="hidden" name="user_id"
                        value="<?php echo (int) $found_user["id"]; ?>">

                    <input type="hidden" name="search_student_id"
                        value="<?php echo htmlspecialchars($search_student_id); ?>">

                    <div class="form-group">

                        <label for="name">Full Name</label>

                        <input
                            type="text"
                            id="name"
                            name="name"
                            value="<?php echo htmlspecialchars($found_user["name"] ?? ""); ?>"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label for="student_id">Student ID</label>

                        <input
                            type="text"
                            id="student_id"
                            name="student_id"
                            value="<?php echo htmlspecialchars($found_user["student_id"] ?? ""); ?>"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label for="email">Email Address</label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?php echo htmlspecialchars($found_user["email"] ?? ""); ?>"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label for="role">Account Role</label>

                        <select id="role" name="role" required>

                            <option value="student"
                                <?php echo $found_user["role"] === "student" ? "selected" : ""; ?>>
                                Student
                            </option>

                            <option value="admin"
                                <?php echo $found_user["role"] === "admin" ? "selected" : ""; ?>>
                                Administrator
                            </option>

                        </select>

                    </div>

                    <div class="form-group">

                        <label for="status">Account Status</label>

                        <select id="status" name="status" required>

                            <option value="active"
                                <?php echo $found_user["status"] === "active" ? "selected" : ""; ?>>
                                Active
                            </option>

                            <option value="inactive"
                                <?php echo $found_user["status"] === "inactive" ? "selected" : ""; ?>>
                                Inactive
                            </option>

                        </select>

                    </div>

                    <div class="form-actions">

                        <button
                            type="submit"
                            name="update_user"
                            class="save"
                        >
                            Save Changes
                        </button>

                        <a
                            href="users.php?student_id=<?php echo urlencode($search_student_id); ?>"
                            class="button cancel"
                        >
                            Cancel
                        </a>

                    </div>

                </form>

            </section>

        <?php else: ?>

            <!-- USER PROFILE -->

            <section class="profile-card">

                <div class="profile-heading">

                    <div>

                        <h2>Student Profile</h2>

                        <p>
                            Account information retrieved from Supabase.
                        </p>

                    </div>

                    <span class="status status-<?php echo htmlspecialchars($found_user["status"]); ?>">
                        <?php echo htmlspecialchars(ucfirst($found_user["status"])); ?>
                    </span>

                </div>

                <div class="profile-grid">

                    <div class="profile-field">

                        <label>Database User ID</label>

                        <p>
                            <?php echo (int) $found_user["id"]; ?>
                        </p>

                    </div>

                    <div class="profile-field">

                        <label>Student ID</label>

                        <p>
                            <?php echo htmlspecialchars($found_user["student_id"]); ?>
                        </p>

                    </div>

                    <div class="profile-field">

                        <label>Full Name</label>

                        <p>
                            <?php echo htmlspecialchars($found_user["name"]); ?>
                        </p>

                    </div>

                    <div class="profile-field">

                        <label>Email Address</label>

                        <p>
                            <?php echo htmlspecialchars($found_user["email"]); ?>
                        </p>

                    </div>

                    <div class="profile-field">

                        <label>Account Role</label>

                        <p>
                            <span class="role role-<?php echo htmlspecialchars($found_user["role"]); ?>">
                                <?php echo htmlspecialchars(ucfirst($found_user["role"])); ?>
                            </span>
                        </p>

                    </div>

                    <div class="profile-field">

                        <label>Account Status</label>

                        <p>
                            <?php echo htmlspecialchars(ucfirst($found_user["status"])); ?>
                        </p>

                    </div>

                    <div class="profile-field">

                        <label>Date Registered</label>

                        <p>
                            <?php echo htmlspecialchars($found_user["created_at"] ?? "Not available"); ?>
                        </p>

                    </div>

                </div>

                <div class="profile-actions">

                    <a
                        href="users.php?student_id=<?php echo urlencode($search_student_id); ?>&edit=1"
                        class="button edit"
                    >
                        Edit Profile
                    </a>

                    <?php if ((int) $found_user["id"] !== $user_id): ?>

                        <form method="POST" action="users.php"
                            onsubmit="return confirm('Are you sure you want to delete this account? This action cannot be undone.');">

                            <input type="hidden" name="csrf_token"
                                value="<?php echo htmlspecialchars($csrf_token); ?>">

                            <input type="hidden" name="delete_user_id"
                                value="<?php echo (int) $found_user["id"]; ?>">

                            <input type="hidden" name="search_student_id"
                                value="<?php echo htmlspecialchars($search_student_id); ?>">

                            <button type="submit" name="delete_user" class="delete">
                                Delete Account
                            </button>

                        </form>

                    <?php endif; ?>

                </div>

            </section>

            <!-- CHANGE PASSWORD -->

            <?php if ((int) $found_user["id"] !== $user_id): ?>

                <section class="form-card">

                    <div class="password-card">

                        <h3>Change Student Password</h3>

                        <form method="POST" action="users.php">

                            <input type="hidden" name="csrf_token"
                                value="<?php echo htmlspecialchars($csrf_token); ?>">

                            <input type="hidden" name="password_user_id"
                                value="<?php echo (int) $found_user["id"]; ?>">

                            <input type="hidden" name="search_student_id"
                                value="<?php echo htmlspecialchars($search_student_id); ?>">

                            <div class="form-group">

                                <label for="new_password">
                                    New Password
                                </label>

                                <input
                                    type="password"
                                    id="new_password"
                                    name="new_password"
                                    minlength="8"
                                    autocomplete="new-password"
                                    required
                                >

                            </div>

                            <div class="form-group">

                                <label for="confirm_password">
                                    Confirm New Password
                                </label>

                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    minlength="8"
                                    autocomplete="new-password"
                                    required
                                >

                            </div>

                            <button
                                type="submit"
                                name="change_password"
                                class="password"
                                onclick="return confirm('Are you sure you want to change this student password?');"
                            >
                                Change Password
                            </button>

                        </form>

                    </div>

                </section>

            <?php endif; ?>

        <?php endif; ?>

    <?php endif; ?>

</main>

<script>

/* PREVENT BACK/FORWARD CACHE */

window.addEventListener("pageshow", function(event) {

    if (event.persisted) {
        window.location.reload();
    }

});

/* PASSWORD CONFIRMATION */

const passwordForm = document.querySelector(
    'form input[name="new_password"]'
);

if (passwordForm) {

    const form = passwordForm.closest("form");

    form.addEventListener("submit", function(event) {

        const password = form.querySelector(
            '[name="new_password"]'
        ).value;

        const confirmPassword = form.querySelector(
            '[name="confirm_password"]'
        ).value;

        if (password !== confirmPassword) {

            event.preventDefault();

            alert("The passwords do not match.");

        }

    });

}

</script>

</body>
</html>
