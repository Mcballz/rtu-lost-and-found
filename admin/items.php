
<?php

session_start();

/* =========================================================
   PREVENT BROWSER CACHE
========================================================= */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

/* =========================================================
   LOGIN CHECK
========================================================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . "/../config/supabase.php";

/* =========================================================
   ADMIN ROLE CHECK
========================================================= */

$user_id = (int) $_SESSION["user_id"];

try {
    $stmt = $supabase->prepare("
        SELECT role
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");

    $stmt->execute([":user_id" => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Admin verification role error: " . $e->getMessage());
    die("Unable to verify administrator access.");
}

if (!$user || $user["role"] !== "admin") {
    http_response_code(403);
    die("Access denied. Admins only.");
}

/* =========================================================
   CSRF TOKEN
========================================================= */

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["csrf_token"];

/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function e($value)
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

function validCsrfToken()
{
    return isset($_POST["csrf_token"], $_SESSION["csrf_token"])
        && hash_equals(
            $_SESSION["csrf_token"],
            (string) $_POST["csrf_token"]
        );
}

/* =========================================================
   PAGE VARIABLES
========================================================= */

$verification_code = "";
$claim_verification_code = "";

$search_type = "report";
$selected_claim_id = null;

$item = null;
$matches = [];
$claims = [];

$lookup_error = "";
$delete_error = "";
$matches_error = "";
$claims_error = "";
$action_message = "";

$active_tab = "overview";

/* =========================================================
   APPROVE / REJECT CLAIM
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["claim_action"])
) {

    $claim_id = filter_var(
        $_POST["claim_id"] ?? null,
        FILTER_VALIDATE_INT
    );

    $selected_item_id = filter_var(
        $_POST["selected_item_id"] ?? null,
        FILTER_VALIDATE_INT
    );

    $claim_action = $_POST["claim_action"];

    $rejection_reason = trim(
        $_POST["rejection_reason"] ?? ""
    );

    $return_code = strtoupper(
        trim($_POST["verification_code"] ?? "")
    );

    $return_claim_code = strtoupper(
        trim($_POST["claim_verification_code"] ?? "")
    );

    $redirect_url = "items.php?code=" .
        urlencode($return_code) .
        "&tab=claims";

    if ($return_claim_code !== "") {
        $redirect_url .= "&claim_code=" .
            urlencode($return_claim_code);
    }

    if (!validCsrfToken()) {

        $_SESSION["verification_action_error"] =
            "Invalid request. Please refresh the page and try again.";

        header("Location: " . $redirect_url);
        exit();
    }

    if (
        !$claim_id ||
        !$selected_item_id ||
        !in_array($claim_action, ["approve", "reject"], true)
    ) {

        $_SESSION["verification_action_error"] =
            "Invalid claim action.";

        header("Location: " . $redirect_url);
        exit();
    }

    if (
        !preg_match(
            '/^RTU-(LST|FND)-[A-F0-9]{8,16}$/',
            $return_code
        )
    ) {

        $_SESSION["verification_action_error"] =
            "Invalid report verification code.";

        header("Location: items.php");
        exit();
    }

    if (
        $return_claim_code !== "" &&
        !preg_match('/^RTU-CLM-[A-F0-9]{8}$/', $return_claim_code)
    ) {

        $_SESSION["verification_action_error"] =
            "Invalid claim verification code.";

        header("Location: " . $redirect_url);
        exit();
    }

    if ($claim_action === "reject") {

        if ($rejection_reason === "") {

            $_SESSION["verification_action_error"] =
                "Please provide a reason for rejecting this claim.";

            header("Location: " . $redirect_url);
            exit();
        }

        if (mb_strlen($rejection_reason) > 1000) {

            $_SESSION["verification_action_error"] =
                "The rejection reason must not exceed 1,000 characters.";

            header("Location: " . $redirect_url);
            exit();
        }
    }

    try {

        $supabase->beginTransaction();

        /*
         * Verify the claim belongs to the selected report.
         * Only pending claims can be processed.
         */

        $claim_sql = "
            SELECT
                claims.id,
                claims.item_id,
                claims.user_id,
                claims.status,
                claims.verification_code,
                items.verification_code AS report_code,
                items.item_type,
                items.item_name
            FROM claims
            INNER JOIN items
                ON claims.item_id = items.id
            WHERE claims.id = :claim_id
              AND claims.item_id = :item_id
              AND items.verification_code = :report_code
              AND claims.status = 'pending'
        ";

        $claim_params = [
            ":claim_id" => $claim_id,
            ":item_id" => $selected_item_id,
            ":report_code" => $return_code
        ];

        if ($return_claim_code !== "") {

            $claim_sql .= "
                AND claims.verification_code = :claim_code
            ";

            $claim_params[":claim_code"] = $return_claim_code;
        }

        $claim_sql .= "
            FOR UPDATE OF claims
        ";

        $stmt = $supabase->prepare($claim_sql);
        $stmt->execute($claim_params);

        $claim = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$claim) {
            throw new Exception(
                "The claim is no longer pending or does not belong to this report."
            );
        }

        $item_id = (int) $claim["item_id"];
        $claimant_id = (int) $claim["user_id"];

        /* =============================================
           APPROVE CLAIM
        ============================================== */

        if ($claim_action === "approve") {

            $stmt = $supabase->prepare("
                UPDATE claims
                SET status = 'approved'
                WHERE id = :claim_id
                  AND status = 'pending'
            ");

            $stmt->execute([
                ":claim_id" => $claim_id
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new Exception("Unable to approve the claim.");
            }

            $stmt = $supabase->prepare("
                UPDATE items
                SET status = 'returned'
                WHERE id = :item_id
            ");

            $stmt->execute([
                ":item_id" => $item_id
            ]);

            $success_message =
                "The claim has been approved. An automatic message has been sent to the claimant.";

            /*
             * AUTOMATIC APPROVAL MESSAGE
             */

            $automatic_message =
                "Your claim request for \"" .
                $claim["item_name"] .
                "\" has been approved. Please coordinate with the RTU Lost and Found office regarding the retrieval of your claimed item.";

        } else {

            /* =========================================
               REJECT CLAIM
            ========================================== */

            $stmt = $supabase->prepare("
                UPDATE claims
                SET status = 'rejected'
                WHERE id = :claim_id
                  AND status = 'pending'
            ");

            $stmt->execute([
                ":claim_id" => $claim_id
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new Exception("Unable to reject the claim.");
            }

            $stmt = $supabase->prepare("
                UPDATE items
                SET status = 'found'
                WHERE id = :item_id
            ");

            $stmt->execute([
                ":item_id" => $item_id
            ]);

            $success_message =
                "The claim has been rejected. The claimant has been notified of the reason.";

            /*
             * AUTOMATIC REJECTION MESSAGE
             */

            $automatic_message =
                "Your claim request for \"" .
                $claim["item_name"] .
                "\" has been rejected.\n\n" .
                "Reason provided by the administrator:\n" .
                $rejection_reason;
        }

        /* =============================================
           SEND AUTOMATIC MESSAGE TO CLAIMANT
        ============================================== */

        $message_stmt = $supabase->prepare("
            INSERT INTO messages (
                sender_id,
                receiver_id,
                item_id,
                message,
                image_path,
                is_read
            )
            VALUES (
                :sender_id,
                :receiver_id,
                :item_id,
                :message,
                NULL,
                FALSE
            )
        ");

        $message_stmt->execute([
            ":sender_id" => $user_id,
            ":receiver_id" => $claimant_id,
            ":item_id" => $item_id,
            ":message" => $automatic_message
        ]);

        /*
         * Commit both the claim decision and message together.
         */

        $supabase->commit();

        $_SESSION["verification_action_success"] = $success_message;

        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

        header("Location: " . $redirect_url);
        exit();

    } catch (Throwable $e) {

        if ($supabase->inTransaction()) {
            $supabase->rollBack();
        }

        error_log(
            "Item Verification Claim Action Error: " .
            $e->getMessage()
        );

        $_SESSION["verification_action_error"] =
            "Unable to process the claim. It may have already been updated, or the automatic message could not be sent.";

        header("Location: " . $redirect_url);
        exit();
    }
}

/* =========================================================
   DELETE VERIFIED ITEM
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["delete_item_id"])
) {

    $delete_id = filter_var(
        $_POST["delete_item_id"],
        FILTER_VALIDATE_INT
    );

    $return_code = strtoupper(
        trim($_POST["verification_code"] ?? "")
    );

    if (!validCsrfToken()) {

        $_SESSION["verification_action_error"] =
            "Invalid request. Please refresh the page and try again.";

        header("Location: items.php?code=" . urlencode($return_code));
        exit();
    }

    if (
        !$delete_id ||
        !preg_match('/^RTU-(LST|FND)-[A-F0-9]{8,16}$/', $return_code)
    ) {

        $_SESSION["verification_action_error"] =
            "Invalid item selected.";

        header("Location: items.php");
        exit();
    }

    $item_image = "";
    $item_type = "";
    $claim_images = [];

    try {

        $supabase->beginTransaction();

        $stmt = $supabase->prepare("
            SELECT id, item_type, image
            FROM items
            WHERE id = :item_id
              AND verification_code = :verification_code
            FOR UPDATE
        ");

        $stmt->execute([
            ":item_id" => $delete_id,
            ":verification_code" => $return_code
        ]);

        $item_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item_data) {
            throw new Exception("The selected report could not be found.");
        }

        $item_type = $item_data["item_type"];
        $item_image = $item_data["image"] ?? "";

        /* Collect claim proof images */

        $stmt = $supabase->prepare("
            SELECT proof_image
            FROM claims
            WHERE item_id = :item_id
        ");

        $stmt->execute([
            ":item_id" => $delete_id
        ]);

        $related_claims = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($related_claims as $related_claim) {

            if (!empty($related_claim["proof_image"])) {
                $claim_images[] = $related_claim["proof_image"];
            }
        }

        /* Delete related claims */

        $stmt = $supabase->prepare("
            DELETE FROM claims
            WHERE item_id = :item_id
        ");

        $stmt->execute([
            ":item_id" => $delete_id
        ]);

        /* Delete related AI matches */

        $stmt = $supabase->prepare("
            DELETE FROM ai_matches
            WHERE lost_item_id = :lost_item_id
               OR found_item_id = :found_item_id
        ");

        $stmt->execute([
            ":lost_item_id" => $delete_id,
            ":found_item_id" => $delete_id
        ]);

        /* Delete report */

        $stmt = $supabase->prepare("
            DELETE FROM items
            WHERE id = :item_id
              AND verification_code = :verification_code
        ");

        $stmt->execute([
            ":item_id" => $delete_id,
            ":verification_code" => $return_code
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new Exception("The report could not be deleted.");
        }

        $supabase->commit();

        /* Remove report image */

        if (!empty($item_image)) {

            if ($item_type === "lost") {
                $image_directory = __DIR__ . "/../uploads/lost/";
            } elseif ($item_type === "found") {
                $image_directory = __DIR__ . "/../uploads/found/";
            } else {
                $image_directory = "";
            }

            if ($image_directory !== "") {

                $image_file =
                    $image_directory . basename($item_image);

                if (is_file($image_file)) {
                    @unlink($image_file);
                }
            }
        }

        /* Remove claim proof images */

        foreach ($claim_images as $claim_image) {

            $claim_file =
                __DIR__ . "/../uploads/claims/" .
                basename($claim_image);

            if (is_file($claim_file)) {
                @unlink($claim_file);
            }
        }

        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

        header("Location: items.php?deleted=1");
        exit();

    } catch (Throwable $e) {

        if ($supabase->inTransaction()) {
            $supabase->rollBack();
        }

        error_log("Admin item deletion error: " . $e->getMessage());

        $_SESSION["verification_action_error"] =
            "Unable to delete this report. Please check its database relationships and try again.";

        header(
            "Location: items.php?code=" . urlencode($return_code)
        );

        exit();
    }
}

/* =========================================================
   RETRIEVE SESSION MESSAGES
========================================================= */

if (isset($_SESSION["verification_action_success"])) {

    $action_message = $_SESSION["verification_action_success"];

    unset($_SESSION["verification_action_success"]);
}

if (isset($_SESSION["verification_action_error"])) {

    $lookup_error = $_SESSION["verification_action_error"];

    unset($_SESSION["verification_action_error"]);
}

/* =========================================================
   DETERMINE SEARCH TYPE
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["lookup_report"])
) {

    if (!validCsrfToken()) {

        $lookup_error =
            "Invalid request. Please refresh the page and try again.";

    } else {

        $verification_code = strtoupper(
            trim($_POST["verification_code"] ?? "")
        );

        $search_type = "report";
    }

} elseif (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["lookup_claim"])
) {

    if (!validCsrfToken()) {

        $lookup_error =
            "Invalid request. Please refresh the page and try again.";

    } else {

        $claim_verification_code = strtoupper(
            trim($_POST["claim_verification_code"] ?? "")
        );

        $search_type = "claim";
        $active_tab = "claims";
    }

} elseif (isset($_GET["claim_code"])) {

    $claim_verification_code = strtoupper(
        trim($_GET["claim_code"])
    );

    $search_type = "claim";
    $active_tab = "claims";

} elseif (isset($_GET["code"])) {

    $verification_code = strtoupper(
        trim($_GET["code"])
    );
}

if (isset($_GET["tab"])) {

    $requested_tab = $_GET["tab"];

    if (
        in_array(
            $requested_tab,
            ["overview", "matches", "claims", "manage"],
            true
        )
    ) {
        $active_tab = $requested_tab;
    }
}

/* =========================================================
   LOOK UP CLAIM BY CLAIM VERIFICATION CODE
========================================================= */

if (
    $search_type === "claim" &&
    $claim_verification_code !== "" &&
    empty($lookup_error)
) {

    if (
        !preg_match(
            '/^RTU-CLM-[A-F0-9]{8}$/',
            $claim_verification_code
        )
    ) {

        $lookup_error =
            "Invalid claim verification code. Please check the code and try again.";

    } else {

        try {

            $stmt = $supabase->prepare("
                SELECT
                    claims.id AS selected_claim_id,
                    claims.verification_code AS claim_verification_code,
                    claims.item_id,
                    items.verification_code AS report_verification_code
                FROM claims
                INNER JOIN items
                    ON claims.item_id = items.id
                WHERE claims.verification_code = :claim_code
                LIMIT 1
            ");

            $stmt->execute([
                ":claim_code" => $claim_verification_code
            ]);

            $claim_lookup = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$claim_lookup) {

                $lookup_error =
                    "No claim was found using that verification code.";

            } else {

                $selected_claim_id =
                    (int) $claim_lookup["selected_claim_id"];

                $verification_code = strtoupper(
                    $claim_lookup["report_verification_code"] ?? ""
                );

                if ($verification_code === "") {

                    $lookup_error =
                        "The report associated with this claim does not have a verification code.";
                }
            }

        } catch (Throwable $e) {

            error_log("Claim Verification Lookup Error: " . $e->getMessage());

            $lookup_error =
                "Unable to retrieve the claim. Please try again.";
        }
    }
}

/* =========================================================
   LOOK UP REPORT AND LOAD RELATED INFORMATION
========================================================= */

if (
    $verification_code !== "" &&
    empty($lookup_error)
) {

    if (
        !preg_match(
            '/^RTU-(LST|FND)-[A-F0-9]{8,16}$/',
            $verification_code
        )
    ) {

        $lookup_error =
            "Invalid verification code format. Please check the code and try again.";

    } else {

        try {

            /* GET REPORT AND REPORTER INFORMATION */

            $stmt = $supabase->prepare("
                SELECT
                    items.*,
                    users.name AS reporter_name,
                    users.student_id AS reporter_student_id,
                    users.email AS reporter_email
                FROM items
                INNER JOIN users
                    ON items.user_id = users.id
                WHERE items.verification_code = :verification_code
                LIMIT 1
            ");

            $stmt->execute([
                ":verification_code" => $verification_code
            ]);

            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {

                $lookup_error =
                    "No report was found using that verification code.";

            } else {

                $selected_item_id = (int) $item["id"];

                /* GET AI MATCHES */

                try {

                    if ($item["item_type"] === "found") {

                        $match_sql = "
                            SELECT
                                ai_matches.similarity_score,
                                candidate.id AS candidate_id,
                                candidate.item_name AS candidate_name,
                                candidate.item_type AS candidate_type,
                                candidate.category AS candidate_category,
                                candidate.description AS candidate_description,
                                candidate.location AS candidate_location,
                                candidate.date_reported AS candidate_date,
                                candidate.image AS candidate_image
                            FROM ai_matches
                            INNER JOIN items AS candidate
                                ON ai_matches.lost_item_id = candidate.id
                            WHERE ai_matches.found_item_id = :item_id
                              AND candidate.item_type = 'lost'
                            ORDER BY ai_matches.similarity_score DESC
                        ";

                    } else {

                        $match_sql = "
                            SELECT
                                ai_matches.similarity_score,
                                candidate.id AS candidate_id,
                                candidate.item_name AS candidate_name,
                                candidate.item_type AS candidate_type,
                                candidate.category AS candidate_category,
                                candidate.description AS candidate_description,
                                candidate.location AS candidate_location,
                                candidate.date_reported AS candidate_date,
                                candidate.image AS candidate_image
                            FROM ai_matches
                            INNER JOIN items AS candidate
                                ON ai_matches.found_item_id = candidate.id
                            WHERE ai_matches.lost_item_id = :item_id
                              AND candidate.item_type = 'found'
                            ORDER BY ai_matches.similarity_score DESC
                        ";
                    }

                    $match_stmt = $supabase->prepare($match_sql);

                    $match_stmt->execute([
                        ":item_id" => $selected_item_id
                    ]);

                    $matches = $match_stmt->fetchAll(PDO::FETCH_ASSOC);

                } catch (Throwable $e) {

                    error_log(
                        "Item Verification AI Matches Error: " .
                        $e->getMessage()
                    );

                    $matches_error =
                        "The report was found, but its AI matches could not be loaded.";
                }

                /* GET CLAIMS FOR THIS REPORT */

                try {

                    $claim_sql = "
                        SELECT
                            claims.*,
                            items.item_name,
                            items.category,
                            items.description AS item_description,
                            items.location,
                            items.date_reported,
                            users.name,
                            users.student_id,
                            users.email
                        FROM claims
                        INNER JOIN items
                            ON claims.item_id = items.id
                        INNER JOIN users
                            ON claims.user_id = users.id
                        WHERE claims.item_id = :item_id
                        ORDER BY claims.created_at DESC
                    ";

                    $claim_stmt = $supabase->prepare($claim_sql);

                    $claim_stmt->execute([
                        ":item_id" => $selected_item_id
                    ]);

                    $claims = $claim_stmt->fetchAll(PDO::FETCH_ASSOC);

                } catch (Throwable $e) {

                    error_log(
                        "Item Verification Claims Error: " .
                        $e->getMessage()
                    );

                    $claims_error =
                        "The report was found, but its claims could not be loaded.";
                }
            }

        } catch (Throwable $e) {

            error_log("Item Verification Lookup Error: " . $e->getMessage());

            $lookup_error =
                "Unable to retrieve the report. Please try again.";
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

<title>Item Verification - RTU Lost and Found</title>

<style>

/* =========================================================
   GLOBAL
========================================================= */

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
    line-height: 1.6;
}

a {
    text-decoration: none;
}

/* =========================================================
   TOP NAVBAR
========================================================= */

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
    gap: 0;
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

/* =========================================================
   ADMIN NAVIGATION
========================================================= */

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

/* =========================================================
   MAIN CONTAINER
========================================================= */

.container {
    width: 92%;
    max-width: 1200px;
    margin: 28px auto 50px;
}

/* =========================================================
   PAGE HEADER
========================================================= */

.page-header {
    background: #fff;
    padding: 25px;
    border-radius: 16px;
    margin-bottom: 25px;
    border-top: 4px solid #d4af37;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
}

.page-header h1 {
    color: #0f3d75;
    font-size: 27px;
    margin-bottom: 8px;
}

.page-header p {
    color: #64748b;
    font-size: 14px;
    line-height: 1.6;
}

/* =========================================================
   ALERTS
========================================================= */

.alert {
    padding: 14px 18px;
    margin-bottom: 20px;
    border-radius: 9px;
    font-size: 14px;
    line-height: 1.5;
}

.alert-success {
    background: #dcfce7;
    border: 1px solid #86efac;
    color: #166534;
}

.alert-error {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
}

/* =========================================================
   SEARCH
========================================================= */

.search-card {
    background: #fff;
    padding: 28px;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    border-top: 4px solid #d4af37;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
    margin-bottom: 25px;
}

.search-card h2 {
    color: #0f3d75;
    font-size: 21px;
    margin-bottom: 9px;
}

.search-card p {
    color: #64748b;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 20px;
}

.search-form {
    display: flex;
    align-items: stretch;
    gap: 10px;
}

.search-input {
    flex: 1;
    min-width: 0;
    height: 46px;
    padding: 0 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    outline: none;
    font-size: 15px;
    font-family: Arial, Helvetica, sans-serif;
    text-transform: uppercase;
}

.search-input:focus {
    border-color: #164a8a;
    box-shadow: 0 0 0 3px rgba(22,74,138,0.10);
}

.search-button {
    min-height: 46px;
    padding: 0 22px;
    background: #0f3d75;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.18s;
}

.search-button:hover {
    background: #174ea6;
}

/* =========================================================
   VERIFIED REPORT CARD
========================================================= */

.item-card {
    background: #fff;
    padding: 25px;
    margin-bottom: 25px;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
}

.item-card > h2 {
    color: #0f3d75;
    font-size: 21px;
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e7eb;
}

/* =========================================================
   VERIFICATION CODE
========================================================= */

.code-panel {
    background: #f8fbff;
    border: 1px solid #cbdcf5;
    border-left: 5px solid #d4af37;
    border-radius: 10px;
    padding: 17px;
    margin-bottom: 22px;
}

.code-panel p {
    color: #64748b;
    font-size: 13px;
    margin-bottom: 8px;
}

.code-value {
    color: #0f3d75;
    font-size: 18px;
    font-weight: bold;
    letter-spacing: 1px;
    overflow-wrap: anywhere;
}

/* =========================================================
   ITEM INFORMATION
========================================================= */

.item-info {
    line-height: 1.7;
}

.item-info p {
    margin-bottom: 8px;
    font-size: 14px;
    color: #475569;
    overflow-wrap: anywhere;
}

.item-info strong {
    color: #1f2937;
}

/* =========================================================
   BADGES
========================================================= */

.badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: bold;
    text-transform: capitalize;
}

.lost,
.rejected {
    background: #fee2e2;
    color: #b91c1c;
}

.found,
.approved {
    background: #dcfce7;
    color: #15803d;
}

.claim_pending,
.pending {
    background: #fef3c7;
    color: #92400e;
}

.returned {
    background: #dbeafe;
    color: #1d4ed8;
}

/* =========================================================
   PHOTOS
========================================================= */

.item-photo {
    width: 200px;
    max-height: 200px;
    object-fit: contain;
    display: block;
    border: 1px solid #dbe2ea;
    border-radius: 10px;
    margin-top: 10px;
    background: #f8fafc;
}

.proof-image {
    width: 220px;
    max-height: 220px;
    object-fit: contain;
    display: block;
    margin-top: 10px;
    background: white;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 5px;
}

.match-photo {
    width: 150px;
    max-height: 150px;
    object-fit: contain;
    display: block;
    border: 1px solid #dbe2ea;
    border-radius: 8px;
    margin-top: 8px;
    background: #f8fafc;
}

/* =========================================================
   TABS
========================================================= */

.tab-navigation {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 8px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    margin-bottom: 22px;
}

.tab-button {
    flex: 1;
    min-width: 120px;
    padding: 12px 15px;
    border: none;
    border-radius: 7px;
    background: transparent;
    color: #334155;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.18s;
}

.tab-button:hover {
    background: #e2e8f0;
}

.tab-button.active {
    background: #0f3d75;
    color: #fff;
    box-shadow: 0 2px 7px rgba(15,61,117,0.18);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* =========================================================
   INFORMATION SECTIONS
========================================================= */

.info-section {
    margin-top: 18px;
    padding: 19px;
    background: #f8fafc;
    border-radius: 9px;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #164a8a;
}

.info-section h3 {
    color: #0f2f5f;
    font-size: 16px;
    margin-bottom: 10px;
}

.info-section p {
    color: #374151;
    font-size: 14px;
    margin-bottom: 5px;
    overflow-wrap: anywhere;
}

/* =========================================================
   BUTTONS
========================================================= */

.action-buttons,
.actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 18px;
    padding-top: 18px;
    border-top: 1px solid #e5e7eb;
}

.view-button,
.delete-button,
.button {
    display: inline-block;
    padding: 10px 15px;
    border: none;
    border-radius: 8px;
    color: #fff;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.18s;
    font-family: Arial, Helvetica, sans-serif;
    text-align: center;
}

.view-button {
    background: #0f3d75;
}

.view-button:hover {
    background: #174ea6;
}

.delete-button,
.reject {
    background: #b91c1c;
}

.delete-button:hover,
.reject:hover {
    background: #991b1b;
}

.approve {
    background: #16803c;
}

.approve:hover {
    background: #126b32;
}

/* =========================================================
   SECTION HEADINGS
========================================================= */

.section-heading {
    color: #0f3d75;
    font-size: 21px;
    margin-bottom: 8px;
}

.section-description {
    color: #64748b;
    font-size: 14px;
    margin-bottom: 18px;
    line-height: 1.6;
}

/* =========================================================
   AI MATCHES
========================================================= */

.ai-section {
    padding: 5px 0;
}

.ai-match {
    background: #fff;
    padding: 20px;
    margin-top: 15px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 3px 12px rgba(0,0,0,0.05);
}

.ai-match h4 {
    color: #0f3d75;
    font-size: 17px;
    margin-bottom: 13px;
}

.ai-match p {
    color: #475569;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 8px;
}

.ai-match strong {
    color: #1f2937;
}

.score {
    display: inline-block;
    margin: 12px 0;
    padding: 8px 12px;
    border-radius: 7px;
    background: #fff8dc;
    border: 1px solid #e8cf72;
    color: #92701c !important;
    font-size: 17px !important;
    font-weight: bold;
}

/* =========================================================
   CLAIM CARDS
========================================================= */

.claim-card {
    background: #fff;
    padding: 24px;
    margin-bottom: 20px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    border-top: 3px solid #d4af37;
    box-shadow: 0 3px 14px rgba(0,0,0,0.05);
}

.claim-card.selected-claim {
    border: 2px solid #164a8a;
    border-top: 4px solid #d4af37;
    background: #f8fbff;
}

.claim-card h3 {
    color: #0f2f5f;
    font-size: 19px;
    margin-bottom: 15px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.claim-info p {
    margin-bottom: 7px;
    font-size: 14px;
    line-height: 1.7;
    overflow-wrap: anywhere;
}

.claim-info strong {
    color: #263445;
}

.selected-label {
    display: inline-block;
    padding: 5px 9px;
    margin-bottom: 12px;
    border-radius: 6px;
    background: #dbeafe;
    color: #1d4ed8;
    font-size: 12px;
    font-weight: bold;
}

/* =========================================================
   REJECTION REASON FIELD
========================================================= */

.rejection-reason {
    display: block;
    width: 100%;
    min-height: 105px;
    padding: 12px 14px;
    margin-bottom: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #fff;
    color: #1f2937;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
    line-height: 1.6;
    resize: vertical;
    outline: none;
}

.rejection-reason:focus {
    border-color: #164a8a;
    box-shadow: 0 0 0 3px rgba(22,74,138,0.10);
}

.rejection-reason::placeholder {
    color: #94a3b8;
}

.rejection-form {
    width: 100%;
}

/* =========================================================
   EMPTY STATES
========================================================= */

.empty {
    background: #fff;
    padding: 35px 25px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    text-align: center;
    color: #64748b;
    margin-top: 15px;
}

.empty h3 {
    color: #0f3d75;
    margin-bottom: 8px;
}

.warning-note {
    margin-top: 22px;
    padding: 13px 15px;
    background: #fff8dc;
    border: 1px solid #e8cf72;
    border-radius: 8px;
    color: #795f16;
    font-size: 13px;
    line-height: 1.6;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 800px) {

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

    .page-header {
        padding: 21px;
    }

    .page-header h1 {
        font-size: 23px;
    }

    .item-card {
        padding: 20px;
    }
}

@media (max-width: 600px) {

    .brand-title {
        font-size: 17px;
    }

    .brand-logo {
        width: 40px;
        height: 40px;
    }

    .admin-nav a {
        font-size: 12px;
        padding: 0 10px;
    }

    .search-form {
        flex-direction: column;
    }

    .search-button {
        width: 100%;
    }

    .tab-navigation {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .tab-button {
        min-width: 0;
        padding: 11px 8px;
    }

    .action-buttons,
    .actions {
        align-items: stretch;
        flex-direction: column;
    }

    .action-buttons a,
    .action-buttons button,
    .actions a,
    .actions button {
        width: 100%;
    }

    .item-photo,
    .proof-image {
        width: 100%;
        max-width: 300px;
    }
}

/* =========================================================
   PRINT
========================================================= */

@media print {

    .navbar,
    .admin-nav,
    .search-card,
    .tab-navigation,
    .action-buttons,
    .actions {
        display: none !important;
    }

    .tab-content {
        display: block !important;
    }
}

</style>

</head>

<body>

<!-- =======================================================
     TOP NAVBAR
======================================================= -->

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

<!-- =======================================================
     ADMIN NAVIGATION
======================================================= -->

<nav class="admin-nav">

    <a href="items.php" class="active">Item Verification</a>
    <a href="users.php">Manage Users</a>

</nav>

<!-- =======================================================
     MAIN CONTENT
======================================================= -->

<div class="container">

    <div class="page-header">

        <h1>Item Verification</h1>

        <p>
            Search using a report verification code or a claim verification code
            to review the associated item, AI matches, and submitted claims.
        </p>

    </div>

    <?php if (isset($_GET["deleted"]) && $_GET["deleted"] === "1"): ?>

        <div class="alert alert-success">
            The item and its related claims and AI matches have been removed from the system.
        </div>

    <?php endif; ?>

    <?php if (!empty($action_message)): ?>

        <div class="alert alert-success">
            <?php echo e($action_message); ?>
        </div>

    <?php endif; ?>

    <?php if (!empty($lookup_error)): ?>

        <div class="alert alert-error">
            <?php echo e($lookup_error); ?>
        </div>

    <?php endif; ?>

    <?php if (!empty($delete_error)): ?>

        <div class="alert alert-error">
            <?php echo e($delete_error); ?>
        </div>

    <?php endif; ?>

    <!-- ===================================================
         REPORT VERIFICATION SEARCH
    ==================================================== -->

    <div class="search-card">

        <h2>Search Report by Verification Code</h2>

        <p>
            Ask the reporter for the verification code displayed after
            submitting their report or inside their My Reports page.
        </p>

        <form method="POST" action="items.php" class="search-form">

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo e($csrf_token); ?>"
            >

            <input
                type="text"
                name="verification_code"
                class="search-input"
                placeholder="Example: RTU-LST-A1B2C3D4"
                value="<?php echo e($verification_code); ?>"
                maxlength="24"
                autocomplete="off"
                required
            >

            <button
                type="submit"
                name="lookup_report"
                class="search-button"
            >
                Verify Report
            </button>

        </form>

    </div>

    <!-- ===================================================
         CLAIM VERIFICATION SEARCH
    ==================================================== -->

    <div class="search-card">

        <h2>Search Claim by Verification Code</h2>

        <p>
            Ask the claimant for the verification code displayed after
            submitting their claim request.
        </p>

        <form method="POST" action="items.php" class="search-form">

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo e($csrf_token); ?>"
            >

            <input
                type="text"
                name="claim_verification_code"
                class="search-input"
                placeholder="Example: RTU-CLM-A1B2C3D4"
                value="<?php echo e($claim_verification_code); ?>"
                maxlength="24"
                autocomplete="off"
                required
            >

            <button
                type="submit"
                name="lookup_claim"
                class="search-button"
            >
                Search Claim
            </button>

        </form>

    </div>

    <!-- ===================================================
         VERIFIED REPORT AND CLAIM
    ==================================================== -->

    <?php if ($item): ?>

        <div class="item-card">

            <h2>
                <?php
                echo $search_type === "claim"
                    ? "Verified Claim and Associated Item"
                    : "Verified Report";
                ?>
            </h2>

            <?php if ($search_type === "claim"): ?>

                <div class="code-panel">

                    <p>Claim Verification Code</p>

                    <div class="code-value">
                        <?php echo e($claim_verification_code); ?>
                    </div>

                </div>

            <?php endif; ?>

            <div class="code-panel">

                <p>Report Verification Code</p>

                <div class="code-value">
                    <?php echo e($item["verification_code"]); ?>
                </div>

            </div>

            <!-- TAB NAVIGATION -->

            <div class="tab-navigation">

                <button
                    type="button"
                    class="tab-button"
                    data-tab="overview"
                >
                    Overview
                </button>

                <button
                    type="button"
                    class="tab-button"
                    data-tab="matches"
                >
                    AI Matches (<?php echo count($matches); ?>)
                </button>

                <button
                    type="button"
                    class="tab-button"
                    data-tab="claims"
                >
                    Claims (<?php echo count($claims); ?>)
                </button>

                <button
                    type="button"
                    class="tab-button"
                    data-tab="manage"
                >
                    Manage Item
                </button>

            </div>

            <!-- ===========================================
                 OVERVIEW TAB
            ============================================ -->

            <section
                class="tab-content"
                id="tab-overview"
            >

                <h3 class="section-heading">Report Information</h3>

                <p class="section-description">
                    Review the details and reporter information associated with this report.
                </p>

                <div class="item-info">

                    <p>
                        <strong>Item ID:</strong>
                        <?php echo (int) $item["id"]; ?>
                    </p>

                    <p>
                        <strong>Item Name:</strong>
                        <?php echo e($item["item_name"]); ?>
                    </p>

                    <p>
                        <strong>Type:</strong>
                        <?php echo e(ucfirst($item["item_type"])); ?>
                    </p>

                    <p>
                        <strong>Category:</strong>
                        <?php echo e($item["category"] ?? "Not specified"); ?>
                    </p>

                    <p>
                        <strong>Description:</strong>
                        <?php echo nl2br(e($item["description"] ?? "")); ?>
                    </p>

                    <p>
                        <strong>Location:</strong>
                        <?php echo e($item["location"] ?? "Not specified"); ?>
                    </p>

                    <p>
                        <strong>Date Reported:</strong>
                        <?php echo e($item["date_reported"] ?? "Not specified"); ?>
                    </p>

                    <p>
                        <strong>Reported By:</strong>
                        <?php echo e($item["reporter_name"]); ?>
                    </p>

                    <p>
                        <strong>Reporter Student ID:</strong>
                        <?php echo e($item["reporter_student_id"] ?? "Not specified"); ?>
                    </p>

                    <p>
                        <strong>Reporter Email:</strong>
                        <?php echo e($item["reporter_email"] ?? "Not specified"); ?>
                    </p>

                    <p>
                        <strong>Status:</strong>

                        <span class="badge <?php echo e($item["status"]); ?>">
                            <?php
                            echo e(
                                ucfirst(
                                    str_replace("_", " ", $item["status"])
                                )
                            );
                            ?>
                        </span>

                    </p>

                </div>

                <?php if (!empty($item["image"])): ?>

                    <?php

                    $image_directory = (
                        $item["item_type"] === "lost"
                    ) ? "../uploads/lost/" : "../uploads/found/";

                    $image_path = $image_directory .
                        rawurlencode(basename($item["image"]));

                    ?>

                    <div class="info-section">

                        <h3>Reported Item Photo</h3>

                        <img
                            src="<?php echo e($image_path); ?>"
                            alt="Reported Item Photo"
                            class="item-photo"
                        >

                    </div>

                <?php endif; ?>

                <div class="action-buttons">

                    <a
                        class="view-button"
                        href="view_item.php?id=<?php echo (int) $item["id"]; ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        View Full Report
                    </a>

                </div>

            </section>

            <!-- ===========================================
                 AI MATCHES TAB
            ============================================ -->

            <section
                class="tab-content"
                id="tab-matches"
            >

                <h3 class="section-heading">AI Possible Matches</h3>

                <?php if ($item["item_type"] === "found"): ?>

                    <p class="section-description">
                        The system compares this found item's image with reported lost items to identify possible matches.
                    </p>

                <?php else: ?>

                    <p class="section-description">
                        The system compares this lost item's image with reported found items to identify possible matches.
                    </p>

                <?php endif; ?>

                <?php if (!empty($matches_error)): ?>

                    <div class="alert alert-error">
                        <?php echo e($matches_error); ?>
                    </div>

                <?php elseif (!empty($matches)): ?>

                    <?php foreach ($matches as $match): ?>

                        <div class="ai-match">

                            <h4>AI Match Candidate</h4>

                            <p>
                                <strong>Matched Item:</strong>
                                <?php echo e($match["candidate_name"]); ?>
                            </p>

                            <p>
                                <strong>Item Type:</strong>
                                <?php echo e(ucfirst($match["candidate_type"])); ?>
                            </p>

                            <p>
                                <strong>Category:</strong>
                                <?php echo e($match["candidate_category"] ?? "Not specified"); ?>
                            </p>

                            <p>
                                <strong>Description:</strong>
                                <?php
                                echo nl2br(
                                    e($match["candidate_description"] ?? "No description provided.")
                                );
                                ?>
                            </p>

                            <p>
                                <strong>Location:</strong>
                                <?php echo e($match["candidate_location"] ?? "Not specified"); ?>
                            </p>

                            <p>
                                <strong>Date Reported:</strong>
                                <?php echo e($match["candidate_date"] ?? "Not specified"); ?>
                            </p>

                            <p class="score">
                                AI Similarity:
                                <?php
                                echo number_format(
                                    (float) $match["similarity_score"],
                                    2
                                );
                                ?>%
                            </p>

                            <?php if (!empty($match["candidate_image"])): ?>

                                <?php

                                $match_directory = (
                                    $match["candidate_type"] === "lost"
                                ) ? "../uploads/lost/" : "../uploads/found/";

                                $match_image_path = $match_directory .
                                    rawurlencode(
                                        basename($match["candidate_image"])
                                    );

                                ?>

                                <p>
                                    <strong>Matched Item Photo:</strong>
                                </p>

                                <img
                                    src="<?php echo e($match_image_path); ?>"
                                    alt="Matched Item Photo"
                                    class="match-photo"
                                >

                            <?php endif; ?>

                            <br><br>

                            <a
                                class="view-button"
                                href="view_item.php?id=<?php echo (int) $match["candidate_id"]; ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                View Matched Report
                            </a>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty">

                        <h3>No AI Matches Found</h3>

                        <p>
                            There are currently no possible AI matches associated with this report.
                        </p>

                    </div>

                <?php endif; ?>

            </section>

            <!-- ===========================================
                 CLAIMS TAB
            ============================================ -->

            <section
                class="tab-content"
                id="tab-claims"
            >

                <h3 class="section-heading">Manage Claims</h3>

                <p class="section-description">
                    Review claims submitted for this specific report. Only claims associated with the verified item are displayed here.
                    Approved and rejected decisions automatically send a message to the claimant.
                </p>

                <?php if (!empty($claims_error)): ?>

                    <div class="alert alert-error">
                        <?php echo e($claims_error); ?>
                    </div>

                <?php elseif (!empty($claims)): ?>

                    <?php foreach ($claims as $claim): ?>

                        <?php

                        $is_selected_claim = (
                            $selected_claim_id !== null &&
                            (int) $claim["id"] === (int) $selected_claim_id
                        );

                        ?>

                        <div
                            class="claim-card <?php echo $is_selected_claim ? 'selected-claim' : ''; ?>"
                            id="claim-<?php echo (int) $claim["id"]; ?>"
                        >

                            <?php if ($is_selected_claim): ?>

                                <span class="selected-label">
                                    Claim Found Using Verification Code
                                </span>

                            <?php endif; ?>

                            <h3>
                                <?php echo e($claim["item_name"]); ?>
                            </h3>

                            <div class="claim-info">

                                <p>
                                    <strong>Claim ID:</strong>
                                    <?php echo (int) $claim["id"]; ?>
                                </p>

                                <p>
                                    <strong>Claim Verification Code:</strong>

                                    <span class="code-value">
                                        <?php
                                        echo e(
                                            $claim["verification_code"] ?? "Not available"
                                        );
                                        ?>
                                    </span>
                                </p>

                                <p>
                                    <strong>Claim Status:</strong>

                                    <span class="badge <?php echo e($claim["status"]); ?>">
                                        <?php echo e(ucfirst($claim["status"])); ?>
                                    </span>

                                </p>

                                <p>
                                    <strong>Claim Submitted:</strong>
                                    <?php echo e($claim["created_at"] ?? "Not available"); ?>
                                </p>

                            </div>

                            <div class="info-section">

                                <h3>Claimant Information</h3>

                                <p>
                                    <strong>Name:</strong>
                                    <?php echo e($claim["name"]); ?>
                                </p>

                                <p>
                                    <strong>Student ID:</strong>
                                    <?php echo e($claim["student_id"] ?? "Not specified"); ?>
                                </p>

                                <p>
                                    <strong>Email:</strong>
                                    <?php echo e($claim["email"]); ?>
                                </p>

                            </div>

                            <div class="info-section">

                                <h3>Found Item Description</h3>

                                <p>
                                    <?php
                                    echo nl2br(
                                        e($claim["item_description"] ?? "")
                                    );
                                    ?>
                                </p>

                            </div>

                            <div class="info-section">

                                <h3>Claim Explanation</h3>

                                <p>
                                    <?php

                                    if (!empty($claim["claim_description"])) {

                                        echo nl2br(
                                            e($claim["claim_description"])
                                        );

                                    } else {

                                        echo "No claim explanation provided.";

                                    }

                                    ?>
                                </p>

                            </div>

                            <?php if (!empty($claim["proof_image"])): ?>

                                <div class="info-section">

                                    <h3>Proof Image</h3>

                                    <img
                                        src="../uploads/claims/<?php
                                            echo rawurlencode(
                                                basename((string) $claim["proof_image"])
                                            );
                                        ?>"
                                        alt="Claim Proof"
                                        class="proof-image"
                                        loading="lazy"
                                    >

                                </div>

                            <?php endif; ?>

                            <div class="actions">

                                <?php if ($claim["status"] === "pending"): ?>

                                    <!-- APPROVE CLAIM FORM -->

                                    <form
                                        method="POST"
                                        action="items.php"
                                        onsubmit="return confirm('Are you sure you want to approve this claim? The claimant will automatically receive an approval message.');"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?php echo e($csrf_token); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="claim_id"
                                            value="<?php echo (int) $claim["id"]; ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="selected_item_id"
                                            value="<?php echo (int) $item["id"]; ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="verification_code"
                                            value="<?php echo e($verification_code); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="claim_verification_code"
                                            value="<?php
                                                echo $is_selected_claim
                                                    ? e($claim_verification_code)
                                                    : '';
                                            ?>"
                                        >

                                        <button
                                            type="submit"
                                            name="claim_action"
                                            value="approve"
                                            class="button approve"
                                        >
                                            Approve and Notify User
                                        </button>

                                    </form>

                                    <!-- REJECT CLAIM FORM -->

                                    <form
                                        method="POST"
                                        action="items.php"
                                        class="rejection-form"
                                        onsubmit="return confirm('Are you sure you want to reject this claim? The claimant will receive your explanation.');"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?php echo e($csrf_token); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="claim_id"
                                            value="<?php echo (int) $claim["id"]; ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="selected_item_id"
                                            value="<?php echo (int) $item["id"]; ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="verification_code"
                                            value="<?php echo e($verification_code); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="claim_verification_code"
                                            value="<?php
                                                echo $is_selected_claim
                                                    ? e($claim_verification_code)
                                                    : '';
                                            ?>"
                                        >

                                        <label
                                            for="rejection_reason_<?php echo (int) $claim["id"]; ?>"
                                            style="display:block; font-weight:bold; color:#0f2f5f; margin-bottom:8px;"
                                        >
                                            Rejection Reason
                                        </label>

                                        <textarea
                                            id="rejection_reason_<?php echo (int) $claim["id"]; ?>"
                                            name="rejection_reason"
                                            class="rejection-reason"
                                            placeholder="Explain why this claim is being rejected. This message will be sent to the claimant."
                                            maxlength="1000"
                                            required
                                        ></textarea>

                                        <button
                                            type="submit"
                                            name="claim_action"
                                            value="reject"
                                            class="button reject"
                                        >
                                            Reject and Notify User
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <p>
                                        This claim has already been processed.
                                        The claimant should receive the automatic decision message.
                                    </p>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty">

                        <h3>No Claims Found</h3>

                        <p>
                            No claims have been submitted for this particular report.
                        </p>

                    </div>

                <?php endif; ?>

            </section>

            <!-- ===========================================
                 MANAGE ITEM TAB
            ============================================ -->

            <section
                class="tab-content"
                id="tab-manage"
            >

                <h3 class="section-heading">Manage Verified Item</h3>

                <p class="section-description">
                    Manage the report after reviewing its details, AI matches, and related claims.
                </p>

                <div class="info-section">

                    <h3>Selected Report</h3>

                    <p>
                        <strong>Item:</strong>
                        <?php echo e($item["item_name"]); ?>
                    </p>

                    <p>
                        <strong>Verification Code:</strong>
                        <?php echo e($item["verification_code"]); ?>
                    </p>

                    <p>
                        <strong>Current Status:</strong>
                        <?php
                        echo e(
                            ucfirst(
                                str_replace("_", " ", $item["status"])
                            )
                        );
                        ?>
                    </p>

                </div>

                <div class="warning-note">

                    <strong>Permanent Removal Warning:</strong>
                    Removing this report also deletes its related claims and AI match records.
                    This action cannot be undone.

                </div>

                <div class="action-buttons">

                    <form
                        method="POST"
                        action="items.php"
                        onsubmit="return confirm('Are you sure you want to permanently remove this report, its related claims, and AI matches? This action cannot be undone.');"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo e($csrf_token); ?>"
                        >

                        <input
                            type="hidden"
                            name="delete_item_id"
                            value="<?php echo (int) $item["id"]; ?>"
                        >

                        <input
                            type="hidden"
                            name="verification_code"
                            value="<?php echo e($verification_code); ?>"
                        >

                        <button
                            type="submit"
                            class="delete-button"
                        >
                            Remove Item Permanently
                        </button>

                    </form>

                </div>

            </section>

            <!-- ADMIN REMINDER -->

            <div class="warning-note">

                <strong>Administrator Reminder:</strong>
                The verification code identifies the report or claim only.
                It does not prove ownership of the reported item.
                Always verify the claimant's identity and supporting evidence before approving a return.

            </div>

        </div>

    <?php endif; ?>

</div>

<script>

/* =========================================================
   PREVENT BACK/FORWARD CACHE
========================================================= */

window.addEventListener("pageshow", function(event) {

    if (event.persisted) {
        window.location.reload();
    }

});

/* =========================================================
   AUTOMATICALLY FORMAT VERIFICATION CODES
========================================================= */

const verificationInputs = document.querySelectorAll(".search-input");

verificationInputs.forEach(function(input) {

    input.addEventListener("input", function() {

        this.value = this.value.toUpperCase().replace(/\s/g, "");

    });

});

/* =========================================================
   TAB NAVIGATION
========================================================= */

const tabButtons = document.querySelectorAll(".tab-button");
const tabContents = document.querySelectorAll(".tab-content");

function showTab(tabName) {

    tabButtons.forEach(function(button) {

        button.classList.toggle(
            "active",
            button.dataset.tab === tabName
        );

    });

    tabContents.forEach(function(content) {

        content.classList.toggle(
            "active",
            content.id === "tab-" + tabName
        );

    });

}

tabButtons.forEach(function(button) {

    button.addEventListener("click", function() {

        showTab(this.dataset.tab);

    });

});

/* =========================================================
   OPEN THE REQUESTED TAB
========================================================= */

const requestedTab = <?php echo json_encode($active_tab); ?>;

if (document.getElementById("tab-" + requestedTab)) {
    showTab(requestedTab);
} else {
    showTab("overview");
}

/* =========================================================
   SCROLL TO CLAIM FOUND BY CLAIM CODE
========================================================= */

const selectedClaimId = <?php
    echo $selected_claim_id !== null
        ? json_encode((int) $selected_claim_id)
        : "null";
?>;

if (selectedClaimId !== null) {

    const selectedClaim = document.getElementById(
        "claim-" + selectedClaimId
    );

    if (selectedClaim) {

        setTimeout(function() {

            selectedClaim.scrollIntoView({
                behavior: "smooth",
                block: "center"
            });

        }, 200);

    }
}

</script>

</body>
</html>
