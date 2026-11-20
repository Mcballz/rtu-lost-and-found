<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>RTU Lost and Found</title>


    <style>

        /* =====================================================
           GLOBAL
        ===================================================== */

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

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            color: white;

            /*
                BACKGROUND IMAGE

                Put your background image in the same folder
                as this index.php file.

                Example:

                background.jpg
            */

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


        /* =====================================================
           MAIN PAGE
        ===================================================== */

        .page {

            min-height: 100vh;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 30px;
        }


        /* =====================================================
           MAIN CARD
        ===================================================== */

        .main-card {

            width: 100%;

            max-width: 850px;

            background:
                rgba(255, 255, 255, 0.96);

            color: #172554;

            border-radius: 20px;

            padding: 55px 50px;

            text-align: center;

            border-top:
                6px solid #d4af37;

            border-bottom:
                6px solid #d4af37;

            box-shadow:
                0 15px 45px
                rgba(0, 0, 0, 0.30);

            backdrop-filter:
                blur(5px);
        }


        /* =====================================================
           LOGO
        ===================================================== */

        .logo-area {

            width: 100px;

            height: 100px;

            margin: 0 auto 22px;

            border-radius: 50%;

            display: flex;

            align-items: center;

            justify-content: center;

            background: #ffffff;

            border:
                4px solid #d4af37;

            box-shadow:
                0 5px 18px
                rgba(15, 47, 95, 0.18);

            overflow: hidden;
        }


        .logo-area img {

            width: 82px;

            height: 82px;

            object-fit: contain;

            display: block;
        }


        /* =====================================================
           TITLE
        ===================================================== */

        .main-card h1 {

            font-size: 36px;

            color: #0f2f5f;

            margin-bottom: 12px;

            font-weight: 800;
        }


        /* =====================================================
           GOLD LINE
        ===================================================== */

        .gold-line {

            width: 90px;

            height: 4px;

            background: #d4af37;

            margin:
                0 auto 22px;

            border-radius: 10px;
        }


        /* =====================================================
           DESCRIPTION
        ===================================================== */

        .main-card p {

            font-size: 16px;

            line-height: 1.7;

            color: #64748b;

            max-width: 650px;

            margin:
                0 auto 15px;
        }


        /* =====================================================
           WELCOME
        ===================================================== */

        .welcome {

            color:
                #0f2f5f !important;

            font-size:
                18px !important;

            font-weight:
                bold;

            margin-top:
                20px !important;

            margin-bottom:
                25px !important;
        }


        /* =====================================================
           BUTTON AREA
        ===================================================== */

        .buttons {

            display: flex;

            justify-content: center;

            align-items: center;

            gap: 15px;

            flex-wrap: wrap;

            margin-top: 30px;
        }


        /* =====================================================
           BUTTON
        ===================================================== */

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


        /* =====================================================
           BLUE BUTTON
        ===================================================== */

        .blue-button {

            background:
                linear-gradient(
                    135deg,
                    #2563eb,
                    #164a8a
                );

            color: white;

            border-bottom:
                3px solid #d4af37;

            box-shadow:
                0 5px 12px
                rgba(37, 99, 235, 0.20);
        }


        .blue-button:hover {

            transform:
                translateY(-3px);

            background:
                linear-gradient(
                    135deg,
                    #1d4ed8,
                    #123d73
                );

            box-shadow:
                0 8px 18px
                rgba(37, 99, 235, 0.30);
        }


        /* =====================================================
           GOLD BUTTON
        ===================================================== */

        .gold-button {

            background:
                #d4af37;

            color:
                #172554;

            border-bottom:
                3px solid #a78318;

            box-shadow:
                0 5px 12px
                rgba(212, 175, 55, 0.20);
        }


        .gold-button:hover {

            transform:
                translateY(-3px);

            background:
                #e5c158;

            box-shadow:
                0 8px 18px
                rgba(212, 175, 55, 0.30);
        }


        /* =====================================================
           LOGOUT BUTTON
        ===================================================== */

        .logout-button {

            background:
                #dc2626;

            color:
                white;

            border-bottom:
                3px solid #991b1b;
        }


        .logout-button:hover {

            transform:
                translateY(-3px);

            background:
                #b91c1c;
        }


        /* =====================================================
           FOOTER
        ===================================================== */

        .footer {

            margin-top:
                35px;

            padding-top:
                20px;

            border-top:
                1px solid #e2e8f0;

            color:
                #94a3b8;

            font-size:
                13px;

            line-height:
                1.6;
        }


        /* =====================================================
           MOBILE
        ===================================================== */

        @media (max-width: 600px) {

            .page {

                padding:
                    18px;
            }


            .main-card {

                padding:
                    40px 25px;
            }


            .main-card h1 {

                font-size:
                    28px;
            }


            .main-card p {

                font-size:
                    14px;
            }


            .logo-area {

                width:
                    85px;

                height:
                    85px;
            }


            .logo-area img {

                width:
                    70px;

                height:
                    70px;
            }


            .buttons {

                flex-direction:
                    column;

                align-items:
                    stretch;
            }


            .button {

                width:
                    100%;
            }

        }

    </style>

</head>


<body>


<div class="page">


    <div class="main-card">


        <!-- =================================================
             RTU LOGO

             Put logo.png beside index.php
        ================================================== -->

        <div class="logo-area">

            <img
                src="uploads/rtulogo.png"
                alt="RTU Logo"
            >

        </div>


        <!-- =================================================
             TITLE
        ================================================== -->

        <h1>
            RTU Lost and Found
        </h1>


        <div class="gold-line"></div>


        <!-- =================================================
             DESCRIPTION
        ================================================== -->

        <p>

            Welcome to the Rizal Technological University
            Lost and Found Management System.

        </p>


        <?php if (isset($_SESSION["user_id"])): ?>


            <!-- =================================================
                 LOGGED-IN USER
            ================================================== -->

            <p class="welcome">

                Welcome,
                <?php
                echo htmlspecialchars(
                    $_SESSION["name"]
                );
                ?>!

            </p>


            <div class="buttons">


                <a
                    href="dashboard.php"
                    class="button blue-button"
                >
                    Go to Dashboard
                </a>


                <a
                    href="logout.php"
                    class="button logout-button"
                >
                    Logout
                </a>


            </div>


        <?php else: ?>


            <!-- =================================================
                 NOT LOGGED IN
            ================================================== -->

            <div class="buttons">


                <a
                    href="login.php"
                    class="button blue-button"
                >
                    Login
                </a>


                <a
                    href="register.php"
                    class="button gold-button"
                >
                    Register
                </a>


            </div>


        <?php endif; ?>


        <!-- =================================================
             FOOTER
        ================================================== -->

        <div class="footer">

            Rizal Technological University
            <br>

            Lost and Found Management System

        </div>


    </div>


</div>


</body>

</html>