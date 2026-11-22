<?php
include("../includes/auth.php");
require_login();
include("../includes/dbconfig.php");

function dashboard_escape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$welcomeName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
$role = $_SESSION['role'] ?? 'cashier';
$isAdmin = $role === 'admin';

$summaryResult = mysqli_query(
    $conn,
    "SELECT
        (SELECT COALESCE(SUM(total_amount), 0)
         FROM orders
         WHERE status = 'completed'
           AND order_date >= CURDATE()
           AND order_date < CURDATE() + INTERVAL 1 DAY) AS today_sales,
        (SELECT COUNT(*)
         FROM orders
         WHERE status = 'completed'
           AND order_date >= CURDATE()
           AND order_date < CURDATE() + INTERVAL 1 DAY) AS completed_today,
        (SELECT COUNT(*) FROM foods WHERE is_available = 1) AS available_foods,
        (SELECT COUNT(*) FROM customers) AS customer_count"
);
if (!$summaryResult) {
    die('Unable to load dashboard summary: ' . dashboard_escape(mysqli_error($conn)));
}
$summary = mysqli_fetch_assoc($summaryResult);
mysqli_free_result($summaryResult);

$salesResult = mysqli_query(
    $conn,
    "SELECT DATE(order_date) AS sale_day, SUM(total_amount) AS daily_sales
     FROM orders
     WHERE status = 'completed'
       AND order_date >= CURDATE() - INTERVAL 6 DAY
       AND order_date < CURDATE() + INTERVAL 1 DAY
     GROUP BY DATE(order_date)
     ORDER BY sale_day"
);
if (!$salesResult) {
    die('Unable to load sales chart: ' . dashboard_escape(mysqli_error($conn)));
}

$salesByDay = [];
for ($dayOffset = 6; $dayOffset >= 0; $dayOffset--) {
    $date = date('Y-m-d', strtotime('-' . $dayOffset . ' days'));
    $salesByDay[$date] = 0.0;
}
while ($dailySale = mysqli_fetch_assoc($salesResult)) {
    if (isset($salesByDay[$dailySale['sale_day']])) {
        $salesByDay[$dailySale['sale_day']] = (float)$dailySale['daily_sales'];
    }
}
mysqli_free_result($salesResult);
$maxDailySales = max($salesByDay);

$statusResult = mysqli_query($conn, "SELECT status, COUNT(*) AS status_count FROM orders GROUP BY status");
if (!$statusResult) {
    die('Unable to load order summary: ' . dashboard_escape(mysqli_error($conn)));
}
$statusCounts = ['completed' => 0, 'pending' => 0, 'cancelled' => 0];
while ($statusRow = mysqli_fetch_assoc($statusResult)) {
    if (isset($statusCounts[$statusRow['status']])) {
        $statusCounts[$statusRow['status']] = (int)$statusRow['status_count'];
    }
}
mysqli_free_result($statusResult);
$allOrdersCount = array_sum($statusCounts);

