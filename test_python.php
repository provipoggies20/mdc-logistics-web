<?php
$command = escapeshellcmd("python3 " . __DIR__ . "/handle_chat.py");
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open($command, $descriptors, $pipes);
if (is_resource($process)) {
    fwrite($pipes[0], json_encode(['message' => 'Test query', 'vehicle_data' => ['totalVehicles' => 10]]));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    echo "Output: $output\nError: $error\n";
} else {
    echo "Failed to start process\n";
}
?>