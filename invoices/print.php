<?php
include("../includes/auth.php");
require_login();
include("../includes/dbconfig.php");

if (!in_array($_SESSION['role'] ?? '', ['admin', 'cashier'], true)) {
    header("Location: ../login.php");
    exit;
}

function invoice_escape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$orderId = filter_var($_GET['order_id'] ?? '', FILTER_VALIDATE_INT);
$orderId = $orderId !== false && $orderId > 0 ? (int)$orderId : null;

$ordersResult = mysqli_query(
    $conn,
    "SELECT o.order_id, o.order_date, o.total_amount, o.payment_method, o.status,
            u.full_name AS cashier_name,
            c.full_name AS customer_name,
            c.phone AS customer_phone,
            c.email AS customer_email
     FROM orders o
     INNER JOIN users u ON u.user_id = o.user_id
     LEFT JOIN customers c ON c.customer_id = o.customer_id
     ORDER BY o.order_id DESC"
);
if (!$ordersResult) {
    die('Unable to load orders: ' . invoice_escape(mysqli_error($conn)));
}

$order = null;
$items = [];
if ($orderId !== null) {
    $orderStatement = mysqli_prepare(
        $conn,
        "SELECT o.order_id, o.order_date, o.total_amount, o.payment_method, o.status,
                u.full_name AS cashier_name,
                c.full_name AS customer_name,
                c.phone AS customer_phone,
                c.email AS customer_email
         FROM orders o
         INNER JOIN users u ON u.user_id = o.user_id
         LEFT JOIN customers c ON c.customer_id = o.customer_id
         WHERE o.order_id = ?"
    );
    mysqli_stmt_bind_param($orderStatement, 'i', $orderId);
    mysqli_stmt_execute($orderStatement);
    $orderResult = mysqli_stmt_get_result($orderStatement);
    $order = mysqli_fetch_assoc($orderResult) ?: null;
    mysqli_stmt_close($orderStatement);

    if ($order) {
        $itemStatement = mysqli_prepare(
            $conn,
            "SELECT oi.quantity, oi.unit_price, oi.subtotal, f.food_name
             FROM order_items oi
             INNER JOIN foods f ON f.food_id = oi.food_id
             WHERE oi.order_id = ?
             ORDER BY oi.order_item_id"
        );
        mysqli_stmt_bind_param($itemStatement, 'i', $orderId);
        mysqli_stmt_execute($itemStatement);
        $itemResult = mysqli_stmt_get_result($itemStatement);
        while ($item = mysqli_fetch_assoc($itemResult)) {
            $items[] = $item;
        }
        mysqli_stmt_close($itemStatement);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $order ? 'Invoice #' . (int)$order['order_id'] : 'Print Invoice' ?></title>
    <style>
        :root {
            font-family: "Segoe UI", Arial, sans-serif;
            color: #20251f;
            background: #eef1ec;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #eef1ec;
        }

        .toolbar {
            display: flex;
            max-width: 760px;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 18px auto 12px;
            padding: 10px 12px;
            border: 1px solid #d7ddd4;
            border-radius: 8px;
            background: #fff;
        }

        .toolbar form {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .toolbar select {
            min-width: 180px;
            height: 38px;
            padding: 0 9px;
            border: 1px solid #bbc5b7;
            border-radius: 6px;
            background: #fff;
            color: #20251f;
        }

        .toolbar button,
        .back-link {
            height: 38px;
            padding: 0 13px;
            border: 1px solid #25321f;
            border-radius: 6px;
            background: #d7f65d;
            color: #25321f;
            font-size: 13px;
            font-weight: 750;
            text-decoration: none;
            cursor: pointer;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            border-color: #bbc5b7;
            background: #fff;
        }

        .invoice {
            width: 760px;
            max-width: calc(100% - 32px);
            min-height: 900px;
            margin: 0 auto 30px;
            padding: 42px 46px;
            border: 1px solid #dfe4da;
            background: #fff;
            box-shadow: 0 12px 35px rgba(44, 53, 41, .10);
        }

        .invoice-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 30px;
            padding-bottom: 24px;
            border-bottom: 3px solid #b6ef67;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-mark {
            display: grid;
            width: 46px;
            height: 46px;
            place-items: center;
            border-radius: 10px;
            background: #d7f65d;
            color: #1b2117;
            font-size: 22px;
            font-weight: 850;
        }

        .brand h1 {
            margin: 0;
            font-size: 26px;
            letter-spacing: -.04em;
        }

        .brand p,
        .invoice-meta p,
        .customer-block p,
        .footer-note {
            margin: 4px 0 0;
            color: #737b70;
            font-size: 12px;
        }

        .invoice-meta {
            text-align: right;
        }

        .invoice-meta strong {
            display: block;
            margin-bottom: 5px;
            color: #2f3a29;
            font-size: 20px;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin: 28px 0;
        }

        .customer-block {
            min-height: 85px;
            padding: 14px 16px;
            border: 1px solid #e1e5dc;
            border-radius: 7px;
        }

        .customer-block strong,
        .detail-label {
            display: block;
            margin-bottom: 7px;
            color: #5e6859;
            font-size: 10px;
            letter-spacing: .10em;
            text-transform: uppercase;
        }

        .detail-list {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px 16px;
            margin: 0;
            padding: 14px 16px;
            border: 1px solid #e1e5dc;
            border-radius: 7px;
            font-size: 12px;
        }

        .detail-list dt { color: #737b70; }
        .detail-list dd { margin: 0; font-weight: 700; }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 10px 8px;
            border-bottom: 1px solid #cfd6ca;
            background: #f5f7f2;
            color: #65705f;
            font-size: 10px;
            letter-spacing: .07em;
            text-align: left;
            text-transform: uppercase;
        }

        th:last-child,
        td:last-child,
        th:nth-child(2),
        td:nth-child(2),
        th:nth-child(3),
        td:nth-child(3) {
            text-align: right;
        }

        td {
            padding: 13px 8px;
            border-bottom: 1px solid #e8ebe4;
            font-size: 12px;
        }

        .item-name {
            font-weight: 700;
        }

        .totals {
            display: grid;
            width: 280px;
            margin: 22px 0 28px auto;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 9px 0;
            border-bottom: 1px solid #edf0e9;
            font-size: 12px;
        }

        .grand-total {
            margin-top: 5px;
            padding: 13px 14px;
            border: 0;
            border-radius: 7px;
            background: #d7f65d;
            color: #26301f;
            font-size: 16px;
            font-weight: 850;
        }

        .invoice-footer {
            margin-top: 36px;
            padding-top: 18px;
            border-top: 1px solid #e1e5dc;
            text-align: center;
        }

        .invoice-footer strong {
            display: block;
            margin-bottom: 5px;
            font-size: 13px;
        }

        .status {
            display: inline-block;
            margin-top: 7px;
            padding: 5px 9px;
            border-radius: 20px;
            background: #edf1e8;
            color: #596455;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .empty-message {
            min-height: 530px;
            display: grid;
            place-items: center;
            color: #737b70;
            text-align: center;
        }

        @media (max-width: 700px) {
            .toolbar { flex-wrap: wrap; margin-right: 16px; margin-left: 16px; }
            .toolbar form { width: 100%; flex-wrap: wrap; }
            .toolbar select { flex: 1; min-width: 150px; }
            .invoice { padding: 28px 20px; min-height: 700px; }
            .invoice-header, .details-grid { grid-template-columns: 1fr; }
            .invoice-meta { text-align: left; }
            .details-grid { display: grid; }
            th, td { padding-right: 5px; padding-left: 5px; }
        }

        @media print {
            @page { size: A4; margin: 12mm; }
            body { background: #fff; }
            .toolbar { display: none; }
            .invoice {
                width: 100%;
                max-width: none;
                min-height: 0;
                margin: 0;
                padding: 18mm 12mm;
                border: 0;
                box-shadow: none;
            }
            .invoice-footer { margin-top: 25mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <form method="get" action="print.php">
            <select name="order_id" aria-label="Select order" required>
                <option value="">Select an order</option>
                <?php mysqli_data_seek($ordersResult, 0); ?>
                <?php while ($option = mysqli_fetch_assoc($ordersResult)): ?>
                    <option value="<?= (int)$option['order_id'] ?>" <?= $orderId === (int)$option['order_id'] ? 'selected' : '' ?>>
                        #<?= (int)$option['order_id'] ?> - <?= invoice_escape(date('M j, Y', strtotime($option['order_date']))) ?> - <?= invoice_escape(ucfirst($option['status'])) ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <button type="submit">View invoice</button>
        </form>
        <div>
            <?php if ($order): ?>
                <button type="button" onclick="window.print()">Print invoice</button>
            <?php endif; ?>
            <a class="back-link" href="../Admin/orders.php">Back</a>
        </div>
    </div>

    <main class="invoice">
        <?php if (!$order): ?>
            <div class="empty-message">
                <div>
                    <h2>No invoice selected</h2>
                    <p>Choose an order from the list above to preview or print its invoice.</p>
                </div>
            </div>
        <?php else: ?>
            <header class="invoice-header">
                <div class="brand">
                    <span class="brand-mark" aria-hidden="true">R</span>
                    <div>
                        <h1>RestoFlow</h1>
                        <p>Restaurant Management System</p>
                        <p>Customer invoice</p>
                    </div>
                </div>
                <div class="invoice-meta">
                    <strong>Invoice</strong>
                    <p>Invoice No: #<?= str_pad((string)$order['order_id'], 6, '0', STR_PAD_LEFT) ?></p>
                    <p>Date: <?= invoice_escape(date('M j, Y', strtotime($order['order_date']))) ?></p>
                    <p>Time: <?= invoice_escape(date('g:i A', strtotime($order['order_date']))) ?></p>
                </div>
            </header>

            <section class="details-grid">
                <div class="customer-block">
                    <strong>Billed to</strong>
                    <b><?= invoice_escape($order['customer_name'] ?: 'Walk-in Customer') ?></b>
                    <?php if (!empty($order['customer_phone'])): ?><p>Phone: <?= invoice_escape($order['customer_phone']) ?></p><?php endif; ?>
                    <?php if (!empty($order['customer_email'])): ?><p>Email: <?= invoice_escape($order['customer_email']) ?></p><?php endif; ?>
                </div>
                <dl class="detail-list">
                    <dt>Order ID</dt><dd>#<?= (int)$order['order_id'] ?></dd>
                    <dt>Cashier</dt><dd><?= invoice_escape($order['cashier_name']) ?></dd>
                    <dt>Payment</dt><dd><?= invoice_escape($order['payment_method']) ?></dd>
                    <dt>Status</dt><dd><?= invoice_escape(ucfirst($order['status'])) ?></dd>
                </dl>
            </section>

            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Quantity</th>
                        <th>Unit price</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($items): ?>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td class="item-name"><?= invoice_escape($item['food_name']) ?></td>
                                <td><?= number_format((int)$item['quantity']) ?></td>
                                <td>৳<?= number_format((float)$item['unit_price'], 2) ?></td>
                                <td>৳<?= number_format((float)$item['subtotal'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align:center;color:#737b70;">No item details found for this order.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="totals">
                <div class="total-row"><span>Subtotal</span><strong>৳<?= number_format((float)$order['total_amount'], 2) ?></strong></div>
                <div class="total-row"><span>Tax</span><strong>৳0.00</strong></div>
                <div class="total-row grand-total"><span>Total</span><span>৳<?= number_format((float)$order['total_amount'], 2) ?></span></div>
            </div>

            <footer class="invoice-footer">
                <strong>Thank you for dining with RestoFlow!</strong>
                <p class="footer-note">Please keep this invoice for your records.</p>
                <span class="status"><?= invoice_escape($order['status']) ?> order</span>
            </footer>
        <?php endif; ?>
    </main>
</body>
</html>
