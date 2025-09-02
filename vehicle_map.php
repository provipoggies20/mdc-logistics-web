<?php
ob_start();
$lat = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
$lng = filter_input(INPUT_GET, 'lng', FILTER_VALIDATE_FLOAT);
$vehicle = filter_input(INPUT_GET, 'vehicle', FILTER_SANITIZE_STRING);
$lat = $lat !== false ? $lat : 0;
$lng = $lng !== false ? $lng : 0;
$vehicle = $vehicle ?: 'Unknown Vehicle';
error_log("Vehicle parameter: $vehicle");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Map - <?php echo htmlspecialchars($vehicle); ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        body { margin: 0; padding: 0; font-family: Arial, sans-serif; }
        #map { width: 100%; height: 100vh; }
        .error-message {
            position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
            background: #f8d7da; color: #721c24; padding: 10px 20px;
            border-radius: 5px; z-index: 1000;
        }
    </style>
</head>
<body>
    <div id="map"></div>
    <?php if ($lat === 0 && $lng === 0): ?>
        <div class="error-message">Invalid or missing coordinates. Showing default location.</div>
    <?php endif; ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const map = L.map('map').setView([<?php echo $lat; ?>, <?php echo $lng; ?>], 50);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        const vehicleName = '<?php echo addslashes(htmlspecialchars($vehicle)); ?>';
        let marker = L.marker([<?php echo $lat; ?>, <?php echo $lng; ?>])
            .addTo(map)
            .bindPopup(`<b>${vehicleName}</b><br>Last Updated: Unknown`)
            .openPopup();
        console.log('Marker added:', { name: vehicleName, lat: <?php echo $lat; ?>, lng: <?php echo $lng; ?>, last_updated: 'Initial' });

        function fetchVehicleData() {
            const url = `fetch_vehicle_data.php?target_name=<?php echo urlencode($vehicle); ?>`;
            console.log('Fetching data from:', url);
            fetch(url)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.text().then(text => {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('Raw response:', text);
                            throw new Error('Invalid JSON response');
                        }
                    });
                })
                .then(data => {
                    console.log('Fetch response:', data);
                    if (!data.success) {
                        console.error('Error fetching vehicle data:', data.error);
                        const errorMessage = document.querySelector('.error-message') || document.createElement('div');
                        errorMessage.className = 'error-message';
                        errorMessage.textContent = data.error || 'Failed to fetch vehicle data';
                        document.body.appendChild(errorMessage);
                        marker.setPopupContent(`<b>${vehicleName}</b><br>Last Updated: Error`);
                        marker.openPopup();
                        return;
                    }

                    const newLat = parseFloat(data.latitude) || <?php echo $lat; ?>;
                    const newLng = parseFloat(data.longitude) || <?php echo $lng; ?>;
                    const lastUpdated = data.last_updated && !isNaN(data.last_updated) 
                        ? new Date(data.last_updated * 1000).toLocaleString() 
                        : (data.last_updated || 'Unknown');

                    marker.setLatLng([newLat, newLng]);
                    marker.setPopupContent(`<b>${vehicleName}</b><br>Last Updated: ${lastUpdated}`);
                    marker.openPopup();
                    map.setView([newLat, newLng], 13);
                    console.log('Marker updated:', { name: vehicleName, lat: newLat, lng: newLng, last_updated: lastUpdated });

                    if (newLat !== 0 && newLng !== 0) {
                        const errorMessage = document.querySelector('.error-message');
                        if (errorMessage) errorMessage.remove();
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    const errorMessage = document.querySelector('.error-message') || document.createElement('div');
                    errorMessage.className = 'error-message';
                    errorMessage.textContent = `Error fetching vehicle data: ${error.message}`;
                    document.body.appendChild(errorMessage);
                    marker.setPopupContent(`<b>${vehicleName}</b><br>Last Updated: Error`);
                    marker.openPopup();
                });
        }

        setInterval(fetchVehicleData, 5000);
        fetchVehicleData();
    </script>
</body>
</html>

<?php
ob_end_flush();
?>