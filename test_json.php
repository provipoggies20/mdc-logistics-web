$message = "test";
$vehicle_data = ['totalVehicles' => 0, 'activeVehicles' => 0];
$payload = json_encode(['message' => $message, 'vehicle_data' => $vehicle_data]);
echo $payload;