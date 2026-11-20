
<?php

session_start();

/* PREVENT BROWSER CACHING */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

/* LOGIN CHECK */
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

/* DATABASE */
require_once "config/database.php";

$user_id = (int) $_SESSION["user_id"];
$error = "";
$success = "";

/* CURRENT USER */
$user_stmt = $conn->prepare("
    SELECT name, email, role
    FROM users
    WHERE id = ?
");

$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();

$current_user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

$current_name = $current_user["name"] ?? "User";
$current_email = $current_user["email"] ?? "";

$profile_initial = strtoupper(substr(trim($current_name), 0, 1));

if ($profile_initial === "") {
    $profile_initial = "U";
}

/* FORM SUBMISSION */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $item_name = trim($_POST["item_name"] ?? "");
    $category = trim($_POST["category"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $location = trim($_POST["location"] ?? "");
    $date_reported = $_POST["date_reported"] ?? "";

    /* VALIDATION */
    if (
        $item_name === "" ||
        $category === "" ||
        $description === "" ||
        $location === "" ||
        $date_reported === ""
    ) {
        $error = "Please fill in all required fields.";

    } elseif (
        !isset($_FILES["image"]) ||
        $_FILES["image"]["error"] !== UPLOAD_ERR_OK
    ) {
        $error = "Please upload an image of the found item.";

    } else {

        $image = $_FILES["image"];

        $allowed_extensions = [
            "jpg",
            "jpeg",
            "png",
            "webp"
        ];

        $extension = strtolower(
            pathinfo($image["name"], PATHINFO_EXTENSION)
        );

        /* IMAGE VALIDATION */
        if (!in_array($extension, $allowed_extensions, true)) {
            $error = "Invalid image format. Please use JPG, JPEG, PNG, or WEBP.";

        } elseif ($image["size"] > 10 * 1024 * 1024) {
            $error = "Image is too large. Maximum size is 10MB.";

        } else {

            /* UPLOAD FOLDER */
            $upload_folder = "uploads/found/";

            if (!is_dir($upload_folder)) {
                if (!mkdir($upload_folder, 0777, true) && !is_dir($upload_folder)) {
                    $error = "Failed to create the image upload folder.";
                }
            }

            if ($error === "") {

                /* IMAGE NAME */
                $image_name = "found_" . bin2hex(random_bytes(6)) . "." . $extension;
                $image_path = $upload_folder . $image_name;

                /* MOVE IMAGE */
                if (!move_uploaded_file($image["tmp_name"], $image_path)) {
                    $error = "Failed to upload image.";

                } else {

                    /* INSERT FOUND ITEM */
                    $insert_stmt = $conn->prepare("
                        INSERT INTO items
                        (
                            user_id,
                            item_type,
                            item_name,
                            category,
                            description,
                            location,
                            date_reported,
                            image,
                            status
                        )
                        VALUES
                        (
                            ?,
                            'found',
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            'found'
                        )
                    ");

                    if (!$insert_stmt) {
                        $error = "Unable to prepare the found-item record.";

                    } else {

                        $insert_stmt->bind_param(
                            "issssss",
                            $user_id,
                            $item_name,
                            $category,
                            $description,
                            $location,
                            $date_reported,
                            $image_name
                        );

                        if (!$insert_stmt->execute()) {
                            $error = "Failed to save the found item.";

                            if (file_exists($image_path)) {
                                unlink($image_path);
                            }

                        } else {

                            /*
                             * OLD AUTOMATIC AI MATCHING REMOVED.
                             *
                             * The newer hybrid AI matching process
                             * remains separate and is accessed through
                             * the matching feature in My Reports.
                             */

                            $success = "Found item reported successfully.";
                        }

                        $insert_stmt->close();
                    }
                }
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

<meta http-equiv="Pragma" content="no-cache">

<meta http-equiv="Expires" content="0">

<title>Report Found Item - RTU Lost and Found</title>

<style>

/* GLOBAL */

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f3f6fb;
    color: #1f2937;
    min-height: 100vh;
}

/* NAVBAR */

.navbar {
    width: 100%;
    min-height: 74px;
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

/* BRAND */

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: white;
    flex-shrink: 0;
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

/* PROFILE */

.profile {
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
    padding: 4px 6px;
    border-radius: 30px;
    font-family: Arial, Helvetica, sans-serif;
    transition: background 0.2s;
}

.profile-button:hover {
    background: rgba(255, 255, 255, 0.10);
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
    border: 2px solid rgba(255, 255, 255, 0.85);
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

/* PROFILE MENU */

.profile-menu {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 310px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.18);
    border: 1px solid #e2e8f0;
    overflow: hidden;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-7px);
    transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s ease;
    z-index: 2000;
}

.profile-menu.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.profile-menu-header {
    padding: 17px 20px;
    display: flex;
    align-items: center;
    gap: 13px;
    background: white;
    border-bottom: 1px solid #e2e8f0;
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

.profile-menu-details {
    min-width: 0;
    flex: 1;
}

.profile-menu-name {
    color: #172554;
    font-size: 15px;
    font-weight: 700;
    margin-bottom: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.profile-menu-email {
    color: #64748b;
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.profile-menu-links {
    padding: 8px 12px 10px;
}

.profile-menu-links a {
    display: flex;
    align-items: center;
    gap: 11px;
    width: 100%;
    padding: 11px 9px;
    color: #172554;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    border-radius: 7px;
    transition: background 0.2s, color 0.2s;
}

.profile-menu-links a:hover {
    background: #f1f5f9;
    color: #164a8a;
}

.menu-bullet {
    width: 7px;
    height: 7px;
    min-width: 7px;
    border-radius: 50%;
    background: #d4af37;
    display: block;
}

.profile-menu-links a.signout {
    color: #dc2626;
    margin-top: 4px;
    border-top: 1px solid #e2e8f0;
    border-radius: 0;
    padding-top: 14px;
}

.profile-menu-links a.signout:hover {
    background: #fef2f2;
    color: #b91c1c;
}

/* MAIN CONTAINER */

.container {
    width: 100%;
    max-width: 850px;
    margin: 28px auto 50px;
    padding: 0;
}

/* PAGE HEADER */

.page-header {
    background: linear-gradient(135deg, #0f2f5f, #164a8a);
    color: white;
    padding: 20px 30px;
    border-radius: 14px;
    margin-bottom: 25px;
    box-shadow: 0 8px 25px rgba(15, 47, 95, 0.20);
    border-bottom: 4px solid #d4af37;
}

.page-header h1 {
    font-size: 28px;
    margin-bottom: 8px;
}

.page-header p {
    color: #e5e7eb;
    font-size: 15px;
}

/* FORM CARD */

.card {
    background: white;
    border-radius: 16px;
    padding: 30px;
    box-shadow: 0 5px 18px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

/* ALERTS */

.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
    padding: 14px 16px;
    border-radius: 9px;
    margin-bottom: 22px;
    font-size: 14px;
}

.success {
    background: #ecfdf5;
    color: #166534;
    border: 1px solid #bbf7d0;
    padding: 14px 16px;
    border-radius: 9px;
    margin-bottom: 22px;
    font-size: 14px;
}

/* FORM */

.form-group {
    margin-bottom: 21px;
}

label {
    display: block;
    font-weight: 700;
    color: #334155;
    margin-bottom: 8px;
    font-size: 14px;
}

input,
select,
textarea {
    width: 100%;
    padding: 13px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 9px;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
    background: white;
    color: #1f2937;
    outline: none;
    transition: 0.2s;
}

input:focus,
select:focus,
textarea:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}

textarea {
    resize: vertical;
    min-height: 120px;
    line-height: 1.5;
}

input[type="file"] {
    padding: 11px;
    background: #f8fafc;
    cursor: pointer;
}

.help-text {
    margin-top: 7px;
    font-size: 12px;
    color: #64748b;
}

/* AI INFORMATION */

.ai-info {
    margin-top: 25px;
    padding: 20px;
    border-radius: 12px;
    background: linear-gradient(135deg, #eff6ff, #f8fafc);
    border: 1px solid #bfdbfe;
    border-left: 5px solid #2563eb;
}

.ai-info h3 {
    margin: 0 0 10px;
    color: #164a8a;
    font-size: 17px;
}

.ai-info p {
    margin: 7px 0;
    font-size: 13px;
    line-height: 1.6;
    color: #475569;
}

/* SUBMIT BUTTON */

.submit-button {
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: 9px;
    background: linear-gradient(135deg, #2563eb, #164a8a);
    color: white;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    border-bottom: 3px solid #d4af37;
    transition: 0.2s;
}

.submit-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 7px 16px rgba(37, 99, 235, 0.25);
}

/* RESPONSIVE */

@media (max-width: 900px) {
    .container {
        width: 92%;
        max-width: 850px;
    }
}

@media (max-width: 700px) {
    .navbar {
        padding: 8px 15px;
    }

    .brand-title {
        font-size: 18px;
    }

    .brand-logo {
        width: 38px;
        height: 38px;
    }

    .profile-name {
        max-width: 110px;
    }

    .profile-menu {
        width: 290px;
    }

    .container {
        width: 94%;
        margin: 20px auto 35px;
    }

    .page-header {
        padding: 23px;
    }

    .page-header h1 {
        font-size: 23px;
    }

    .card {
        padding: 20px;
    }
}

@media (max-width: 450px) {
    .profile-name {
        display: none;
    }

    .profile-button {
        padding: 4px 5px;
    }

    .profile-menu {
        right: -5px;
        width: 285px;
    }
}

</style>

</head>

<body>

<!-- NAVBAR -->

<div class="navbar">

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

    <div class="profile">

        <button
            type="button"
            class="profile-button"
            id="profileButton"
            onclick="toggleProfileMenu(event)"
        >

            <span class="profile-name">
                <?php echo htmlspecialchars($current_name); ?>
            </span>

            <span class="profile-circle">
                <?php echo htmlspecialchars($profile_initial); ?>
            </span>

            <span class="profile-arrow" id="profileArrow"></span>

        </button>

        <div class="profile-menu" id="profileMenu">

            <div class="profile-menu-header">

                <div class="profile-header-circle">
                    <?php echo htmlspecialchars($profile_initial); ?>
                </div>

                <div class="profile-menu-details">

                    <div class="profile-menu-name">
                        <?php echo htmlspecialchars($current_name); ?>
                    </div>

                    <div class="profile-menu-email">
                        <?php echo htmlspecialchars($current_email); ?>
                    </div>

                </div>

            </div>

            <div class="profile-menu-links">

                <a href="messages.php">
                    <span class="menu-bullet"></span>
                    <span>Messages</span>
                </a>

                <a href="notifications.php">
                    <span class="menu-bullet"></span>
                    <span>Notifications</span>
                </a>

                <a href="logout.php" class="signout">
                    <span class="menu-bullet"></span>
                    <span>Sign Out</span>
                </a>

            </div>

        </div>

    </div>

</div>

<!-- MAIN -->

<div class="container">

    <div class="page-header">

        <h1>Report Found Item</h1>

        <p>
            Report an item that you found on the RTU campus.
        </p>

    </div>

    <div class="card">

        <?php if ($error !== ""): ?>

            <div class="error">
                <?php echo htmlspecialchars($error); ?>
            </div>

        <?php endif; ?>

        <?php if ($success !== ""): ?>

            <div class="success">
                <?php echo htmlspecialchars($success); ?>
            </div>

            <br>

        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <!-- ITEM NAME -->

            <div class="form-group">

                <label for="item_name">
                    Item Name
                </label>

                <input
                    type="text"
                    id="item_name"
                    name="item_name"
                    placeholder="Example: Black cellphone"
                    value="<?php echo htmlspecialchars($_POST["item_name"] ?? ""); ?>"
                    required
                >

            </div>

            <!-- CATEGORY -->

            <div class="form-group">

                <label for="category">
                    Category
                </label>

                <select id="category" name="category" required>

                    <option value="">
                        Select Category
                    </option>

                    <?php

                    $categories = [
                        "Wallet",
                        "Phone",
                        "Laptop",
                        "Tablet",
                        "ID / Identification Card",
                        "School Supplies",
                        "Bag",
                        "Clothing",
                        "Keys",
                        "Accessories",
                        "Electronic Device",
                        "Books",
                        "Documents",
                        "Other"
                    ];

                    foreach ($categories as $cat):

                    ?>

                        <option
                            value="<?php echo htmlspecialchars($cat); ?>"
                            <?php echo (($_POST["category"] ?? "") === $cat) ? "selected" : ""; ?>
                        >
                            <?php echo htmlspecialchars($cat); ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <!-- DESCRIPTION -->

            <div class="form-group">

                <label for="description">
                    Description
                </label>

                <textarea
                    id="description"
                    name="description"
                    placeholder="Describe the item, including color, design, brand, or other details."
                    required
                ><?php echo htmlspecialchars($_POST["description"] ?? ""); ?></textarea>

            </div>

            <!-- LOCATION -->

            <div class="form-group">

                <label for="location">
                    Where did you find it?
                </label>

                <input
                    type="text"
                    id="location"
                    name="location"
                    placeholder="Example: RTU Library"
                    value="<?php echo htmlspecialchars($_POST["location"] ?? ""); ?>"
                    required
                >

            </div>

            <!-- DATE -->

            <div class="form-group">

                <label for="date_reported">
                    Date Found
                </label>

                <input
                    type="date"
                    id="date_reported"
                    name="date_reported"
                    value="<?php echo htmlspecialchars($_POST["date_reported"] ?? date("Y-m-d")); ?>"
                    required
                >

            </div>

            <!-- IMAGE -->

            <div class="form-group">

                <label for="image">
                    Item Image
                </label>

                <input
                    type="file"
                    id="image"
                    name="image"
                    accept=".jpg,.jpeg,.png,.webp"
                    required
                >

                <div class="help-text">
                    Upload a clear image of the found item.
                    Maximum size: 10MB.
                </div>

            </div>

            <!-- AI INFORMATION -->

            <div class="ai-info">

                <h3>
                    AI Image Matching
                </h3>

                <p>
                    Your found-item report will be saved first.
                    AI image matching is handled separately through
                    the matching feature in My Reports.
                </p>

                <p>
                    The matching feature uses the system's newer
                    hybrid AI matching process to identify possible
                    lost-and-found item matches.
                </p>

            </div>

            <br>

            <!-- SUBMIT -->

            <button type="submit" class="submit-button">
                Submit Found Item
            </button>

        </form>

    </div>

</div>

<script>

/* PROFILE DROPDOWN */

function toggleProfileMenu(event) {

    event.stopPropagation();

    const menu = document.getElementById("profileMenu");
    const button = document.getElementById("profileButton");
    const arrow = document.getElementById("profileArrow");

    menu.classList.toggle("show");
    button.classList.toggle("open");

    if (menu.classList.contains("show")) {
        arrow.style.transform = "rotate(180deg)";
    } else {
        arrow.style.transform = "rotate(0deg)";
    }
}

/* CLOSE DROPDOWN WHEN CLICKING OUTSIDE */

document.addEventListener("click", function(event) {

    const profile = document.querySelector(".profile");
    const menu = document.getElementById("profileMenu");
    const button = document.getElementById("profileButton");
    const arrow = document.getElementById("profileArrow");

    if (!profile.contains(event.target)) {

        menu.classList.remove("show");
        button.classList.remove("open");
        arrow.style.transform = "rotate(0deg)";

    }

});

/* PREVENT BACK/FORWARD CACHE */

window.addEventListener("pageshow", function(event) {

    if (event.persisted) {
        window.location.reload();
    }

});

</script>

</body>
</html>