$ordersResult = mysqli_query(
    $conn,
    "SELECT o.order_id, o.order_date, o.total_amount, o.payment_method, o.status,
            c.full_name AS customer_name
     FROM orders o
     LEFT JOIN customers c ON c.customer_id = o.customer_id
     ORDER BY o.order_date DESC, o.order_id DESC
     LIMIT 6"
);
if (!$ordersResult) {
    die('Unable to load recent orders: ' . dashboard_escape(mysqli_error($conn)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Restaurant Dashboard</title>
    <link rel="stylesheet" href="../assets/style.css?v=<?= filemtime(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'style.css') ?>">
</head>
<body>
    <header class="topbar">
        <a class="brand" href="dashboard.php">
            <span class="brand-mark" aria-hidden="true">R</span>
            <span>Resto<span class="brand-light">Flow</span></span>
        </a>
        <div class="topbar-right">
            <span class="today-date"><?= dashboard_escape(date('D, M j, Y')) ?></span>
            <span class="user-avatar" aria-hidden="true"><?= dashboard_escape(strtoupper(substr($welcomeName, 0, 1))) ?></span>
            <div class="user-details">
                <strong><?= dashboard_escape($welcomeName) ?></strong>
                <span><?= dashboard_escape(ucfirst($role)) ?></span>
            </div>
            <a class="logout-link" href="../logout.php">Log out</a>
        </div>
    </header>

    <div class="dashboard-layout">
        <aside class="sidebar" aria-label="Main sidebar">
            <p class="sidebar-title">Workspace</p>
            <nav aria-label="Main navigation">
                <a class="sidebar-link active" href="dashboard.php" aria-current="page">
                    <span class="nav-icon" aria-hidden="true">◈</span>Dashboard
                </a>
                <a class="sidebar-link" href="orders.php">
                    <span class="nav-icon" aria-hidden="true">▤</span>Orders
                </a>
                <a class="sidebar-link" href="order_items.php">
                    <span class="nav-icon" aria-hidden="true">▦</span>Order items
                </a>
                <a class="sidebar-link" href="customers.php">
                    <span class="nav-icon" aria-hidden="true">♙</span>Customers
                </a>
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
                    <strong>Daily overview</strong>
                    <p>Keep track of sales, orders, and your menu from one place.</p>
                </div>
                <a class="sidebar-logout" href="../logout.php"><span aria-hidden="true">↪</span>Log out</a>
            </div>
        </aside>

        <main class="dashboard-content">
            <section class="page-heading">
                <div>
                    <p class="eyebrow">Restaurant overview</p>
                    <h1>Welcome back, <?= dashboard_escape($welcomeName) ?></h1>
                    <p class="heading-copy">Here is what is happening at your restaurant today.</p>
                </div>
                <a class="primary-button" href="orders.php"><span aria-hidden="true">+</span> Create order</a>
            </section>

            <section class="metric-grid" aria-label="Restaurant summary">
                <article class="metric-card metric-sales">
                    <div class="metric-top">
                        <span class="metric-label">Sales today</span>
                        <span class="metric-icon" aria-hidden="true">৳</span>
                    </div>
                    <strong class="metric-value">৳<?= number_format((float)$summary['today_sales'], 2) ?></strong>
                    <span class="metric-caption">From completed orders</span>
                </article>
                <article class="metric-card metric-orders">
                    <div class="metric-top">
                        <span class="metric-label">Completed orders</span>
                        <span class="metric-icon" aria-hidden="true">O</span>
                    </div>
                    <strong class="metric-value"><?= number_format((int)$summary['completed_today']) ?></strong>
                    <span class="metric-caption">Completed today</span>
                </article>
                <article class="metric-card metric-foods">
                    <div class="metric-top">
                        <span class="metric-label">Available foods</span>
                        <span class="metric-icon" aria-hidden="true">F</span>
                    </div>
                    <strong class="metric-value"><?= number_format((int)$summary['available_foods']) ?></strong>
                    <span class="metric-caption">Ready to order</span>
                </article>
                <article class="metric-card metric-customers">
                    <div class="metric-top">
                        <span class="metric-label">Customers</span>
                        <span class="metric-icon" aria-hidden="true">C</span>
                    </div>
                    <strong class="metric-value"><?= number_format((int)$summary['customer_count']) ?></strong>
                    <span class="metric-caption">Customer records</span>
                </article>
            </section>

            <section class="insights-grid" aria-label="Sales and order insights">
                <article class="panel sales-panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Last seven days</p>
                            <h2>Sales overview</h2>
                        </div>
                        <span class="panel-tag">Completed orders</span>
                    </div>
                    <div class="sales-chart" role="img" aria-label="Daily sales for the last seven days">
                        <?php foreach ($salesByDay as $date => $sales): ?>
                            <?php
                            $barLevel = $maxDailySales > 0 ? (int)round(($sales / $maxDailySales) * 10) : 0;
                            if ($sales > 0 && $barLevel === 0) {
                                $barLevel = 1;
                            }
                            ?>
                            <div class="chart-column">
                                <span class="chart-amount">৳<?= number_format($sales, 0) ?></span>
                                <div class="chart-track">
                                    <span class="chart-fill bar-level-<?= $barLevel ?>"></span>
                                </div>
                                <span class="chart-day"><?= dashboard_escape(date('D', strtotime($date))) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="panel status-panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Order activity</p>
                            <h2>Order status</h2>
                        </div>
                        <span class="panel-tag">All time</span>
                    </div>
                    <div class="status-summary">
                        <div class="status-total">
                            <strong><?= number_format($allOrdersCount) ?></strong>
                            <span>Total orders</span>
                        </div>
                        <?php foreach ($statusCounts as $status => $count): ?>
                            <div class="status-row">
                                <div class="status-label">
                                    <span class="status-dot status-dot-<?= dashboard_escape($status) ?>"></span>
                                    <span><?= dashboard_escape(ucfirst($status)) ?></span>
                                    <strong><?= number_format($count) ?></strong>
                                </div>
                                <progress class="status-progress status-progress-<?= dashboard_escape($status) ?>"
                                    value="<?= $count ?>" max="<?= max(1, $allOrdersCount) ?>"><?= $count ?></progress>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            </section>

            <section class="panel recent-panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Latest activity</p>
                        <h2>Recent orders</h2>
                    </div>
                    <a class="text-link" href="orders.php">View all orders <span aria-hidden="true">→</span></a>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Date</th>
                                <th>Payment</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($ordersResult) > 0): ?>
                                <?php while ($order = mysqli_fetch_assoc($ordersResult)): ?>
                                    <tr>
                                        <td><a class="order-number" href="order_items.php?order_id=<?= (int)$order['order_id'] ?>">#<?= (int)$order['order_id'] ?></a></td>
                                        <td><?= dashboard_escape($order['customer_name'] ?? 'Walk-in') ?></td>
                                        <td><?= dashboard_escape(date('M j, g:i A', strtotime($order['order_date']))) ?></td>
                                        <td><?= dashboard_escape($order['payment_method']) ?></td>
                                        <td class="table-total">৳<?= number_format((float)$order['total_amount'], 2) ?></td>
                                        <td><span class="status-badge status-<?= dashboard_escape($order['status']) ?>"><?= dashboard_escape(ucfirst($order['status'])) ?></span></td>
                                        <td><a class="row-link" href="orders.php?edit=<?= (int)$order['order_id'] ?>">Details</a></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td class="empty-state" colspan="7">No orders yet. Create your first order to get started.</td></tr>
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
