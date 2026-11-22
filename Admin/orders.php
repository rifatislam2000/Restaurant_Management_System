<?php
include("../includes/auth.php");
require_login();
include("../includes/dbconfig.php");

if (!in_array($_SESSION['role'] ?? '', ['admin', 'cashier'], true)) {
    header("Location: ../login.php");
    exit;
}

if (empty($_SESSION['orders_csrf'])) {
    $_SESSION['orders_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['orders_csrf'];
$error = '';
$editOrder = null;

function orders_escape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        http_response_code(400);
        $error = 'The form expired. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $orderId = filter_var($_POST['order_id'] ?? '', FILTER_VALIDATE_INT);

        if ($action === 'delete') {
            if ($orderId === false || $orderId < 1) {
                $error = 'Select a valid order.';
            } else {
                mysqli_begin_transaction($conn);
                $statement = mysqli_prepare($conn, "DELETE FROM orders WHERE order_id = ?");
                mysqli_stmt_bind_param($statement, 'i', $orderId);
                $deleted = mysqli_stmt_execute($statement) && mysqli_stmt_affected_rows($statement) > 0;
                mysqli_stmt_close($statement);
                if ($deleted && mysqli_commit($conn)) {
                    header('Location: orders.php?success=deleted');
                    exit;
                }
                mysqli_rollback($conn);
                $error = 'Unable to delete the order.';
            }
        } elseif ($action === 'create') {
            $customerInput = $_POST['customer_id'] ?? '';
            $customerId = $customerInput === '' ? null : filter_var($customerInput, FILTER_VALIDATE_INT);
            $paymentMethod = $_POST['payment_method'] ?? '';
            $foodIds = $_POST['food_id'] ?? [];
            $quantities = $_POST['quantity'] ?? [];

            if (($customerInput !== '' && ($customerId === false || $customerId < 1))
                || !is_string($paymentMethod)
                || !in_array($paymentMethod, ['Cash', 'Card', 'bKash', 'Nagad'], true)
                || !is_array($foodIds) || !is_array($quantities)
                || count($foodIds) < 1 || count($foodIds) !== count($quantities)) {
                $error = 'Enter a valid customer, payment method, and at least one food item.';
            } else {
                $items = [];
                foreach ($foodIds as $index => $foodInput) {
                    $foodId = filter_var($foodInput, FILTER_VALIDATE_INT);
                    $quantity = filter_var($quantities[$index] ?? '', FILTER_VALIDATE_INT);
                    if ($foodId === false || $foodId < 1 || $quantity === false || $quantity < 1) {
                        $error = 'Choose valid food items and positive whole-number quantities.';
                        break;
                    }
                    $items[] = ['food_id' => $foodId, 'quantity' => $quantity];
                }

                if ($error === '') {
                    mysqli_begin_transaction($conn);
                    $userId = (int)$_SESSION['user_id'];
                    $insertOrder = mysqli_prepare(
                        $conn,
                        "INSERT INTO orders (user_id, customer_id, total_amount, payment_method, status)
                         VALUES (?, ?, 0, ?, 'completed')"
                    );
                    mysqli_stmt_bind_param($insertOrder, 'iis', $userId, $customerId, $paymentMethod);
                    $created = mysqli_stmt_execute($insertOrder);
                    $newOrderId = (int)mysqli_insert_id($conn);
                    mysqli_stmt_close($insertOrder);

                    if ($created) {
                        $findFood = mysqli_prepare($conn, "SELECT price FROM foods WHERE food_id = ? AND is_available = 1");
                        $insertItem = mysqli_prepare(
                            $conn,
                            "INSERT INTO order_items (order_id, food_id, quantity, unit_price, subtotal)
                             VALUES (?, ?, ?, ?, ?)"
                        );
                        $total = 0.0;
                        foreach ($items as $item) {
                            mysqli_stmt_bind_param($findFood, 'i', $item['food_id']);
                            if (!mysqli_stmt_execute($findFood)) {
                                $created = false;
                                break;
                            }
                            mysqli_stmt_bind_result($findFood, $unitPrice);
                            if (!mysqli_stmt_fetch($findFood)) {
                                mysqli_stmt_free_result($findFood);
                                $created = false;
                                $error = 'One of the selected foods is unavailable or no longer exists.';
                                break;
                            }
                            mysqli_stmt_free_result($findFood);
                            $unitPrice = (float)$unitPrice;
                            $subtotal = $unitPrice * $item['quantity'];
                            mysqli_stmt_bind_param(
                                $insertItem,
                                'iiidd',
                                $newOrderId,
                                $item['food_id'],
                                $item['quantity'],
                                $unitPrice,
                                $subtotal
                            );
                            if (!mysqli_stmt_execute($insertItem)) {
                                $created = false;
                                break;
                            }
                            $total += $subtotal;
                        }
                        mysqli_stmt_close($findFood);
                        mysqli_stmt_close($insertItem);

                        if ($created) {
                            $updateTotal = mysqli_prepare($conn, "UPDATE orders SET total_amount = ? WHERE order_id = ?");
                            mysqli_stmt_bind_param($updateTotal, 'di', $total, $newOrderId);
                            $created = mysqli_stmt_execute($updateTotal);
                            mysqli_stmt_close($updateTotal);
                        }
                    }

                    if ($created && mysqli_commit($conn)) {
                        header('Location: orders.php?success=added');
                        exit;
                    }
                    mysqli_rollback($conn);
                    if ($error === '') {
                        $error = 'Unable to save order: ' . orders_escape(mysqli_error($conn));
                    }
                }
            }
        } elseif ($action === 'update') {
            $customerInput = $_POST['customer_id'] ?? '';
            $customerId = $customerInput === '' ? null : filter_var($customerInput, FILTER_VALIDATE_INT);
            $paymentMethod = $_POST['payment_method'] ?? '';
            $status = $_POST['status'] ?? '';
            if ($orderId === false || $orderId < 1
                || ($customerInput !== '' && ($customerId === false || $customerId < 1))
                || !is_string($paymentMethod) || !in_array($paymentMethod, ['Cash', 'Card', 'bKash', 'Nagad'], true)
                || !is_string($status) || !in_array($status, ['pending', 'completed', 'cancelled'], true)) {
                $error = 'Enter valid order details.';
            } else {
                if ($status === 'completed') {
                    $countStatement = mysqli_prepare($conn, "SELECT COUNT(*) FROM order_items WHERE order_id = ?");
                    mysqli_stmt_bind_param($countStatement, 'i', $orderId);
                    mysqli_stmt_execute($countStatement);
                    mysqli_stmt_bind_result($countStatement, $itemCount);
                    mysqli_stmt_fetch($countStatement);
                    mysqli_stmt_close($countStatement);

                    if ((int)$itemCount < 1) {
                        $error = 'An order must contain at least one food item before it can be completed.';
                    }
                }

                if ($error === '') {
                    $statement = mysqli_prepare(
                        $conn,
                        "UPDATE orders SET customer_id = ?, payment_method = ?, status = ? WHERE order_id = ?"
                    );
                    mysqli_stmt_bind_param($statement, 'issi', $customerId, $paymentMethod, $status, $orderId);
                    if (mysqli_stmt_execute($statement)) {
                        mysqli_stmt_close($statement);
                        header('Location: orders.php?success=updated');
                        exit;
                    }
                    $error = 'Unable to update order: ' . mysqli_stmt_error($statement);
                    mysqli_stmt_close($statement);
                }
            }
        } else {
            $error = 'Invalid action.';
        }
    }
}

