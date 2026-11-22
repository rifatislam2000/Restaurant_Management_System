<?php
include("../includes/auth.php");
require_login();
include("../includes/dbconfig.php");

if (!in_array($_SESSION['role'] ?? '', ['admin', 'cashier'], true)) {
    header("Location: ../login.php");
    exit;
}

function reports_escape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function reports_valid_date($value)
{
    $date = DateTime::createFromFormat('Y-m-d', (string)$value);
    return $date && $date->format('Y-m-d') === (string)$value;
}

$welcomeName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
$role = $_SESSION['role'] ?? 'cashier';
$isAdmin = $role === 'admin';

$fromDate = $_GET['from'] ?? date('Y-m-d', strtotime('-29 days'));
$toDate = $_GET['to'] ?? date('Y-m-d');
$statusFilter = $_GET['status'] ?? 'all';

if (!reports_valid_date($fromDate) || !reports_valid_date($toDate) || $fromDate > $toDate) {
    $fromDate = date('Y-m-d', strtotime('-29 days'));
    $toDate = date('Y-m-d');
}
if (!in_array($statusFilter, ['all', 'pending', 'completed', 'cancelled'], true)) {
    $statusFilter = 'all';
}

$statusSql = $statusFilter === 'all' ? '' : ' AND o.status = ?';
$params = [$fromDate, $toDate];
$types = 'ss';
if ($statusFilter !== 'all') {
    $params[] = $statusFilter;
    $types .= 's';
}

$reportQuery = "
    SELECT o.order_id, o.order_date, o.total_amount, o.payment_method, o.status,
           u.full_name AS cashier_name,
           c.full_name AS customer_name,
           (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS item_count
    FROM orders o
    INNER JOIN users u ON u.user_id = o.user_id
    LEFT JOIN customers c ON c.customer_id = o.customer_id
    WHERE o.order_date >= ? AND o.order_date < DATE_ADD(?, INTERVAL 1 DAY)
";
$reportQuery .= $statusSql . " ORDER BY o.order_date DESC, o.order_id DESC";

$reportStatement = mysqli_prepare($conn, $reportQuery);
if ($statusFilter === 'all') {
    mysqli_stmt_bind_param($reportStatement, 'ss', $fromDate, $toDate);
} else {
    mysqli_stmt_bind_param($reportStatement, 'sss', $fromDate, $toDate, $statusFilter);
}
mysqli_stmt_execute($reportStatement);
$reportResult = mysqli_stmt_get_result($reportStatement);
$reportRows = [];
while ($row = mysqli_fetch_assoc($reportResult)) {
    $reportRows[] = $row;
}
mysqli_stmt_close($reportStatement);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="restaurant-report-' . $fromDate . '-to-' . $toDate . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Order', 'Date', 'Customer', 'Cashier', 'Payment', 'Items', 'Total', 'Status']);
    foreach ($reportRows as $row) {
        fputcsv($output, [
            '#' . $row['order_id'],
            $row['order_date'],
            $row['customer_name'] ?: 'Walk-in',
            $row['cashier_name'],
            $row['payment_method'],
            $row['item_count'],
            $row['total_amount'],
            ucfirst($row['status']),
        ]);
    }
    fclose($output);
    exit;
}

$summaryStatusSql = $statusFilter === 'all' ? '' : ' AND status = ?';
$summaryStatement = mysqli_prepare(
    $conn,
    "SELECT
        COUNT(*) AS order_count,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) AS sales,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_count
     FROM orders
     WHERE order_date >= ? AND order_date < DATE_ADD(?, INTERVAL 1 DAY)" . $summaryStatusSql
);
if ($statusFilter === 'all') {
    mysqli_stmt_bind_param($summaryStatement, 'ss', $fromDate, $toDate);
} else {
    mysqli_stmt_bind_param($summaryStatement, 'sss', $fromDate, $toDate, $statusFilter);
}
mysqli_stmt_execute($summaryStatement);
$summaryResult = mysqli_stmt_get_result($summaryStatement);
$summary = mysqli_fetch_assoc($summaryResult);
mysqli_stmt_close($summaryStatement);

