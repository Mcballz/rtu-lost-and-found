
<?php

session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

require_once "config/database.php";

$user_id = (int) $_SESSION["user_id"];

function returnToReports($item_id, $message = "")
{
    if ($message !== "") {
        $_SESSION["ai_match_error"] = $message;
    }

    header("Location: my_reports.php?matches_for=" . (int) $item_id);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: my_reports.php");
    exit();
}

$item_id = filter_input(INPUT_POST, "item_id", FILTER_VALIDATE_INT);

if (!$item_id || $item_id <= 0) {
    header("Location: my_reports.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| CONFIGURATION
|--------------------------------------------------------------------------
*/

$threshold = 70.00;

$matches = [];
$errors = 0;
$already_existing = 0;

/*
|--------------------------------------------------------------------------
| VERIFY SELECTED REPORT
|--------------------------------------------------------------------------
*/

$item_stmt = $conn->prepare("
    SELECT id, user_id, item_type, item_name, category,
           description, location, image, status
    FROM items
    WHERE id = ? AND user_id = ?
    LIMIT 1
");

if (!$item_stmt) {
    returnToReports($item_id, "Could not retrieve your report.");
}

$item_stmt->bind_param("ii", $item_id, $user_id);
$item_stmt->execute();

$item = $item_stmt->get_result()->fetch_assoc();
$item_stmt->close();

if (!$item) {
    returnToReports(
        $item_id,
        "Report not found or you are not allowed to match it."
    );
}

$item_type = strtolower($item["item_type"]);
$item_status = strtolower($item["status"]);

if (!in_array($item_type, ["lost", "found"], true)) {
    returnToReports($item_id, "Invalid report type.");
}

if (in_array($item_status, ["returned", "cancelled"], true)) {
    returnToReports(
        $item_id,
        "Matching is unavailable for this report."
    );
}

if (empty($item["image"])) {
    returnToReports(
        $item_id,
        "This report does not have an uploaded image."
    );
}

/*
|--------------------------------------------------------------------------
| SELECTED REPORT IMAGE
|--------------------------------------------------------------------------
*/

$selected_folder = ($item_type === "lost")
    ? "uploads/lost/"
    : "uploads/found/";

$selected_image = basename($item["image"]);

$selected_image_path = __DIR__ . DIRECTORY_SEPARATOR .
    str_replace("/", DIRECTORY_SEPARATOR, $selected_folder) .
    $selected_image;

if (!is_file($selected_image_path)) {
    returnToReports(
        $item_id,
        "The report image could not be found."
    );
}

/*
|--------------------------------------------------------------------------
| PYTHON CONFIGURATION
|--------------------------------------------------------------------------
*/

$python = __DIR__ . DIRECTORY_SEPARATOR .
    "ai" . DIRECTORY_SEPARATOR .
    "venv" . DIRECTORY_SEPARATOR .
    "Scripts" . DIRECTORY_SEPARATOR .
    "python.exe";

$python_script = __DIR__ . DIRECTORY_SEPARATOR .
    "ai" . DIRECTORY_SEPARATOR .
    "hybrid_matching.py";

if (!is_file($python)) {
    returnToReports(
        $item_id,
        "Python executable could not be found."
    );
}

if (!is_file($python_script)) {
    returnToReports(
        $item_id,
        "Hybrid AI matching script could not be found."
    );
}

if (!function_exists("shell_exec")) {
    returnToReports(
        $item_id,
        "PHP shell_exec is disabled. Please check your PHP configuration."
    );
}

/*
|--------------------------------------------------------------------------
| GET REPORTS OF THE OPPOSITE TYPE
|--------------------------------------------------------------------------
*/

if ($item_type === "lost") {

    $candidate_type = "found";

    $candidate_stmt = $conn->prepare("
        SELECT id, user_id, item_name, category,
               description, location, image, status
        FROM items
        WHERE item_type = 'found'
          AND image IS NOT NULL
          AND image != ''
          AND status IN ('found', 'claim_pending')
          AND user_id != ?
          AND id != ?
        ORDER BY created_at DESC
    ");

} else {

    $candidate_type = "lost";

    $candidate_stmt = $conn->prepare("
        SELECT id, user_id, item_name, category,
               description, location, image, status
        FROM items
        WHERE item_type = 'lost'
          AND image IS NOT NULL
          AND image != ''
          AND status = 'lost'
          AND user_id != ?
          AND id != ?
        ORDER BY created_at DESC
    ");
}

if (!$candidate_stmt) {
    returnToReports(
        $item_id,
        "Could not retrieve reports for matching."
    );
}

$candidate_stmt->bind_param("ii", $user_id, $item_id);
$candidate_stmt->execute();

$candidates = $candidate_stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$candidate_stmt->close();

$candidate_count = count($candidates);

/*
|--------------------------------------------------------------------------
| STOP IF THERE ARE NO CANDIDATES
|--------------------------------------------------------------------------
*/

if ($candidate_count === 0) {

    $_SESSION["ai_match_results"] = [
        "item_id" => $item_id,
        "item_type" => $item_type,
        "item_name" => $item["item_name"],
        "threshold" => $threshold,
        "candidate_count" => 0,
        "matches_found" => 0,
        "already_existing" => 0,
        "errors" => 0,
        "matches" => []
    ];

    unset($_SESSION["ai_match_error"]);

    header("Location: my_reports.php?matches_for=" . $item_id);
    exit();
}

/*
|--------------------------------------------------------------------------
| PREPARE HYBRID AI INPUT
|--------------------------------------------------------------------------
*/

$source_data = [
    "id" => (int) $item["id"],
    "image" => $selected_image_path,
    "item_name" => $item["item_name"],
    "category" => $item["category"],
    "description" => $item["description"],
    "color" => "",
    "location" => $item["location"]
];

$hybrid_candidates = [];

$candidate_lookup = [];

$candidate_folder = ($candidate_type === "lost")
    ? "uploads/lost/"
    : "uploads/found/";

foreach ($candidates as $candidate) {

    $candidate_image = basename($candidate["image"]);

    $candidate_image_path = __DIR__ . DIRECTORY_SEPARATOR .
        str_replace("/", DIRECTORY_SEPARATOR, $candidate_folder) .
        $candidate_image;

    if (!is_file($candidate_image_path)) {
        $errors++;
        continue;
    }

    $candidate_id = (int) $candidate["id"];

    $hybrid_candidates[] = [
        "id" => $candidate_id,
        "image" => $candidate_image_path,
        "item_name" => $candidate["item_name"],
        "category" => $candidate["category"],
        "description" => $candidate["description"],
        "color" => "",
        "location" => $candidate["location"]
    ];

    $candidate_lookup[$candidate_id] = $candidate;
}

if (empty($hybrid_candidates)) {
    returnToReports(
        $item_id,
        "No valid candidate images were available for AI matching."
    );
}

/*
|--------------------------------------------------------------------------
| PREPARE TEMPORARY JSON INPUT
|--------------------------------------------------------------------------
*/

$input_data = [
    "source" => $source_data,
    "candidates" => $hybrid_candidates
];

$json_input = json_encode(
    $input_data,
    JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);

if ($json_input === false) {
    returnToReports(
        $item_id,
        "Could not prepare the hybrid AI request."
    );
}

$temp_file = tempnam(sys_get_temp_dir(), "rtu_ai_");

if ($temp_file === false) {
    returnToReports(
        $item_id,
        "Could not create temporary AI input file."
    );
}

if (file_put_contents($temp_file, $json_input) === false) {
    @unlink($temp_file);

    returnToReports(
        $item_id,
        "Could not write temporary AI input file."
    );
}

/*
|--------------------------------------------------------------------------
| RUN PYTHON HYBRID AI DIRECTLY
|--------------------------------------------------------------------------
|
| Python loads DINOv2 and CLIP, performs matching,
| outputs JSON, and then exits.
|
| No Flask server is required.
|
*/

set_time_limit(0);
ini_set("max_execution_time", "0");

$command =
    escapeshellarg($python) . " " .
    escapeshellarg($python_script) . " " .
    escapeshellarg($temp_file) .
    " 2>NUL";

$ai_output = shell_exec($command);

/*
|--------------------------------------------------------------------------
| REMOVE TEMPORARY INPUT FILE
|--------------------------------------------------------------------------
*/

@unlink($temp_file);

if ($ai_output === null || trim($ai_output) === "") {

    error_log("RTU Hybrid AI returned empty output.");

    returnToReports(
        $item_id,
        "The hybrid AI did not return a response. Please check the Python script."
    );
}

/*
|--------------------------------------------------------------------------
| EXTRACT JSON RESPONSE
|--------------------------------------------------------------------------
|
| Python may print model-loading messages before its JSON response.
| Extract the final JSON object rather than decoding those messages.
|
*/

$ai_output = trim($ai_output);

$json_start = strrpos($ai_output, '{"success"');

if ($json_start === false) {
    $json_start = strrpos($ai_output, "{\n");
}

if ($json_start !== false) {
    $ai_output = substr($ai_output, $json_start);
}

$ai_response = json_decode($ai_output, true);

if (
    !is_array($ai_response) ||
    empty($ai_response["success"])
) {

    error_log(
        "RTU Hybrid AI output error: " .
        substr($ai_output, 0, 2000)
    );

    returnToReports(
        $item_id,
        "Hybrid AI processing failed. Please check the Python output."
    );
}

if (
    !isset($ai_response["results"]) ||
    !is_array($ai_response["results"])
) {
    returnToReports(
        $item_id,
        "The hybrid AI returned an invalid result."
    );
}

/*
|--------------------------------------------------------------------------
| PROCESS HYBRID AI RESULTS
|--------------------------------------------------------------------------
*/

foreach ($ai_response["results"] as $ai_result) {

    if (
        isset($ai_result["error"]) ||
        !isset($ai_result["candidate_id"]) ||
        !isset($ai_result["final_score"])
    ) {
        $errors++;
        continue;
    }

    $candidate_id = (int) $ai_result["candidate_id"];

    if (!isset($candidate_lookup[$candidate_id])) {
        $errors++;
        continue;
    }

    $candidate = $candidate_lookup[$candidate_id];

    $similarity_score = (float) $ai_result["final_score"];

    if (
        !is_finite($similarity_score) ||
        $similarity_score < 0 ||
        $similarity_score > 100
    ) {
        $errors++;
        continue;
    }

    if ($similarity_score < $threshold) {
        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | IDENTIFY LOST AND FOUND REPORTS
    |--------------------------------------------------------------------------
    */

    if ($item_type === "lost") {

        $lost_id = (int) $item["id"];
        $found_id = (int) $candidate["id"];

        $lost_user_id = (int) $item["user_id"];
        $found_user_id = (int) $candidate["user_id"];

        $lost_name = $item["item_name"];
        $found_name = $candidate["item_name"];

        $lost_description = $item["description"];
        $found_description = $candidate["description"];

        $lost_location = $item["location"];
        $found_location = $candidate["location"];

        $lost_image = $item["image"];
        $found_image = $candidate["image"];

    } else {

        $lost_id = (int) $candidate["id"];
        $found_id = (int) $item["id"];

        $lost_user_id = (int) $candidate["user_id"];
        $found_user_id = (int) $item["user_id"];

        $lost_name = $candidate["item_name"];
        $found_name = $item["item_name"];

        $lost_description = $candidate["description"];
        $found_description = $item["description"];

        $lost_location = $candidate["location"];
        $found_location = $item["location"];

        $lost_image = $candidate["image"];
        $found_image = $item["image"];
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK EXISTING MATCH
    |--------------------------------------------------------------------------
    */

    $duplicate_stmt = $conn->prepare("
        SELECT id
        FROM ai_matches
        WHERE lost_item_id = ?
          AND found_item_id = ?
        LIMIT 1
    ");

    if (!$duplicate_stmt) {
        $errors++;
        continue;
    }

    $duplicate_stmt->bind_param("ii", $lost_id, $found_id);
    $duplicate_stmt->execute();

    $existing_match = $duplicate_stmt
        ->get_result()
        ->fetch_assoc();

    $duplicate_stmt->close();

    if ($existing_match) {

        $already_existing++;
        $match_id = (int) $existing_match["id"];

    } else {

        /*
        |--------------------------------------------------------------------------
        | SAVE NEW MATCH
        |--------------------------------------------------------------------------
        */

        $match_stmt = $conn->prepare("
            INSERT INTO ai_matches
            (lost_item_id, found_item_id, similarity_score)
            VALUES (?, ?, ?)
        ");

        if (!$match_stmt) {
            $errors++;
            continue;
        }

        $match_stmt->bind_param(
            "iid",
            $lost_id,
            $found_id,
            $similarity_score
        );

        if (!$match_stmt->execute()) {
            $errors++;
            $match_stmt->close();
            continue;
        }

        $match_id = (int) $conn->insert_id;
        $match_stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | PREPARE MESSAGE FINDER URL
    |--------------------------------------------------------------------------
    */

    $message_finder_url = "";

    if ($item_type === "lost" && $found_user_id !== $user_id) {

        $message_finder_url =
            "messages.php?user=" . $found_user_id .
            "&item=" . $found_id;
    }

    /*
    |--------------------------------------------------------------------------
    | PREPARE RESULT
    |--------------------------------------------------------------------------
    */

    $components = $ai_result["components"] ?? [];

    $matches[] = [
        "match_id" => $match_id,
        "lost_item_id" => $lost_id,
        "found_item_id" => $found_id,
        "lost_user_id" => $lost_user_id,
        "found_user_id" => $found_user_id,

        "lost_name" => $lost_name,
        "found_name" => $found_name,

        "lost_description" => $lost_description,
        "found_description" => $found_description,

        "lost_location" => $lost_location,
        "found_location" => $found_location,

        "lost_image" => $lost_image,
        "found_image" => $found_image,

        "similarity_score" => round($similarity_score, 2),

        "dinov2_score" => $components["dinov2"] ?? null,
        "clip_image_score" => $components["clip_image"] ?? null,
        "clip_text_score" => $components["clip_text"] ?? null,
        "metadata_score" => $components["metadata"] ?? null,

        "message_finder_url" => $message_finder_url
    ];
}

/*
|--------------------------------------------------------------------------
| SAVE RESULTS FOR MY REPORTS PAGE
|--------------------------------------------------------------------------
*/

$_SESSION["ai_match_results"] = [
    "item_id" => $item_id,
    "item_type" => $item_type,
    "item_name" => $item["item_name"],
    "threshold" => $threshold,
    "candidate_count" => $candidate_count,
    "matches_found" => count($matches),
    "already_existing" => $already_existing,
    "errors" => $errors,
    "matches" => $matches
];

unset($_SESSION["ai_match_error"]);

header("Location: my_reports.php?matches_for=" . $item_id);
exit();

