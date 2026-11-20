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

$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();

$current_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current_user || $current_user["role"] !== "admin") {
    die("Access denied. Admins only.");
}

/* =========================================================
   DELETE USER
========================================================= */

if (isset($_GET["delete"])) {
    $delete_id = (int) $_GET["delete"];

    // Prevent an admin from deleting their own account.
    if ($delete_id > 0 && $delete_id !== $user_id) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: users.php");
    exit();
}

/* =========================================================
   UPDATE USER
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_user"])) {
    $edit_id = (int) ($_POST["user_id"] ?? 0);
    $name = trim($_POST["name"] ?? "");
    $student_id = trim($_POST["student_id"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $role = $_POST["role"] ?? "";

    if (
        $edit_id > 0 &&
        $name !== "" &&
        $student_id !== "" &&
        filter_var($email, FILTER_VALIDATE_EMAIL) &&
        in_array($role, ["student", "admin"], true)
    ) {
        $stmt = $conn->prepare("
            UPDATE users
            SET name = ?, student_id = ?, email = ?, role = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "ssssi",
            $name,
            $student_id,
            $email,
            $role,
            $edit_id
        );

        $stmt->execute();
        $stmt->close();
    }

    header("Location: users.php");
    exit();
}

/* =========================================================
   CHANGE PASSWORD
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["change_password"])) {
    $password_user_id = (int) ($_POST["password_user_id"] ?? 0);
    $new_password = $_POST["new_password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (
        $password_user_id > 0 &&
        $password_user_id !== $user_id &&
        $new_password !== "" &&
        $new_password === $confirm_password
    ) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            UPDATE users
            SET password = ?
            WHERE id = ?
        ");

        $stmt->bind_param("si", $hashed_password, $password_user_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: users.php");
    exit();
}

/* =========================================================
   GET USERS
========================================================= */

