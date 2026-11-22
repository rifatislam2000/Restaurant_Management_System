<?php
include("../includes/auth.php");
require_login();
include("../includes/dbconfig.php");

if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit;
}

if (empty($_SESSION['foods_csrf'])) {
    $_SESSION['foods_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['foods_csrf'];
$error = '';
$editFood = null;

function foods_escape($value)
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
        $foodId = filter_var($_POST['food_id'] ?? '', FILTER_VALIDATE_INT);

        if ($action === 'delete') {
            if ($foodId === false || $foodId < 1) {
                $error = 'Select a valid food item.';
            } else {
                $statement = mysqli_prepare($conn, "DELETE FROM foods WHERE food_id = ?");
                mysqli_stmt_bind_param($statement, 'i', $foodId);
                if (mysqli_stmt_execute($statement) && mysqli_stmt_affected_rows($statement) > 0) {
                    mysqli_stmt_close($statement);
                    header('Location: foods.php?success=deleted');
                    exit;
                }
                $error = mysqli_stmt_errno($statement) === 1451
                    ? 'This food cannot be deleted because it is used in an order.'
                    : 'Unable to delete food. It may no longer exist.';
                mysqli_stmt_close($statement);
            }
        } elseif ($action === 'save') {
            $foodName = trim((string)($_POST['food_name'] ?? ''));
            $categoryId = filter_var($_POST['category_id'] ?? '', FILTER_VALIDATE_INT);
            $description = trim((string)($_POST['description'] ?? ''));
            $price = filter_var($_POST['price'] ?? '', FILTER_VALIDATE_FLOAT);
            $image = trim((string)($_POST['image'] ?? ''));
            $available = ($_POST['is_available'] ?? '') === '1' ? 1 : 0;
            $isUpdate = $foodId !== false && $foodId > 0;

            if ($foodName === '' || strlen($foodName) > 150) {
                $error = 'Enter a food name of no more than 150 characters.';
            } elseif ($categoryId === false || $categoryId < 1) {
                $error = 'Choose a valid category.';
            } elseif ($price === false || $price < 0 || !is_finite((float)$price)) {
                $error = 'Enter a valid non-negative price.';
            } elseif (strlen($image) > 255) {
                $error = 'The image path must be no more than 255 characters.';
            } else {
                if ($isUpdate) {
                    $statement = mysqli_prepare(
                        $conn,
                        "UPDATE foods SET category_id = ?, food_name = ?, description = ?, price = ?, image = ?, is_available = ?
                         WHERE food_id = ?"
                    );
                    mysqli_stmt_bind_param($statement, 'issdsii', $categoryId, $foodName, $description, $price, $image, $available, $foodId);
                } else {
                    $statement = mysqli_prepare(
                        $conn,
                        "INSERT INTO foods (category_id, food_name, description, price, image, is_available)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    mysqli_stmt_bind_param($statement, 'issdsi', $categoryId, $foodName, $description, $price, $image, $available);
                }

                if (mysqli_stmt_execute($statement)) {
                    mysqli_stmt_close($statement);
                    header('Location: foods.php?success=' . ($isUpdate ? 'updated' : 'added'));
                    exit;
                }
                $error = 'Unable to save food: ' . mysqli_stmt_error($statement);
                mysqli_stmt_close($statement);
            }
        } else {
            $error = 'Invalid action.';
        }
    }
}

$messages = ['added' => 'Food added successfully.', 'updated' => 'Food updated successfully.', 'deleted' => 'Food deleted successfully.'];
$success = $messages[$_GET['success'] ?? ''] ?? '';

if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId !== false && $editId > 0) {
        $statement = mysqli_prepare($conn, "SELECT food_id, category_id, food_name, description, price, image, is_available FROM foods WHERE food_id = ?");
        mysqli_stmt_bind_param($statement, 'i', $editId);
        mysqli_stmt_execute($statement);
        $result = mysqli_stmt_get_result($statement);
        $editFood = mysqli_fetch_assoc($result) ?: null;
        mysqli_stmt_close($statement);
    }
}

