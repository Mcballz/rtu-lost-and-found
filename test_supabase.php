
<?php

ini_set('display_errors', '1');
error_reporting(E_ALL);

try {

    require_once __DIR__ . "/config/supabase.php";

    echo "<h2>RTU Lost and Found - Supabase Database Test</h2>";
    echo "<p style='color:green;'>Connected to Supabase successfully!</p>";

    $tables = [
        "users",
        "items",
        "claims",
        "ai_matches",
        "messages",
        "message_reactions"
    ];

    echo "<table border='1' cellpadding='10' cellspacing='0'>";
    echo "<tr><th>Table Name</th><th>Total Records</th><th>Status</th></tr>";

    foreach ($tables as $table) {

        $stmt = $supabase->query(
            "SELECT COUNT(*) FROM public." . $table
        );

        $count = $stmt->fetchColumn();

        echo "<tr>";
        echo "<td>" . htmlspecialchars($table) . "</td>";
        echo "<td>" . htmlspecialchars((string)$count) . "</td>";
        echo "<td style='color:green;'>Accessible</td>";
        echo "</tr>";
    }

    echo "</table>";

} catch (Throwable $e) {

    echo "<h3 style='color:red;'>Database Test Failed</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";

}

?>
