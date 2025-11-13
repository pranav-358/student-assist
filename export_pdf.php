<?php
require_once 'dompdf/autoload.inc.php';
use Dompdf\Dompdf;

//  Load only PHP data 
ob_start();
include('weekly_report.php');  
ob_end_clean(); 

//$imgPath = 'C:/xampp/htdocs/student/dyp_logo.png';
//$imgData = base64_encode(file_get_contents($imgPath));
//$base64 = 'data:image/png;base64,' . $imgData;

ob_start();
?>

<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; }

        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .header img {
            height: 70px;
            margin-bottom: 10px;
        }

        .summary {
            background: #eaeaea;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 16px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .table th, .table td {
            border: 1px solid black;
            padding: 8px;
            font-size: 14px;
        }

        footer {
            position: fixed;
            bottom: 10px;
            text-align: center;
            width: 100%;
            font-size: 12px;
            color: #444;
        }
    </style>
</head>

<body>

<div class="header">

    <h2>Weekly Complaint Report</h2>
    <p><?php echo date('d M Y'); ?></p>
</div>

<div class="summary">
    <strong>Total Complaints:</strong> <?php echo $total; ?><br>
    <strong>Solved:</strong> <?php echo $solved; ?> |
    <strong>Pending:</strong> <?php echo $pending; ?>
</div>

<h3>Category-wise Complaints</h3>
<table class="table">
    <tr>
        <th>Category</th>
        <th>Count</th>
    </tr>
    <?php
    if (!empty($categoryLabels)) {
        for ($i = 0; $i < count($categoryLabels); $i++) {
            echo "<tr><td>{$categoryLabels[$i]}</td><td>{$categoryCounts[$i]}</td></tr>";
        }
    } else {
        echo "<tr><td colspan='2'>No Data Available</td></tr>";
    }
    ?>
</table>

<h3>Daily Complaints (Last 7 Days)</h3>
<table class="table">
    <tr>
        <th>Date</th>
        <th>Count</th>
    </tr>
    <?php
    if (!empty($dailyLabels)) {
        for ($i = 0; $i < count($dailyLabels); $i++) {
            echo "<tr><td>{$dailyLabels[$i]}</td><td>{$dailyCounts[$i]}</td></tr>";
        }
    } else {
        echo "<tr><td colspan='2'>No Data Available</td></tr>";
    }
    ?>
</table>

<footer>
    © 2025 Student Assist App | Dr. D.Y. Patil Institute of Technology, Pimpri, Pune
</footer>

</body>
</html>

<?php
$html = ob_get_clean();

$pdf = new Dompdf();
//$pdf->set_option('isRemoteEnabled', true); 
$pdf->loadHtml($html);
$pdf->setPaper('A4', 'portrait');
$pdf->render();
$pdf->stream("Weekly_Report.pdf", ["Attachment" => true]);
?>