$salesStatement = mysqli_prepare(
    $conn,
    "SELECT COALESCE(SUM(oi.quantity), 0) AS items_sold
     FROM order_items oi
     INNER JOIN orders o ON o.order_id = oi.order_id
     WHERE o.status = 'completed'
       AND o.order_date >= ? AND o.order_date < DATE_ADD(?, INTERVAL 1 DAY)"
);
mysqli_stmt_bind_param($salesStatement, 'ss', $fromDate, $toDate);
mysqli_stmt_execute($salesStatement);
$itemsResult = mysqli_stmt_get_result($salesStatement);
$itemsSold = (int)(mysqli_fetch_assoc($itemsResult)['items_sold'] ?? 0);
mysqli_stmt_close($salesStatement);

$completedCount = (int)$summary['completed_count'];
$averageOrder = $completedCount > 0 ? (float)$summary['sales'] / $completedCount : 0.0;

$topStatement = mysqli_prepare(
    $conn,
    "SELECT f.food_name, SUM(oi.quantity) AS quantity_sold,
            SUM(oi.subtotal) AS item_sales
     FROM order_items oi
     INNER JOIN foods f ON f.food_id = oi.food_id
     INNER JOIN orders o ON o.order_id = oi.order_id
     WHERE o.status = 'completed'
       AND o.order_date >= ? AND o.order_date < DATE_ADD(?, INTERVAL 1 DAY)
     GROUP BY f.food_id, f.food_name
     ORDER BY quantity_sold DESC, f.food_name
     LIMIT 8"
);
mysqli_stmt_bind_param($topStatement, 'ss', $fromDate, $toDate);
mysqli_stmt_execute($topStatement);
$topFoodsResult = mysqli_stmt_get_result($topStatement);
$topFoods = [];
while ($food = mysqli_fetch_assoc($topFoodsResult)) {
    $topFoods[] = $food;
}
mysqli_stmt_close($topStatement);

$categoryStatement = mysqli_prepare(
    $conn,
    "SELECT c.category_name, SUM(oi.quantity) AS quantity_sold,
            SUM(oi.subtotal) AS category_sales
     FROM order_items oi
     INNER JOIN foods f ON f.food_id = oi.food_id
     INNER JOIN categories c ON c.category_id = f.category_id
     INNER JOIN orders o ON o.order_id = oi.order_id
     WHERE o.status = 'completed'
       AND o.order_date >= ? AND o.order_date < DATE_ADD(?, INTERVAL 1 DAY)
     GROUP BY c.category_id, c.category_name
     ORDER BY category_sales DESC
     LIMIT 8"
);
mysqli_stmt_bind_param($categoryStatement, 'ss', $fromDate, $toDate);
mysqli_stmt_execute($categoryStatement);
$categoryResult = mysqli_stmt_get_result($categoryStatement);
$categories = [];
while ($category = mysqli_fetch_assoc($categoryResult)) {
    $categories[] = $category;
}
mysqli_stmt_close($categoryStatement);

$dailyStatement = mysqli_prepare(
    $conn,
    "SELECT DATE(order_date) AS sale_day, SUM(total_amount) AS daily_sales
     FROM orders
     WHERE status = 'completed'
       AND order_date >= ? AND order_date < DATE_ADD(?, INTERVAL 1 DAY)
     GROUP BY DATE(order_date)
     ORDER BY sale_day"
);
mysqli_stmt_bind_param($dailyStatement, 'ss', $fromDate, $toDate);
mysqli_stmt_execute($dailyStatement);
$dailyResult = mysqli_stmt_get_result($dailyStatement);
$dailySales = [];
while ($day = mysqli_fetch_assoc($dailyResult)) {
    $dailySales[$day['sale_day']] = (float)$day['daily_sales'];
}
mysqli_stmt_close($dailyStatement);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Restaurant Reports</title>
    <link rel="stylesheet" href="../assets/style.css?v=<?= filemtime(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'style.css') ?>">
