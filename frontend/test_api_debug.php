<?php
/**
 * Test script for exam levels API
 * Access this file directly in browser to debug
 */

// Database configuration
$db_host = 'localhost';
$db_name = 'nattest_regs';
$db_user = 'root';
$db_pass = '';

echo "<h1>Exam Levels API Debug</h1>";

// Test database connection
try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

    if ($conn->connect_error) {
        echo "<p style='color:red'>❌ Database connection failed: " . $conn->connect_error . "</p>";
        echo "<p><strong>Check your database credentials in config.php</strong></p>";
        exit;
    }

    echo "<p style='color:green'>✅ Database connection successful</p>";

    // Check if tables exist
    $tables = ['exam_dates', 'exam_levels', 'registrations'];

    echo "<h2>Table Status:</h2><ul>";
    foreach ($tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result && $result->num_rows > 0) {
            echo "<li style='color:green'>✅ $table table exists</li>";
        } else {
            echo "<li style='color:red'>❌ $table table missing</li>";
        }
    }
    echo "</ul>";

    // Check exam dates
    echo "<h2>Available Exam Dates:</h2>";
    $result = $conn->query("SELECT * FROM exam_dates ORDER BY exam_date");

    if ($result && $result->num_rows > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Exam Date</th><th>Deadline</th><th>ID</th><th>Levels Available</th></tr>";

        while ($row = $result->fetch_assoc()) {
            $examDateId = $row['id'];
            echo "<tr>";
            echo "<td>" . $row['exam_date'] . "</td>";
            echo "<td>" . $row['registration_deadline'] . "</td>";
            echo "<td>" . $examDateId . "</td>";

            // Get levels for this date
            $levelResult = $conn->prepare("SELECT level FROM exam_levels WHERE exam_date_id = ? ORDER BY level");
            $levelResult->bind_param('s', $examDateId);
            $levelResult->execute();
            $levels = [];
            while ($levelRow = $levelResult->get_result()->fetch_assoc()) {
                $levels[] = $levelRow['level'];
            }
            echo "<td>" . implode(', ', $levels) . "</td>";
            echo "</tr>";
        }

        echo "</table>";

        echo "<p><strong>✅ Found " . $result->num_rows . " exam date(s)</strong></p>";

        // Test API endpoint
        echo "<h2>Test API Endpoint:</h2>";
        $firstDate = $result->fetch_assoc();
        $testDate = $firstDate['exam_date'];

        echo "<p>Testing with date: <strong>$testDate</strong></p>";
        echo "<p>API URL should be: <code>/intake/api/exam-dates/levels.php?date=$testDate</code></p>";

        // Simulate API call
        echo "<h3>API Response:</h3>";
        $levelResult->data_seek(0); // Reset pointer
        $levels = [];
        while ($levelRow = $levelResult->get_result()->fetch_assoc()) {
            $levels[] = $levelRow['level'];
        }

        $response = [
            'levels' => $levels,
            'exam_date' => $testDate,
            'count' => count($levels)
        ];

        echo "<pre style='background:#f5f5f5; padding:10px; border:1px solid #ddd;'>";
        echo json_encode($response, JSON_PRETTY_PRINT);
        echo "</pre>";

        echo "<p style='color:green'>✅ API endpoint should work correctly</p>";

    } else {
        echo "<p style='color:red'>❌ No exam dates found in database</p>";
        echo "<p><strong>You need to add exam dates through the admin panel first!</strong></p>";
    }

    $conn->close();

} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
}
?>