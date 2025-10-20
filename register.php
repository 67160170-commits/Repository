<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once 'config_mysqli.php';


// สร้าง CSRF token ครั้งแรก
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = "";

// ฟังก์ชันเล็ก ๆ กัน XSS เวลา echo ค่าเดิมกลับฟอร์ม
function e($str){ return htmlspecialchars($str ?? "", ENT_QUOTES, "UTF-8"); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ตรวจ CSRF token
  if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $errors[] = "CSRF token ไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองอีกครั้ง";
  }

  // รับค่าจากฟอร์ม
  $username  = trim($_POST['username'] ?? "");
  $password  = $_POST['password'] ?? "";
  $email     = trim($_POST['email'] ?? "");
  $full_name = trim($_POST['name'] ?? "");

  // ตรวจความถูกต้องเบื้องต้น
  if ($username === "" || !preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username)) {
    $errors[] = "กรุณากรอก username 3–30 ตัวอักษร (a-z, A-Z, 0-9, _, .)";
  }
  if (strlen($password) < 8) {
    $errors[] = "รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร";
  }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "อีเมลไม่ถูกต้อง";
  }
  if ($full_name === "" || mb_strlen($full_name) > 100) {
    $errors[] = "กรุณากรอกชื่อจริง (ไม่เกิน 100 ตัวอักษร)";
  }

  // ถ้าไม่มี error
  if (empty($errors)) {
    // ตรวจว่ามี username หรือ email ซ้ำหรือยัง
    $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
      $errors[] = "มี username หรือ email นี้อยู่แล้ว";
    } else {
      // บันทึกข้อมูล
      $hashed_password = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $mysqli->prepare("INSERT INTO users (username, password, email, name, created_at) VALUES (?, ?, ?, ?, NOW())");
      $stmt->bind_param("ssss", $username, $hashed_password, $email, $full_name);
      if ($stmt->execute()) {
        $success = "สมัครสมาชิกสำเร็จ! กำลังพาไปหน้าเข้าสู่ระบบ...";
        header("refresh:2;url=login.php");
      } else {
        $errors[] = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
      }
    }
    $stmt->close();
  }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>สมัครสมาชิก</title>
</head>
<body>
  <h2>สมัครสมาชิก</h2>

  <?php if ($errors): ?>
    <div style="color:red;">
      <ul><?php foreach($errors as $e) echo "<li>".e($e)."</li>"; ?></ul>
    </div>
  <?php elseif ($success): ?>
    <div style="color:green;"><?= e($success) ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
    <label>ชื่อผู้ใช้:</label><br>
    <input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" required><br>

    <label>รหัสผ่าน:</label><br>
    <input type="password" name="password" required><br>

    <label>อีเมล:</label><br>
    <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required><br>

    <label>ชื่อ-นามสกุล:</label><br>
    <input type="text" name="name" value="<?= e($_POST['name'] ?? '') ?>" required><br><br>

    <button type="submit">สมัครสมาชิก</button>
  </form>
</body>
</html>
