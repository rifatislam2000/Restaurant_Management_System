<?php
include("../includes/auth.php");
require_login();
include("../includes/dbconfig.php");

if (!in_array($_SESSION['role'] ?? '', ['admin', 'cashier'], true)) {
    header("Location: ../login.php");
    exit;
}
$isAdmin = $_SESSION['role'] === 'admin';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$error = '';
$success = '';
$editCustomer = null;

function customers_escape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $error = 'Invalid request. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $customerId = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT);

        if ($action === 'add' || $action === 'update') {
            $fullNameInput = $_POST['full_name'] ?? '';
            $phoneInput = $_POST['phone'] ?? '';
            $emailInput = $_POST['email'] ?? '';
            $validFields = is_string($fullNameInput) && is_string($phoneInput) && is_string($emailInput);
            $fullName = is_string($fullNameInput) ? trim($fullNameInput) : '';
            $phone = is_string($phoneInput) ? trim($phoneInput) : '';
            $email = is_string($emailInput) ? trim($emailInput) : '';
            $phone = $phone === '' ? null : $phone;
            $email = $email === '' ? null : $email;

            if (!$validFields) {
                $error = 'Enter valid customer details.';
            } elseif ($action === 'update' && !$isAdmin) {
                $error = 'Only administrators can update customer records.';
            } elseif ($action === 'update' && ($customerId === false || $customerId < 1)) {
                $error = 'Select a valid customer.';
            } elseif ($fullName === '' || strlen($fullName) > 100) {
                $error = 'Enter a customer name of no more than 100 characters.';
            } elseif ($phone !== null && strlen($phone) > 30) {
                $error = 'The phone number must be no more than 30 characters.';
            } elseif ($email !== null && (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                $error = 'Enter a valid email address of no more than 100 characters.';
            } else {
                if ($action === 'add') {
                    $statement = mysqli_prepare(
                        $conn,
                        "INSERT INTO customers (full_name, phone, email) VALUES (?, ?, ?)"
                    );
                    mysqli_stmt_bind_param($statement, 'sss', $fullName, $phone, $email);
                } else {
                    $statement = mysqli_prepare(
                        $conn,
                        "UPDATE customers SET full_name = ?, phone = ?, email = ? WHERE customer_id = ?"
                    );
                    mysqli_stmt_bind_param($statement, 'sssi', $fullName, $phone, $email, $customerId);
                }

                if (mysqli_stmt_execute($statement)) {
                    mysqli_stmt_close($statement);
                    header('Location: customers.php?success=' . ($action === 'add' ? 'added' : 'updated'));
                    exit;
                }

                $error = 'Unable to save customer: ' . mysqli_stmt_error($statement);
                mysqli_stmt_close($statement);
            }
        } elseif ($action === 'delete') {
            if (!$isAdmin) {
                $error = 'Only administrators can delete customer records.';
            } elseif ($customerId === false || $customerId < 1) {
                $error = 'Select a valid customer.';
            } else {
                $statement = mysqli_prepare($conn, "DELETE FROM customers WHERE customer_id = ?");
                mysqli_stmt_bind_param($statement, 'i', $customerId);
                if (mysqli_stmt_execute($statement) && mysqli_stmt_affected_rows($statement) > 0) {
                    mysqli_stmt_close($statement);
                    header('Location: customers.php?success=deleted');
                    exit;
                }
                $error = 'Unable to delete customer. The customer may no longer exist.';
                mysqli_stmt_close($statement);
            }
        } else {
            $error = 'Invalid action.';
        }
    }
}

$messages = [
    'added' => 'Customer added successfully.',
    'updated' => 'Customer updated successfully.',
    'deleted' => 'Customer deleted successfully.',
];
$successCode = $_GET['success'] ?? '';
$success = is_string($successCode) ? ($messages[$successCode] ?? '') : '';

if ($isAdmin && isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId !== false && $editId > 0) {
        $statement = mysqli_prepare(
            $conn,
            "SELECT customer_id, full_name, phone, email FROM customers WHERE customer_id = ?"
        );
        mysqli_stmt_bind_param($statement, 'i', $editId);
        mysqli_stmt_execute($statement);
        $result = mysqli_stmt_get_result($statement);
        $editCustomer = mysqli_fetch_assoc($result) ?: null;
        mysqli_stmt_close($statement);
    }
}

$customersResult = mysqli_query(
    $conn,
    "SELECT c.customer_id, c.full_name, c.phone, c.email, c.created_at,
            (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.customer_id) AS order_count
     FROM customers c
     ORDER BY c.customer_id DESC"
);
if (!$customersResult) {
    die('Unable to load customers: ' . customers_escape(mysqli_error($conn)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <main class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2">Customer Management</h1>
            <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
        </div>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?= customers_escape($success) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= customers_escape($error) ?></div>
        <?php endif; ?>

        <section class="card mb-4">
            <div class="card-header">
                <?= $editCustomer ? 'Edit Customer' : 'Add Customer' ?>
            </div>
            <div class="card-body">
                <form method="post" action="customers.php">
                    <input type="hidden" name="csrf_token" value="<?= customers_escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="<?= $editCustomer ? 'update' : 'add' ?>">
                    <?php if ($editCustomer): ?>
                        <input type="hidden" name="customer_id" value="<?= (int)$editCustomer['customer_id'] ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="full_name">Full name</label>
                            <input class="form-control" id="full_name" name="full_name" type="text"
                                maxlength="100" value="<?= customers_escape($editCustomer['full_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="phone">Phone (optional)</label>
                            <input class="form-control" id="phone" name="phone" type="tel"
                                maxlength="30" value="<?= customers_escape($editCustomer['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="email">Email (optional)</label>
                            <input class="form-control" id="email" name="email" type="email"
                                maxlength="100" value="<?= customers_escape($editCustomer['email'] ?? '') ?>">
                        </div>
                    </div>

                    <button class="btn btn-primary mt-3" type="submit">
                        <?= $editCustomer ? 'Update Customer' : 'Add Customer' ?>
                    </button>
                    <?php if ($editCustomer): ?>
                        <a href="customers.php" class="btn btn-secondary mt-3">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card-header">Customer List</div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Orders</th>
                            <th>Added</th>
                            <?php if ($isAdmin): ?><th>Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($customersResult) > 0): ?>
                            <?php while ($customer = mysqli_fetch_assoc($customersResult)): ?>
                                <tr>
                                    <td><?= (int)$customer['customer_id'] ?></td>
                                    <td><?= customers_escape($customer['full_name']) ?></td>
                                    <td><?= customers_escape($customer['phone'] ?? '—') ?></td>
                                    <td><?= customers_escape($customer['email'] ?? '—') ?></td>
                                    <td><?= (int)$customer['order_count'] ?></td>
                                    <td><?= customers_escape($customer['created_at'] ?? '—') ?></td>
                                    <?php if ($isAdmin): ?>
                                        <td class="text-nowrap">
                                            <a class="btn btn-sm btn-warning" href="customers.php?edit=<?= (int)$customer['customer_id'] ?>">Edit</a>
                                            <form method="post" action="customers.php" class="d-inline"
                                                onsubmit="return confirm('Delete this customer? Existing orders will remain as walk-in orders.');">
                                                <input type="hidden" name="csrf_token" value="<?= customers_escape($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="customer_id" value="<?= (int)$customer['customer_id'] ?>">
                                                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td class="text-center" colspan="<?= $isAdmin ? 7 : 6 ?>">No customers found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>