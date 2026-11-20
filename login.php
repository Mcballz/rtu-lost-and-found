
<?php

session_start();

/* =====================================================
   PREVENT BROWSER CACHE
===================================================== */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

/* =====================================================
   SUPABASE CONNECTION
===================================================== */

$message = "";

try {

    require_once __DIR__ . "/config/supabase.php";

} catch (Throwable $e) {

    error_log("Supabase Connection Error: " . $e->getMessage());

    $message = "Unable to connect to the database. Please try again later.";

}

/* =====================================================
   LOGIN PROCESS
===================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if (empty($email) || empty($password)) {

        $message = "Please enter your email and password.";

    } elseif (!isset($supabase) || !$supabase instanceof PDO) {

        $message = "Unable to connect to the database. Please try again later.";

    } else {

        try {

            $sql = "SELECT
                        id,
                        student_id,
                        name,
                        email,
                        password,
                        role
                    FROM public.users
                    WHERE email = :email
                    LIMIT 1";

            $stmt = $supabase->prepare($sql);

            $stmt->execute([
                ":email" => $email
            ]);

            $user = $stmt->fetch();

            if ($user && password_verify($password, $user["password"])) {

                session_regenerate_id(true);

                $_SESSION["user_id"] = $user["id"];
                $_SESSION["student_id"] = $user["student_id"];
                $_SESSION["name"] = $user["name"];
                $_SESSION["email"] = $user["email"];
                $_SESSION["role"] = $user["role"];

                if ($user["role"] === "admin") {

                    header("Location: admin/dashboard.php");

                } else {

                    header("Location: dashboard.php");

                }

                exit();

            } else {

                $message = "Invalid email or password.";

            }

        } catch (Throwable $e) {

            error_log("Supabase Login Error: " . $e->getMessage());

            $message = "Unable to process login. Please try again.";

        }

    }

}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Login - RTU Lost and Found</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {

            margin: 0;

            min-height: 100vh;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background:
                linear-gradient(
                    135deg,
                    #002B73 0%,
                    #003DA5 50%,
                    #001F54 100%
                );

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 25px;

            color: #1f2937;
        }

        /* MAIN CONTAINER */

        .login-container {

            width: 100%;

            max-width: 1000px;

            min-height: 570px;

            background: white;

            border-radius: 20px;

            overflow: hidden;

            display: grid;

            grid-template-columns: 45% 55%;

            box-shadow:
                0 20px 50px
                rgba(0, 0, 0, 0.25);
        }

        /* LEFT SIDE */

        .login-left {

            background:
                linear-gradient(
                    160deg,
                    #003DA5,
                    #002B73
                );

            color: white;

            display: flex;

            flex-direction: column;

            align-items: center;

            justify-content: center;

            text-align: center;

            padding: 45px 35px;

            position: relative;
        }

        .login-left::before {

            content: "";

            position: absolute;

            width: 260px;

            height: 260px;

            border: 2px solid
                rgba(245, 194, 66, 0.25);

            border-radius: 50%;

            top: -100px;

            left: -100px;
        }

        .login-left::after {

            content: "";

            position: absolute;

            width: 220px;

            height: 220px;

            border: 2px solid
                rgba(245, 194, 66, 0.2);

            border-radius: 50%;

            bottom: -90px;

            right: -90px;
        }

        /* LOGO */

        .logo-wrapper {

            width: 150px;

            height: 150px;

            background: white;

            border-radius: 50%;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 15px;

            margin-bottom: 25px;

            border:
                6px solid #F5C242;

            box-shadow:
                0 8px 25px
                rgba(0, 0, 0, 0.25);

            position: relative;

            z-index: 2;
        }

        .logo-wrapper img {

            width: 100%;

            height: 100%;

            object-fit: contain;
        }

        .login-left h1 {

            margin: 0;

            font-size: 28px;

            font-weight: 800;

            position: relative;

            z-index: 2;
        }

        .gold-text {

            color: #F5C242;
        }

        .login-left p {

            max-width: 340px;

            line-height: 1.6;

            margin: 12px 0 0;

            color: #e5efff;

            font-size: 14px;

            position: relative;

            z-index: 2;
        }

        .gold-line {

            width: 80px;

            height: 4px;

            background: #F5C242;

            border-radius: 10px;

            margin: 18px 0;

            position: relative;

            z-index: 2;
        }

        /* RIGHT SIDE */

        .login-right {

            padding: 55px 60px;

            display: flex;

            flex-direction: column;

            justify-content: center;
        }

        .login-header {

            margin-bottom: 28px;
        }

        .login-header h2 {

            margin: 0 0 8px;

            font-size: 30px;

            color: #002B73;
        }

        .login-header p {

            margin: 0;

            color: #6b7280;

            font-size: 14px;
        }

        /* ERROR MESSAGE */

        .error-message {

            background: #fef2f2;

            border:
                1px solid #fecaca;

            color: #b91c1c;

            padding: 12px 14px;

            border-radius: 10px;

            margin-bottom: 20px;

            font-size: 13px;

            font-weight: 600;
        }

        /* FORM */

        .form-group {

            margin-bottom: 20px;
        }

        .form-group label {

            display: block;

            margin-bottom: 8px;

            font-size: 14px;

            font-weight: bold;

            color: #374151;
        }

        .input-wrapper {

            position: relative;
        }

        .input-icon {

            position: absolute;

            left: 14px;

            top: 50%;

            transform:
                translateY(-50%);

            font-size: 17px;

            color: #003DA5;

            pointer-events: none;
        }

        .form-group input {

            width: 100%;

            height: 48px;

            border:
                1px solid #d1d5db;

            border-radius: 10px;

            padding:
                0 14px 0 43px;

            font-size: 14px;

            outline: none;

            transition:
                border-color 0.2s,
                box-shadow 0.2s;

            background: #fff;
        }

        .form-group input:focus {

            border-color: #003DA5;

            box-shadow:
                0 0 0 3px
                rgba(0, 61, 165, 0.12);
        }

        /* LOGIN BUTTON */

        .login-button {

            width: 100%;

            height: 50px;

            border: none;

            border-radius: 10px;

            background:
                linear-gradient(
                    135deg,
                    #003DA5,
                    #002B73
                );

            color: white;

            font-size: 15px;

            font-weight: bold;

            cursor: pointer;

            transition:
                transform 0.15s,
                box-shadow 0.15s;

            box-shadow:
                0 5px 12px
                rgba(0, 43, 115, 0.25);
        }

        .login-button:hover {

            transform:
                translateY(-1px);

            box-shadow:
                0 8px 18px
                rgba(0, 43, 115, 0.3);
        }

        .login-button:active {

            transform:
                translateY(0);
        }

        /* LINKS */

        .register-link {

            text-align: center;

            margin-top: 22px;

            font-size: 14px;

            color: #6b7280;
        }

        .register-link a {

            color: #003DA5;

            font-weight: bold;

            text-decoration: none;
        }

        .register-link a:hover {

            color: #F0B900;

            text-decoration: underline;
        }

        /* GOLD ACCENT */

        .gold-accent {

            height: 5px;

            width: 100%;

            background: #F5C242;

            position: absolute;

            top: 0;

            left: 0;
        }

        /* RESPONSIVE */

        @media (max-width: 800px) {

            body {

                padding: 15px;
            }

            .login-container {

                grid-template-columns: 1fr;

                max-width: 500px;
            }

            .login-left {

                min-height: 320px;

                padding: 35px 25px;
            }

            .logo-wrapper {

                width: 110px;

                height: 110px;

                border-width: 5px;

                margin-bottom: 18px;
            }

            .login-left h1 {

                font-size: 23px;
            }

            .login-left p {

                font-size: 13px;
            }

            .login-right {

                padding: 40px 30px;
            }
        }

        @media (max-width: 450px) {

            .login-right {

                padding:
                    35px 20px;
            }

            .login-header h2 {

                font-size: 26px;
            }
        }

    </style>

