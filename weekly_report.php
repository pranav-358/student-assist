<?php
//Database connection
$servername = "localhost";
$username = "root";
$password = "";
$database = "student_assist_db";   
$conn = mysqli_connect($servername, $username, $password, $database);

if (!$conn) {
    die("Database Connection failed: " . mysqli_connect_error());
}

// ✅ Last 7 days
$start_date = date('Y-m-d', strtotime('-7 days'));
$end_date = date('Y-m-d');

// ✅ Total complaints
$totalQuery = "SELECT COUNT(*) AS total FROM complaints 
               WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'";
$totalResult = mysqli_query($conn, $totalQuery);
$total = mysqli_fetch_assoc($totalResult)['total'];

// ✅ Solved vs Pending
$statusQuery = "SELECT status, COUNT(*) AS count FROM complaints 
                WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'
                GROUP BY status";

$statusResult = mysqli_query($conn, $statusQuery);

$solved = 0;
$pending = 0;

while ($row = mysqli_fetch_assoc($statusResult)) {
    if (strcasecmp($row['status'], 'Solved') == 0) $solved = $row['count'];
    if (strcasecmp($row['status'], 'Pending') == 0) $pending = $row['count'];
}

// ✅ Category-wise data
$categoryQuery = "SELECT category, COUNT(*) AS count 
                  FROM complaints 
                  WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'
                  GROUP BY category";

$categoryResult = mysqli_query($conn, $categoryQuery);

$categoryLabels = [];
$categoryCounts = [];

while ($row = mysqli_fetch_assoc($categoryResult)) {
    $categoryLabels[] = $row['category'];
    $categoryCounts[] = $row['count'];
}

// ✅ Daily complaints data
$dailyLabels = [];
$dailyCounts = [];

for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $dailyLabels[] = $day;

    $countQuery = "SELECT COUNT(*) AS count FROM complaints WHERE DATE(created_at) = '$day'";
    $countResult = mysqli_query($conn, $countQuery);
    $dailyCounts[] = mysqli_fetch_assoc($countResult)['count'];
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Weekly Report</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body {
            font-family: Arial;
            background: #f3f3f3;
            padding: 20px;
        }

        .container {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            max-width: 1100px;
            margin: auto;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #ddd;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .header img {
            height: 70px;
            margin-bottom: 10px;
        }

        .summary-box {
            padding: 12px;
            background: #eaf3ff;
            border-left: 5px solid #0066ff;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 18px;
        }

        .chart-section {
            padding: 20px;
            background: white;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 2px 6px rgba(0,0,0,.1);
        }

        footer {
            text-align: center;
            margin-top: 30px;
            font-size: 13px;
            color: #555;
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <img src="dyp_logo.png" alt="Institute Logo"> 
        <h1>📊 Weekly Complaint Report [IT Department]</h1>
        <div>(Showing data from <strong><?php echo $start_date; ?></strong> to <strong><?php echo $end_date; ?></strong>)</div>
    </div>

    <div class="summary-box">
        ✅ Total Complaints This Week: <strong><?php echo $total; ?></strong><br>
        🟢 Solved: <strong><?php echo $solved; ?></strong> |
        🔴 Pending: <strong><?php echo $pending; ?></strong>
    </div>

    <div class="chart-section" style="width:500px; height:500px; margin:0 auto;">
        <h3>Solved vs Pending</h3>
        <canvas id="pieChart"></canvas>
    </div>

    <div class="chart-section">
        <h3>Category-wise Complaints</h3>
        <canvas id="barChart"></canvas>
    </div>

    <div class="chart-section">
        <h3>Daily Complaints (7 Days)</h3>
        <canvas id="lineChart"></canvas>
    </div>

    <form action="export_pdf.php" method="POST">
        <button type="submit"
            style="background:#007bff;color:white;border:none;padding:12px 25px;border-radius:6px;cursor:pointer;margin:20px auto;display:block;">
            📄 Export Weekly Report as PDF
        </button>
    </form>

    <footer>
        © 2025 Student Assist App — All Rights Reserved <br>
        Dr. D.Y. Patil Institute of Technology, Pimpri, Pune
    </footer>

</div>


<script>
// Pie Chart
new Chart(document.getElementById("pieChart"), {
    type: 'pie',
    data: {
        labels: ["Solved", "Pending"],
        datasets: [{ data: [<?php echo $solved; ?>, <?php echo $pending; ?>], backgroundColor:["#4CAF50","#F44336"] }]
    }
});

// Bar Chart
new Chart(document.getElementById("barChart"), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($categoryLabels); ?>,
        datasets: [{
            label: "Complaints",
            data: <?php echo json_encode($categoryCounts); ?>,
            backgroundColor: "#2196F3"
        }]
    }
});

// Line Chart
new Chart(document.getElementById("lineChart"), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($dailyLabels); ?>,
        datasets: [{
            label: "Daily Complaints",
            data: <?php echo json_encode($dailyCounts); ?>,
            borderColor:"#007bff",borderWidth:3,fill:false,tension:0.3
        }]
    }
});
</script>

</body>
</html>
