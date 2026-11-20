<?php

$python = __DIR__ . "/ai/venv/Scripts/python.exe";
$script = __DIR__ . "/ai/hybrid_matching.py";
$input = __DIR__ . "/ai/hybrid_input.json";

$command =
    escapeshellarg($python) . " " .
    escapeshellarg($script) . " " .
    escapeshellarg($input) .
    " 2>&1";

echo "<pre>";
echo "Running hybrid AI engine...\n\n";
echo shell_exec($command);
echo "</pre>";

?>