$messages = ['added' => 'Order created successfully.', 'updated' => 'Order updated successfully.', 'deleted' => 'Order deleted successfully.'];
$success = $messages[$_GET['success'] ?? ''] ?? '';

if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId !== false && $editId > 0) {
        $statement = mysqli_prepare($conn, "SELECT order_id, customer_id, payment_method, status FROM orders WHERE order_id = ?");
        mysqli_stmt_bind_param($statement, 'i', $editId);
        mysqli_stmt_execute($statement);
        $result = mysqli_stmt_get_result($statement);
        $editOrder = mysqli_fetch_assoc($result) ?: null;
        mysqli_stmt_close($statement);
    }
}

$customersResult = mysqli_query($conn, "SELECT customer_id, full_name FROM customers ORDER BY full_name");
$foodsResult = mysqli_query($conn, "SELECT food_id, food_name, price FROM foods WHERE is_available = 1 ORDER BY food_name");
$ordersResult = mysqli_query(
    $conn,
    "SELECT o.order_id, o.order_date, o.total_amount, o.payment_method, o.status,
            u.full_name AS cashier_name, c.full_name AS customer_name,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS item_count
     FROM orders o
     INNER JOIN users u ON u.user_id = o.user_id
     LEFT JOIN customers c ON c.customer_id = o.customer_id
     ORDER BY o.order_id DESC"
);
if (!$customersResult || !$foodsResult || !$ordersResult) {
    die('Unable to load order data: ' . orders_escape(mysqli_error($conn)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">Order Management</h1>
        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>
    <?php if ($success !== ''): ?><div class="alert alert-success"><?= orders_escape($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= orders_escape($error) ?></div><?php endif; ?>
    <section class="card mb-4">
        <div class="card-header"><?= $editOrder ? 'Edit Order' : 'Create Order' ?></div>
        <div class="card-body">
            <?php if ($editOrder): ?>
                <form method="post" action="orders.php">
                    <input type="hidden" name="csrf_token" value="<?= orders_escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="order_id" value="<?= (int)$editOrder['order_id'] ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="customer_id">Customer (optional)</label>
                            <select class="form-select" name="customer_id" id="customer_id">
                                <option value="">Walk-in</option>
                                <?php while ($customer = mysqli_fetch_assoc($customersResult)): ?>
                                    <option value="<?= (int)$customer['customer_id'] ?>" <?= (int)($editOrder['customer_id'] ?? 0) === (int)$customer['customer_id'] ? 'selected' : '' ?>><?= orders_escape($customer['full_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="payment_method">Payment method</label>
                            <select class="form-select" name="payment_method" id="payment_method" required>
                                <?php foreach (['Cash', 'Card', 'bKash', 'Nagad'] as $method): ?>
                                    <option value="<?= orders_escape($method) ?>" <?= $editOrder['payment_method'] === $method ? 'selected' : '' ?>><?= orders_escape($method) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="status">Status</label>
                            <select class="form-select" name="status" id="status" required>
                                <?php foreach (['pending', 'completed', 'cancelled'] as $status): ?>
                                    <option value="<?= orders_escape($status) ?>" <?= $editOrder['status'] === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" type="submit">Update Order</button>
                    <a href="orders.php" class="btn btn-secondary mt-3">Cancel</a>
                </form>
            <?php else: ?>
                <form method="post" action="orders.php">
                    <input type="hidden" name="csrf_token" value="<?= orders_escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label" for="customer_id">Customer (optional)</label>
                            <select class="form-select" name="customer_id" id="customer_id">
                                <option value="">Walk-in</option>
                                <?php while ($customer = mysqli_fetch_assoc($customersResult)): ?>
                                    <option value="<?= (int)$customer['customer_id'] ?>"><?= orders_escape($customer['full_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="payment_method">Payment method</label>
                            <select class="form-select" name="payment_method" id="payment_method" required>
                                <option value="Cash">Cash</option><option value="Card">Card</option>
                                <option value="bKash">bKash</option><option value="Nagad">Nagad</option>
                            </select>
                        </div>
                    </div>
                    <?php if (mysqli_num_rows($foodsResult) === 0): ?>
                        <p>Add an available food item before creating an order.</p>
                    <?php else: ?>
                        <div id="order-items">
                            <div class="row g-2 align-items-end mb-2 order-item-row">
                                <div class="col-md-8">
                                    <label class="form-label">Food</label>
                                    <select class="form-select" name="food_id[]" required>
                                        <option value="">Choose food</option>
                                        <?php while ($food = mysqli_fetch_assoc($foodsResult)): ?>
                                            <option value="<?= (int)$food['food_id'] ?>"><?= orders_escape($food['food_name']) ?> (<?= number_format((float)$food['price'], 2) ?>)</option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-3"><label class="form-label">Quantity</label><input class="form-control" type="number" name="quantity[]" min="1" step="1" value="1" required></div>
                                <div class="col-md-1"><button class="btn btn-outline-danger remove-item" type="button" disabled>Remove</button></div>
                            </div>
                        </div>
                        <button class="btn btn-outline-primary" id="add-item" type="button">Add another food</button>
                        <button class="btn btn-primary" type="submit">Create Order</button>
                    <?php endif; ?>
                </form>
                <?php if (mysqli_num_rows($foodsResult) > 0): ?>
                    <template id="order-item-template">
                        <div class="row g-2 align-items-end mb-2 order-item-row">
                            <div class="col-md-8">
                                <label class="form-label">Food</label>
                                <select class="form-select" name="food_id[]" required>
                                    <option value="">Choose food</option>
                                    <?php mysqli_data_seek($foodsResult, 0); ?>
                                    <?php while ($food = mysqli_fetch_assoc($foodsResult)): ?>
                                        <option value="<?= (int)$food['food_id'] ?>"><?= orders_escape($food['food_name']) ?> (<?= number_format((float)$food['price'], 2) ?>)</option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-3"><label class="form-label">Quantity</label><input class="form-control" type="number" name="quantity[]" min="1" step="1" value="1" required></div>
                            <div class="col-md-1"><button class="btn btn-outline-danger remove-item" type="button">Remove</button></div>
                        </div>
                    </template>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
    <section class="card">
        <div class="card-header">Order History</div>
        <div class="table-responsive">
            <table class="table table-striped table-bordered mb-0">
                <thead class="table-dark"><tr><th>Order</th><th>Date</th><th>Customer</th><th>Cashier</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (mysqli_num_rows($ordersResult) > 0): ?>
                    <?php while ($order = mysqli_fetch_assoc($ordersResult)): ?>
                        <tr>
                            <td>#<?= (int)$order['order_id'] ?></td><td><?= orders_escape($order['order_date']) ?></td>
                            <td><?= orders_escape($order['customer_name'] ?? 'Walk-in') ?></td><td><?= orders_escape($order['cashier_name']) ?></td>
                            <td><?= (int)$order['item_count'] ?></td><td><?= number_format((float)$order['total_amount'], 2) ?></td>
                            <td><?= orders_escape($order['payment_method']) ?></td><td><?= orders_escape(ucfirst($order['status'])) ?></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-warning" href="orders.php?edit=<?= (int)$order['order_id'] ?>">Edit</a>
                                <a class="btn btn-sm btn-info" href="order_items.php?order_id=<?= (int)$order['order_id'] ?>">Items</a>
                                <form method="post" action="orders.php" class="d-inline" onsubmit="return confirm('Delete this order and its items?');">
                                    <input type="hidden" name="csrf_token" value="<?= orders_escape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="order_id" value="<?= (int)$order['order_id'] ?>">
                                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td class="text-center" colspan="9">No orders found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script>
const orderItems = document.getElementById('order-items');
const itemTemplate = document.getElementById('order-item-template');
const addItemButton = document.getElementById('add-item');

if (orderItems && itemTemplate && addItemButton) {
    addItemButton.addEventListener('click', () => {
        orderItems.appendChild(itemTemplate.content.cloneNode(true));
        updateRemoveButtons();
    });
    orderItems.addEventListener('click', (event) => {
        if (event.target.classList.contains('remove-item')) {
            event.target.closest('.order-item-row').remove();
            updateRemoveButtons();
        }
    });
}

function updateRemoveButtons() {
    if (!orderItems) return;
    const rows = orderItems.querySelectorAll('.order-item-row');
    rows.forEach((row) => {
        row.querySelector('.remove-item').disabled = rows.length === 1;
    });
}
</script>
</body>
</html>