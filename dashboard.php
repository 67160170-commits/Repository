<?php
// 1. **Login Protection**
// ใช้ config_mysqli.php ซึ่งคาดว่าจะสร้าง object $mysqli สำหรับเชื่อมต่อฐานข้อมูล
require __DIR__ . '/config_mysqli.php'; 
// ตรวจสอบสถานะการเข้าสู่ระบบ
if (empty($_SESSION['user_id'])) {
  header('Location: login.php'); exit; // Redirect ไปหน้า Login ถ้ายังไม่ได้เข้าสู่ระบบ
}

// 2. **Function ดึงข้อมูลจากฐานข้อมูล**
// ใช้ $mysqli object ที่มาจาก config_mysqli.php
function fetch_all($mysqli, $sql) {
  // ตรวจสอบว่า $mysqli ถูกสร้างขึ้นหรือไม่
  if (!$mysqli) { return []; } 
  
  // ตั้งค่าภาษา (สำคัญสำหรับภาษาไทย)
  if ($mysqli->character_set_name() !== 'utf8mb4') {
    $mysqli->set_charset('utf8mb4');
  }

  $res = $mysqli->query($sql);
  if (!$res) {
    // ในกรณีที่ View ยังไม่ถูกสร้าง หรือ Query ผิดพลาด
    // echo "Query failed: " . $mysqli->error . "<br>"; 
    return []; 
  }
  $rows = [];
  while ($row = $res->fetch_assoc()) { $rows[] = $row; }
  $res->free();
  return $rows;
}

// Function สำหรับเตรียมข้อมูลให้ Chart.js
function toXY($data, $xKey, $yKey) {
  $labels = [];
  $values = [];
  foreach ($data as $o) {
    $labels[] = $o[$xKey];
    $values[] = (float) $o[$yKey];
  }
  return ['labels' => $labels, 'values' => $values];
}

// 3. **เตรียมข้อมูลสำหรับกราฟต่าง ๆ**
// ดึงข้อมูลจาก Views ทั้งหมดที่สร้างใน retail_dw.sql
$monthly = fetch_all($mysqli, "SELECT ym, net_sales FROM v_monthly_sales");
$category = fetch_all($mysqli, "SELECT category, net_sales FROM v_sales_by_category");
$region = fetch_all($mysqli, "SELECT region, net_sales FROM v_sales_by_region");
$topProducts = fetch_all($mysqli, "SELECT product_name, qty_sold, net_sales FROM v_top_products");
$payment = fetch_all($mysqli, "SELECT payment_method, net_sales FROM v_payment_share");
$hourly = fetch_all($mysqli, "SELECT hour_of_day, net_sales FROM v_hourly_sales");
$newReturning = fetch_all($mysqli, "SELECT date_key, new_customer_sales, returning_sales FROM v_new_vs_returning ORDER BY date_key");

// ดึง Quick Metrics (ยอดขายล่าสุด)
$dailySales = fetch_all($mysqli, "SELECT date_key, net_sales, qty FROM v_daily_sales ORDER BY date_key DESC LIMIT 1");
$latest_sales = $dailySales[0] ?? ['date_key' => 'N/A', 'net_sales' => 0, 'qty' => 0];

