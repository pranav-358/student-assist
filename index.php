<?php
// Database configuration 
$servername = "localhost";
$username = "root"; 
$password = ""; 
$dbname = "student_assist_db"; 

// Admin configuration
$admin_password_hash = hash('sha256', 'secureadminpass'); // Hashed password for 'admin' (CHANGE THIS!)

// Constants
const MAX_POSTS_PER_DAY = 3;
const APP_TITLE = "Student Assist App";
const COLLEGE_NAME = "Dr. D.Y. Patil Institute of Technology, Pimpri";
const DEPARTMENT = "IT Department";

// Error reporting setup
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session to manage student ID and admin login state
session_start();
date_default_timezone_set('Asia/Kolkata');
$today = date("Y-m-d"); // Current date for rate limiting

// Helper function for redirection
function redirect($url) {
    header("Location: $url");
    exit();
}
// 2. DATABASE CONNECTION & INITIALIZATION

$conn = @new mysqli($servername, $username, $password, $dbname);
$db_setup_error = '';

if ($conn->connect_error) {
    $db_setup_error = "Connection failed: " . $conn->connect_error . ". Please check your credentials and ensure the database exists.";
} elseif (!$conn->select_db($dbname)) {
    $db_setup_error = "Database '$dbname' not selected. Please ensure the database is created.";
}

// =================================================================================
// 3. AUTHENTICATION MANAGEMENT
// (Logic remains the same, focusing on UI later)
// =================================================================================

// Student Session Management
$is_student_logged_in = isset($_SESSION['student_anonymous_uid']);
$student_anonymous_uid = $is_student_logged_in ? $_SESSION['student_anonymous_uid'] : null;
$student_public_id = $is_student_logged_in ? $_SESSION['student_public_id'] : null;

// The UID used for DB interactions is the anonymous one
$student_uid = $student_anonymous_uid; 
$login_message = '';
$register_message = '';