</head>
<body>
    <header class="topbar">
        <a class="brand" href="dashboard.php">
            <span class="brand-mark" aria-hidden="true">R</span>
            <span>Resto<span class="brand-light">Flow</span></span>
        </a>
        <div class="topbar-right">
            <span class="today-date"><?= reports_escape(date('D, M j, Y')) ?></span>
            <span class="user-avatar" aria-hidden="true"><?= reports_escape(strtoupper(substr($welcomeName, 0, 1))) ?></span>
            <div class="user-details">
                <strong><?= reports_escape($welcomeName) ?></strong>
                <span><?= reports_escape(ucfirst($role)) ?></span>
            </div>
            <a class="logout-link" href="../logout.php">Log out</a>
        </div>
    </header>

    <div class="dashboard-layout">
        <aside class="sidebar" aria-label="Main sidebar">
            <p class="sidebar-title">Workspace</p>
            <nav aria-label="Main navigation">
                <a class="sidebar-link" href="dashboard.php"><span class="nav-icon" aria-hidden="true">◈</span>Dashboard</a>
                <a class="sidebar-link" href="orders.php"><span class="nav-icon" aria-hidden="true">▤</span>Orders</a>
                <a class="sidebar-link" href="order_items.php"><span class="nav-icon" aria-hidden="true">▦</span>Order items</a>
                <a class="sidebar-link" href="customers.php"><span class="nav-icon" aria-hidden="true">♙</span>Customers</a>
                <a class="sidebar-link active" href="reports.php" aria-current="page"><span class="nav-icon" aria-hidden="true">▥</span>Reports</a>
            </nav>
            <?php if ($isAdmin): ?>
                <p class="sidebar-title management-title">Management</p>
                <nav aria-label="Management navigation">
                    <a class="sidebar-link" href="foods.php"><span class="nav-icon" aria-hidden="true">◉</span>Foods</a>
                    <a class="sidebar-link" href="categories.php"><span class="nav-icon" aria-hidden="true">◫</span>Categories</a>
                    <a class="sidebar-link" href="users.php"><span class="nav-icon" aria-hidden="true">♧</span>Users</a>
                </nav>
            <?php endif; ?>
            <div class="sidebar-bottom">
                <div class="sidebar-tip">
                    <span class="tip-mark" aria-hidden="true">✦</span>
                    <strong>Report centre</strong>
                    <p>Review sales, order activity, and product performance.</p>
                </div>
                <a class="sidebar-logout" href="../logout.php"><span aria-hidden="true">↪</span>Log out</a>
            </div>
        </aside>

        <main class="dashboard-content">
            <section class="page-heading">
                <div>
                    <p class="eyebrow">Business intelligence</p>
                    <h1>Reports</h1>
                    <p class="heading-copy">Sales and order performance for <?= reports_escape(date('M j, Y', strtotime($fromDate))) ?> – <?= reports_escape(date('M j, Y', strtotime($toDate))) ?>.</p>
                </div>
                <div class="report-actions">
                    <a class="secondary-button" href="?<?= http_build_query(['from' => $fromDate, 'to' => $toDate, 'status' => $statusFilter, 'export' => 'csv']) ?>">Export CSV</a>
                    <button class="primary-button" type="button" onclick="window.print()">Print report</button>
                </div>
            </section>

            <form class="report-filter" method="get" action="reports.php">
                <label>From
                    <input type="date" name="from" value="<?= reports_escape($fromDate) ?>" required>
                </label>
                <label>To
                    <input type="date" name="to" value="<?= reports_escape($toDate) ?>" required>
                </label>
                <label>Status
                    <select name="status">
                        <?php foreach (['all' => 'All orders', 'completed' => 'Completed', 'pending' => 'Pending', 'cancelled' => 'Cancelled'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="filter-button" type="submit">Apply filter</button>
                <a class="reset-link" href="reports.php">Reset</a>
            </form>

            <section class="metric-grid" aria-label="Report summary">
                <article class="metric-card metric-sales">
                    <div class="metric-top"><span class="metric-label">Completed sales</span><span class="metric-icon">৳</span></div>
                    <strong class="metric-value">৳<?= number_format((float)$summary['sales'], 2) ?></strong>
                    <span class="metric-caption">Within selected period</span>
                </article>
                <article class="metric-card metric-orders">
                    <div class="metric-top"><span class="metric-label">Orders</span><span class="metric-icon">O</span></div>
                    <strong class="metric-value"><?= number_format((int)$summary['order_count']) ?></strong>
                    <span class="metric-caption"><?= number_format($completedCount) ?> completed</span>
                </article>
                <article class="metric-card metric-foods">
                    <div class="metric-top"><span class="metric-label">Items sold</span><span class="metric-icon">F</span></div>
                    <strong class="metric-value"><?= number_format($itemsSold) ?></strong>
                    <span class="metric-caption">Completed orders</span>
                </article>
                <article class="metric-card metric-customers">
                    <div class="metric-top"><span class="metric-label">Average order</span><span class="metric-icon">A</span></div>
                    <strong class="metric-value">৳<?= number_format($averageOrder, 2) ?></strong>
                    <span class="metric-caption">Per completed order</span>
                </article>
            </section>

            <section class="insights-grid report-insights">
                <article class="panel">
                    <div class="panel-heading">
                        <div><p class="eyebrow">Daily completed sales</p><h2>Sales trend</h2></div>
                        <span class="panel-tag"><?= count($dailySales) ?> sales day<?= count($dailySales) === 1 ? '' : 's' ?></span>
                    </div>
                    <?php $maxDaily = max($dailySales ?: [0]); ?>
                    <div class="report-bars">
                        <?php if ($dailySales): ?>
                            <?php foreach ($dailySales as $day => $amount): ?>
                                <?php $height = $maxDaily > 0 ? max(8, round(($amount / $maxDaily) * 100)) : 8; ?>
                                <div class="report-bar-column" title="<?= reports_escape($day) ?>: ৳<?= number_format($amount, 2) ?>">
                                    <strong>৳<?= number_format($amount, 0) ?></strong>
                                    <div class="report-bar-track"><span style="height: <?= $height ?>%"></span></div>
                                    <small><?= reports_escape(date('M j', strtotime($day))) ?></small>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="report-empty">No completed sales in this period.</p>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-heading">
                        <div><p class="eyebrow">Product mix</p><h2>Sales by category</h2></div>
                        <span class="panel-tag">Top <?= count($categories) ?></span>
                    </div>
                    <div class="report-ranking">
                        <?php if ($categories): ?>
                            <?php foreach ($categories as $category): ?>
                                <div class="ranking-row">
                                    <div><strong><?= reports_escape($category['category_name']) ?></strong><span><?= number_format((int)$category['quantity_sold']) ?> items</span></div>
                                    <strong>$<?= number_format((float)$category['category_sales'], 2) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="report-empty">No category sales found.</p>
                        <?php endif; ?>
                    </div>
                </article>
            </section>

            <section class="panel recent-panel">
                <div class="panel-heading">
                    <div><p class="eyebrow">Best sellers</p><h2>Top food items</h2></div>
                    <span class="panel-tag">Completed orders</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Rank</th><th>Food item</th><th>Quantity sold</th><th>Sales</th></tr></thead>
                        <tbody>
                            <?php if ($topFoods): ?>
                                <?php foreach ($topFoods as $index => $food): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><strong><?= reports_escape($food['food_name']) ?></strong></td>
                                        <td><?= number_format((int)$food['quantity_sold']) ?></td>
                                        <td class="table-total">$<?= number_format((float)$food['item_sales'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td class="empty-state" colspan="4">No food sales found for this period.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel recent-panel report-orders-panel">
                <div class="panel-heading">
                    <div><p class="eyebrow">Detailed report</p><h2>Orders</h2></div>
                    <span class="panel-tag"><?= number_format(count($reportRows)) ?> record<?= count($reportRows) === 1 ? '' : 's' ?></span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Cashier</th><th>Payment</th><th>Items</th><th>Total</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php if ($reportRows): ?>
                                <?php foreach ($reportRows as $order): ?>
                                    <tr>
                                        <td><a class="order-number" href="order_items.php?order_id=<?= (int)$order['order_id'] ?>">#<?= (int)$order['order_id'] ?></a></td>
                                        <td><?= reports_escape(date('M j, Y g:i A', strtotime($order['order_date']))) ?></td>
                                        <td><?= reports_escape($order['customer_name'] ?: 'Walk-in') ?></td>
                                        <td><?= reports_escape($order['cashier_name']) ?></td>
                                        <td><?= reports_escape($order['payment_method']) ?></td>
                                        <td><?= number_format((int)$order['item_count']) ?></td>
                                        <td class="table-total">৳<?= number_format((float)$order['total_amount'], 2) ?></td>
                                        <td><span class="status-badge status-<?= reports_escape($order['status']) ?>"><?= reports_escape(ucfirst($order['status'])) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td class="empty-state" colspan="8">No orders match the selected report filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <footer class="dashboard-footer">RestoFlow <span>·</span> Restaurant management</footer>
        </main>
    </div>
</body>
</html>