// ปิด Connection
$mysqli->close();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard ยอดขาย</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
</head>
<body class="bg-light">
  <nav class="navbar navbar-light bg-white border-bottom mb-4">
    <div class="container">
      <span class="navbar-brand">Retail Sales Dashboard (s67160170)</span>
      <div class="d-flex align-items-center gap-3">
        <span class="text-muted small">สวัสดี, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Guest'); ?></span>
        <a class="btn btn-outline-danger btn-sm" href="logout.php">Logout</a>
      </div>
    </div>
  </nav>

  <div class="container">
    <h1 class="h3 mb-4">ภาพรวมยอดขาย (Data Warehouse)</h1>

    <div class="row mb-4">
      <div class="col-md-4">
        <div class="card p-3 shadow-sm border-0">
          <p class="text-muted small mb-1">ยอดขายสุทธิล่าสุด (<?php echo $latest_sales['date_key']; ?>)</p>
          <h2 class="h4 text-success"><?php echo number_format($latest_sales['net_sales'], 2); ?> บาท</h2>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card p-3 shadow-sm border-0">
          <p class="text-muted small mb-1">ปริมาณสินค้าขายได้ล่าสุด (<?php echo $latest_sales['date_key']; ?>)</p>
          <h2 class="h4"><?php echo number_format($latest_sales['qty']); ?> ชิ้น</h2>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card p-3 shadow-sm border-0">
          <p class="text-muted small mb-1">ข้อมูลล่าสุดถึง</p>
          <h2 class="h4"><?php echo $latest_sales['date_key']; ?></h2>
        </div>
      </div>
    </div>
    
    <div class="row mb-4">
      <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
          <div class="card-body">
            <h5 class="card-title">ยอดขายรวมรายเดือน</h5>
            <canvas id="chartMonthly"></canvas>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
          <div class="card-body">
            <h5 class="card-title">สัดส่วนยอดขายตามหมวดหมู่</h5>
            <canvas id="chartCategory"></canvas>
          </div>
        </div>
      </div>
    </div>
    
    <div class="row mb-4">
      <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
          <div class="card-body">
            <h5 class="card-title">ยอดขายลูกค้าใหม่ vs. ลูกค้าเก่า (รายวัน)</h5>
            <canvas id="chartNewReturning"></canvas>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
          <div class="card-body">
            <h5 class="card-title">ยอดขายเฉลี่ยตามช่วงเวลา (ชั่วโมง)</h5>
            <canvas id="chartHourly"></canvas>
          </div>
        </div>
      </div>
    </div>

    <div class="row mb-4">
      <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
          <div class="card-body">
            <h5 class="card-title">Top 10 สินค้าขายดี (ตามยอดขาย)</h5>
            <table class="table table-striped table-sm">
              <thead>
                <tr>
                  <th>#</th>
                  <th>สินค้า</th>
                  <th>ปริมาณ (ชิ้น)</th>
                  <th>ยอดขาย (บาท)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($topProducts as $index => $item): ?>
                  <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                    <td><?php echo number_format($item['qty_sold']); ?></td>
                    <td><?php echo number_format($item['net_sales'], 2); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
          <div class="card-body">
            <h5 class="card-title">สัดส่วนการชำระเงิน</h5>
            <canvas id="chartPayment"></canvas>
          </div>
        </div>
      </div>
    </div>

  </div>

  <script>
    // ส่งข้อมูล PHP ไปยัง JavaScript
    const monthlyData = <?php echo json_encode($monthly); ?>;
    const categoryData = <?php echo json_encode($category); ?>;
    const paymentData = <?php echo json_encode($payment); ?>;
    const hourlyData = <?php echo json_encode($hourly); ?>;
    const newReturningData = <?php echo json_encode($newReturning); ?>;
    
    // Function สำหรับแปลงข้อมูลตามที่ Chart.js ต้องการ (นำมาจาก PHP)
    const toXY = (data, xKey, yKey) => {
      const labels = [];
      const values = [];
      data.forEach(o => {
        labels.push(o[xKey]);
        values.push(parseFloat(o[yKey]));
      });
      return { labels, values };
    };

    // สีหลักสำหรับกราฟ
    const primaryColor = 'rgb(54, 162, 235)';
    const secondaryColor = 'rgb(255, 99, 132)';
    
    // Monthly Sales Chart (Line)
    (() => {
      const {labels, values} = toXY(monthlyData, 'ym', 'net_sales');
      new Chart(document.getElementById('chartMonthly'), {
        type: 'line',
        data: { 
          labels, 
          datasets: [{ 
            label: 'ยอดขายสุทธิ (฿)', 
            data: values,
            borderColor: primaryColor,
            backgroundColor: 'rgba(54, 162, 235, 0.2)',
            tension: 0.1
          }] 
        },
      });
    })();

    // Category Sales Chart (Pie)
    (() => {
      const {labels, values} = toXY(categoryData, 'category', 'net_sales');
      new Chart(document.getElementById('chartCategory'), {
        type: 'pie',
        data: { 
          labels, 
          datasets: [{ 
            data: values,
            backgroundColor: ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f'],
          }] 
        },
      });
    })();

    // Payment Share Chart (Pie)
    (() => {
      const {labels, values} = toXY(paymentData, 'payment_method', 'net_sales');
      new Chart(document.getElementById('chartPayment'), {
        type: 'doughnut',
        data: { 
          labels, 
          datasets: [{ 
            data: values,
            backgroundColor: ['#76b7b2', '#59a14f', '#edc949', '#af7aa1'],
          }] 
        },
      });
    })();

    // Hourly Sales Chart (Bar)
    (() => {
      const {labels, values} = toXY(hourlyData, 'hour_of_day', 'net_sales');
      new Chart(document.getElementById('chartHourly'), {
        type: 'bar',
        data: { 
          labels, 
          datasets: [{ 
            label: 'ยอดขาย (฿)', 
            data: values,
            backgroundColor: secondaryColor,
          }] 
        },
        options: { scales: { x: { title: { display: true, text: 'ชั่วโมง' } } } }
      });
    })();

    // New vs Returning Chart (Stacked Line)
    (() => {
      const labels = newReturningData.map(o => o.date_key);
      const newC = newReturningData.map(o => parseFloat(o.new_customer_sales));
      const retC = newReturningData.map(o => parseFloat(o.returning_sales));
      new Chart(document.getElementById('chartNewReturning'), {
        type: 'line',
        data: { labels,
          datasets: [
            { label: 'ลูกค้าใหม่ (฿)', data: newC, borderColor: '#f28e2b', tension: 0.3, fill: false },
            { label: 'ลูกค้าเก่า (฿)', data: retC, borderColor: '#4e79a7', tension: 0.3, fill: false }
          ]
        },
      });
    })();
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>