<?php
$host = 'localhost';
$dbname = 'ems';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $names = [
        'Abdul Karim', 'Fatema Begum', 'Rashid Khan', 'Shireen Akter',
        'Nasrin Begum', 'Rafiqul Hasan', 'Mizanur Rahman', 'Farhana Yasmin',
        'Kamal Uddin', 'Ahsan Karim', 'Rezaul Haque', 'Moulana Abdul Latif',
        'Nurun Nahar Begum', 'Shahidul Islam', 'Rokhsana Chowdhury', 'Anwar Hossain',
        'Khadija Begum', 'Shahana Akhter', 'Mohammad Ali', 'Jahanara Begum'
    ];
    
    // Get the maximum existing ID to continue from there
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(voter_code, 10) AS UNSIGNED)) as max_id FROM voters WHERE voter_code LIKE 'VTR-2026-%'");
    $result = $stmt->fetch();
    $start_id = ($result['max_id'] ?? 0) + 1;
    
    echo "Starting from ID: $start_id<br>";
    echo "Target: 540 for station 1, 520 for station 2, 240 for station 3<br><br>";
    
    // Define distribution
    $distribution = [
        1 => 540,  // station_id 1
        2 => 520,  // station_id 2
        3 => 240   // station_id 3
    ];
    
    $pdo->beginTransaction();
    $inserted = 0;
    $skipped = 0;
    $count_station1 = 0;
    $count_station2 = 0;
    $count_station3 = 0;
    
    // Create a list of station assignments based on distribution
    $station_assignments = [];
    foreach ($distribution as $station_id => $count) {
        for ($i = 0; $i < $count; $i++) {
            $station_assignments[] = $station_id;
        }
    }
    shuffle($station_assignments); // Randomize the order
    
    for ($i = 0; $i < 1300; $i++) {
        $current_id = $start_id + $i;
        
        // Check if voter_code already exists
        $check = $pdo->prepare("SELECT COUNT(*) FROM voters WHERE voter_code = ?");
        $check->execute(['VTR-2026-' . str_pad($current_id, 5, '0', STR_PAD_LEFT)]);
        
        if ($check->fetchColumn() > 0) {
            $skipped++;
            continue;
        }
        
        // Get assigned station from distribution
        $station_id = $station_assignments[$i];
        
        // Update counter
        if ($station_id == 1) $count_station1++;
        if ($station_id == 2) $count_station2++;
        if ($station_id == 3) $count_station3++;
        
        // Determine constituency based on station
        $constituency_id = $station_id; // station 1 -> constituency 1, etc.
        
        // Determine booth (1-4) based on station
        $booth_id = (($i + floor($i/10)) % 4) + 1;
        
        $national_id = 'NID' . str_pad($current_id, 6, '0', STR_PAD_LEFT);
        $full_name = $names[$current_id % 20];
        $year = rand(1950, 2005);
        $month = rand(1, 12);
        $day = rand(1, 28);
        $dob = sprintf("%04d-%02d-%02d", $year, $month, $day);
        
        $voted_date = rand(1, 30);
        $voted_hour = rand(8, 16);
        $voted_min = rand(0, 59);
        $voted_sec = rand(0, 59);
        $voted_at = sprintf("2026-04-%02d %02d:%02d:%02d", $voted_date, $voted_hour, $voted_min, $voted_sec);
        $voter_code = 'VTR-2026-' . str_pad($current_id, 5, '0', STR_PAD_LEFT);
        
        $sql = "INSERT INTO voters (national_id, full_name, date_of_birth, constituency_id, polling_station_id, booth_id, has_voted, voted_at, voter_code) 
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$national_id, $full_name, $dob, $constituency_id, $station_id, $booth_id, $voted_at, $voter_code]);
        $inserted++;
        
        if ($inserted % 100 == 0) {
            echo "Inserted $inserted voters (skipped $skipped)...<br>";
            flush();
        }
    }
    
    $pdo->commit();
    
    echo "<hr>";
    echo "<strong style='color:green'>✅ Successfully inserted $inserted new voters!</strong><br>";
    echo "<strong style='color:orange'>⚠️ Skipped $skipped duplicates</strong><br><br>";
    echo "<strong>Distribution achieved:</strong><br>";
    echo "📍 Station 1: $count_station1 voters (Target: 540)<br>";
    echo "📍 Station 2: $count_station2 voters (Target: 520)<br>";
    echo "📍 Station 3: $count_station3 voters (Target: 240)<br>";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>