</head>

<body>

<div class="login-container">

    <!-- LEFT SIDE -->

    <div class="login-left">

        <div class="gold-accent"></div>

        <div class="logo-wrapper">

            <img
                src="uploads/rtulogo.png"
                alt="RTU Logo"
            >

        </div>

        <h1>

            RTU
            <span class="gold-text">
                Lost and Found
            </span>

        </h1>

        <div class="gold-line"></div>

        <p>

            A centralized platform for reporting,
            searching, and managing lost and found
            items within Rizal Technological University.

        </p>

    </div>

    <!-- RIGHT SIDE -->

    <div class="login-right">

        <div class="login-header">

            <h2>
                Welcome Back
            </h2>

            <p>
                Sign in to your RTU Lost and Found account.
            </p>

        </div>

        <?php if (!empty($message)): ?>

            <div class="error-message">

                <?php
                echo htmlspecialchars($message);
                ?>

            </div>

        <?php endif; ?>

        <form
            method="POST"
            autocomplete="on"
        >

            <!-- EMAIL -->

            <div class="form-group">

                <label for="email">
                    Email Address
                </label>

                <div class="input-wrapper">

                    <span class="input-icon"></span>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="Enter your email"
                        value="<?php
                            echo htmlspecialchars(
                                $_POST["email"] ?? ""
                            );
                        ?>"
                        autocomplete="email"
                        required
                    >

                </div>

            </div>

            <!-- PASSWORD -->

            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <div class="input-wrapper">

                    <span class="input-icon"></span>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >

                </div>

            </div>

            <!-- LOGIN -->

            <button
                type="submit"
                class="login-button"
            >

                Login

            </button>

        </form>

        <!-- REGISTER -->

        <div class="register-link">

            Don't have an account?

            <a href="register.php">
                Register here
            </a>

        </div>

    </div>

</div>

</body>

</html>
