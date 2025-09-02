<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .card-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px;
            padding: 8px;
            background: #f9faff;
            border-radius: 8px;
        }
        .card {
            background: #fff;
            border-radius: 6px;
            padding: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.15);
            border-left: 3px solid #e60000;
            width: 180px;
        }
        .card-header {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .card-field {
            font-size: 0.8rem;
            display: flex;
            gap: 4px;
        }
        .card-field label {
            min-width: 60px;
        }
        @media (max-width: 500px) {
            .card-container { grid-template-columns: 1fr; }
            .card { width: 100%; }
        }
        @media (min-width: 501px) and (max-width: 800px) {
            .card-container { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="card-container">
        <div class="card">
            <div class="card-header"><i class="fas fa-tools"></i><span>TEST_VEH_1</span></div>
            <div class="card-field"><label>Type:</label><span>Truck</span></div>
            <div class="card-field"><label>Status:</label><span>Due</span></div>
        </div>
        <div class="card">
            <div class="card-header"><i class="fas fa-tools"></i><span>TEST_VEH_2</span></div>
            <div class="card-field"><label>Type:</label><span>Crane</span></div>
            <div class="card-field"><label>Status:</label><span>Nearing</span></div>
        </div>
    </div>
</body>
</html>