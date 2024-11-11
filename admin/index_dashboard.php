<?php 
require_once '../config/condb.php';

// จำนวนหมวดหมู่สินค้า
$stmtCountCounter = $condb->prepare("SELECT COUNT(*) as totalView FROM tbl_type");
$stmtCountCounter->execute();
$rowC = $stmtCountCounter->fetch(PDO::FETCH_ASSOC);

// จำนวนสมาชิก
$stmtCountMember = $condb->prepare("SELECT COUNT(*) as totalMember FROM tbl_member");
$stmtCountMember->execute();
$rowM = $stmtCountMember->fetch(PDO::FETCH_ASSOC);

// จำนวนสินค้า
$stmtCountProduct = $condb->prepare("SELECT COUNT(*) as totalProduct FROM tbl_product");
$stmtCountProduct->execute();
$rowP = $stmtCountProduct->fetch(PDO::FETCH_ASSOC);

// นับจำนวนสินค้าที่นำออกทั้งหมดในวันนี้
$queryTodayOut = $condb->prepare("SELECT SUM(quantity) AS totalTodayOut FROM tbl_order WHERE DATE(date_out) = CURDATE()");
$queryTodayOut->execute();
$rowTodayOut = $queryTodayOut->fetch(PDO::FETCH_ASSOC);


$month = isset($_GET['month']) ? $_GET['month'] : date('m'); // ตั้งค่าเป็นเดือนปัจจุบันโดยอัตโนมัติ
$year = isset($_GET['year']) ? $_GET['year'] : date('Y'); // ตั้งค่าเป็นปีปัจจุบันโดยอัตโนมัติ

