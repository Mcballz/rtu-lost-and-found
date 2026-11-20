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
   CHECK LOGIN
========================================================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

/* =========================================================
   SUPABASE CONNECTION
========================================================= */

require_once __DIR__ . "/config/supabase.php";

$user_id = (int) $_SESSION["user_id"];

/* =========================================================
   GET CURRENT USER
========================================================= */

$user_stmt = $supabase->prepare("
    SELECT id, student_id, name, email, role
    FROM users
    WHERE id = :user_id
    LIMIT 1
");

$user_stmt->execute([
    ":user_id" => $user_id
]);

$current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/* =========================================================
   GET UNREAD MESSAGES
========================================================= */

$total_unread = 0;

$unread_stmt = $supabase->prepare("
    SELECT COUNT(*) AS total_unread
    FROM messages
    WHERE receiver_id = :user_id
    AND is_read = FALSE
");

$unread_stmt->execute([
    ":user_id" => $user_id
]);

$unread_data = $unread_stmt->fetch(PDO::FETCH_ASSOC);

$total_unread = (int) ($unread_data["total_unread"] ?? 0);

/* =========================================================
   GET USER'S CLAIMS
========================================================= */

$claims_sql = "
    SELECT
        claims.id,
        claims.item_id,
        claims.status,
        claims.created_at,
        items.item_name,
        items.category,
        items.image,
        items.item_type,
        items.status AS item_status,
        items.location,
        items.description
    FROM claims
    INNER JOIN items ON claims.item_id = items.id
    WHERE claims.user_id = :user_id
    ORDER BY claims.created_at DESC
";

$claims_stmt = $supabase->prepare($claims_sql);

$claims_stmt->execute([
    ":user_id" => $user_id
]);

$claims_result = $claims_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_claims = count($claims_result);

/* =========================================================
   GET USER'S REPORTED ITEMS
   INCLUDING VERIFICATION CODE
========================================================= */

$sql = "
    SELECT
        id,
        item_type,
        item_name,
        category,
        description,
        location,
        date_reported,
        image,
        status,
        verification_code,
        created_at
    FROM items
    WHERE user_id = :user_id
    ORDER BY created_at DESC
";

$stmt = $supabase->prepare($sql);

$stmt->execute([
    ":user_id" => $user_id
]);

$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_reports = count($result);

/* =========================================================
   GET AI MATCH RESULTS
========================================================= */

$ai_results = $_SESSION["ai_match_results"] ?? null;
$ai_error = $_SESSION["ai_match_error"] ?? null;

/*
 * Revalidate every saved AI match against the database.
 * The selected report must belong to the logged-in user.
 * Lost reports match found reports, and found reports match lost reports.
 * The candidate must never belong to the logged-in user.
 */

if (
    is_array($ai_results) &&
    !empty($ai_results["matches"]) &&
    is_array($ai_results["matches"])
) {

    $valid_matches = [];

    $source_item_id = (int) ($ai_results["item_id"] ?? 0);
    $source_type = strtolower(trim((string) ($ai_results["item_type"] ?? "")));

    $source_valid = false;

    if ($source_item_id > 0) {

        $source_stmt = $supabase->prepare("
            SELECT id, user_id, item_type, status
            FROM items
            WHERE id = :item_id
              AND user_id = :user_id
            LIMIT 1
        ");

        $source_stmt->execute([
            ":item_id" => $source_item_id,
            ":user_id" => $user_id
        ]);

        $source = $source_stmt->fetch(PDO::FETCH_ASSOC);

        if ($source) {

            $source_type = strtolower(trim((string) $source["item_type"]));
            $source_status = strtolower(trim((string) $source["status"]));

            $source_valid =
                ($source_type === "lost" && $source_status === "lost") ||
                ($source_type === "found" && in_array($source_status, ["found", "claim_pending"], true));
        }
    }

    if ($source_valid && in_array($source_type, ["lost", "found"], true)) {

        $check_match_stmt = $supabase->prepare("
            SELECT
                l.id AS lost_item_id,
                l.user_id AS lost_user_id,
                l.item_name AS lost_name,
                l.description AS lost_description,
                l.location AS lost_location,
                l.image AS lost_image,
                l.status AS lost_status,

                f.id AS found_item_id,
                f.user_id AS found_user_id,
                f.item_name AS found_name,
                f.description AS found_description,
                f.location AS found_location,
                f.image AS found_image,
                f.status AS found_status

            FROM items AS l
            INNER JOIN items AS f
                ON f.id = :found_item_id

            WHERE l.id = :lost_item_id
              AND l.item_type = 'lost'
              AND f.item_type = 'found'
              AND l.user_id <> f.user_id
              AND l.status = 'lost'
              AND f.status IN ('found', 'claim_pending')

            LIMIT 1
        ");

        foreach ($ai_results["matches"] as $match) {

            $lost_item_id = (int) ($match["lost_item_id"] ?? 0);
            $found_item_id = (int) ($match["found_item_id"] ?? 0);

            if ($lost_item_id <= 0 || $found_item_id <= 0) {
                continue;
            }

            if (
                ($source_type === "lost" && $lost_item_id !== $source_item_id) ||
                ($source_type === "found" && $found_item_id !== $source_item_id)
            ) {
                continue;
            }

            $check_match_stmt->execute([
                ":found_item_id" => $found_item_id,
                ":lost_item_id" => $lost_item_id
            ]);

            $db_match = $check_match_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$db_match) {
                continue;
            }

            if (
                ($source_type === "lost" && (int) $db_match["lost_user_id"] !== $user_id) ||
                ($source_type === "found" && (int) $db_match["found_user_id"] !== $user_id) ||
                ($source_type === "lost" && (int) $db_match["found_user_id"] === $user_id) ||
                ($source_type === "found" && (int) $db_match["lost_user_id"] === $user_id)
            ) {
                continue;
            }

            if ($source_type === "lost") {

                $candidate_type = "found";
                $candidate_name = $db_match["found_name"];
                $candidate_description = $db_match["found_description"];
                $candidate_location = $db_match["found_location"];
                $candidate_image = $db_match["found_image"];
                $candidate_user_id = (int) $db_match["found_user_id"];
                $candidate_item_id = (int) $db_match["found_item_id"];

            } else {

                $candidate_type = "lost";
                $candidate_name = $db_match["lost_name"];
                $candidate_description = $db_match["lost_description"];
                $candidate_location = $db_match["lost_location"];
                $candidate_image = $db_match["lost_image"];
                $candidate_user_id = (int) $db_match["lost_user_id"];
                $candidate_item_id = (int) $db_match["lost_item_id"];
            }

            $valid_matches[] = [
                "item_type" => $candidate_type,
                "item_name" => $candidate_name,
                "description" => $candidate_description,
                "location" => $candidate_location,
                "image" => $candidate_image,
                "similarity_score" => (float) ($match["similarity_score"] ?? $match["score"] ?? 0),
                "lost_item_id" => (int) $db_match["lost_item_id"],
                "found_item_id" => (int) $db_match["found_item_id"],
                "lost_user_id" => (int) $db_match["lost_user_id"],
                "found_user_id" => (int) $db_match["found_user_id"],
                "candidate_user_id" => $candidate_user_id,
                "candidate_item_id" => $candidate_item_id,
                "message_finder_url" => "messages.php?user=" . $candidate_user_id . "&item=" . $candidate_item_id
            ];
        }
    }

    $ai_results["matches"] = $valid_matches;
    $ai_results["matches_found"] = count($valid_matches);
}

unset($_SESSION["ai_match_error"]);

/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function e($value) {
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

function itemImagePath($image, $type) {

    $folder = strtolower(trim((string) $type)) === "lost"
        ? "uploads/lost/"
        : "uploads/found/";

    return $folder . basename((string) $image);
}

function getMatchLevel($score) {

    if ($score >= 98) {
        return "high";
    }

    if ($score >= 90) {
        return "strong";
    }

    return "possible";
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>My Reports - RTU Lost and Found</title>

<style>

:root {
    --rtu-blue: #003b7a;
    --rtu-blue-dark: #002b5c;
    --rtu-gold: #f4c430;
    --background: #f4f7fb;
    --card: #ffffff;
    --text: #1e293b;
    --muted: #64748b;
    --border: #dbe3ee;
    --danger: #dc2626;
    --success: #15803d;
}

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: var(--background);
    color: var(--text);
}

/* =========================================================
   NAVBAR
========================================================= */

.navbar {
    width: 100%;
    min-height: 70px;
    background: linear-gradient(135deg, #0f2f5f, #164a8a);
    color: white;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 28px;
    border-bottom: 4px solid #d4af37;
    box-shadow: 0 4px 15px rgba(15, 47, 95, .20);
    position: relative;
    z-index: 1000;
}

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

.profile-container {
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
    padding: 5px 7px;
    border-radius: 30px;
    font-family: Arial, Helvetica, sans-serif;
    transition: background .2s ease;
}

.profile-button:hover {
    background: rgba(255, 255, 255, .12);
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
    min-width: 40px;
    border-radius: 50%;
    background: #d4af37;
    color: #0f2f5f;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: bold;
    border: 2px solid rgba(255, 255, 255, .8);
}

.profile-arrow {
    width: 0;
    height: 0;
    border-left: 5px solid transparent;
    border-right: 5px solid transparent;
    border-top: 6px solid white;
    transition: transform .2s ease;
}

.profile-button.open .profile-arrow {
    transform: rotate(180deg);
}

.profile-dropdown {
    position: absolute;
    top: calc(100% + 12px);
    right: 0;
    width: 310px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 10px 35px rgba(0, 0, 0, .20);
    border: 1px solid #e2e8f0;
    overflow: hidden;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-8px);
    transition: opacity .2s ease, transform .2s ease, visibility .2s ease;
}

.profile-dropdown.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.profile-header {
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 13px;
    background: white;
    border-bottom: 1px solid #e5e7eb;
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

.profile-details {
    min-width: 0;
}

.profile-details strong {
    display: block;
    color: #172554;
    font-size: 15px;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.profile-details span {
    display: block;
    color: #64748b;
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dropdown-links {
    padding: 8px;
}

.dropdown-link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 12px 13px;
    color: #334155;
    text-decoration: none;
    border-radius: 9px;
    font-size: 14px;
    font-weight: 600;
    transition: background .2s ease, color .2s ease;
}

.dropdown-link:hover {
    background: #eff6ff;
    color: #164a8a;
}

.dropdown-link-content {
    display: flex;
    align-items: center;
    gap: 10px;
}

.dropdown-marker {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #d4af37;
    flex-shrink: 0;
}

.signout {
    color: #b91c1c !important;
}

.signout:hover {
    background: #fef2f2 !important;
    color: #991b1b !important;
}

/* =========================================================
   MAIN
========================================================= */

.container {
    width: min(1120px, 94%);
    margin: 32px auto 55px;
}

.header {
    position: relative;
    background: linear-gradient(135deg, #fff, #f7faff);
    padding: 28px 30px;
    border-radius: 14px;
    margin-bottom: 32px;
    border: 1px solid var(--border);
    border-top: 5px solid var(--rtu-blue);
    box-shadow: 0 5px 20px rgba(0, 0, 0, .07);
    overflow: hidden;
}

.header::after {
    content: "";
    position: absolute;
    width: 120px;
    height: 120px;
    right: -40px;
    top: -55px;
    background: var(--rtu-gold);
    opacity: .13;
    border-radius: 50%;
}

.header h1 {
    margin: 0 0 8px;
    color: var(--rtu-blue);
    font-size: 28px;
    position: relative;
    z-index: 1;
}

.header p {
    margin: 0;
    color: var(--muted);
    font-size: 14px;
    position: relative;
    z-index: 1;
}

.section {
    margin-bottom: 38px;
}

.section-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.section-title h2 {
    margin: 0;
    color: var(--rtu-blue);
    font-size: 21px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-title h2::before {
    content: "";
    display: inline-block;
    width: 5px;
    height: 24px;
    background: var(--rtu-gold);
    border-radius: 5px;
}

.count {
    color: var(--rtu-blue);
    font-size: 13px;
    background: white;
    padding: 7px 13px;
    border-radius: 20px;
    border: 1px solid var(--border);
    font-weight: 600;
}

/* =========================================================
   CLAIMS
========================================================= */

.claims-container {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.claim-card {
    background: white;
    border-radius: 13px;
    overflow: hidden;
    border: 1px solid var(--border);
    border-left: 5px solid var(--rtu-gold);
    box-shadow: 0 4px 16px rgba(0, 0, 0, .06);
    transition: .2s ease;
}

.claim-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 59, 122, .12);
}

.claim-header {
    background: linear-gradient(135deg, #f4f8fd, #fff);
    padding: 18px 21px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
}

.claim-title {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.claim-title h3 {
    margin: 0;
    color: var(--rtu-blue);
    font-size: 18px;
}

.claim-label {
    color: var(--muted);
    font-size: 12px;
}

.claim-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 13px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    white-space: nowrap;
}

.pending {
    background: #fff8dc;
    color: #8a6814;
    border: 1px solid #e5c957;
}

.approved {
    background: #dcfce7;
    color: var(--success);
    border: 1px solid #86efac;
}

.rejected {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.claim-content {
    padding: 19px 21px;
}

.claim-info {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px 25px;
    font-size: 13px;
    color: var(--muted);
    margin-bottom: 17px;
}

.claim-info div {
    padding: 7px 0;
    border-bottom: 1px solid #edf1f5;
}

.claim-info strong {
    color: #334155;
}

.claim-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 17px;
    background: var(--rtu-blue);
    color: white;
    text-decoration: none;
    border-radius: 7px;
    font-size: 13px;
    font-weight: bold;
    border-bottom: 3px solid var(--rtu-gold);
    transition: .2s ease;
}

.claim-button:hover {
    background: var(--rtu-blue-dark);
    transform: translateY(-2px);
}

/* =========================================================
   EMPTY STATE
========================================================= */

.empty {
    background: white;
    padding: 38px 25px;
    text-align: center;
    border-radius: 13px;
    color: var(--muted);
    border: 1px solid var(--border);
    box-shadow: 0 3px 13px rgba(0, 0, 0, .05);
}

.empty h3 {
    margin: 0 0 7px;
    color: var(--rtu-blue);
}

.empty p {
    margin: 0;
    font-size: 13px;
}

/* =========================================================
   ITEMS GRID
========================================================= */

.items {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 21px;
}

.card {
    background: white;
    border-radius: 13px;
    overflow: hidden;
    border: 1px solid var(--border);
    box-shadow: 0 4px 15px rgba(0, 0, 0, .06);
    transition: .2s ease;
}

.card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 23px rgba(0, 59, 122, .12);
}

.item-image,
.no-image {
    width: 100%;
    height: 200px;
    display: block;
}

.item-image {
    object-fit: cover;
    background: #e8eef6;
}

.no-image {
    background: linear-gradient(135deg, #e8eef6, #f5f8fc);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--muted);
    font-size: 13px;
}

.card-content {
    padding: 19px;
}

.card-content h3 {
    margin: 0 0 11px;
    color: var(--rtu-blue);
    font-size: 17px;
}

.badges {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-bottom: 14px;
}

.badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 15px;
    font-size: 10px;
    font-weight: bold;
}

.lost-badge {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.found-badge {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #86efac;
}

.status-badge {
    background: #fff8dc;
    color: #8a6814;
    border: 1px solid #e5c957;
}

/* =========================================================
   VERIFICATION CODE
========================================================= */

.verification-box {
    margin: 14px 0;
    padding: 13px;
    background: #f7faff;
    border: 1px solid #cbd9eb;
    border-left: 4px solid #d4af37;
    border-radius: 8px;
}

.verification-label {
    display: block;
    color: #64748b;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    margin-bottom: 7px;
}

.verification-code-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.verification-code {
    color: #0f2f5f;
    font-size: 13px;
    font-weight: bold;
    letter-spacing: .5px;
    overflow-wrap: anywhere;
}

.copy-code-button {
    flex-shrink: 0;
    border: 1px solid #cbd5e1;
    background: white;
    color: #164a8a;
    padding: 7px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 11px;
    font-weight: bold;
    font-family: inherit;
    transition: .2s ease;
}

.copy-code-button:hover {
    background: #eaf1fa;
}

.verification-note {
    margin: 8px 0 0;
    color: #64748b;
    font-size: 11px;
    line-height: 1.5;
}

.verification-missing {
    margin: 14px 0;
    padding: 11px;
    background: #fff8dc;
    border: 1px solid #e5c957;
    border-radius: 8px;
    color: #8a6814;
    font-size: 11px;
    line-height: 1.5;
}

.details {
    font-size: 13px;
    line-height: 1.7;
    color: var(--muted);
    margin-bottom: 15px;
}

.details > div {
    padding: 4px 0;
    border-bottom: 1px solid #edf1f5;
}

.details strong {
    color: #334155;
}

.view-button,
.message-button {
    display: block;
    text-align: center;
    padding: 10px;
    background: var(--rtu-blue);
    color: white;
    text-decoration: none;
    border-radius: 7px;
    font-size: 12px;
    font-weight: bold;
    border-bottom: 3px solid var(--rtu-gold);
    transition: .2s ease;
}

.view-button:hover,
.message-button:hover {
    background: var(--rtu-blue-dark);
}

.ai-match-form {
    margin-top: 9px;
}

.ai-match-button {
    display: block;
    width: 100%;
    padding: 10px;
    border: 0;
    border-bottom: 3px solid var(--rtu-gold);
    border-radius: 7px;
    background: #f3f6fb;
    color: var(--rtu-blue);
    font-family: inherit;
    font-size: 12px;
    font-weight: bold;
    cursor: pointer;
    transition: .2s ease;
}

.ai-match-button:hover {
    background: #e4ebf5;
}

/* =========================================================
   AI RESULTS
========================================================= */

.ai-results {
    background: white;
    border: 1px solid var(--border);
    border-top: 5px solid var(--rtu-blue);
    border-radius: 13px;
    padding: 22px;
    margin-bottom: 35px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, .06);
}

.ai-results h2 {
    margin: 0 0 8px;
    color: var(--rtu-blue);
    font-size: 21px;
}

.ai-summary {
    color: var(--muted);
    font-size: 13px;
    line-height: 1.7;
    margin-bottom: 18px;
}

.ai-match-list {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 17px;
}

.ai-match-card {
    border: 1px solid var(--border);
    border-radius: 11px;
    overflow: hidden;
    background: #fff;
    transition: transform .2s ease, box-shadow .2s ease;
}

.ai-match-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 22px rgba(0, 0, 0, .09);
}

.ai-match-card img {
    width: 100%;
    height: auto;
    max-height: 450px;
    object-fit: contain;
    object-position: center;
    display: block;
    background: #f1f5f9;
}

.ai-match-content {
    padding: 16px;
}

.ai-match-content h3 {
    color: var(--rtu-blue);
    margin: 0 0 10px;
    font-size: 17px;
}

.similarity {
    display: inline-block;
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
    border-radius: 20px;
    padding: 6px 11px;
    font-size: 12px;
    font-weight: bold;
    margin-bottom: 12px;
}

.ai-match-details {
    color: var(--muted);
    font-size: 13px;
    line-height: 1.7;
    margin-bottom: 13px;
    overflow-wrap: anywhere;
}

.message-button {
    background: #f3f6fb;
    color: var(--rtu-blue);
    border: 1px solid var(--border);
    border-bottom: 3px solid var(--rtu-gold);
}

.message-button:hover {
    background: #e4ebf5;
    color: var(--rtu-blue-dark);
}

/* =========================================================
   HIGH SIMILARITY HIGHLIGHT
========================================================= */

.ai-match-card.high-match {
    border: 2px solid #d4af37;
    background: linear-gradient(145deg, #fffdf3, #ffffff);
    box-shadow: 0 5px 22px rgba(212, 175, 55, .20);
}

.ai-match-card.high-match:hover {
    box-shadow: 0 10px 28px rgba(212, 175, 55, .30);
}

.high-match-banner {
    padding: 13px 15px;
    background: linear-gradient(135deg, #fff4c2, #ffed9e);
    border-bottom: 1px solid #e5c957;
    color: #765600;
}

.high-match-banner strong {
    display: block;
    font-size: 16px;
    margin-bottom: 4px;
}

.high-match-banner span {
    font-size: 12px;
    line-height: 1.5;
}

.ai-match-card.high-match .similarity {
    background: #fff0b3;
    color: #765600;
    border: 1px solid #d4af37;
    font-size: 13px;
}

.strong-match-label {
    display: inline-block;
    background: #e0efff;
    color: #164a8a;
    border: 1px solid #bfdbfe;
    border-radius: 20px;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: bold;
    margin-bottom: 10px;
}

.possible-match-label {
    display: inline-block;
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
    border-radius: 20px;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: bold;
    margin-bottom: 10px;
}

.ai-empty {
    background: #f7faff;
    border: 1px solid var(--border);
    border-radius: 9px;
    padding: 18px;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.6;
}

.ai-error {
    background: #fff1f2;
    border: 1px solid #fecdd3;
    border-radius: 9px;
    padding: 14px 16px;
    color: #b91c1c;
    font-size: 13px;
    margin-bottom: 20px;
}

/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 900px) {
    .items {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 700px) {

    .navbar {
        padding: 10px 14px;
    }

    .brand-logo {
        width: 38px;
        height: 38px;
    }

    .brand-title {
        font-size: 16px;
    }

    .profile-name {
        display: none;
    }

    .profile-dropdown {
        width: calc(100vw - 28px);
        right: 0;
    }

    .claim-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .claim-info {
        grid-template-columns: 1fr;
    }

    .ai-match-list {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 600px) {

    .container {
        width: 94%;
        margin: 20px auto 40px;
    }

    .header {
        padding: 22px;
    }

    .header h1 {
        font-size: 23px;
    }

    .items {
        grid-template-columns: 1fr;
    }

    .section-title {
        align-items: flex-start;
        gap: 10px;
        flex-direction: column;
    }

    .claim-content {
        padding: 16px;
    }

    .verification-code-row {
        align-items: flex-start;
        flex-direction: column;
    }

    .copy-code-button {
        width: 100%;
    }
}

@media print {

    .navbar,
    .ai-match-form,
    .copy-code-button {
        display: none;
    }

}

</style>

</head>

<body>

<!-- =========================================================
     NAVBAR
========================================================= -->

<nav class="navbar">

    <a href="dashboard.php" class="brand">

        <span class="brand-title">RTU Lost and Found</span>

        <img
            src="uploads/rtulogo.png"
            alt="RTU Logo"
            class="brand-logo"
        >

    </a>

    <div class="profile-container">

        <button
            type="button"
            class="profile-button"
            id="profileButton"
            aria-label="Open profile menu"
            aria-expanded="false"
            aria-controls="profileDropdown"
        >

            <span class="profile-name">
                <?php echo e($current_user["name"]); ?>
            </span>

            <span class="profile-circle">
                <?php echo e(strtoupper(substr($current_user["name"], 0, 1))); ?>
            </span>

            <span class="profile-arrow"></span>

        </button>

        <div class="profile-dropdown" id="profileDropdown">

            <div class="profile-header">

                <div class="profile-header-circle">
                    <?php echo e(strtoupper(substr($current_user["name"], 0, 1))); ?>
                </div>

                <div class="profile-details">

                    <strong>
                        <?php echo e($current_user["name"]); ?>
                    </strong>

                    <span>
                        <?php echo e($current_user["email"]); ?>
                    </span>

                </div>

            </div>

            <div class="dropdown-links">

                <a href="messages.php" class="dropdown-link">
                    <span class="dropdown-link-content">
                        <span class="dropdown-marker"></span>
                        <span>Messages</span>
                    </span>
                </a>

                <a href="notifications.php" class="dropdown-link">
                    <span class="dropdown-link-content">
                        <span class="dropdown-marker"></span>
                        <span>Notifications</span>
                    </span>
                </a>

                <a href="logout.php" class="dropdown-link signout">
                    <span class="dropdown-link-content">
                        <span class="dropdown-marker"></span>
                        <span>Sign Out</span>
                    </span>
                </a>

            </div>

        </div>

    </div>

</nav>

<div class="container">

    <div class="header">

        <h1>My Reports &amp; Claims</h1>

        <p>
            View your submitted claims and the lost or found items you have reported.
        </p>

    </div>

    <!-- AI MATCH RESULTS -->

    <?php if ($ai_error): ?>

        <div class="ai-results">

            <h2>AI Matching Results</h2>

            <div class="ai-error">
                <?php echo e($ai_error); ?>
            </div>

        </div>

    <?php elseif (is_array($ai_results)): ?>

        <div class="ai-results">

            <h2>AI Matching Results</h2>

            <div class="ai-summary">

                <strong>Selected item:</strong>
                <?php echo e($ai_results["item_name"] ?? "Your reported item"); ?>

                <br>

                <strong>Similarity threshold:</strong>
                <?php echo e($ai_results["threshold"] ?? 70); ?>%

                <br>

                <strong>Items checked:</strong>
                <?php echo (int) ($ai_results["candidate_count"] ?? 0); ?>

                <br>

                <strong>Possible matches:</strong>
                <?php echo (int) ($ai_results["matches_found"] ?? 0); ?>

            </div>

            <?php $matches = $ai_results["matches"] ?? []; ?>

            <?php if (!empty($matches) && is_array($matches)): ?>

                <div class="ai-match-list">

                    <?php foreach ($matches as $match): ?>

                        <?php

                        $match_type = strtolower(trim((string) ($match["item_type"] ?? "")));
                        $match_name = $match["item_name"] ?? "Matching Item";
                        $match_description = $match["description"] ?? "";
                        $match_location = $match["location"] ?? "";
                        $match_image = $match["image"] ?? "";
                        $match_score = (float) ($match["similarity_score"] ?? $match["score"] ?? 0);
                        $message_url = $match["message_finder_url"] ?? "";

                        $match_level = getMatchLevel($match_score);

                        if ($message_url === "") {

                            $finder_id = (int) ($match["found_user_id"] ?? 0);
                            $found_id = (int) ($match["found_item_id"] ?? 0);

                            if ($finder_id > 0 && $found_id > 0) {

                                $message_url = "messages.php?user="
                                    . $finder_id
                                    . "&item="
                                    . $found_id;
                            }
                        }

                        $image_type = $match_type;

                        if ($image_type === "") {
                            $image_type = !empty($match["found_item_id"])
                                ? "found"
                                : "lost";
                        }

                        $match_image_path = $match_image !== ""
                            ? itemImagePath($match_image, $image_type)
                            : "";

                        ?>

                        <div class="ai-match-card <?php echo $match_level === "high" ? "high-match" : ""; ?>">

                            <?php if ($match_level === "high"): ?>

                                <div class="high-match-banner">

                                    <strong>This might be your item!</strong>

                                    <span>
                                        The AI found a very high similarity between your report and this item.
                                        Please verify the details before contacting the other user.
                                    </span>

                                </div>

                            <?php endif; ?>

                            <?php if ($match_image_path !== ""): ?>

                                <img
                                    src="<?php echo e($match_image_path); ?>"
                                    alt="<?php echo e($match_name); ?>"
                                    onerror="this.style.display='none';"
                                >

                            <?php else: ?>

                                <div class="no-image">
                                    No Image Available
                                </div>

                            <?php endif; ?>

                            <div class="ai-match-content">

                                <h3>
                                    <?php echo e($match_name); ?>
                                </h3>

                                <?php if ($match_level === "high"): ?>

                                    <div class="similarity">
                                        <?php echo number_format($match_score, 2); ?>% Similarity
                                    </div>

                                <?php elseif ($match_level === "strong"): ?>

                                    <div class="strong-match-label">
                                        Very Strong Possible Match
                                    </div>

                                    <br>

                                    <div class="similarity">
                                        <?php echo number_format($match_score, 2); ?>% Similarity
                                    </div>

                                <?php else: ?>

                                    <div class="possible-match-label">
                                        Possible Match
                                    </div>

                                    <br>

                                    <div class="similarity">
                                        <?php echo number_format($match_score, 2); ?>% Similarity
                                    </div>

                                <?php endif; ?>

                                <div class="ai-match-details">

                                    <div>
                                        <strong>Description:</strong>

                                        <?php
                                        echo e(
                                            $match_description
                                            ?: "No description available"
                                        );
                                        ?>
                                    </div>

                                    <div>
                                        <strong>Location:</strong>

                                        <?php
                                        echo e(
                                            $match_location
                                            ?: "Not specified"
                                        );
                                        ?>
                                    </div>

                                </div>

                                <?php if ($message_url !== ""): ?>

                                    <a
                                        href="<?php echo e($message_url); ?>"
                                        class="message-button"
                                    >
                                        Message Finder
                                    </a>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="ai-empty">

                    No valid matching items were found above the similarity threshold.

                    You can try running the matching process again later.

                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>

    <!-- MY CLAIMS -->

    <div class="section">

        <div class="section-title">

            <h2>My Claims</h2>

            <span class="count">
                <?php echo $total_claims; ?> claim(s)
            </span>

        </div>

        <?php if (!empty($claims_result)): ?>

            <div class="claims-container">

                <?php foreach ($claims_result as $claim): ?>

                    <div class="claim-card">

                        <div class="claim-header">

                            <div class="claim-title">

                                <h3>
                                    <?php echo e($claim["item_name"]); ?>
                                </h3>

                                <span class="claim-label">
                                    Claim for reported found item
                                </span>

                            </div>

                            <?php

                            $claim_status = strtolower(
                                (string) $claim["status"]
                            );

                            $claim_class = in_array(
                                $claim_status,
                                ["pending", "approved", "rejected"],
                                true
                            ) ? $claim_status : "pending";

                            ?>

                            <span class="claim-status <?php echo e($claim_class); ?>">
                                <?php echo e(ucfirst($claim_status)); ?>
                            </span>

                        </div>

                        <div class="claim-content">

                            <div class="claim-info">

                                <div>
                                    <strong>Category:</strong>
                                    <?php echo e($claim["category"] ?? "N/A"); ?>
                                </div>

                                <div>
                                    <strong>Location:</strong>
                                    <?php echo e($claim["location"] ?? "N/A"); ?>
                                </div>

                                <div>
                                    <strong>Claim Submitted:</strong>
                                    <?php echo e($claim["created_at"]); ?>
                                </div>

                                <div>
                                    <strong>Item Status:</strong>

                                    <?php

                                    echo e(
                                        ucwords(
                                            str_replace(
                                                "_",
                                                " ",
                                                $claim["item_status"]
                                            )
                                        )
                                    );

                                    ?>

                                </div>

                            </div>

                            <a
                                class="claim-button"
                                href="item.php?id=<?php echo (int) $claim["item_id"]; ?>"
                            >
                                View Claimed Item
                            </a>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty">

                <h3>No Claims Yet</h3>

                <p>You have not submitted any claims.</p>

            </div>

        <?php endif; ?>

    </div>

    <!-- MY REPORTED ITEMS -->

    <div class="section">

        <div class="section-title">

            <h2>My Reported Items</h2>

            <span class="count">
                <?php echo $total_reports; ?> report(s)
            </span>

        </div>

        <?php if (!empty($result)): ?>

            <div class="items">

                <?php foreach ($result as $item): ?>

                    <div class="card">

                        <?php if (!empty($item["image"])): ?>

                            <img
                                class="item-image"
                                src="<?php echo e(itemImagePath($item["image"], $item["item_type"])); ?>"
                                alt="<?php echo e($item["item_name"]); ?>"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                            >

                            <div class="no-image" style="display:none;">
                                Image unavailable
                            </div>

                        <?php else: ?>

                            <div class="no-image">
                                No Image Available
                            </div>

                        <?php endif; ?>

                        <div class="card-content">

                            <h3>
                                <?php echo e($item["item_name"]); ?>
                            </h3>

                            <div class="badges">

                                <?php if (strtolower(trim($item["item_type"])) === "lost"): ?>

                                    <span class="badge lost-badge">
                                        LOST
                                    </span>

                                <?php else: ?>

                                    <span class="badge found-badge">
                                        FOUND
                                    </span>

                                <?php endif; ?>

                                <span class="badge status-badge">

                                    <?php

                                    echo e(
                                        ucwords(
                                            str_replace(
                                                "_",
                                                " ",
                                                $item["status"]
                                            )
                                        )
                                    );

                                    ?>

                                </span>

                            </div>

                            <!-- VERIFICATION CODE -->

                            <?php if (!empty($item["verification_code"])): ?>

                                <div class="verification-box">

                                    <span class="verification-label">
                                        Report Verification Code
                                    </span>

                                    <div class="verification-code-row">

                                        <span class="verification-code">
                                            <?php echo e($item["verification_code"]); ?>
                                        </span>

                                        <button
                                            type="button"
                                            class="copy-code-button"
                                            onclick="copyVerificationCode(this, '<?php echo e($item["verification_code"]); ?>')"
                                        >
                                            Copy Code
                                        </button>

                                    </div>

                                    <p class="verification-note">
                                        Keep this code and provide it to the administrator when requesting verification of this report. This code identifies your report but does not replace identity or ownership verification.
                                    </p>

                                </div>

                            <?php else: ?>

                                <div class="verification-missing">
                                    No verification code is available for this report. This may be an older report submitted before the verification-code feature was added.
                                </div>

                            <?php endif; ?>

                            <div class="details">

                                <div>
                                    <strong>Category:</strong>
                                    <?php echo e($item["category"] ?? "N/A"); ?>
                                </div>

                                <div>
                                    <strong>Location:</strong>
                                    <?php echo e($item["location"] ?? "N/A"); ?>
                                </div>

                                <div>
                                    <strong>Date:</strong>
                                    <?php echo e($item["date_reported"]); ?>
                                </div>

                                <div>

                                    <strong>Description:</strong>

                                    <?php

                                    $description = $item["description"] ?? "";

                                    if (strlen($description) > 80) {
                                        $description = substr($description, 0, 80) . "...";
                                    }

                                    echo e($description ?: "No description");

                                    ?>

                                </div>

                            </div>

                            <a
                                class="view-button"
                                href="item.php?id=<?php echo (int) $item["id"]; ?>"
                            >
                                View Item
                            </a>

                            <?php

                            $can_run_ai =
                                !empty($item["image"]) &&
                                !in_array(
                                    strtolower((string) $item["status"]),
                                    ["returned", "cancelled"],
                                    true
                                );

                            ?>

                            <?php if ($can_run_ai): ?>

                                <form
                                    class="ai-match-form"
                                    action="run_ai_matching.php"
                                    method="POST"
                                >

                                    <input
                                        type="hidden"
                                        name="item_id"
                                        value="<?php echo (int) $item["id"]; ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="ai-match-button"
                                    >
                                        Find Matching Items
                                    </button>

                                </form>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty">

                <h3>No Reports Yet</h3>

                <p>You have not reported any lost or found items.</p>

            </div>

        <?php endif; ?>

    </div>

</div>

<script>

/* PROFILE DROPDOWN */

const profileButton = document.getElementById("profileButton");
const profileDropdown = document.getElementById("profileDropdown");

if (profileButton && profileDropdown) {

    profileButton.addEventListener("click", function(event) {

        event.stopPropagation();

        const isOpen = profileDropdown.classList.toggle("show");

        profileButton.classList.toggle("open", isOpen);

        profileButton.setAttribute(
            "aria-expanded",
            isOpen ? "true" : "false"
        );

    });

    document.addEventListener("click", function(event) {

        if (
            !profileDropdown.contains(event.target) &&
            !profileButton.contains(event.target)
        ) {

            profileDropdown.classList.remove("show");
            profileButton.classList.remove("open");

            profileButton.setAttribute("aria-expanded", "false");

        }

    });

    document.addEventListener("keydown", function(event) {

        if (event.key === "Escape") {

            profileDropdown.classList.remove("show");
            profileButton.classList.remove("open");

            profileButton.setAttribute("aria-expanded", "false");

        }

    });

}

/* COPY VERIFICATION CODE */

function copyVerificationCode(button, code) {

    if (navigator.clipboard && window.isSecureContext) {

        navigator.clipboard.writeText(code)
            .then(function() {
                button.textContent = "Copied!";
                setTimeout(function() {
                    button.textContent = "Copy Code";
                }, 1800);
            })
            .catch(function() {
                fallbackCopyCode(button, code);
            });

    } else {

        fallbackCopyCode(button, code);

    }

}

function fallbackCopyCode(button, code) {

    const textArea = document.createElement("textarea");

    textArea.value = code;
    textArea.style.position = "fixed";
    textArea.style.opacity = "0";

    document.body.appendChild(textArea);

    textArea.select();

    try {

        const successful = document.execCommand("copy");

        if (successful) {
            button.textContent = "Copied!";
        } else {
            button.textContent = "Copy Failed";
        }

    } catch (error) {

        button.textContent = "Copy Failed";

    }

    document.body.removeChild(textArea);

    setTimeout(function() {
        button.textContent = "Copy Code";
    }, 1800);

}

/* PREVENT BACK/FORWARD CACHE */

window.addEventListener("pageshow", function(event) {

    if (event.persisted) {
        window.location.reload();
    }

});

</script>

</body>
</html>