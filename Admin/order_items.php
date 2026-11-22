<?php
include("../includes/auth.php");
require_login();
include("../includes/dbconfig.php");

if (!in_array($_SESSION['role'] ?? '', ['admin', 'cashier'], true)) {
    header("Location: ../login.php");
    exit;
}

if (empty($_SESSION['order_items_csrf'])) {
    $_SESSION['order_items_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['order_items_csrf'];
$error = '';
$editItem = null;

function order_items_escape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function order_items_recalculate($conn, $orderId)
{
    $statement = mysqli_prepare(
        $conn,
        "UPDATE orders
         SET total_amount = (SELECT COALESCE(SUM(subtotal), 0) FROM order_items WHERE order_id = ?)
         WHERE order_id = ?"
    );
    mysqli_stmt_bind_param($statement, 'ii', $orderId, $orderId);
    $updated = mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
    return $updated;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        http_response_code(400);
        $error = 'The form expired. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $itemId = filter_var($_POST['order_item_id'] ?? '', FILTER_VALIDATE_INT);

        if ($action === 'delete') {
            if ($itemId === false || $itemId < 1) {
                $error = 'Select a valid order item.';
            } else {
                $statement = mysqli_prepare($conn, "SELECT order_id FROM order_items WHERE order_item_id = ?");
                mysqli_stmt_bind_param($statement, 'i', $itemId);
                mysqli_stmt_execute($statement);
                mysqli_stmt_bind_result($statement, $oldOrderId);
                $found = mysqli_stmt_fetch($statement);
                mysqli_stmt_close($statement);

                if (!$found) {
                    $error = 'Order item not found.';
                } else {
                    $countStatement = mysqli_prepare($conn, "SELECT COUNT(*) FROM order_items WHERE order_id = ?");
                    mysqli_stmt_bind_param($countStatement, 'i', $oldOrderId);
                    mysqli_stmt_execute($countStatement);
                    mysqli_stmt_bind_result($countStatement, $itemCount);
                    mysqli_stmt_fetch($countStatement);
                    mysqli_stmt_close($countStatement);

                    $statusStatement = mysqli_prepare($conn, "SELECT status FROM orders WHERE order_id = ?");
                    mysqli_stmt_bind_param($statusStatement, 'i', $oldOrderId);
                    mysqli_stmt_execute($statusStatement);
                    mysqli_stmt_bind_result($statusStatement, $oldOrderStatus);
                    mysqli_stmt_fetch($statusStatement);
                    mysqli_stmt_close($statusStatement);

                    if ((int)$itemCount <= 1 && $oldOrderStatus !== 'cancelled') {
                        $error = 'An active order must keep at least one food item.';
                    } else {
                        mysqli_begin_transaction($conn);
                        $deleteStatement = mysqli_prepare($conn, "DELETE FROM order_items WHERE order_item_id = ?");
                        mysqli_stmt_bind_param($deleteStatement, 'i', $itemId);
                        $deleted = mysqli_stmt_execute($deleteStatement) && mysqli_stmt_affected_rows($deleteStatement) > 0;
                        mysqli_stmt_close($deleteStatement);
                        if ($deleted && order_items_recalculate($conn, (int)$oldOrderId) && mysqli_commit($conn)) {
                            header('Location: order_items.php?success=deleted');
                            exit;
                        }
                        mysqli_rollback($conn);
                        $error = 'Unable to delete order item.';
                    }
                }
            }
        } elseif ($action === 'save') {
            $orderId = filter_var($_POST['order_id'] ?? '', FILTER_VALIDATE_INT);
            $foodId = filter_var($_POST['food_id'] ?? '', FILTER_VALIDATE_INT);
            $quantity = filter_var($_POST['quantity'] ?? '', FILTER_VALIDATE_INT);
            $isUpdate = $itemId !== false && $itemId > 0;

            if ($orderId === false || $orderId < 1 || $foodId === false || $foodId < 1 || $quantity === false || $quantity < 1) {
                $error = 'Choose a valid order and food item, and enter a positive whole-number quantity.';
            } else {
                $orderStatement = mysqli_prepare($conn, "SELECT status FROM orders WHERE order_id = ?");
                mysqli_stmt_bind_param($orderStatement, 'i', $orderId);
                mysqli_stmt_execute($orderStatement);
                mysqli_stmt_bind_result($orderStatement, $orderStatus);
                $orderExists = mysqli_stmt_fetch($orderStatement);
                mysqli_stmt_close($orderStatement);

                if (!$orderExists || $orderStatus === 'cancelled') {
                    $error = 'Choose an existing order that is not cancelled.';
                } else {
                    $unitPrice = null;
                    $oldOrderId = null;
                    $oldFoodId = null;

                    if ($isUpdate) {
                        $oldStatement = mysqli_prepare(
                            $conn,
                            "SELECT order_id, food_id, unit_price FROM order_items WHERE order_item_id = ?"
                        );
                        mysqli_stmt_bind_param($oldStatement, 'i', $itemId);
                        mysqli_stmt_execute($oldStatement);
                        mysqli_stmt_bind_result($oldStatement, $oldOrderId, $oldFoodId, $savedPrice);
                        if (mysqli_stmt_fetch($oldStatement)) {
                            if ((int)$oldFoodId === (int)$foodId) {
                                $unitPrice = (float)$savedPrice;
                            }
                        } else {
                            $error = 'Order item not found.';
                        }
                        mysqli_stmt_close($oldStatement);
                    }

                    if ($error === '' && $unitPrice === null) {
                        $foodStatement = mysqli_prepare(
                            $conn,
                            "SELECT price FROM foods WHERE food_id = ? AND is_available = 1"
                        );
                        mysqli_stmt_bind_param($foodStatement, 'i', $foodId);
                        mysqli_stmt_execute($foodStatement);
                        mysqli_stmt_bind_result($foodStatement, $currentPrice);
                        if (mysqli_stmt_fetch($foodStatement)) {
                            $unitPrice = (float)$currentPrice;
                        } else {
                            $error = 'Choose an available food item.';
                        }
                        mysqli_stmt_close($foodStatement);
                    }

                    if ($error === '') {
                        $sourceStatus = null;
                        if ($isUpdate && (int)$oldOrderId !== (int)$orderId) {
                            $sourceStatusStatement = mysqli_prepare($conn, "SELECT status FROM orders WHERE order_id = ?");
                            mysqli_stmt_bind_param($sourceStatusStatement, 'i', $oldOrderId);
                            mysqli_stmt_execute($sourceStatusStatement);
                            mysqli_stmt_bind_result($sourceStatusStatement, $sourceStatus);
                            mysqli_stmt_fetch($sourceStatusStatement);
                            mysqli_stmt_close($sourceStatusStatement);

                            $countStatement = mysqli_prepare($conn, "SELECT COUNT(*) FROM order_items WHERE order_id = ?");
                            mysqli_stmt_bind_param($countStatement, 'i', $oldOrderId);
                            mysqli_stmt_execute($countStatement);
                            mysqli_stmt_bind_result($countStatement, $sourceItemCount);
                            mysqli_stmt_fetch($countStatement);
                            mysqli_stmt_close($countStatement);

                            if ((int)$sourceItemCount <= 1 && $sourceStatus !== 'cancelled') {
                                $error = 'An active order must keep at least one food item.';
                            }
                        }
                    }

                    if ($error === '') {
                        $subtotal = $unitPrice * $quantity;
                        mysqli_begin_transaction($conn);
                        if ($isUpdate) {
                            $saveStatement = mysqli_prepare(
                                $conn,
                                "UPDATE order_items SET order_id = ?, food_id = ?, quantity = ?, unit_price = ?, subtotal = ?
                                 WHERE order_item_id = ?"
                            );
                            mysqli_stmt_bind_param($saveStatement, 'iiiddi', $orderId, $foodId, $quantity, $unitPrice, $subtotal, $itemId);
                        } else {
                            $saveStatement = mysqli_prepare(
                                $conn,
                                "INSERT INTO order_items (order_id, food_id, quantity, unit_price, subtotal)
                                 VALUES (?, ?, ?, ?, ?)"
                            );
                            mysqli_stmt_bind_param($saveStatement, 'iiidd', $orderId, $foodId, $quantity, $unitPrice, $subtotal);
                        }
                        $saved = mysqli_stmt_execute($saveStatement);
                        mysqli_stmt_close($saveStatement);
                        $saved = $saved && order_items_recalculate($conn, (int)$orderId);
                        if ($isUpdate && (int)$oldOrderId !== (int)$orderId) {
                            $saved = $saved && order_items_recalculate($conn, (int)$oldOrderId);
                        }
                        if ($saved && mysqli_commit($conn)) {
                            header('Location: order_items.php?success=' . ($isUpdate ? 'updated' : 'added'));
                            exit;
                        }
                        mysqli_rollback($conn);
                        $error = 'Unable to save order item: ' . order_items_escape(mysqli_error($conn));
                    }
                }
            }
        } else {
            $error = 'Invalid action.';
        }
    }
}

$messages = ['added' => 'Order item added successfully.', 'updated' => 'Order item updated successfully.', 'deleted' => 'Order item deleted successfully.'];
$success = $messages[$_GET['success'] ?? ''] ?? '';

if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId !== false && $editId > 0) {
        $statement = mysqli_prepare($conn, "SELECT order_item_id, order_id, food_id, quantity FROM order_items WHERE order_item_id = ?");
        mysqli_stmt_bind_param($statement, 'i', $editId);
        mysqli_stmt_execute($statement);
        $result = mysqli_stmt_get_result($statement);
        $editItem = mysqli_fetch_assoc($result) ?: null;
        mysqli_stmt_close($statement);
    }
}

