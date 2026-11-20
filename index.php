
<?php

session_start();

/* =========================================================
   NO-CACHE / SESSION PROTECTION
========================================================= */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

/* =========================================================
   SUPABASE CONNECTION
========================================================= */

require_once __DIR__ . "/config/supabase.php";

/* =========================================================
   CHECK LOGGED-IN USER
========================================================= */

$is_logged_in = isset($_SESSION["user_id"]);
$logged_in_name = "User";

if ($is_logged_in) {

    try {

        $user_id = (int) $_SESSION["user_id"];

        $user_stmt = $supabase->prepare("
            SELECT name
            FROM users
            WHERE id = :user_id
            LIMIT 1
        ");

        $user_stmt->execute([
            ":user_id" => $user_id
        ]);

        $current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);

        if ($current_user) {

            $logged_in_name = $current_user["name"];

            $_SESSION["name"] = $logged_in_name;

        } else {

            session_unset();
            session_destroy();

            header("Location: login.php");
            exit();
        }

    } catch (PDOException $e) {

        error_log("Index - Supabase user query error: " . $e->getMessage());

        $logged_in_name = $_SESSION["name"] ?? "User";
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>RTU Lost and Found</title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            width: 100%;
            min-height: 100%;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            color: white;

            background-image:
                linear-gradient(
                    rgba(7, 29, 61, 0.78),
                    rgba(7, 29, 61, 0.88)
                ),
                url("uploads/rtu.jpg");

            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            min-height: 100vh;
        }

        .page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px;
        }

        .main-card {
            width: 100%;
            max-width: 850px;

            background: rgba(255, 255, 255, 0.96);
            color: #172554;

            border-radius: 20px;
            padding: 55px 50px;
            text-align: center;

            border-top: 6px solid #d4af37;
            border-bottom: 6px solid #d4af37;

            box-shadow: 0 15px 45px rgba(0, 0, 0, 0.30);

            backdrop-filter: blur(5px);
        }

        .logo-area {
            width: 100px;
            height: 100px;

            margin: 0 auto 22px;
            border-radius: 50%;

            display: flex;
            align-items: center;
            justify-content: center;

            background: #ffffff;
            border: 4px solid #d4af37;

            box-shadow: 0 5px 18px rgba(15, 47, 95, 0.18);

            overflow: hidden;
        }

        .logo-area img {
            width: 82px;
            height: 82px;
            object-fit: contain;
            display: block;
        }

        .main-card h1 {
            font-size: 36px;
            color: #0f2f5f;
            margin-bottom: 12px;
            font-weight: 800;
        }

        .gold-line {
            width: 90px;
            height: 4px;
            background: #d4af37;
            margin: 0 auto 22px;
            border-radius: 10px;
        }

        .main-card p {
            font-size: 16px;
            line-height: 1.7;
            color: #64748b;
            max-width: 650px;
            margin: 0 auto 15px;
        }

        .welcome {
            color: #0f2f5f !important;
            font-size: 18px !important;
            font-weight: bold;
            margin-top: 20px !important;
            margin-bottom: 25px !important;
        }

        .buttons {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 30px;
        }

        .button {
            display: inline-block;
            min-width: 150px;
            padding: 13px 25px;

            border-radius: 9px;
            text-decoration: none;

            font-size: 15px;
            font-weight: bold;

            transition: 0.2s;
            cursor: pointer;
        }

        .blue-button {
            background: linear-gradient(135deg, #2563eb, #164a8a);
            color: white;
            border-bottom: 3px solid #d4af37;

            box-shadow: 0 5px 12px rgba(37, 99, 235, 0.20);
        }

        .blue-button:hover {
            transform: translateY(-3px);
            background: linear-gradient(135deg, #1d4ed8, #123d73);
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.30);
        }

        .gold-button {
            background: #d4af37;
            color: #172554;
            border-bottom: 3px solid #a78318;

            box-shadow: 0 5px 12px rgba(212, 175, 55, 0.20);
        }

        .gold-button:hover {
            transform: translateY(-3px);
            background: #e5c158;
            box-shadow: 0 8px 18px rgba(212, 175, 55, 0.30);
        }

        .logout-button {
            background: #dc2626;
            color: white;
            border-bottom: 3px solid #991b1b;
        }

        .logout-button:hover {
            transform: translateY(-3px);
            background: #b91c1c;
        }

        .footer {
            margin-top: 35px;
            padding-top: 20px;

            border-top: 1px solid #e2e8f0;

            color: #94a3b8;
            font-size: 13px;
            line-height: 1.6;
        }

        @media (max-width: 600px) {

            .page {
                padding: 18px;
            }

            .main-card {
                padding: 40px 25px;
            }

            .main-card h1 {
                font-size: 28px;
            }

            .main-card p {
                font-size: 14px;
            }

            .logo-area {
                width: 85px;
                height: 85px;
            }

            .logo-area img {
                width: 70px;
                height: 70px;
            }

            .buttons {
                flex-direction: column;
                align-items: stretch;
            }

            .button {
                width: 100%;
            }

        }

    </style>

</head>

<body>

<div class="page">

    <div class="main-card">

        <div class="logo-area">

            <img
                src="uploads/rtulogo.png"
                alt="RTU Logo"
            >

        </div>

        <h1>RTU Lost and Found</h1>

        <div class="gold-line"></div>

        <p>
            Welcome to the Rizal Technological University
            Lost and Found Management System.
        </p>

        <?php if ($is_logged_in): ?>

            <p class="welcome">
                Welcome,
                <?php
                echo htmlspecialchars(
                    $logged_in_name,
                    ENT_QUOTES,
                    "UTF-8"
                );
                ?>!
            </p>

            <div class="buttons">

                <a href="dashboard.php" class="button blue-button">
                    Go to Dashboard
                </a>

                <a href="logout.php" class="button logout-button">
                    Logout
                </a>

            </div>

        <?php else: ?>

            <div class="buttons">

                <a href="login.php" class="button blue-button">
                    Login
                </a>

                <a href="register.php" class="button gold-button">
                    Register
                </a>

            </div>

        <?php endif; ?>

        <div class="footer">

            Rizal Technological University
            <br>
            Lost and Found Management System

        </div>

    </div>

</div>

</body>
</html>