// 3.1 Student Registration Logic (Prepared Statements)
if (isset($_POST['student_register']) && $conn && !$db_setup_error) {
    $input_sid = filter_var($_POST['student_id'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $input_pass = $_POST['student_password'] ?? '';

    if (empty($input_sid) || empty($input_pass)) {
        $register_message = "Student ID and Password cannot be empty.";
    } else {
        // Check if student_id already exists (Prepared Statement)
        $stmt_check = $conn->prepare("SELECT student_id FROM students WHERE student_id = ?");
        if ($stmt_check) {
            $stmt_check->bind_param("s", $input_sid);
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                $register_message = "Registration Failed: Student ID already registered.";
            } else {
                // Use PHP's built-in password hashing for security
                $password_hash = password_hash($input_pass, PASSWORD_BCRYPT);
                // Generate a unique, persistent, anonymous ID
                $new_anonymous_uid = hash('sha256', uniqid(mt_rand(), true) . microtime());

                // Insert new student (Prepared Statement)
                $stmt_insert = $conn->prepare("INSERT INTO students (student_id, password_hash, anonymous_uid) VALUES (?, ?, ?)");
                if ($stmt_insert) {
                    $stmt_insert->bind_param("sss", $input_sid, $password_hash, $new_anonymous_uid);
                    if ($stmt_insert->execute()) {
                        $register_message = "Registration successful! Please log in with your credentials.";
                    } else {
                        $register_message = "Registration error: " . $stmt_insert->error;
                    }
                    $stmt_insert->close();
                } else {
                    $register_message = "Database error (Registration): Could not prepare statement.";
                }
            }
            $stmt_check->close();
        }
    }
}

// 3.2 Student Login/Logout Logic (Prepared Statements)
if (isset($_POST['student_login']) && $conn && !$db_setup_error) {
    $input_sid = filter_var($_POST['student_id'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $input_pass = $_POST['student_password'] ?? '';

    // Retrieve user data (Prepared Statement)
    $stmt = $conn->prepare("SELECT password_hash, anonymous_uid FROM students WHERE student_id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $input_sid);
        $stmt->execute();
        $stmt->bind_result($db_pass_hash, $db_anon_uid);
        
        if ($stmt->fetch()) {
            // Verify password securely
            if (password_verify($input_pass, $db_pass_hash)) {
                // Login success! Set session with the anonymous UID
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
    } else {
         $login_message = "Database error (Login): Could not prepare statement.";
    }
}
   
if (isset($_POST['student_logout'])) {
    unset($_SESSION['student_anonymous_uid']);
    unset($_SESSION['student_public_id']);
    redirect('index.php');
}

// 3.3 Check block status (Uses the anonymous UID)
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

// 3.4 Admin Login/Logout
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


// =================================================================================
// 4. POST COMPLAINT LOGIC (Prepared Statements)
// (Logic remains the same, focusing on UI later)
// =================================================================================

$post_message = "";

// Show message from session if redirected
if (isset($_SESSION['post_message'])) {
    $post_message = $_SESSION['post_message'];
    unset($_SESSION['post_message']);
}

if (isset($_POST['post_complaint']) && $is_student_logged_in && !$is_user_blocked && $conn && !$db_setup_error) {
    $category = filter_var($_POST['category'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $complaint_text = filter_var($_POST['complaint_text'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $current_anon_uid = $student_anonymous_uid; // Use the secured UID

    if (empty($complaint_text) || empty($category)) {
        $_SESSION['post_message'] = "Category and Complaint text cannot be empty.";
    } else {
        // 4.1 Check post count (Rate Limiting)
        $stmt = $conn->prepare("SELECT COUNT(*) FROM complaints WHERE user_id = ? AND created_at = ?");
        if ($stmt) {
            $stmt->bind_param("ss", $current_anon_uid, $today);
            $stmt->execute();
            $stmt->bind_result($post_count);
            $stmt->fetch();
            $stmt->close();

            if ($post_count >= MAX_POSTS_PER_DAY) {
                $_SESSION['post_message'] = "Limit Reached: You have already posted " . MAX_POSTS_PER_DAY . " complaints today. Please try again tomorrow.";
            } else {
                // 4.2 Insert new complaint
                $status = 'Pending';
                $stmt = $conn->prepare("INSERT INTO complaints (user_id, category, complaint_text, status, created_at) VALUES (?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("sssss", $current_anon_uid, $category, $complaint_text, $status, $today);
                    if ($stmt->execute()) {
                        $_SESSION['post_message'] = "Complaint posted successfully! (Remaining: " . (MAX_POSTS_PER_DAY - $post_count - 1) . " today)";
                    } else {
                        $_SESSION['post_message'] = "Error posting complaint: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                     $_SESSION['post_message'] = "Database error: Could not prepare statement.";
                }
            }
        }
    }
    redirect('index.php'); // PRG pattern: redirect after POST
} elseif (isset($_POST['post_complaint']) && !$is_student_logged_in) {
     $_SESSION['post_message'] = "Error: You must be logged in as a student to post a complaint.";
     redirect('index.php');
}


// =================================================================================
// 5. ADMIN ACTION LOGIC (Update Status / Block) (Prepared Statements)
// (Logic remains the same, focusing on UI later)
// =================================================================================

if ($is_admin && isset($_POST['action_type']) && $conn && !$db_setup_error) {
    $action_id = filter_var($_POST['action_id'] ?? 0, FILTER_VALIDATE_INT);
    $action_type = filter_var($_POST['action_type'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $target_uid = filter_var($_POST['target_uid'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);

    if ($action_type === 'solve' || $action_type === 'pending') {
        if ($action_id > 0) {
            // 5.1 Update Status
            $new_status = ($action_type === 'solve' ? 'Solved' : 'Pending');
            $stmt = $conn->prepare("UPDATE complaints SET status = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $new_status, $action_id);
                $stmt->execute();
                $stmt->close();
            }
            redirect('index.php'); // Refresh to show changes
        }
    }
    
    // 5.2 Blocking logic (Independent of action_id, based on target_uid)
    if ($action_type === 'block' && !empty($target_uid)) {
        // Block the user ID in the complaints table
        $stmt_complaints = $conn->prepare("UPDATE complaints SET is_blocked = TRUE WHERE user_id = ?");
        if ($stmt_complaints) {
            $stmt_complaints->bind_param("s", $target_uid);
            $stmt_complaints->execute();
            $stmt_complaints->close();
        }
        redirect('index.php'); // Refresh to show changes
    }
}

// =================================================================================
// 6. FETCH COMPLAINTS
// (Logic remains the same, focusing on UI later)
// =================================================================================

$complaints = [];
if ($conn && !$db_setup_error) {
    // Fetch all complaints, order by status (Pending first) and then by latest
    $sql = "SELECT id, user_id, category, complaint_text, status, created_at, is_blocked FROM complaints 
            ORDER BY FIELD(status, 'Pending', 'Solved'), is_blocked DESC, created_at DESC";
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $complaints[] = $row;
        }
    }
}

// =================================================================================
// 7. HTML STRUCTURE AND STYLING (The Overhaul)
// =================================================================================
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
        /* Base Styling & Variables */
        :root {
            --primary-blue: #3b82f6; /* Tailwind Blue-500 */
            --primary-dark: #1e40af; /* Tailwind Blue-800 */
            --secondary-bg: #f4f6f9; /* Light Gray/Off-white for main background */
            --card-bg: #ffffff;
            --text-color: #1f2937;
            --pending-color: #f59e0b; /* Amber */
            --solved-color: #10b981; /* Emerald */
            --blocked-color: #ef4444; /* Red */
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.06);
            --radius: 0.5rem;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--secondary-bg);
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
        }

        /* --- Global Layout --- */
        .app-layout {
            display: flex;
            min-height: 100vh;
        }
        
        .main-content-wrapper {
            flex-grow: 1;
            padding: 2rem 1rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* --- Header (Student/Public View) --- */
        header.student-header {
            background-color: var(--card-bg);
            border-bottom: 3px solid var(--primary-blue);
            box-shadow: var(--shadow);
            padding: 1rem 0;
        }

        header.student-header .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo-text {
            color: var(--primary-dark);
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logo-meta {
            font-size: 0.75rem;
            color: #6b7280;
            font-weight: 500;
            margin-top: 0.2rem;
        }
        
        /* --- Sidebar (Admin View) --- */
        .admin-sidebar {
            width: 280px;
            background-color: var(--primary-dark);
            color: white;
            padding: 1.5rem;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }
        
        .admin-sidebar h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 2rem;
            color: white;
            border-bottom: 2px solid var(--primary-blue);
            padding-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .admin-sidebar .info-box {
            background-color: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            font-size: 0.9rem;
            border-left: 4px solid var(--primary-blue);
        }

        /* --- Admin Main Content --- */
        .admin-main-content {
            flex-grow: 1;
            padding: 2rem;
            background-color: var(--secondary-bg);
        }
        
        /* --- Card and Form Styling --- */
        .card {
            background-color: var(--card-bg);
            padding: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease-in-out;
        }
        
        .card:hover {
             box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
        }

        h2 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--primary-dark);
            border-left: 4px solid var(--primary-blue);
            padding-left: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #374151; /* Slate-700 */
            font-size: 0.9rem;
        }

        input[type="text"],
        input[type="password"],
        select,
        textarea {
            width: 100%;
            padding: 0.75rem;
            margin-bottom: 1rem;
            border: 1px solid #d1d5db; /* Gray-300 */
            border-radius: var(--radius);
            font-size: 1rem;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: var(--primary-blue);
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3);
        }

        textarea {
            resize: vertical;
            min-height: 120px;
        }

        /* --- Buttons --- */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.3s, transform 0.2s, box-shadow 0.3s;
            text-decoration: none;
            text-align: center;
            gap: 0.5rem;
        }
        
        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        .btn-primary {
            background-color: var(--primary-blue);
            color: var(--card-bg);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            box-shadow: var(--shadow);
            transform: translateY(-1px);
        }
        
        .btn-logout {
            background-color: var(--blocked-color);
            color: var(--card-bg);
        }
        .btn-logout:hover {
            background-color: #b91c1c;
            transform: translateY(-1px);
        }
        
        .btn-action {
            padding: 0.4rem 0.8rem;
            font-size: 0.875rem;
            margin-left: 0.5rem;
            border-radius: 0.4rem;
        }
        
        .btn-solve { background-color: var(--solved-color); }
        .btn-pending { background-color: var(--pending-color); }
        .btn-block { background-color: var(--blocked-color); }

        .btn-solve, .btn-pending, .btn-block { color: var(--card-bg); }

        .btn-solve:hover { background-color: #0d9488; }
        .btn-pending:hover { background-color: #d97706; }
        .btn-block:hover { background-color: #dc2626; }


        /* --- Alerts --- */
        .alert {
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        
        .alert-info {
            background-color: #e0f2fe;
            color: var(--primary-dark);
            border-left: 4px solid var(--primary-blue);
        }

        /* --- Complaint List Grid --- */
        .complaint-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        .complaint-item {
            background-color: var(--card-bg);
            border: 1px solid #e5e7eb;
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }
        
        .complaint-item h3 {
            font-size: 1.15rem;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            text-transform: capitalize;
            font-weight: 700;
        }

        /* --- Status Badges --- */
        .header-meta {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
            border-bottom: 1px dashed #e5e7eb;
            padding-bottom: 0.5rem;
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 9999px;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.7rem;
            line-height: 1;
            margin-top: 0.2rem;
            white-space: nowrap;
        }

        .status-pending { background-color: #fef3c7; color: var(--pending-color); }
        .status-solved { background-color: #d1fae5; color: var(--solved-color); }
        .status-blocked { background-color: #fee2e2; color: var(--blocked-color); }
        
        .complaint-text-container {
            margin-bottom: 1.25rem;
            color: #4b5563;
        }

        .footer-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            color: #6b7280;
            padding-top: 1rem;
            border-top: 1px solid #f3f4f6;
            margin-top: 0.5rem;
        }
        
        .admin-controls {
            padding-top: 1rem;
            margin-top: 1rem;
            border-top: 1px solid #e5e7eb;
        }
        
        .admin-controls form {
            display: inline-block;
        }
        
        .admin-id-label {
            font-size: 0.7rem; 
            color: #9ca3af; 
            margin-bottom: 0.75rem;
            font-style: italic;
            width: 100%;
            display: block;
            word-break: break-all;
        }
        
        .admin-action-group {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        /* --- Modals (Keep Existing Styles for Functionality) --- */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.6);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-content {
            background-color: #fefefe;
            padding: 2rem;
            border-radius: var(--radius);
            width: 90%;
            max-width: 450px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            text-align: left;
        }

        .modal-content h3 {
            color: var(--primary-dark);
            font-size: 1.5rem;
            margin-bottom: 1rem;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .modal-buttons {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .modal-buttons .btn {
            flex-grow: 1;
        }
        
        .btn-cancel { 
            background-color: #6b7280; 
            color: white;
        }
        .btn-cancel:hover { 
            background-color: #4b5563; 
        }

        /* --- Student Info --- */
        .student-info {
            background-color: #dbeafe; /* Blue-100 */
            color: var(--primary-dark);
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid var(--primary-blue);
        }
        
        /* --- Responsive Adjustments --- */
        @media (max-width: 768px) {
            .app-layout {
                flex-direction: column;
            }
            .admin-sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            .admin-main-content {
                padding: 1rem;
            }
            header.student-header .container {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            .auth-controls {
                width: 100%;
                justify-content: center;
            }
            .logo-meta {
                margin-bottom: 0.5rem;
            }
        }
    </style>
</head>
<body>

<?php if ($is_admin): ?>
<div class="app-layout">
    
    <div class="admin-sidebar">
        <h2><i class="fas fa-user-shield"></i> Admin Panel</h2>
        
        <div class="info-box">
            <p style="font-weight: 600; color: white; margin-bottom: 0.5rem;">College System Status</p>
            <p style="color: #bfdbfe;">Total Complaints: <?php echo count($complaints); ?></p>
            <p style="color: #bfdbfe;">Pending: <?php echo count(array_filter($complaints, fn($c) => $c['status'] === 'Pending' && !$c['is_blocked'])); ?></p>
        </div>

        <form method="POST">
            <button type="submit" name="admin_logout" class="btn btn-logout" style="width: 100%;">
                <i class="fas fa-sign-out-alt"></i> Log Out Admin
            </button>
        </form>
    
    <br>
    
        <div style="text-align:left; margin-bottom:20px;">
    <a href="weekly_report.php" 
       style="
            background:#1976D2; 
            color:white; 
            padding:12px 22px; 
            border-radius:6px; 
            text-decoration:none; 
            font-weight:600;
            box-shadow:0 3px 8px rgba(0,0,0,0.2);
            transition:0.3s;
        "
        onmouseover="this.style.background='#0D47A1'"
        onmouseout="this.style.background='#1976D2'"
    >
        📊 Weekly Report !!
    </a>
</div>

        

    


        <div style="margin-top: auto; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.1);">
             <p style="font-size: 0.75rem; color: #bfdbfe;"><?php echo APP_TITLE; ?></p>
             <p style="font-size: 0.65rem; color: #93c5fd;"><?php echo COLLEGE_NAME; ?></p>
        </div>
    </div>
    
    <div class="admin-main-content">
        
        <h1 style="font-size: 2.25rem; font-weight: 800; color: var(--primary-dark); margin-bottom: 1.5rem;">
            Complaints Management Dashboard
        </h1>

        <?php if (!empty($db_setup_error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-database"></i> DATABASE ERROR: <?php echo $db_setup_error; ?>
                <p style="margin-top: 0.5rem; font-weight: normal;">Please check PHP configurations and ensure the `complaints` and `students` tables are created.</p>
            </div>
        <?php endif; ?>
        
        <div class="alert alert-info" style="margin-bottom: 2rem;">
             <i class="fas fa-info-circle"></i> Logged in as Administrator. Use the controls below to manage status and block spammers.
        </div>

        <div class="card" style="padding: 1.5rem;">
            <h2><i class="fas fa-clipboard-list"></i> All Reported Complaints</h2>
            
            <?php if (empty($complaints)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-leaf"></i> The log is clean! No complaints posted yet.
                </div>
            <?php else: ?>
                <div class="complaint-grid">
                    <?php foreach ($complaints as $c): ?>
                        <div class="complaint-item">
                            <?php
                                $status_text = htmlspecialchars($c['status']);
                                $status_class = 'status-pending';
                                $icon = '<i class="fas fa-hourglass-half"></i>';

                                if ($c['status'] === 'Solved') {
                                    $status_class = 'status-solved';
                                    $icon = '<i class="fas fa-check-circle"></i>';
                                }

                                if ($c['is_blocked']) {
                                    $status_class = 'status-blocked';
                                    $status_text = 'BLOCKED';
                                    $icon = '<i class="fas fa-ban"></i>';
                                }
                            ?>
                            <div class="header-meta">
                                <h3><?php echo htmlspecialchars($c['category']); ?></h3>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo $icon . ' ' . $status_text; ?>
                                </span>
                            </div>
                            
                            <div class="complaint-text-container">
                                <p><?php echo nl2br(htmlspecialchars($c['complaint_text'])); ?></p>
                            </div>

                            <div class="footer-meta">
                                <span><i class="fas fa-calendar-alt"></i> Date: <?php echo htmlspecialchars($c['created_at']); ?></span>
                            </div>

                            <div class="admin-controls">
                                <span class="admin-id-label">
                                    Anon UID: <?php echo substr(htmlspecialchars($c['user_id']), 0, 12) . '...'; ?>
                                </span>
                                
                                <?php if (!$c['is_blocked']): ?>
                                    <div class="admin-action-group">
                                        <?php if ($c['status'] === 'Pending'): ?>
                                            <form method="POST">
                                                <input type="hidden" name="action_id" value="<?php echo $c['id']; ?>">
                                                <input type="hidden" name="action_type" value="solve">
                                                <button type="submit" class="btn btn-action btn-solve"><i class="fas fa-check"></i> Solved</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST">
                                                <input type="hidden" name="action_id" value="<?php echo $c['id']; ?>">
                                                <input type="hidden" name="action_type" value="pending">
                                                <button type="submit" class="btn btn-action btn-pending"><i class="fas fa-redo-alt"></i> Pending</button>
                                            </form>
                                        <?php endif; ?>

                                        <button 
                                            type="button"
                                            class="btn btn-action btn-block" 
                                            onclick="openBlockModal('<?php echo htmlspecialchars($c['user_id']); ?>')">
                                            <i class="fas fa-user-slash"></i> Block
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span class="status-badge status-blocked" style="width: 100%; text-align: center; padding: 0.5rem; margin-top: 0.5rem;"><i class="fas fa-ban"></i> USER PERMANENTLY BLOCKED</span>
                                <?php endif; ?>

                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
</div>

<?php else: ?>
<header class="student-header">
        <div class="container">
            <div>
                <span class="logo-text"><i class="fas fa-headset" style="color: var(--primary-blue);"></i> <?php echo APP_TITLE; ?></span>
                <p class="logo-meta"><?php echo COLLEGE_NAME . " | " . DEPARTMENT; ?></p>
            </div>
            
            <div class="auth-controls">
                <?php if (!$is_admin): ?>
                    <button type="button" class="btn btn-small" onclick="openModal('adminLoginModal')" style="background-color: var(--primary-dark); color: white;"><i class="fas fa-user-shield"></i> Admin Login</button>
                <?php endif; ?>
                
                <?php if ($is_student_logged_in): ?>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="student_logout" class="btn btn-small btn-logout"><i class="fas fa-sign-out-alt"></i> Student Logout</button>
                    </form>
                <?php else: ?>
                    <button type="button" class="btn btn-small btn-primary" onclick="openModal('loginModal')"><i class="fas fa-sign-in-alt"></i> Student Login</button>
                    <button type="button" class="btn btn-small" onclick="openModal('registerModal')" style="background-color: #f3f4f6; color: var(--primary-dark);"><i class="fas fa-user-plus"></i> Register</button>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <div class="main-content-wrapper">
        <div class="container">
        
        <?php if (!empty($db_setup_error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-database"></i> DATABASE ERROR: <?php echo $db_setup_error; ?>
                <p style="margin-top: 0.5rem; font-weight: normal;">Please check PHP configurations and ensure the `complaints` and `students` tables are created.</p>
            </div>
        <?php endif; ?>
        
        <?php if ($is_student_logged_in): ?>
            <div class="student-info">
                <span><i class="fas fa-user-circle"></i> Logged in as: <?php echo htmlspecialchars($student_public_id); ?></span>
                <span style="color: #6b7280; font-style: italic;"><i class="fas fa-mask"></i> Posting Anonymously</span>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2><i class="fas fa-bullhorn"></i> Submit Complaint/Feedback</h2>
            
            <?php if (!empty($post_message)): ?>
                <div class="alert <?php echo strpos($post_message, 'successful') !== false ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo strpos($post_message, 'successful') !== false ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-exclamation-triangle"></i>'; ?>
                    <p style="margin: 0;"><?php echo $post_message; ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (!$is_student_logged_in): ?>
                <div class="alert alert-info">
                    <i class="fas fa-sign-in-alt"></i> Login Required: Please log in or register your Student ID to submit a complaint.
                </div>
                <div class="modal-buttons" style="margin-top: 0; margin-bottom: 0;">
                    <button type="button" class="btn btn-primary" onclick="openModal('loginModal')"><i class="fas fa-sign-in-alt"></i> Student Login</button>
                    <button type="button" class="btn btn-cancel" onclick="openModal('registerModal')"><i class="fas fa-user-plus"></i> Register Student</button>
                </div>
            <?php elseif ($is_user_blocked): ?>
                <div class="alert alert-error">
                    <i class="fas fa-ban"></i> Account Blocked: You are blocked from posting new complaints due to administrative action.
                </div>
            <?php else: ?>
                <form method="POST">
                    <label for="category"><i class="fas fa-tag"></i> Select Category</label>
                    <select id="category" name="category" required>
                        <option value="" disabled selected>--- Select an area of concern ---</option>
                        <option value="Faculty">Faculty & Teaching</option>
                        <option value="Department">Department Administration</option>
                        <option value="Library">Library Services</option>
                        <option value="Lab">Lab Equipment/Environment</option>
                        <option value="Infrastructure">Infrastructure/Campus</option>
                        <option value="Other">Other</option>
                    </select>

                    <label for="complaint_text"><i class="fas fa-comment-dots"></i> Your Complaint/Feedback</label>
                    <textarea id="complaint_text" name="complaint_text" maxlength="500" required placeholder="Describe your issue concisely (Max 500 characters)"></textarea>

                    <button type="submit" name="post_complaint" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-paper-plane"></i> Submit Anonymously
                    </button>
                    <p style="font-size: 0.8rem; color: #6b7280; margin-top: 0.75rem; text-align: center;">
                        Your Student ID is protected. You can post a maximum of <?php echo MAX_POSTS_PER_DAY; ?> times per day.
                    </p>
                </form>
            <?php endif; ?>
        </div>

        <div class="card">
    <h2><i class="fas fa-list-ul"></i> Public Complaint Log (Latest First)</h2>
    <p style="margin-bottom: 2rem; color: #6b7280;">View all reported issues and their current status to promote transparency and reduce redundant reports.</p>
    
    <?php if (empty($complaints)): ?>
        <div class="alert alert-info">
            <i class="fas fa-leaf"></i> The log is clean! No complaints posted yet.
        </div>
    <?php else: ?>
        <!-- MODIFICATION: Renamed class to 'complaint-grid-3' to enable 3-column horizontal layout -->
        <div class="complaint-grid-3">
            <?php foreach ($complaints as $c): ?>
                <div class="complaint-item">
                    <?php
                        $status_text = htmlspecialchars($c['status']);
                        $status_class = 'status-pending';
                        $icon = '<i class="fas fa-hourglass-half"></i>';

                        if ($c['status'] === 'Solved') {
                            $status_class = 'status-solved';
                            $icon = '<i class="fas fa-check-circle"></i>';
                        }

                        if ($c['is_blocked']) {
                            $status_class = 'status-blocked';
                            $status_text = 'BLOCKED';
                            $icon = '<i class="fas fa-ban"></i>';
                        }
                        
                        
                        if ($c['is_blocked']) {
                            continue; 
                        }
                    ?>
                    
                    <div class="header-meta">
                        <h3><?php echo htmlspecialchars($c['category']); ?></h3>
                        <!-- Note: The badge class used here relies on your original global styles (e.g., status-pending) -->
                        <span class="status-badge <?= $status_class; ?>">
                            <?php echo $icon . ' ' . $status_text; ?>
                        </span>
                    </div>
                    
                    <div class="complaint-text-container">
                        <p><?php echo nl2br(htmlspecialchars($c['complaint_text'])); ?></p>
                    </div>

                    <div class="footer-meta">
                        <span><i class="fas fa-calendar-alt"></i> Date: <?php echo htmlspecialchars($c['created_at']); ?></span>
                        <?php if ($is_student_logged_in && $c['user_id'] === $student_anonymous_uid): ?>
                            <span style="color: var(--primary-blue); font-weight: 700;"><i class="fas fa-star"></i> Your Post</span>
                        <?php endif; ?>
                    </div>
                    
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
    </div>
</div>

<?php endif; ?>


<div id="adminLoginModal" class="modal">
    <div class="modal-content">
        <h3><i class="fas fa-lock"></i> Administrator Login</h3>
        <?php if (isset($_POST['admin_login']) && !$is_admin && !empty($admin_error)): ?>
            <div class="alert alert-error" style="margin-top: 1rem;"><i class="fas fa-times-circle"></i> <?php echo $admin_error; ?></div>
        <?php endif; ?>
        <form method="POST">
            <label for="admin_password">Admin Password</label>
            <input type="password" id="admin_password" name="admin_password" required placeholder="Enter Admin Password">
            <div class="modal-buttons">
                <button type="button" class="btn btn-cancel" onclick="closeModal('adminLoginModal')"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" name="admin_login" class="btn btn-primary"><i class="fas fa-sign-in-alt"></i> Log In</button>
            </div>
        </form>
    </div>
</div>

<div id="loginModal" class="modal">
    <div class="modal-content">
        <h3><i class="fas fa-sign-in-alt"></i> Student Login</h3>
        <?php if (!empty($login_message)): ?>
            <div class="alert <?php echo strpos($login_message, 'Invalid') !== false ? 'alert-error' : 'alert-success'; ?>">
                <?php echo strpos($login_message, 'Invalid') !== false ? '<i class="fas fa-times-circle"></i>' : '<i class="fas fa-check-circle"></i>'; ?>
                <p style="margin: 0;"><?php echo $login_message; ?></p>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <label for="login_student_id">Student ID (e.g., Roll No.)</label>
            <input type="text" id="login_student_id" name="student_id" required placeholder="Enter Student ID">

            <label for="login_password">Password</label>
            <input type="password" id="login_password" name="student_password" required placeholder="Enter Password">

            <div class="modal-buttons" style="margin-top: 0;">
                <button type="button" class="btn btn-cancel" onclick="closeModal('loginModal')"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" name="student_login" class="btn btn-primary"><i class="fas fa-check"></i> Log In</button>
            </div>
        </form>
        <p style="text-align: center; margin-top: 1rem; font-size: 0.9rem;">
            New student? <a href="#" onclick="closeModal('loginModal'); openModal('registerModal'); return false;" style="color: var(--primary-blue); text-decoration: underline; font-weight: 600;">Register here.</a>
        </p>
    </div>
</div>

<div id="registerModal" class="modal">
    <div class="modal-content">
        <h3><i class="fas fa-user-plus"></i> Student Registration</h3>
        <p>Register using your official Student ID. Your ID will not be visible in the public complaint log.</p>
        
        <?php if (!empty($register_message)): ?>
            <div class="alert <?php echo strpos($register_message, 'successful') !== false ? 'alert-success' : 'alert-error'; ?>">
                <?php echo strpos($register_message, 'successful') !== false ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>'; ?>
                <p style="margin: 0;"><?php echo $register_message; ?></p>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <label for="register_student_id">Student ID (e.g., Roll No.)</label>
            <input type="text" id="register_student_id" name="student_id" required placeholder="Enter Student ID">

            <label for="register_password">Create Password</label>
            <input type="password" id="register_password" name="student_password" required placeholder="Create a secure password">

            <div class="modal-buttons" style="margin-top: 0;">
                <button type="button" class="btn btn-cancel" onclick="closeModal('registerModal')"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" name="student_register" class="btn btn-primary"><i class="fas fa-user-plus"></i> Register</button>
            </div>
        </form>
        <p style="text-align: center; margin-top: 1rem; font-size: 0.9rem;">
            Already registered? <a href="#" onclick="closeModal('registerModal'); openModal('loginModal'); return false;" style="color: var(--primary-blue); text-decoration: underline; font-weight: 600;">Log in here.</a>
        </p>
    </div>
</div>

<div id="blockModal" class="modal">
    <div class="modal-content" style="text-align: center;">
        <h3><i class="fas fa-exclamation-triangle" style="color: var(--blocked-color);"></i> Confirm User Block</h3>
        <p>Are you absolutely sure you want to BLOCK this anonymous user ID? This action is typically reserved for continuous spammers.</p>
        <p style="font-weight: 600; margin-top: 10px;">All posts from this user will be marked as BLOCKED.</p>
        
        <div class="modal-buttons">
            <button id="cancelBlock" class="btn btn-cancel" onclick="closeModal('blockModal')"><i class="fas fa-times"></i> Cancel</button>
            <form id="blockForm" method="POST" style="flex-grow: 1;">
                <input type="hidden" name="target_uid" id="modalTargetUid">
                <input type="hidden" name="action_type" value="block">
                <input type="hidden" name="action_id" value="1"> 
                <button type="submit" class="btn btn-block"><i class="fas fa-user-slash"></i> Confirm Block</button>
            </form>
        </div>
    </div>
</div>

<script>
    // ===============================================
    // JAVASCRIPT FOR MODAL & INTERACTION
    // ===============================================
    
    // --- General Modal Functions ---
    function openModal(modalId) {
        // Ensure only one of the login/register modals is open if a specific message is set after a POST
        const loginModal = document.getElementById('loginModal');
        const registerModal = document.getElementById('registerModal');
        const adminLoginModal = document.getElementById('adminLoginModal');
        
        if (loginModal && loginModal.style.display === 'flex') loginModal.style.display = 'none';
        if (registerModal && registerModal.style.display === 'flex') registerModal.style.display = 'none';
        if (adminLoginModal && adminLoginModal.style.display === 'flex') adminLoginModal.style.display = 'none';

        document.getElementById(modalId).style.display = 'flex';
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    
    // --- Specific Block Modal Function ---
    const modalTargetUidInput = document.getElementById('modalTargetUid');

    function openBlockModal(userId) {
        if (modalTargetUidInput) {
            modalTargetUidInput.value = userId;
        }
        openModal('blockModal');
    }

    // Close when clicking outside the modal content (for all modals)
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    }
    
    // --- Character Counter (Existing) ---
    document.addEventListener('DOMContentLoaded', () => {
        const textarea = document.getElementById('complaint_text');
        if (textarea) {
            const maxChars = textarea.maxLength;
            const charIndicator = document.createElement('p');
            charIndicator.style.fontSize = '0.8rem';
            charIndicator.style.color = '#6b7280';
            charIndicator.style.textAlign = 'right';
            charIndicator.style.marginTop = '-0.5rem';
            charIndicator.style.marginBottom = '1rem';
            
            function updateCharCount() {
                const remaining = maxChars - textarea.value.length;
                charIndicator.textContent = `Characters remaining: ${remaining}`;
            }

            textarea.parentNode.insertBefore(charIndicator, textarea.nextSibling);
            textarea.addEventListener('input', updateCharCount);
            updateCharCount(); // Initial call
        }
        
        // --- Auto-open Modals on Error/Success (to show messages) ---
        <?php if (!empty($login_message) && strpos($login_message, 'Invalid') !== false): ?>
            openModal('loginModal');
        <?php elseif (isset($_POST['student_register']) && !empty($register_message)): ?>
            openModal('registerModal');
        <?php elseif (isset($_POST['admin_login']) && !empty($admin_error)): ?>
            openModal('adminLoginModal');
        <?php endif; ?>
    });
</script>

<!-- ================= Enhanced Footer Section ================= -->
<footer style="
    margin-top:40px;
    background:#0d47a1;
    color:white;
    padding:20px 10px;
    text-align:center;
    font-size:14px;
    line-height:22px;">
    
    <p style="margin:0; font-weight:500;">
        📍 Address: Dr. D. Y. Patil Institute of Technology, Pimpri, Pune <br>
        Sant Tukaram Nagar, Pimpri, Pune - 411018
    </p>

    <p style="margin:10px 0 0 0;">
        🔒 <a href="privacy_policy.php" style="color:#ffe082; text-decoration:none;">Privacy Policy</a> |
        📘 <a href="terms_condictions.php" style="color:#ffe082; text-decoration:none;">Terms & Conditions</a>
    </p>

    <p style="margin:10px 0 0 0; font-size:13px;">
        © 2025 Student Assist App — All Rights Reserved.
    </p>
</footer>
<!-- ============================================================ -->


</body>
</html>