$ordersResult = mysqli_query($conn, "SELECT order_id FROM orders WHERE status <> 'cancelled' ORDER BY order_id DESC");
$foodsResult = mysqli_query($conn, "SELECT food_id, food_name, is_available FROM foods ORDER BY food_name");
$itemsResult = mysqli_query(
    $conn,
    "SELECT oi.order_item_id, oi.order_id, oi.food_id, oi.quantity, oi.unit_price, oi.subtotal,
            f.food_name
     FROM order_items oi INNER JOIN foods f ON f.food_id = oi.food_id
     ORDER BY oi.order_item_id DESC"
);
if (!$ordersResult || !$foodsResult || !$itemsResult) {
    die('Unable to load order items: ' . order_items_escape(mysqli_error($conn)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order Item Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">Order Item Management</h1>
        <a href="orders.php" class="btn btn-secondary">Back to Orders</a>
    </div>
    <?php if ($success !== ''): ?><div class="alert alert-success"><?= order_items_escape($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= order_items_escape($error) ?></div><?php endif; ?>
    <section class="card mb-4">
        <div class="card-header"><?= $editItem ? 'Edit Order Item' : 'Add Order Item' ?></div>
        <div class="card-body">
            <?php if (mysqli_num_rows($ordersResult) === 0): ?>
                <p class="mb-0">Create a non-cancelled order before adding items.</p>
            <?php else: ?>
                <form method="post" action="order_items.php">
                    <input type="hidden" name="csrf_token" value="<?= order_items_escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="order_item_id" value="<?= (int)($editItem['order_item_id'] ?? 0) ?>">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label" for="order_id">Order</label>
                            <select class="form-select" id="order_id" name="order_id" required>
                                <option value="">Choose order</option>
                                <?php while ($order = mysqli_fetch_assoc($ordersResult)): ?>
                                    <option value="<?= (int)$order['order_id'] ?>" <?= (int)($editItem['order_id'] ?? ($_GET['order_id'] ?? 0)) === (int)$order['order_id'] ? 'selected' : '' ?>>#<?= (int)$order['order_id'] ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="food_id">Food</label>
                            <select class="form-select" id="food_id" name="food_id" required>
                                <option value="">Choose food</option>
                                <?php while ($food = mysqli_fetch_assoc($foodsResult)): ?>
                                    <option value="<?= (int)$food['food_id'] ?>" <?= (int)($editItem['food_id'] ?? 0) === (int)$food['food_id'] ? 'selected' : '' ?>>
                                        <?= order_items_escape($food['food_name']) ?><?= (int)$food['is_available'] === 1 ? '' : ' (Unavailable)' ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="quantity">Quantity</label>
                            <input class="form-control" id="quantity" name="quantity" type="number" min="1" step="1" required value="<?= (int)($editItem['quantity'] ?? 1) ?>">
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" type="submit"><?= $editItem ? 'Update Item' : 'Add Item' ?></button>
                    <?php if ($editItem): ?><a href="order_items.php" class="btn btn-secondary mt-3">Cancel</a><?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </section>
    <section class="card">
        <div class="card-header">Order Items</div>
        <div class="table-responsive">
            <table class="table table-striped table-bordered mb-0">
                <thead class="table-dark"><tr><th>ID</th><th>Order</th><th>Food</th><th>Quantity</th><th>Unit Price</th><th>Subtotal</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (mysqli_num_rows($itemsResult) > 0): ?>
                    <?php while ($item = mysqli_fetch_assoc($itemsResult)): ?>
                        <tr>
                            <td><?= (int)$item['order_item_id'] ?></td><td>#<?= (int)$item['order_id'] ?></td>
                            <td><?= order_items_escape($item['food_name']) ?></td><td><?= (int)$item['quantity'] ?></td>
                            <td><?= number_format((float)$item['unit_price'], 2) ?></td><td><?= number_format((float)$item['subtotal'], 2) ?></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-warning" href="order_items.php?edit=<?= (int)$item['order_item_id'] ?>">Edit</a>
                                <form method="post" action="order_items.php" class="d-inline" onsubmit="return confirm('Delete this order item?');">
                                    <input type="hidden" name="csrf_token" value="<?= order_items_escape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="order_item_id" value="<?= (int)$item['order_item_id'] ?>">
                                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td class="text-center" colspan="7">No order items found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>