$categoriesResult = mysqli_query($conn, "SELECT category_id, category_name FROM categories ORDER BY category_name");
$foodsResult = mysqli_query(
    $conn,
    "SELECT f.food_id, f.food_name, f.price, f.is_available, c.category_name
     FROM foods f INNER JOIN categories c ON c.category_id = f.category_id
     ORDER BY f.food_id DESC"
);
if (!$categoriesResult || !$foodsResult) {
    die('Unable to load food data: ' . foods_escape(mysqli_error($conn)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Food Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">Food Management</h1>
        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>
    <?php if ($success !== ''): ?><div class="alert alert-success"><?= foods_escape($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= foods_escape($error) ?></div><?php endif; ?>
    <section class="card mb-4">
        <div class="card-header"><?= $editFood ? 'Edit Food' : 'Add Food' ?></div>
        <div class="card-body">
            <?php if (mysqli_num_rows($categoriesResult) === 0): ?>
                <p class="mb-0">Add a category before creating food items.</p>
            <?php else: ?>
                <form method="post" action="foods.php">
                    <input type="hidden" name="csrf_token" value="<?= foods_escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="food_id" value="<?= (int)($editFood['food_id'] ?? 0) ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="food_name">Food name</label>
                            <input class="form-control" id="food_name" name="food_name" maxlength="150" required value="<?= foods_escape($editFood['food_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="category_id">Category</label>
                            <select class="form-select" id="category_id" name="category_id" required>
                                <option value="">Choose category</option>
                                <?php while ($category = mysqli_fetch_assoc($categoriesResult)): ?>
                                    <option value="<?= (int)$category['category_id'] ?>" <?= (int)($editFood['category_id'] ?? 0) === (int)$category['category_id'] ? 'selected' : '' ?>>
                                        <?= foods_escape($category['category_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="price">Price</label>
                            <input class="form-control" id="price" name="price" type="number" min="0" step="0.01" required value="<?= foods_escape($editFood['price'] ?? '') ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="image">Image path (optional)</label>
                            <input class="form-control" id="image" name="image" maxlength="255" value="<?= foods_escape($editFood['image'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="description">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="2"><?= foods_escape($editFood['description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="is_available">Availability</label>
                            <select class="form-select" id="is_available" name="is_available">
                                <option value="1" <?= (int)($editFood['is_available'] ?? 1) === 1 ? 'selected' : '' ?>>Available</option>
                                <option value="0" <?= isset($editFood['is_available']) && (int)$editFood['is_available'] === 0 ? 'selected' : '' ?>>Unavailable</option>
                            </select>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" type="submit"><?= $editFood ? 'Update Food' : 'Add Food' ?></button>
                    <?php if ($editFood): ?><a href="foods.php" class="btn btn-secondary mt-3">Cancel</a><?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </section>
    <section class="card">
        <div class="card-header">Food List</div>
        <div class="table-responsive">
            <table class="table table-striped table-bordered mb-0">
                <thead class="table-dark"><tr><th>ID</th><th>Name</th><th>Category</th><th>Price</th><th>Availability</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (mysqli_num_rows($foodsResult) > 0): ?>
                    <?php while ($food = mysqli_fetch_assoc($foodsResult)): ?>
                        <tr>
                            <td><?= (int)$food['food_id'] ?></td><td><?= foods_escape($food['food_name']) ?></td>
                            <td><?= foods_escape($food['category_name']) ?></td><td><?= number_format((float)$food['price'], 2) ?></td>
                            <td><?= (int)$food['is_available'] === 1 ? 'Available' : 'Unavailable' ?></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-warning" href="foods.php?edit=<?= (int)$food['food_id'] ?>">Edit</a>
                                <form method="post" action="foods.php" class="d-inline" onsubmit="return confirm('Delete this food item?');">
                                    <input type="hidden" name="csrf_token" value="<?= foods_escape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="food_id" value="<?= (int)$food['food_id'] ?>">
                                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td class="text-center" colspan="6">No foods found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>