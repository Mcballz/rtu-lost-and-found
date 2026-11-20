<?php

session_start();

require_once "config/database.php";

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $student_id = trim($_POST["student_id"]);
    $name = trim($_POST["name"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    if (
        empty($student_id) ||
        empty($name) ||
        empty($email) ||
        empty($password)
    ) {

        $message = "Please fill in all fields.";
        $message_type = "error";

    } else {

        $check_sql = "
            SELECT id
            FROM users
            WHERE student_id = ?
            OR email = ?
        ";

        $check_stmt = $conn->prepare($check_sql);

        $check_stmt->bind_param(
            "ss",
            $student_id,
            $email
        );

        $check_stmt->execute();

        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {

            $message = "Student ID or email already exists.";
            $message_type = "error";

        } else {

            $hashed_password = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $sql = "
                INSERT INTO users
                (student_id, name, email, password, role)
                VALUES (?, ?, ?, ?, 'student')
            ";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                "ssss",
                $student_id,
                $name,
                $email,
                $hashed_password
            );

            if ($stmt->execute()) {

                $message = "Registration successful! You can now login.";
                $message_type = "success";

            } else {

                $message = "Registration failed. Please try again.";
                $message_type = "error";

            }

            $stmt->close();
        }

        $check_stmt->close();
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

<title>
    Register - RTU Lost and Found
</title>

<style>

/* =========================================================
   GLOBAL
========================================================= */

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {

    min-height: 100vh;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    /*
       RTU BLUE + GOLD BACKGROUND
    */

    background:
        radial-gradient(
            circle at 15% 20%,
            rgba(212, 175, 55, 0.28),
            transparent 28%
        ),
        radial-gradient(
            circle at 85% 80%,
            rgba(212, 175, 55, 0.22),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #071f42 0%,
            #0b2d5c 45%,
            #123f78 100%
        );

    color: #1f2937;

    display: flex;

    align-items: center;

    justify-content: center;

    padding: 25px;

    position: relative;

    overflow: hidden;
}


/* =========================================================
   BACKGROUND GOLD DECORATION
========================================================= */

body::before {

    content: "";

    position: fixed;

    width: 420px;

    height: 420px;

    border-radius: 50%;

    background:
        rgba(212, 175, 55, 0.12);

    top: -180px;

    right: -120px;

    pointer-events: none;
}


body::after {

    content: "";

    position: fixed;

    width: 350px;

    height: 350px;

    border-radius: 50%;

    background:
        rgba(212, 175, 55, 0.10);

    bottom: -170px;

    left: -120px;

    pointer-events: none;
}


/* =========================================================
   REGISTER WRAPPER
========================================================= */

.register-wrapper {

    width: 100%;

    max-width: 470px;

    position: relative;

    z-index: 2;
}


/* =========================================================
   REGISTER CARD
========================================================= */

.register-card {

    background: white;

    border-radius: 18px;

    overflow: hidden;

    box-shadow:
        0 20px 50px
        rgba(0, 0, 0, 0.30);

    border:
        1px solid
        rgba(212, 175, 55, 0.45);

    position: relative;
}


/* =========================================================
   TOP BLUE HEADER
========================================================= */

.register-header {

    background:
        linear-gradient(
            135deg,
            #071f42,
            #0b2d5c 55%,
            #164a8a
        );

    color: white;

    padding: 30px 30px 27px;

    text-align: center;

    border-bottom:
        5px solid #d4af37;

    position: relative;
}


/* subtle gold highlight */

.register-header::after {

    content: "";

    position: absolute;

    width: 110px;

    height: 110px;

    border-radius: 50%;

    background:
        rgba(212, 175, 55, 0.10);

    top: -60px;

    right: -35px;

    pointer-events: none;
}


/* =========================================================
   LOGO
========================================================= */

.logo-container {

    width: 82px;

    height: 82px;

    margin: 0 auto 15px;

    display: flex;

    align-items: center;

    justify-content: center;

    background: white;

    border-radius: 50%;

    border:
        3px solid #d4af37;

    box-shadow:
        0 5px 18px
        rgba(0, 0, 0, 0.25);

    overflow: hidden;

    position: relative;

    z-index: 2;
}


.logo-container img {

    width: 65px;

    height: 65px;

    object-fit: contain;

    display: block;
}


/* =========================================================
   HEADER TEXT
========================================================= */

.register-header h1 {

    font-size: 27px;

    margin-bottom: 7px;

    color: white;

    position: relative;

    z-index: 2;
}


.register-header p {

    font-size: 14px;

    color: #e5e7eb;

    line-height: 1.5;

    position: relative;

    z-index: 2;
}


/* =========================================================
   FORM AREA
========================================================= */

.register-body {

    padding: 30px;

    background:
        linear-gradient(
            180deg,
            #ffffff 0%,
            #fffdf7 100%
        );
}


/* =========================================================
   MESSAGE
========================================================= */

.message {

    padding: 12px 14px;

    border-radius: 9px;

    margin-bottom: 20px;

    font-size: 13px;

    line-height: 1.5;

    text-align: center;
}


.message.success {

    background: #ecfdf5;

    color: #047857;

    border:
        1px solid #a7f3d0;
}


.message.error {

    background: #fef2f2;

    color: #b91c1c;

    border:
        1px solid #fecaca;
}


/* =========================================================
   FORM GROUP
========================================================= */

.form-group {

    margin-bottom: 18px;
}


.form-group label {

    display: block;

    margin-bottom: 7px;

    color: #0b2d5c;

    font-size: 14px;

    font-weight: bold;
}


/* =========================================================
   INPUT
========================================================= */

.form-group input {

    width: 100%;

    padding: 13px 14px;

    border:
        1px solid #cbd5e1;

    border-radius: 9px;

    background: #ffffff;

    color: #1f2937;

    font-size: 14px;

    transition: 0.2s;
}


.form-group input::placeholder {

    color: #9ca3af;
}


.form-group input:focus {

    outline: none;

    border-color: #164a8a;

    box-shadow:
        0 0 0 3px
        rgba(212, 175, 55, 0.18);
}


/* =========================================================
   REGISTER BUTTON
========================================================= */

.register-button {

    width: 100%;

    padding: 13px;

    margin-top: 5px;

    border: none;

    border-radius: 9px;

    background:
        linear-gradient(
            135deg,
            #0b2d5c,
            #164a8a
        );

    color: white;

    font-size: 15px;

    font-weight: bold;

    cursor: pointer;

    border-bottom:
        4px solid #d4af37;

    transition: 0.2s;
}


.register-button:hover {

    transform:
        translateY(-2px);

    box-shadow:
        0 7px 18px
        rgba(11, 45, 92, 0.30);

    background:
        linear-gradient(
            135deg,
            #164a8a,
            #1d5aa3
        );
}


/* =========================================================
   LINKS
========================================================= */

.login-link {

    text-align: center;

    margin-top: 22px;

    padding-top: 20px;

    border-top:
        1px solid #e5e7eb;

    font-size: 14px;

    color: #64748b;
}


.login-link a {

    color: #164a8a;

    font-weight: bold;

    text-decoration: none;
}


.login-link a:hover {

    color: #92701c;

    text-decoration: underline;
}


/* =========================================================
   BACK HOME
========================================================= */

.back-home {

    display: block;

    text-align: center;

    margin-top: 15px;

    color: #164a8a;

    text-decoration: none;

    font-size: 13px;

    font-weight: bold;

    padding: 8px;

    border-radius: 7px;

    transition: 0.2s;
}


.back-home:hover {

    background: #fff8dc;

    color: #92701c;
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 500px) {

    body {

        padding: 15px;
    }

    .register-header {

        padding:
            25px 20px 23px;
    }

    .register-body {

        padding: 25px 20px;
    }

    .register-header h1 {

        font-size: 24px;
    }

    .logo-container {

        width: 72px;

        height: 72px;
    }

    .logo-container img {

        width: 56px;

        height: 56px;
    }

}

</style>

</head>

<body>


<div class="register-wrapper">


    <div class="register-card">


        <!-- =================================================
             HEADER
        ================================================== -->

        <div class="register-header">


            <!-- RTU LOGO -->

            <div class="logo-container">

                <img
                    src="uploads/rtulogo.png"
                    alt="RTU Logo"
                >

            </div>


            <h1>
                Create Account
            </h1>


            <p>
                Register for the RTU Lost and Found System
            </p>


        </div>


        <!-- =================================================
             FORM
        ================================================== -->

        <div class="register-body">


            <?php if (!empty($message)): ?>

                <div
                    class="message
                    <?php echo $message_type; ?>"
                >

                    <?php
                    echo htmlspecialchars($message);
                    ?>

                </div>

            <?php endif; ?>


            <form method="POST">


                <!-- STUDENT ID -->

                <div class="form-group">

                    <label for="student_id">
                        Student ID
                    </label>

                    <input
                        type="text"
                        id="student_id"
                        name="student_id"
                        placeholder="Enter your student ID"
                        value="<?php
                            echo isset($_POST["student_id"])
                                ? htmlspecialchars($_POST["student_id"])
                                : "";
                        ?>"
                        required
                    >

                </div>


                <!-- FULL NAME -->

                <div class="form-group">

                    <label for="name">
                        Full Name
                    </label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        placeholder="Enter your full name"
                        value="<?php
                            echo isset($_POST["name"])
                                ? htmlspecialchars($_POST["name"])
                                : "";
                        ?>"
                        required
                    >

                </div>


                <!-- EMAIL -->

                <div class="form-group">

                    <label for="email">
                        Email
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="Enter your email"
                        value="<?php
                            echo isset($_POST["email"])
                                ? htmlspecialchars($_POST["email"])
                                : "";
                        ?>"
                        required
                    >

                </div>


                <!-- PASSWORD -->

                <div class="form-group">

                    <label for="password">
                        Password
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Create a password"
                        required
                    >

                </div>


                <!-- REGISTER -->

                <button
                    type="submit"
                    class="register-button"
                >
                    Create Account
                </button>


            </form>


            <!-- LOGIN -->

            <div class="login-link">

                Already have an account?

                <a href="login.php">
                    Login
                </a>

            </div>


            <!-- HOME -->

         

        </div>


    </div>


</div>


</body>

</html>