// คิวรีเพื่อดึงข้อมูลรายรับและกำไร จาก tbl_order
$queryRevenueAndProfit = $condb->prepare("
    SELECT 
        DATE(date_out) AS order_date,
        SUM(sell_price * quantity) AS revenue,
        SUM((sell_price - cost_price) * quantity) AS profit
    FROM tbl_order 
    WHERE YEAR(date_out) = :year AND MONTH(date_out) = :month
    GROUP BY DATE(date_out) 
    ORDER BY order_date ASC
");
$queryRevenueAndProfit->bindParam(':month', $month, PDO::PARAM_STR);
$queryRevenueAndProfit->bindParam(':year', $year, PDO::PARAM_STR);
$queryRevenueAndProfit->execute();
$results = $queryRevenueAndProfit->fetchAll(PDO::FETCH_ASSOC);


// เตรียมข้อมูลสำหรับกราฟ รายรับ และ กำไร
$dates = [];
$revenueValues = [];
$profitValues = [];
foreach ($results as $result) {
    $dates[] = $result['order_date'];
    $revenueValues[] = $result['revenue'];
    $profitValues[] = $result['profit'];
}

// คิวรีเพื่อดึงข้อมูลรายจ่ายจาก tbl_newproduct
// คำสั่ง query เพื่อดึงข้อมูลรายจ่ายเฉพาะในเดือนและปีที่เลือก
$queryExpenses = $condb->prepare("
    SELECT 
        DATE(dateCreate) AS expense_date,
        SUM(newcost_price * newproduct_qty) AS total_expenses
    FROM tbl_newproduct 
    WHERE YEAR(dateCreate) = :year AND MONTH(dateCreate) = :month
    GROUP BY DATE(dateCreate) 
    ORDER BY expense_date ASC
");
$queryExpenses->bindParam(':month', $month, PDO::PARAM_STR);
$queryExpenses->bindParam(':year', $year, PDO::PARAM_STR);
$queryExpenses->execute();
$expenseResults = $queryExpenses->fetchAll(PDO::FETCH_ASSOC);


// เตรียมข้อมูลสำหรับกราฟรายจ่าย
$expenseDates = [];
$expenseValues = [];
foreach ($expenseResults as $result) {
    $expenseDates[] = $result['expense_date'];
    $expenseValues[] = $result['total_expenses'];
}

// ดึงข้อมูลยอดขายของสินค้าทั้งหมด
$query = $condb->prepare("
    SELECT 
        product_id,
        product_name,
        SUM(quantity) AS total_sold,
        AVG(cost_price) AS avg_cost_price,
        AVG(sell_price) AS avg_sell_price
    FROM tbl_order_eoq
    GROUP BY product_id, product_name
");
$query->execute();
$products = $query->fetchAll(PDO::FETCH_ASSOC);

// กำหนดค่าคงที่สำหรับการคำนวณ EOQ
$eoqResults = [];
foreach ($products as $product) {
    $D = $product['total_sold'] ?: 0; // ความต้องการสินค้า
    $S = $product['avg_cost_price'];  // ใช้ราคาทุนเป็นต้นทุนการสั่งซื้อ
    $H = $product['avg_sell_price'] - $product['avg_cost_price']; // ใช้กำไรเป็นต้นทุนการเก็บสินค้า
    
    if ($D > 0 && $H > 0) {  // ตรวจสอบให้แน่ใจว่า H ไม่เป็น 0
        $EOQ = sqrt((2 * $D * $S) / $H);
        $eoqResults[] = [
            'product_id' => $product['product_id'],
            'product_name' => $product['product_name'],
            'EOQ' => ceil($EOQ), // ปัดขึ้นเป็นจำนวนเต็ม
            'total_sold' => $D,
            'avg_cost_price' => $product['avg_cost_price'],
            'avg_sell_price' => $product['avg_sell_price'],
        ];
    }
}

// แบ่งหน้า
$itemsPerPage = 10;
$totalItems = count($eoqResults);
$totalPages = ceil($totalItems / $itemsPerPage);

// ตรวจสอบหมายเลขหน้าปัจจุบัน
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, min($currentPage, $totalPages)); // ตรวจสอบให้หมายเลขหน้าไม่เกินขอบเขต

// คำนวณตำแหน่งเริ่มต้นในการดึงข้อมูล
$offset = ($currentPage - 1) * $itemsPerPage;

// จำกัดให้แสดงผลแค่ 10 อันดับแรกในหน้าปัจจุบัน
$topEoqResults = array_slice($eoqResults, $offset, $itemsPerPage);
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้าหลัก</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1>หน้าแรก</h1>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-lg-3 col-6">
                                        <div class="small-box bg-info">
                                            <div class="inner">
                                                <h3> <?=$rowC['totalView'];?> </h3>
                                                <p>หมวดหมู่</p>
                                            </div>
                                            <div class="icon">
                                                <i class="ion ion-ios-pricetags-outline"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-lg-3 col-6">
                                        <div class="small-box bg-success">
                                            <div class="inner">
                                                <h3> <?=$rowM['totalMember'];?> </h3>
                                                <p>สมาชิก</p>
                                            </div>
                                            <div class="icon">
                                                <i class="ion ion-person-stalker"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-lg-3 col-6">
                                        <div class="small-box bg-warning">
                                            <div class="inner">
                                                <h3><?=$rowP['totalProduct'];?></h3>
                                                <p>สินค้า</p>
                                            </div>
                                            <div class="icon">
                                                <i class="ion ion-bag"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-lg-3 col-6">
                                        <div class="small-box bg-danger">
                                            <div class="inner">
                                                <h3> <?= $rowTodayOut['totalTodayOut'] ?: 0; ?> </h3>
                                                <p>นำสินค้าออกทั้งหมดของวันนี้</p>
                                            </div>
                                            <div class="icon">
                                                <i class="ion ion-bag"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                                <form method="GET" action="">
                                    <label for="monthSelect">เลือกเดือน:</label>
                                    <select name="month" id="monthSelect" onchange="this.form.submit()">
                                        <option value="01" <?= $month == '01' ? 'selected' : ''; ?>>มกราคม</option>
                                        <option value="02" <?= $month == '02' ? 'selected' : ''; ?>>กุมภาพันธ์</option>
                                        <option value="03" <?= $month == '03' ? 'selected' : ''; ?>>มีนาคม</option>
                                        <option value="04" <?= $month == '04' ? 'selected' : ''; ?>>เมษายน</option>
                                        <option value="05" <?= $month == '05' ? 'selected' : ''; ?>>พฤษภาคม</option>
                                        <option value="06" <?= $month == '06' ? 'selected' : ''; ?>>มิถุนายน</option>
                                        <option value="07" <?= $month == '07' ? 'selected' : ''; ?>>กรกฎาคม</option>
                                        <option value="08" <?= $month == '08' ? 'selected' : ''; ?>>สิงหาคม</option>
                                        <option value="09" <?= $month == '09' ? 'selected' : ''; ?>>กันยายน</option>
                                        <option value="10" <?= $month == '10' ? 'selected' : ''; ?>>ตุลาคม</option>
                                        <option value="11" <?= $month == '11' ? 'selected' : ''; ?>>พฤศจิกายน</option>
                                        <option value="12" <?= $month == '12' ? 'selected' : ''; ?>>ธันวาคม</option>
                                    </select>
                                    <label for="yearSelect">เลือกปี:</label>
                                        <select name="year" id="yearSelect" onchange="this.form.submit()">
                                            <?php
                                            // กำหนดปีปัจจุบัน
                                            $currentYear = date('Y');
                                            
                                            // แสดงปีจากปีก่อนหน้า 5 ปี ถึงปีปัจจุบัน
                                            for ($i = 0; $i < 5; $i++) {
                                                $yearOption = $currentYear - $i;
                                                echo '<option value="' . $yearOption . '" ' . ($year == $yearOption ? 'selected' : '') . '>' . $yearOption . '</option>';
                                            }
                                            ?>
                                        </select>

                                </form>

                                <div class="row">
                                    <div class="col-12">
                                       
                                        <h3>กราฟรายรับ-จ่ายและกำไรรายวัน</h3>
                                        <canvas id="profitChart"></canvas>
                                        <?php endif; ?>
                                    </div>
                                </div>


                                <!-- แสดงผล EOQ -->
                                <section class="content">
                                    <div class="container-fluid">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="card">
                                                    <div class="card-body">
                                                        <div class="row">
                                                            <div class="col-12">
                                                                <h3>ปริมาณคำสั่งซื้อที่เหมาะสม (EOQ)</h3>
                                                                <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
                                                                <div class="alert alert-success" role="alert">
                                                                    ลบข้อมูล EOQ สำเร็จ!
                                                                </div>
                                                                <?php endif; ?>
                                                                <table class="table table-bordered">
                                                                    <thead>
                                                                        <tr>
                                                                            <th>ชื่อสินค้า</th>
                                                                            <th>จำนวนที่ต้องสั่งซื้อ</th>
                                                                            <th>ยอดขายรวม</th>
                                                                            <th>ราคาต้นทุน</th>
                                                                            <th>ราคาขาย</th>
                                                                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') { ?>
                                                                            <th>ลบ</th>
                                                                            <?php } ?>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <?php foreach ($topEoqResults as $eoq): ?>
                                                                        <tr>
                                                                            <td><?= $eoq['product_name']; ?></td>
                                                                            <td><?= $eoq['EOQ']; ?></td>
                                                                            <td><?= $eoq['total_sold']; ?></td>
                                                                            <td><?= number_format($eoq['avg_cost_price'], 2); ?>
                                                                            </td>
                                                                            <td><?= number_format($eoq['avg_sell_price'], 2); ?>
                                                                            </td>
                                                                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') { ?>
                                                                            <td>
                                                                                <form action="delete_eoq.php"
                                                                                    method="POST"
                                                                                    style="display:inline;">
                                                                                    <input type="hidden"
                                                                                        name="product_id"
                                                                                        value="<?= $eoq['product_id']; ?>">
                                                                                    <button type="submit"
                                                                                        class="btn btn-danger btn-sm"
                                                                                        onclick="return confirm('คุณแน่ใจหรือไม่ว่าจะลบข้อมูลนี้?');">ลบ</button>
                                                                                </form>
                                                                                <?php } ?>
                                                                            </td>
                                                                        </tr>
                                                                        <?php endforeach; ?>
                                                                    </tbody>

                                                                </table>
                                                                <!-- ปุ่มนำทางสำหรับหน้า EOQ -->
                                                                <nav aria-label="Page navigation">
                                                                    <ul class="pagination">
                                                                        <?php if ($currentPage > 1): ?>
                                                                        <li class="page-item">
                                                                            <a class="page-link"
                                                                                href="?page=<?= $currentPage - 1; ?>">ก่อนหน้า</a>
                                                                        </li>
                                                                        <?php endif; ?>
                                                                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                                                        <li
                                                                            class="page-item <?= ($i === $currentPage) ? 'active' : ''; ?>">
                                                                            <a class="page-link"
                                                                                href="?page=<?= $i; ?>"><?= $i; ?></a>
                                                                        </li>
                                                                        <?php endfor; ?>
                                                                        <?php if ($currentPage < $totalPages): ?>
                                                                        <li class="page-item">
                                                                            <a class="page-link"
                                                                                href="?page=<?= $currentPage + 1; ?>">ถัดไป</a>
                                                                        </li>
                                                                        <?php endif; ?>
                                                                    </ul>
                                                                </nav>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <script>
                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                            const ctxProfit = document.getElementById('profitChart').getContext('2d');
                            const profitChart = new Chart(ctxProfit, {
                                type: 'bar',
                                data: {
                                    labels: <?= json_encode($dates); ?>.map(date => {
                                        const d = new Date(date);
                                        return d.toLocaleDateString('th-TH', {
                                            day: '2-digit',
                                            month: '2-digit',
                                            year: 'numeric'
                                        });
                                    }),
                                    datasets: [{
                                        label: 'รายรับ',
                                        data: <?= json_encode($revenueValues); ?>,
                                        backgroundColor: 'rgba(75, 192, 192, 0.6)',
                                        borderColor: 'rgba(75, 192, 192, 1)',
                                        borderWidth: 1
                                    }, {
                                        label: 'รายจ่าย',
                                        data: <?= json_encode($expenseValues); ?>,
                                        backgroundColor: 'rgba(255, 159, 64, 0.6)',
                                        borderColor: 'rgba(255, 159, 64, 1)',
                                        borderWidth: 1
                                    }, {
                                        label: 'กำไร',
                                        data: <?= json_encode($profitValues); ?>,
                                        backgroundColor: 'rgba(255, 99, 132, 0.6)',
                                        borderColor: 'rgba(255, 99, 132, 1)',
                                        borderWidth: 1
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    scales: {
                                        x: {
                                            beginAtZero: true,
                                            title: {
                                                display: true,
                                                text: 'วัน/เดือน/ปี'
                                            }
                                        },
                                        y: {
                                            beginAtZero: true,
                                            title: {
                                                display: true,
                                                text: 'จำนวนเงิน (บาท)'
                                            }
                                        }
                                    },
                                    plugins: {
                                        legend: {
                                            position: 'top',
                                        }
                                    }
                                }
                            });

                            <?php endif; ?>
                            </script>



                        </div>
                    </div>
                </div>
            </div>
    </div>
    </section>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>