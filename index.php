<?php
// Database configuration 
$servername = "localhost";
$username = "root"; 
$password = ""; 
$dbname = "student_assist_db"; 

// Admin configuration
$admin_password_hash = hash('sha256', 'secureadminpass');

// Constants
const MAX_POSTS_PER_DAY = 3;
const APP_TITLE = "Student Assist App";
const COLLEGE_NAME = "Dr. D.Y. Patil Institute of Technology, Pimpri";
const DEPARTMENT = "IT Department";

// Error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
date_default_timezone_set('Asia/Kolkata');
$today = date("Y-m-d");

function redirect($url) {
    header("Location: $url");
    exit();
}

// Database connection
$conn = @new mysqli($servername, $username, $password, $dbname);
$db_setup_error = '';

if ($conn->connect_error) {
    $db_setup_error = "Connection failed: " . $conn->connect_error;
} elseif (!$conn->select_db($dbname)) {
    $db_setup_error = "Database '$dbname' not selected.";
}

// =================================================================================
// FAKE COMPLAINT DETECTION FUNCTIONS
// =================================================================================

function analyzeComplaint($complaint_text) {
    $suspicious_patterns = [
        'repetitive_words' => ['very very', 'really really', 'so so', 'too too'],
        'excessive_caps' => '/[A-Z]{5,}/',
        'excessive_punctuation' => '/[!?]{3,}/',
        'spam_keywords' => ['free', 'win', 'prize', 'urgent', 'immediately', 'asap', 'click', 'link', 'http', 'money']
    ];
    
    $risk_score = 0;
    $flags = [];
    
    // 1. Check for repetitive words
    foreach ($suspicious_patterns['repetitive_words'] as $pattern) {
        if (stripos($complaint_text, $pattern) !== false) {
            $risk_score += 10;
            $flags[] = "Repetitive words detected";
        }
    }
    
    // 2. Check excessive capitalization
    if (preg_match($suspicious_patterns['excessive_caps'], $complaint_text)) {
        $risk_score += 15;
        $flags[] = "Excessive capitalization";
    }
    
    // 3. Check excessive punctuation
    if (preg_match($suspicious_patterns['excessive_punctuation'], $complaint_text)) {
        $risk_score += 10;
        $flags[] = "Excessive punctuation";
    }
    
    // 4. Check spam keywords
    foreach ($suspicious_patterns['spam_keywords'] as $keyword) {
        if (stripos($complaint_text, $keyword) !== false) {
            $risk_score += 5;
            $flags[] = "Suspicious keyword: $keyword";
        }
    }
    
    // 5. Length analysis (too short/long)
    $length = strlen($complaint_text);
    if ($length < 20) {
        $risk_score += 15;
        $flags[] = "Complaint too short";
    } elseif ($length > 1000) {
        $risk_score += 10;
        $flags[] = "Complaint unusually long";
    }
    
    // Determine risk level
    if ($risk_score >= 30) {
        $risk_level = 'HIGH';
    } elseif ($risk_score >= 15) {
        $risk_level = 'MEDIUM';
    } else {
        $risk_level = 'LOW';
    }
    
    return [
        'risk_score' => $risk_score,
        'risk_level' => $risk_level,
        'flags' => $flags
    ];
}

function checkUserBehavior($user_id, $conn) {
    $behavior_analysis = ['post_frequency' => 0];
    
    // Check posts in last hour
    $stmt = $conn->prepare("SELECT COUNT(*) FROM complaints WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $stmt->bind_result($recent_posts);
    $stmt->fetch();
    $stmt->close();
    
    if ($recent_posts > 3) {
        $behavior_analysis['post_frequency'] = 20;
    }
    
    return $behavior_analysis;
}

function checkSimilarComplaints($complaint_text, $conn) {
    // Simple similarity check
    $stmt = $conn->prepare("SELECT complaint_text FROM complaints WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 10");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $max_similarity = 0;
    while ($row = $result->fetch_assoc()) {
        similar_text($complaint_text, $row['complaint_text'], $similarity);
        if ($similarity > $max_similarity) {
            $max_similarity = $similarity;
        }
    }
    
    return $max_similarity;
}

function getSuspiciousComplaints($conn) {
    $sql = "SELECT * FROM complaints WHERE risk_score >= 25 OR is_auto_flagged = TRUE ORDER BY risk_score DESC";
    $result = $conn->query($sql);
    $suspicious = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $suspicious[] = $row;
        }
    }
    return $suspicious;
}

