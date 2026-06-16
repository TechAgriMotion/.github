<?php
/**
 * TechAgriMotion - Dynamic Status API
 * Pings backend services and merges with manual data.
 */

header('Content-Type: application/json');

// --- 1. Load manual data ---
$manualDataFile = __DIR__ . '/manual_data.json';
$data = [];
if (file_exists($manualDataFile)) {
    $data = json_decode(file_get_contents($manualDataFile), true);
}

if (!$data) {
    // Fallback if manual data is missing
    $data = [
        "force_overall_status" => null,
        "force_overall_message" => null,
        "services" => [],
        "incidents" => []
    ];
}

// --- 2. Define service endpoints/ports to check ---
$checks = [
    'srv_api' => ['host' => '127.0.0.1', 'port' => 8000, 'timeout' => 2], // FastAPI
    'srv_web' => ['host' => '127.0.0.1', 'port' => 80,   'timeout' => 2], // Apache/Web
    'srv_db'  => ['host' => '127.0.0.1', 'port' => 3306, 'timeout' => 2], // MySQL/MariaDB
    'srv_iot' => ['host' => '127.0.0.1', 'port' => 1883, 'timeout' => 2]  // Mosquitto
];

$activeIncidentsCount = 0;
$autoIncidents = [];

// --- 3. Perform checks ---
foreach ($data['services'] as &$service) {
    // Basic mock uptimes for visual representation (in a real scenario, this requires a timeseries DB)
    if (!isset($service['uptime'])) {
        $mockUptimes = ['srv_api' => 99.98, 'srv_web' => 99.99, 'srv_db' => 99.95, 'srv_iot' => 99.90];
        $service['uptime'] = $mockUptimes[$service['id']] ?? 99.9;
    }

    // Use forced status if provided in manual_data.json
    if (!empty($service['force_status'])) {
        $service['status'] = $service['force_status'];
        if ($service['status'] !== 'operational') $activeIncidentsCount++;
        continue;
    }

    $service['status'] = 'operational';
    
    // Dynamic check
    if (isset($checks[$service['id']])) {
        $check = $checks[$service['id']];
        $fp = @fsockopen($check['host'], $check['port'], $errno, $errstr, $check['timeout']);
        
        if (!$fp) {
            $service['status'] = 'major_outage';
            $activeIncidentsCount++;
            
            // Auto-generate incident
            $autoIncidents[] = [
                "id" => "auto_" . $service['id'] . "_" . time(),
                "name" => $service['name'] . " Offline",
                "type" => "Outage",
                "impact" => "major",
                "status" => "investigating",
                "created_at" => gmdate("Y-m-d\TH:i:s\Z"),
                "resolved_at" => null,
                "updates" => [
                    [
                        "status" => "investigating",
                        "message" => "Automated monitoring detected that the service is not responding on port " . $check['port'] . ".",
                        "timestamp" => gmdate("Y-m-d\TH:i:s\Z")
                    ]
                ]
            ];
        } else {
            fclose($fp);
        }
    }
}
unset($service);

// --- 4. Determine overall status ---
$overallStatus = 'operational';
$overallMessage = 'All Systems Operational';

if ($activeIncidentsCount > 0) {
    if ($activeIncidentsCount === count($data['services'])) {
        $overallStatus = 'major_outage';
        $overallMessage = 'Major System Outage';
    } else {
        $overallStatus = 'degraded';
        $overallMessage = 'Partial System Outage';
    }
}

// Override overall status if set manually
if (!empty($data['force_overall_status'])) {
    $overallStatus = $data['force_overall_status'];
}
if (!empty($data['force_overall_message'])) {
    $overallMessage = $data['force_overall_message'];
}

// Check manual incidents to see if any is ongoing
foreach ($data['incidents'] as $incident) {
    if ($incident['status'] !== 'resolved') {
        if ($overallStatus === 'operational') {
            $overallStatus = 'degraded';
            $overallMessage = 'System Degraded / Maintenance';
        }
    }
}

// Merge auto incidents with manual incidents
$allIncidents = array_merge($autoIncidents, $data['incidents']);

// Sort incidents by created_at descending
usort($allIncidents, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// --- 5. Output ---
$output = [
    "overall_status" => $overallStatus,
    "overall_message" => $overallMessage,
    "last_updated" => gmdate("Y-m-d\TH:i:s\Z"),
    "services" => $data['services'],
    "incidents" => $allIncidents
];

echo json_encode($output, JSON_PRETTY_PRINT);