$users = $conn->query("
    SELECT id, student_id, name, email, role, created_at
    FROM users
    ORDER BY created_at DESC
");

/* =========================================================
   SELECT USER FOR EDITING
========================================================= */

$edit_user = null;

if (isset($_GET["edit"])) {
    $edit_id = (int) $_GET["edit"];

    $stmt = $conn->prepare("
        SELECT id, student_id, name, email, role
        FROM users
        WHERE id = ?
    ");

    $stmt->bind_param("i", $edit_id);
    $stmt->execute();

    $edit_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
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

html {
    scroll-behavior: smooth;
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
    line-height: 1.6;
}

/* FORM CARD */

.form-card,
.table-card {
    background: white;
    padding: 26px;
    border-radius: 14px;
    border-top: 3px solid #d4af37;
    box-shadow: 0 3px 14px rgba(0, 0, 0, 0.06);
}

.form-card {
    margin-bottom: 25px;
}

.form-card h2,
.table-card h2 {
    color: #0f2f5f;
    font-size: 21px;
    margin-bottom: 20px;
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
    box-shadow: 0 0 0 3px rgba(15, 47, 95, 0.10);
}

.form-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    flex-wrap: wrap;
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

.save,
.edit {
    background: #0f2f5f;
}

.save:hover,
.edit:hover {
    background: #164a8a;
    transform: translateY(-1px);
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

/* TABLE */

.table-card {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
}

th,
td {
    padding: 14px 13px;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
    font-size: 13px;
    vertical-align: top;
}

th {
    background: #f8fafc;
    color: #334155;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

tbody tr:hover td {
    background: #f8fbff;
}

/* ROLE BADGES */

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

/* USER ACTIONS */

.actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.self {
    display: inline-block;
    color: #64748b;
    font-size: 11px;
    font-weight: 600;
    padding: 5px 8px;
    background: #f1f5f9;
    border-radius: 6px;
}

/* PASSWORD CARD */

.password-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 17px;
    margin-top: 12px;
    border-radius: 10px;
    min-width: 230px;
}

.password-card h3 {
    color: #334155;
    font-size: 14px;
    margin-bottom: 15px;
}

.password-card .form-group {
    margin-bottom: 12px;
}

.password-card .form-group label {
    font-size: 12px;
}

.password-card .form-group input {
    padding: 9px 10px;
    font-size: 13px;
}

/* EMPTY STATE */

.empty {
    padding: 45px 25px;
    text-align: center;
    color: #64748b;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    font-size: 14px;
}

/* RESPONSIVE */

@media (max-width: 850px) {
    .navbar {
        padding: 12px 4%;
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

    .admin-nav .user-dashboard {
        margin-left: 0;
    }
}

@media (max-width: 600px) {
    .navbar {
        gap: 12px;
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

    .admin-nav {
        gap: 5px;
    }

    .admin-nav a {
        padding: 8px 9px;
        font-size: 12px;
    }

    .page-header,
    .form-card,
    .table-card {
        padding: 20px;
    }

    .page-header h1 {
        font-size: 23px;
    }

    .form-actions {
        flex-direction: column;
    }

    .form-actions .button,
    .form-actions button {
        width: 100%;
    }
}

/* PRINT */

@media print {
    .navbar,
    .admin-nav,
    .form-card {
        display: none;
    }

    body {
        background: white;
    }

    .table-card {
        box-shadow: none;
        border: 1px solid #ddd;
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
    <a href="claims.php">Manage Claims</a>
    <a href="users.php" class="active">Users</a>
    <a href="ai_matches.php">AI Matches</a>

</nav>

<!-- MAIN CONTENT -->

<main class="container">

    <section class="page-header">
        <h1>Manage Users</h1>
        <p>View and manage registered users and administrator accounts.</p>
    </section>

    <!-- EDIT USER -->

    <?php if ($edit_user): ?>

        <section class="form-card">

            <h2>Edit User</h2>

            <form method="POST" action="users.php">

                <input
                    type="hidden"
                    name="user_id"
                    value="<?php echo (int) $edit_user["id"]; ?>"
                >

                <div class="form-group">
                    <label for="name">Name</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="<?php echo htmlspecialchars($edit_user["name"]); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="student_id">Student ID</label>
                    <input
                        type="text"
                        id="student_id"
                        name="student_id"
                        value="<?php echo htmlspecialchars($edit_user["student_id"]); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?php echo htmlspecialchars($edit_user["email"]); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role" required>
                        <option
                            value="student"
                            <?php echo $edit_user["role"] === "student" ? "selected" : ""; ?>
                        >
                            Student
                        </option>

                        <option
                            value="admin"
                            <?php echo $edit_user["role"] === "admin" ? "selected" : ""; ?>
                        >
                            Admin
                        </option>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="submit" name="update_user" class="save">
                        Save Changes
                    </button>

                    <a href="users.php" class="button cancel">
                        Cancel
                    </a>
                </div>

            </form>

        </section>

    <?php endif; ?>

    <!-- REGISTERED USERS -->

    <section class="table-card">

        <h2>Registered Users</h2>

        <?php if ($users && $users->num_rows > 0): ?>

            <table>

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Student ID</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Date Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php while ($row = $users->fetch_assoc()): ?>

                        <tr>

                            <td><?php echo (int) $row["id"]; ?></td>

                            <td>
                                <?php echo htmlspecialchars($row["name"]); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($row["student_id"]); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($row["email"]); ?>
                            </td>

                            <td>
                                <span class="role role-<?php echo htmlspecialchars($row["role"]); ?>">
                                    <?php echo htmlspecialchars(ucfirst($row["role"])); ?>
                                </span>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($row["created_at"]); ?>
                            </td>

                            <td>

                                <div class="actions">

                                    <a
                                        href="users.php?edit=<?php echo (int) $row["id"]; ?>"
                                        class="button edit"
                                    >
                                        Edit
                                    </a>

                                    <?php if ((int) $row["id"] !== $user_id): ?>

                                        <a
                                            href="users.php?delete=<?php echo (int) $row["id"]; ?>"
                                            class="button delete"
                                            onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.');"
                                        >
                                            Delete
                                        </a>

                                    <?php else: ?>

                                        <span class="self">Current Account</span>

                                    <?php endif; ?>

                                </div>

                                <?php if ((int) $row["id"] !== $user_id): ?>

                                    <div class="password-card">

                                        <h3>Change Password</h3>

                                        <form method="POST" action="users.php">

                                            <input
                                                type="hidden"
                                                name="password_user_id"
                                                value="<?php echo (int) $row["id"]; ?>"
                                            >

                                            <div class="form-group">
                                                <label>New Password</label>
                                                <input
                                                    type="password"
                                                    name="new_password"
                                                    autocomplete="new-password"
                                                    required
                                                >
                                            </div>

                                            <div class="form-group">
                                                <label>Confirm Password</label>
                                                <input
                                                    type="password"
                                                    name="confirm_password"
                                                    autocomplete="new-password"
                                                    required
                                                >
                                            </div>

                                            <button
                                                type="submit"
                                                name="change_password"
                                                class="button password"
                                                onclick="return confirm('Change this user password?');"
                                            >
                                                Change Password
                                            </button>

                                        </form>

                                    </div>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                </tbody>

            </table>

        <?php else: ?>

            <div class="empty">
                No registered users found.
            </div>

        <?php endif; ?>

    </section>

</main>

<script>
const profileMenu = document.getElementById("profileMenu");
const profileButton = document.getElementById("profileButton");

profileButton.addEventListener("click", function () {
    profileMenu.classList.toggle("open");
});

document.addEventListener("click", function (event) {
    if (!profileMenu.contains(event.target)) {
        profileMenu.classList.remove("open");
    }
});

window.addEventListener("pageshow", function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});
</script>

</body>
</html>