// Authentication Management
$is_student_logged_in = isset($_SESSION['student_anonymous_uid']);
$student_anonymous_uid = $is_student_logged_in ? $_SESSION['student_anonymous_uid'] : null;
$student_public_id = $is_student_logged_in ? $_SESSION['student_public_id'] : null;

$student_uid = $student_anonymous_uid; 
$login_message = '';
$register_message = '';

// Student Registration
if (isset($_POST['student_register']) && $conn && !$db_setup_error) {
    $input_sid = filter_var($_POST['student_id'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $input_pass = $_POST['student_password'] ?? '';

    if (empty($input_sid) || empty($input_pass)) {
        $register_message = "Student ID and Password cannot be empty.";
    } else {
        $stmt_check = $conn->prepare("SELECT student_id FROM students WHERE student_id = ?");
        if ($stmt_check) {
            $stmt_check->bind_param("s", $input_sid);
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                $register_message = "Registration Failed: Student ID already registered.";
            } else {
                $password_hash = password_hash($input_pass, PASSWORD_BCRYPT);
                $new_anonymous_uid = hash('sha256', uniqid(mt_rand(), true) . microtime());

                $stmt_insert = $conn->prepare("INSERT INTO students (student_id, password_hash, anonymous_uid) VALUES (?, ?, ?)");
                if ($stmt_insert) {
                    $stmt_insert->bind_param("sss", $input_sid, $password_hash, $new_anonymous_uid);
                    if ($stmt_insert->execute()) {
                        $register_message = "Registration successful! Please log in.";
                    } else {
                        $register_message = "Registration error: " . $stmt_insert->error;
                    }
                    $stmt_insert->close();
                }
            }
            $stmt_check->close();
        }
    }
}

// Student Login
if (isset($_POST['student_login']) && $conn && !$db_setup_error) {
    $input_sid = filter_var($_POST['student_id'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $input_pass = $_POST['student_password'] ?? '';

    $stmt = $conn->prepare("SELECT password_hash, anonymous_uid FROM students WHERE student_id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $input_sid);
        $stmt->execute();
        $stmt->bind_result($db_pass_hash, $db_anon_uid);
        
        if ($stmt->fetch()) {
            if (password_verify($input_pass, $db_pass_hash)) {
                $_SESSION['student_anonymous_uid'] = $db_anon_uid;
                $_SESSION['student_public_id'] = $input_sid; 
                redirect('index.php');
            } else {
                $login_message = "Invalid Student ID or Password.";
            }
        } else {
            $login_message = "Invalid Student ID or Password.";
        }
        $stmt->close();
    }
}
   
if (isset($_POST['student_logout'])) {
    unset($_SESSION['student_anonymous_uid']);
    unset($_SESSION['student_public_id']);
    redirect('index.php');
}

// Check block status
$is_user_blocked = false;
if ($student_anonymous_uid && $conn && !$db_setup_error) {
    $stmt = $conn->prepare("SELECT is_blocked FROM complaints WHERE user_id = ? AND is_blocked = TRUE LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $student_anonymous_uid);
        $stmt->execute();
        $stmt->bind_result($blocked);
        if ($stmt->fetch()) {
            $is_user_blocked = true;
        }
        $stmt->close();
    }
}

// Admin Login/Logout
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
$admin_error = '';

if (isset($_POST['admin_login'])) {
    $input_pass = hash('sha256', $_POST['admin_password'] ?? '');
    if ($input_pass === $admin_password_hash) {
        $_SESSION['is_admin'] = true;
        redirect('index.php');
    } else {
        $admin_error = "Invalid Admin Password.";
    }
}

if (isset($_POST['admin_logout'])) {
    unset($_SESSION['is_admin']);
    redirect('index.php');
}

// Post Complaint Logic
$post_message = "";

if (isset($_SESSION['post_message'])) {
    $post_message = $_SESSION['post_message'];
    unset($_SESSION['post_message']);
}

if (isset($_POST['post_complaint']) && $is_student_logged_in && !$is_user_blocked && $conn && !$db_setup_error) {
    $category = filter_var($_POST['category'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $complaint_text = filter_var($_POST['complaint_text'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $current_anon_uid = $student_anonymous_uid;

    if (empty($complaint_text) || empty($category)) {
        $_SESSION['post_message'] = "Category and Complaint text cannot be empty.";
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM complaints WHERE user_id = ? AND created_at = ?");
        if ($stmt) {
            $stmt->bind_param("ss", $current_anon_uid, $today);
            $stmt->execute();
            $stmt->bind_result($post_count);
            $stmt->fetch();
            $stmt->close();

            if ($post_count >= MAX_POSTS_PER_DAY) {
                $_SESSION['post_message'] = "Limit Reached: You have already posted " . MAX_POSTS_PER_DAY . " complaints today.";
            } else {
                // 4.2 Fake complaint detection + Insert new complaint
                $status = 'Pending';

                // NEW: Fake complaint detection
                $content_analysis = analyzeComplaint($complaint_text);
                $behavior_analysis = checkUserBehavior($current_anon_uid, $conn);
                $similarity_score = checkSimilarComplaints($complaint_text, $conn);

                $total_risk_score = $content_analysis['risk_score'] + $behavior_analysis['post_frequency'];
                $is_auto_flagged = $total_risk_score >= 25 || $similarity_score > 80;
                $flags_json = json_encode($content_analysis['flags']);

                // Insert with risk analysis
                $stmt = $conn->prepare("INSERT INTO complaints (user_id, category, complaint_text, status, created_at, risk_score, risk_level, is_auto_flagged, flags, similarity_score) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssisssi", $current_anon_uid, $category, $complaint_text, $status, $today, $total_risk_score, $content_analysis['risk_level'], $is_auto_flagged, $flags_json, $similarity_score);
                
                if ($stmt->execute()) {
                    $message = "Complaint posted successfully!";
                    if ($is_auto_flagged) {
                        $message .= " (Flagged for review)";
                    }
                    $_SESSION['post_message'] = $message . " (Remaining: " . (MAX_POSTS_PER_DAY - $post_count - 1) . " today)";
                } else {
                    $_SESSION['post_message'] = "Error posting complaint: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
    redirect('index.php');
} elseif (isset($_POST['post_complaint']) && !$is_student_logged_in) {
     $_SESSION['post_message'] = "Error: You must be logged in as a student to post a complaint.";
     redirect('index.php');
}

// Admin Actions
if ($is_admin && isset($_POST['action_type']) && $conn && !$db_setup_error) {
    $action_id = filter_var($_POST['action_id'] ?? 0, FILTER_VALIDATE_INT);
    $action_type = filter_var($_POST['action_type'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $target_uid = filter_var($_POST['target_uid'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);

    if ($action_type === 'solve' || $action_type === 'pending') {
        if ($action_id > 0) {
            $new_status = ($action_type === 'solve' ? 'Solved' : 'Pending');
            $stmt = $conn->prepare("UPDATE complaints SET status = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $new_status, $action_id);
                $stmt->execute();
                $stmt->close();
            }
            redirect('index.php');
        }
    }
    
    if ($action_type === 'block' && !empty($target_uid)) {
        $stmt_complaints = $conn->prepare("UPDATE complaints SET is_blocked = TRUE WHERE user_id = ?");
        if ($stmt_complaints) {
            $stmt_complaints->bind_param("s", $target_uid);
            $stmt_complaints->execute();
            $stmt_complaints->close();
        }
        redirect('index.php');
    }
}

// Fetch Complaints
$complaints = [];
if ($conn && !$db_setup_error) {
    $sql = "SELECT id, user_id, category, complaint_text, status, created_at, is_blocked, risk_score, risk_level, is_auto_flagged, flags, similarity_score FROM complaints 
            ORDER BY FIELD(status, 'Pending', 'Solved'), is_blocked DESC, created_at DESC";
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $complaints[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_TITLE; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #3b82f6;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
            --bg-light: #f8fafc;
            --bg-white: #ffffff;
            --text-dark: #1e293b;
            --text-light: #64748b;
            --border: #e2e8f0;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius: 8px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 1.5rem 0;
            box-shadow: var(--shadow-lg);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-icon {
            font-size: 2rem;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem;
            border-radius: 50%;
        }

        .logo-text {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .logo-subtitle {
            font-size: 0.875rem;
            opacity: 0.9;
            margin-top: 0.25rem;
        }

        .auth-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        /* Cards */
        .card {
            background: var(--bg-white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border);
            transition: var(--transition);
        }

        .card:hover {
            box-shadow: var(--shadow-lg);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border-left: 4px solid var(--primary);
            padding-left: 1rem;
        }

        /* Forms */
        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        input, select, textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
            font-family: inherit;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        textarea {
            resize: vertical;
            min-height: 120px;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            font-family: inherit;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--secondary);
            color: white;
        }

        .btn-danger {
            background: var(--error);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-color: var(--success);
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border-color: var(--error);
        }

        .alert-info {
            background: #f0f9ff;
            color: var(--primary-dark);
            border-color: var(--primary);
        }

        /* Student Info */
        .student-info {
            background: linear-gradient(135deg, #dbeafe 0%, #e0f2fe 100%);
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid var(--primary);
        }

        /* Complaint Grid */
        .complaint-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .complaint-card {
            background: var(--bg-white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem;
            transition: var(--transition);
        }

        .complaint-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .complaint-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
        }

        .complaint-category {
            font-weight: 700;
            color: var(--primary-dark);
            font-size: 1.1rem;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending {
            background: #fef3c7;
            color: var(--warning);
        }

        .status-solved {
            background: #d1fae5;
            color: var(--success);
        }

        .status-blocked {
            background: #fee2e2;
            color: var(--error);
        }

        .complaint-text {
            color: var(--text-dark);
            margin-bottom: 1rem;
            line-height: 1.6;
        }

        .complaint-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
            color: var(--text-light);
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        /* Admin Panel */
        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        .admin-sidebar {
            width: 280px;
            background: linear-gradient(180deg, var(--primary-dark) 0%, #1e3a8a 100%);
            color: white;
            padding: 2rem 1.5rem;
        }

        .admin-main {
            flex: 1;
            padding: 2rem;
            background: var(--bg-light);
        }

        .admin-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-white);
            padding: 1.5rem;
            border-radius: var(--radius);
            text-align: center;
            box-shadow: var(--shadow);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }

        /* Modals */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 1rem;
        }

        .modal-content {
            background: var(--bg-white);
            border-radius: var(--radius);
            padding: 2rem;
            max-width: 450px;
            width: 100%;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .modal-actions .btn {
            flex: 1;
        }

        /* Footer */
        .footer {
            background: var(--primary-dark);
            color: white;
            padding: 2rem 0;
            margin-top: 4rem;
        }

        .footer-content {
            text-align: center;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin: 1rem 0;
        }

        .footer-links a {
            color: #93c5fd;
            text-decoration: none;
        }

        .footer-links a:hover {
            color: white;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }

            .auth-buttons {
                justify-content: center;
            }

            .complaint-grid {
                grid-template-columns: 1fr;
            }

            .admin-layout {
                flex-direction: column;
            }

            .admin-sidebar {
                width: 100%;
            }

            .student-info {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <?php if ($is_admin): ?>
        <!-- Admin Layout -->
        <div class="admin-layout">
            <div class="admin-sidebar">
                <div class="logo">
                    <i class="fas fa-user-shield logo-icon"></i>
                    <div>
                        <div class="logo-text">Admin Panel</div>
                        <div class="logo-subtitle"><?php echo COLLEGE_NAME; ?></div>
                    </div>
                </div>
                
                <div style="margin: 2rem 0;">
                    <div style="background: rgba(255,255,255,0.1); padding: 1rem; border-radius: var(--radius);">
                        <div style="font-weight: 600; margin-bottom: 0.5rem;">System Status</div>
                        <div>Total Complaints: <?php echo count($complaints); ?></div>
                        <div>Pending: <?php echo count(array_filter($complaints, fn($c) => $c['status'] === 'Pending' && !$c['is_blocked'])); ?></div>
                    </div>
                </div>

                <form method="POST">
                    <button type="submit" name="admin_logout" class="btn btn-danger" style="width: 100%;">
                        <i class="fas fa-sign-out-alt"></i> Logout Admin
                    </button>
                </form>

                <div style="margin-top: 2rem;">
                    <a href="weekly_report.php" class="btn btn-secondary" style="width: 100%;">
                        <i class="fas fa-chart-bar"></i> Weekly Report
                    </a>
                </div>
            </div>

            <div class="admin-main">
                <div class="container">
                    <h1 style="font-size: 2rem; font-weight: 800; color: var(--primary-dark); margin-bottom: 1.5rem;">
                        Complaints Management
                    </h1>

                    <?php if (!empty($db_setup_error)): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-database"></i> Database Error: <?php echo $db_setup_error; ?>
                        </div>
                    <?php endif; ?>

                    <div class="admin-stats">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo count($complaints); ?></div>
                            <div class="stat-label">Total Complaints</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo count(array_filter($complaints, fn($c) => $c['status'] === 'Pending')); ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo count(array_filter($complaints, fn($c) => $c['status'] === 'Solved')); ?></div>
                            <div class="stat-label">Solved</div>
                        </div>
                    </div>

                    <div class="card">
                        <h2 class="section-title">
                            <i class="fas fa-list"></i>
                            All Complaints
                        </h2>

                        <?php if (empty($complaints)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No complaints found in the system.
                            </div>
                        <?php else: ?>
                            <div class="complaint-grid">
                                <?php foreach ($complaints as $c): ?>
                                    <div class="complaint-card">
                                        <div class="complaint-header">
                                            <div class="complaint-category"><?php echo htmlspecialchars($c['category']); ?></div>
                                            <?php
                                                $status_class = 'status-pending';
                                                $status_text = htmlspecialchars($c['status']);
                                                if ($c['status'] === 'Solved') {
                                                    $status_class = 'status-solved';
                                                }
                                                if ($c['is_blocked']) {
                                                    $status_class = 'status-blocked';
                                                    $status_text = 'BLOCKED';
                                                }
                                            ?>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </div>
                                        
                                        <div class="complaint-text">
                                            <?php echo nl2br(htmlspecialchars($c['complaint_text'])); ?>
                                        </div>

                                        <div class="complaint-footer">
                                            <span><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($c['created_at']); ?></span>
                                            <span style="font-size: 0.75rem; color: var(--text-light);">
                                                UID: <?php echo substr(htmlspecialchars($c['user_id']), 0, 8); ?>...
                                            </span>
                                        </div>

                                        <?php if (!$c['is_blocked']): ?>
                                            <div style="margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                <?php if ($c['status'] === 'Pending'): ?>
                                                    <form method="POST">
                                                        <input type="hidden" name="action_id" value="<?php echo $c['id']; ?>">
                                                        <input type="hidden" name="action_type" value="solve">
                                                        <button type="submit" class="btn btn-success btn-small">
                                                            <i class="fas fa-check"></i> Mark Solved
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST">
                                                        <input type="hidden" name="action_id" value="<?php echo $c['id']; ?>">
                                                        <input type="hidden" name="action_type" value="pending">
                                                        <button type="submit" class="btn btn-warning btn-small">
                                                            <i class="fas fa-redo"></i> Reopen
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <button type="button" class="btn btn-danger btn-small" 
                                                    onclick="openBlockModal('<?php echo htmlspecialchars($c['user_id']); ?>')">
                                                    <i class="fas fa-ban"></i> Block User
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <div style="margin-top: 1rem;">
                                                <span class="status-badge status-blocked">
                                                    <i class="fas fa-ban"></i> USER BLOCKED
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ============================================= -->
                    <!-- SUSPICIOUS COMPLAINTS SECTION -->
                    <!-- ============================================= -->
                    
                    <?php
                    $suspicious_complaints = getSuspiciousComplaints($conn);
                    ?>
                    
                    <?php if (!empty($suspicious_complaints)): ?>
                    <div class="card" style="margin-top: 2rem; border-left: 4px solid #ef4444;">
                        <h2 class="section-title" style="border-color: #ef4444;">
                            <i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i>
                            🚨 Suspicious Complaints (Auto-Flagged)
                        </h2>
                        
                        <div class="complaint-grid">
                            <?php foreach ($suspicious_complaints as $c): ?>
                                <div class="complaint-card" style="border: 2px solid #ef4444; background: #fef2f2;">
                                    <div class="complaint-header">
                                        <div class="complaint-category"><?php echo htmlspecialchars($c['category']); ?></div>
                                        <div>
                                            <span class="status-badge" style="background: #ef4444; color: white;">
                                                <i class="fas fa-flag"></i> RISK: <?php echo $c['risk_level']; ?>
                                            </span>
                                            <span class="status-badge" style="background: #f59e0b; color: white;">
                                                Score: <?php echo $c['risk_score']; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="complaint-text">
                                        <?php echo nl2br(htmlspecialchars($c['complaint_text'])); ?>
                                    </div>

                                    <!-- Show detection flags -->
                                    <?php if (!empty($c['flags'])): ?>
                                        <div style="margin: 1rem 0; padding: 0.75rem; background: #fee2e2; border-radius: 4px;">
                                            <strong>🚩 Detection Flags:</strong><br>
                                            <?php 
                                            $flags = json_decode($c['flags'], true);
                                            if (is_array($flags)) {
                                                foreach ($flags as $flag): ?>
                                                    <span style="display: inline-block; background: #fecaca; color: #991b1b; padding: 0.25rem 0.5rem; border-radius: 4px; margin: 0.25rem; font-size: 0.75rem;">
                                                        <?php echo htmlspecialchars($flag); ?>
                                                    </span>
                                                <?php endforeach; 
                                            }?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="complaint-footer">
                                        <span><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($c['created_at']); ?></span>
                                        <span style="color: #ef4444; font-weight: 600;">
                                            <i class="fas fa-robot"></i> Auto-Flagged
                                        </span>
                                    </div>

                                    <!-- Admin actions -->
                                    <div style="margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                        <?php if ($c['status'] === 'Pending'): ?>
                                            <form method="POST">
                                                <input type="hidden" name="action_id" value="<?php echo $c['id']; ?>">
                                                <input type="hidden" name="action_type" value="solve">
                                                <button type="submit" class="btn btn-success btn-small">
                                                    <i class="fas fa-check"></i> Mark Solved
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST">
                                                <input type="hidden" name="action_id" value="<?php echo $c['id']; ?>">
                                                <input type="hidden" name="action_type" value="pending">
                                                <button type="submit" class="btn btn-warning btn-small">
                                                    <i class="fas fa-redo"></i> Reopen
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <button type="button" class="btn btn-danger btn-small" 
                                            onclick="openBlockModal('<?php echo htmlspecialchars($c['user_id']); ?>')">
                                            <i class="fas fa-ban"></i> Block User
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="card" style="margin-top: 2rem; border-left: 4px solid #10b981;">
                        <h2 class="section-title" style="border-color: #10b981;">
                            <i class="fas fa-check-circle" style="color: #10b981;"></i>
                            ✅ Suspicious Complaints
                        </h2>
                        <div class="alert alert-success">
                            <i class="fas fa-thumbs-up"></i> No suspicious complaints detected! System is clean.
                        </div>
                    </div>
                    <?php endif; ?>
                    <!-- ============================================= -->
                    <!-- END SUSPICIOUS COMPLAINTS SECTION -->
                    <!-- ============================================= -->
                    
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Student/Public Layout -->
        <header class="header">
            <div class="container">
                <div class="header-content">
                    <div class="logo">
                        <i class="fas fa-headset logo-icon"></i>
                        <div>
                            <div class="logo-text"><?php echo APP_TITLE; ?></div>
                            <div class="logo-subtitle"><?php echo COLLEGE_NAME . " | " . DEPARTMENT; ?></div>
                        </div>
                    </div>
                    
                    <div class="auth-buttons">
                        <?php if ($is_student_logged_in): ?>
                            <form method="POST">
                                <button type="submit" name="student_logout" class="btn btn-danger">
                                    <i class="fas fa-sign-out-alt"></i> Logout
                                </button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="btn btn-primary" onclick="openModal('loginModal')">
                                <i class="fas fa-sign-in-alt"></i> Student Login
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="openModal('registerModal')">
                                <i class="fas fa-user-plus"></i> Register
                            </button>
                        <?php endif; ?>
                        
                        <button type="button" class="btn" style="background: rgba(255,255,255,0.2); color: white;" 
                                onclick="openModal('adminLoginModal')">
                            <i class="fas fa-user-shield"></i> Admin
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <main class="container" style="padding: 2rem 1rem;">
            <?php if (!empty($db_setup_error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-database"></i> Database Error: <?php echo $db_setup_error; ?>
                </div>
            <?php endif; ?>

            <?php if ($is_student_logged_in): ?>
                <div class="student-info">
                    <div>
                        <i class="fas fa-user-circle"></i> 
                        <strong>Logged in as: <?php echo htmlspecialchars($student_public_id); ?></strong>
                    </div>
                    <div style="color: var(--text-light);">
                        <i class="fas fa-mask"></i> Posting Anonymously
                    </div>
                </div>
            <?php endif; ?>

            <!-- Complaint Submission Card -->
            <div class="card">
                <h2 class="section-title">
                    <i class="fas fa-bullhorn"></i>
                    Submit Complaint/Feedback
                </h2>

                <?php if (!empty($post_message)): ?>
                    <div class="alert <?php echo strpos($post_message, 'successful') !== false ? 'alert-success' : 'alert-error'; ?>">
                        <?php echo strpos($post_message, 'successful') !== false ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-exclamation-triangle"></i>'; ?>
                        <?php echo $post_message; ?>
                    </div>
                <?php endif; ?>

                <?php if (!$is_student_logged_in): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Please log in or register to submit a complaint.
                    </div>
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                        <button type="button" class="btn btn-primary" onclick="openModal('loginModal')">
                            <i class="fas fa-sign-in-alt"></i> Student Login
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="openModal('registerModal')">
                            <i class="fas fa-user-plus"></i> Register
                        </button>
                    </div>
                <?php elseif ($is_user_blocked): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-ban"></i> Your account has been blocked from posting complaints.
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <div class="form-group">
                            <label for="category"><i class="fas fa-tag"></i> Category</label>
                            <select id="category" name="category" required>
                                <option value="">Select a category</option>
                                <option value="Faculty">Faculty & Teaching</option>
                                <option value="Department">Department Administration</option>
                                <option value="Library">Library Services</option>
                                <option value="Lab">Lab Equipment</option>
                                <option value="Infrastructure">Infrastructure</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="complaint_text"><i class="fas fa-comment"></i> Your Complaint</label>
                            <textarea id="complaint_text" name="complaint_text" maxlength="500" 
                                      placeholder="Describe your issue (max 500 characters)" required></textarea>
                            <div style="text-align: right; font-size: 0.875rem; color: var(--text-light); margin-top: 0.5rem;">
                                <span id="charCount">500</span> characters remaining
                            </div>
                        </div>

                        <button type="submit" name="post_complaint" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-paper-plane"></i> Submit Anonymously
                        </button>
                        
                        <div style="text-align: center; margin-top: 1rem; color: var(--text-light); font-size: 0.875rem;">
                            <i class="fas fa-shield-alt"></i> Your identity is protected
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Public Complaints Card -->
            <div class="card">
                <h2 class="section-title">
                    <i class="fas fa-list-ul"></i>
                    Public Complaint Log
                </h2>
                <p style="color: var(--text-light); margin-bottom: 1.5rem;">
                    View all reported issues and their current status. Promoting transparency across the institution.
                </p>

                <?php 
                $public_complaints = array_filter($complaints, fn($c) => !$c['is_blocked']);
                if (empty($public_complaints)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No complaints have been posted yet.
                    </div>
                <?php else: ?>
                    <div class="complaint-grid">
                        <?php foreach ($public_complaints as $c): ?>
                            <div class="complaint-card">
                                <div class="complaint-header">
                                    <div class="complaint-category"><?php echo htmlspecialchars($c['category']); ?></div>
                                    <?php
                                        $status_class = $c['status'] === 'Solved' ? 'status-solved' : 'status-pending';
                                        $status_text = htmlspecialchars($c['status']);
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </div>
                                
                                <div class="complaint-text">
                                    <?php echo nl2br(htmlspecialchars($c['complaint_text'])); ?>
                                </div>

                                <div class="complaint-footer">
                                    <span><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($c['created_at']); ?></span>
                                    <?php if ($is_student_logged_in && $c['user_id'] === $student_anonymous_uid): ?>
                                        <span style="color: var(--primary); font-weight: 600;">
                                            <i class="fas fa-star"></i> Your Post
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    <?php endif; ?>

    <!-- Modals -->
    <div id="adminLoginModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-user-shield"></i>
                    Admin Login
                </h3>
            </div>
            <?php if (!empty($admin_error)): ?>
                <div class="alert alert-error"><?php echo $admin_error; ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label for="admin_password">Admin Password</label>
                    <input type="password" id="admin_password" name="admin_password" required 
                           placeholder="Enter admin password">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('adminLoginModal')">Cancel</button>
                    <button type="submit" name="admin_login" class="btn btn-primary">Login</button>
                </div>
            </form>
        </div>
    </div>

    <div id="loginModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-sign-in-alt"></i>
                    Student Login
                </h3>
            </div>
            <?php if (!empty($login_message)): ?>
                <div class="alert alert-error"><?php echo $login_message; ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label for="login_student_id">Student ID</label>
                    <input type="text" id="login_student_id" name="student_id" required 
                           placeholder="Enter your student ID">
                </div>
                <div class="form-group">
                    <label for="login_password">Password</label>
                    <input type="password" id="login_password" name="student_password" required 
                           placeholder="Enter your password">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('loginModal')">Cancel</button>
                    <button type="submit" name="student_login" class="btn btn-primary">Login</button>
                </div>
            </form>
            <div style="text-align: center; margin-top: 1rem;">
                <a href="#" onclick="closeModal('loginModal'); openModal('registerModal'); return false;" 
                   style="color: var(--primary); text-decoration: none;">
                    New student? Register here
                </a>
            </div>
        </div>
    </div>

    <div id="registerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-user-plus"></i>
                    Student Registration
                </h3>
            </div>
            <?php if (!empty($register_message)): ?>
                <div class="alert <?php echo strpos($register_message, 'successful') !== false ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo $register_message; ?>
                </div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label for="register_student_id">Student ID</label>
                    <input type="text" id="register_student_id" name="student_id" required 
                           placeholder="Enter your student ID">
                </div>
                <div class="form-group">
                    <label for="register_password">Password</label>
                    <input type="password" id="register_password" name="student_password" required 
                           placeholder="Create a password">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('registerModal')">Cancel</button>
                    <button type="submit" name="student_register" class="btn btn-primary">Register</button>
                </div>
            </form>
            <div style="text-align: center; margin-top: 1rem;">
                <a href="#" onclick="closeModal('registerModal'); openModal('loginModal'); return false;" 
                   style="color: var(--primary); text-decoration: none;">
                    Already registered? Login here
                </a>
            </div>
        </div>
    </div>

    <div id="blockModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" style="color: var(--error);">
                    <i class="fas fa-exclamation-triangle"></i>
                    Confirm Block User
                </h3>
            </div>
            <p>Are you sure you want to block this user? This will prevent them from posting new complaints.</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('blockModal')">Cancel</button>
                <form id="blockForm" method="POST" style="flex: 1;">
                    <input type="hidden" name="target_uid" id="modalTargetUid">
                    <input type="hidden" name="action_type" value="block">
                    <button type="submit" class="btn btn-danger" style="width: 100%;">Confirm Block</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <p><strong><?php echo COLLEGE_NAME; ?></strong></p>
                <p>Sant Tukaram Nagar, Pimpri, Pune - 411018</p>
                <div class="footer-links">
                    <a href="privacy_policy.php">Privacy Policy</a>
                    <a href="terms_condictions.php">Terms & Conditions</a>
                </div>
                <p>&copy; 2025 Student Assist App. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function openBlockModal(userId) {
            document.getElementById('modalTargetUid').value = userId;
            openModal('blockModal');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Character counter
        document.addEventListener('DOMContentLoaded', function() {
            const textarea = document.getElementById('complaint_text');
            const charCount = document.getElementById('charCount');
            
            if (textarea && charCount) {
                textarea.addEventListener('input', function() {
                    const remaining = 500 - this.value.length;
                    charCount.textContent = remaining;
                });
            }

            // Auto-open modals with errors
            <?php if (!empty($login_message)): ?>
                openModal('loginModal');
            <?php elseif (!empty($register_message)): ?>
                openModal('registerModal');
            <?php elseif (!empty($admin_error)): ?>
                openModal('adminLoginModal');
            <?php endif; ?>
        });
    </script>
</body>
</html>