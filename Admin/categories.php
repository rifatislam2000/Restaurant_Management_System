<?php
include("../includes/auth.php");
require_login();

include("../includes/dbconfig.php");


if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$error = "";
$success = "";

// Escape output
function category_escape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


// ADD, UPDATE AND DELETE


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Check CSRF token
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $submittedToken)) {

        $error = "Invalid request. Please try again.";
    } else {

        $action = $_POST['action'] ?? '';

        // ADD CATEGORY

        if ($action === 'add') {

            $categoryName = trim($_POST['category_name'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($categoryName === '') {

                $error = "Category name is required.";
            } else {

                $sql = "INSERT INTO categories
                        (category_name, description)
                        VALUES (?, ?)";

                $stmt = mysqli_prepare($conn, $sql);

                mysqli_stmt_bind_param(
                    $stmt,
                    "ss",
                    $categoryName,
                    $description
                );

                try {
                    $executed = mysqli_stmt_execute($stmt);
                } catch (mysqli_sql_exception $exception) {
                    if ((int)$exception->getCode() !== 1062) {
                        throw $exception;
                    }
                    $executed = false;
                    $error = "Category already exists.";
                }

                if ($executed) {
                    mysqli_stmt_close($stmt);
                    header("Location: categories.php?success=added");
                    exit;
                }

                if ($error === '') {
                    $error = "Unable to add category.";
                }
                mysqli_stmt_close($stmt);
            }
        }

        // UPDATE CATEGORY
        elseif ($action === 'update') {

            $categoryId = (int)($_POST['category_id'] ?? 0);
            $categoryName = trim($_POST['category_name'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($categoryId <= 0 || $categoryName === '') {

                $error = "Please enter a valid category name.";
            } else {

                $sql = "UPDATE categories
                        SET category_name = ?, description = ?
                        WHERE category_id = ?";

                $stmt = mysqli_prepare($conn, $sql);

                mysqli_stmt_bind_param(
                    $stmt,
                    "ssi",
                    $categoryName,
                    $description,
                    $categoryId
                );

                try {
                    $executed = mysqli_stmt_execute($stmt);
                } catch (mysqli_sql_exception $exception) {
                    if ((int)$exception->getCode() !== 1062) {
                        throw $exception;
                    }
                    $executed = false;
                    $error = "Category name already exists.";
                }

                if ($executed) {
                    mysqli_stmt_close($stmt);
                    header("Location: categories.php?success=updated");
                    exit;
                }

                if ($error === '') {
                    $error = "Unable to update category.";
                }
                mysqli_stmt_close($stmt);
            }
        }

        // DELETE CATEGORY
        elseif ($action === 'delete') {

            $categoryId = (int)($_POST['category_id'] ?? 0);

            if ($categoryId <= 0) {

                $error = "Invalid category.";
            } else {

                // Check linked food items
                $checkSql = "SELECT COUNT(*)
                             FROM foods
                             WHERE category_id = ?";

                $checkStmt = mysqli_prepare($conn, $checkSql);

                mysqli_stmt_bind_param(
                    $checkStmt,
                    "i",
                    $categoryId
                );

                mysqli_stmt_execute($checkStmt);

                mysqli_stmt_bind_result($checkStmt, $totalFoods);
                mysqli_stmt_fetch($checkStmt);

                mysqli_stmt_close($checkStmt);

                if ($totalFoods > 0) {

                    $error = "Cannot delete this category because food items are linked to it.";
                } else {

                    $deleteSql = "DELETE FROM categories
                                  WHERE category_id = ?";

                    $deleteStmt = mysqli_prepare($conn, $deleteSql);

                    mysqli_stmt_bind_param(
                        $deleteStmt,
                        "i",
                        $categoryId
                    );

                    if (mysqli_stmt_execute($deleteStmt)) {

                        if (mysqli_stmt_affected_rows($deleteStmt) > 0) {
                            mysqli_stmt_close($deleteStmt);
                            header("Location: categories.php?success=deleted");
                            exit;
                        } else {
                            $error = "Category not found.";
                        }
                    } else {
                        $error = "Unable to delete category.";
                    }

                    mysqli_stmt_close($deleteStmt);
                }
            }
        }
    }
}

// ==============================
// SUCCESS MESSAGES
// ==============================

if (isset($_GET['success'])) {

    if ($_GET['success'] === 'added') {
        $success = "Category added successfully.";
    } elseif ($_GET['success'] === 'updated') {
        $success = "Category updated successfully.";
    } elseif ($_GET['success'] === 'deleted') {
        $success = "Category deleted successfully.";
    }
}

// ==============================
// EDIT CATEGORY DATA
// ==============================

$editCategory = null;

if (isset($_GET['edit'])) {

    $editId = (int)$_GET['edit'];

    if ($editId > 0) {

        $editSql = "SELECT category_id, category_name, description
                    FROM categories
                    WHERE category_id = ?";

        $editStmt = mysqli_prepare($conn, $editSql);

        mysqli_stmt_bind_param(
            $editStmt,
            "i",
            $editId
        );

        mysqli_stmt_execute($editStmt);

        mysqli_stmt_bind_result(
            $editStmt,
            $editCategoryId,
            $editCategoryName,
            $editDescription
        );

        if (mysqli_stmt_fetch($editStmt)) {

            $editCategory = [
                'category_id' => $editCategoryId,
                'category_name' => $editCategoryName,
                'description' => $editDescription
            ];
        }

        mysqli_stmt_close($editStmt);
    }
}

// ==============================
// VIEW ALL CATEGORIES
// ==============================

$sql = "SELECT category_id, category_name, description, created_at
        FROM categories
        ORDER BY category_id DESC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Database query failed.");
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Category Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

</head>

<body class="bg-light">

    <div class="container py-5">

        <!-- Page Heading -->
        <div class="d-flex justify-content-between align-items-center mb-4">

            <h2>Category Management</h2>

            <a href="dashboard.php" class="btn btn-secondary">
                Back to Dashboard
            </a>

        </div>

        <!-- Success Message -->
        <?php if ($success !== ''): ?>

            <div class="alert alert-success">
                <?= category_escape($success) ?>
            </div>

        <?php endif; ?>

        <!-- Error Message -->
        <?php if ($error !== ''): ?>

            <div class="alert alert-danger">
                <?= category_escape($error) ?>
            </div>

        <?php endif; ?>


        <!-- Add / Edit Category Form -->
        <div class="card mb-4">

            <div class="card-header">

                <h5 class="mb-0">
                    <?= $editCategory ? 'Edit Category' : 'Add New Category' ?>
                </h5>

            </div>

            <div class="card-body">

                <form method="POST" action="categories.php">

                    <input type="hidden"
                        name="csrf_token"
                        value="<?= category_escape($csrfToken) ?>">

                    <input type="hidden"
                        name="action"
                        value="<?= $editCategory ? 'update' : 'add' ?>">

                    <?php if ($editCategory): ?>

                        <input type="hidden"
                            name="category_id"
                            value="<?= (int)$editCategory['category_id'] ?>">

                    <?php endif; ?>


                    <div class="mb-3">

                        <label class="form-label">Category Name</label>

                        <input type="text"
                            name="category_name"
                            class="form-control"
                            placeholder="Enter category name"
                            value="<?= category_escape($editCategory['category_name'] ?? '') ?>"
                            required>

                    </div>


                    <div class="mb-3">

                        <label class="form-label">Description</label>

                        <textarea name="description"
                            class="form-control"
                            rows="3"
                            placeholder="Enter category description"><?= category_escape($editCategory['description'] ?? '') ?></textarea>

                    </div>


                    <button type="submit" class="btn btn-primary">

                        <?= $editCategory ? 'Update Category' : 'Add Category' ?>

                    </button>


                    <?php if ($editCategory): ?>

                        <a href="categories.php" class="btn btn-secondary">
                            Cancel
                        </a>

                    <?php endif; ?>

                </form>

            </div>

        </div>


        <!-- Category List -->
        <div class="card">

            <div class="card-header">

                <h5 class="mb-0">Category List</h5>

            </div>

            <div class="card-body">

                <div class="table-responsive">

                    <table class="table table-bordered table-striped">

                        <thead class="table-dark">

                            <tr>
                                <th>ID</th>
                                <th>Category Name</th>
                                <th>Description</th>
                                <th>Created At</th>
                                <th>Action</th>
                            </tr>

                        </thead>


                        <tbody>

                            <?php if (mysqli_num_rows($result) > 0): ?>

                                <?php while ($row = mysqli_fetch_assoc($result)): ?>

                                    <tr>

                                        <td>
                                            <?= (int)$row['category_id'] ?>
                                        </td>

                                        <td>
                                            <?= category_escape($row['category_name']) ?>
                                        </td>

                                        <td>
                                            <?= category_escape($row['description']) ?>
                                        </td>

                                        <td>
                                            <?= category_escape($row['created_at']) ?>
                                        </td>

                                        <td>

                                            <!-- Edit Button -->
                                            <a href="categories.php?edit=<?= (int)$row['category_id'] ?>"
                                                class="btn btn-sm btn-warning">

                                                Edit

                                            </a>


                                            <!-- Delete Form -->
                                            <form method="POST"
                                                action="categories.php"
                                                class="d-inline"
                                                onsubmit="return confirm('Are you sure you want to delete this category?');">

                                                <input type="hidden"
                                                    name="csrf_token"
                                                    value="<?= category_escape($csrfToken) ?>">

                                                <input type="hidden"
                                                    name="action"
                                                    value="delete">

                                                <input type="hidden"
                                                    name="category_id"
                                                    value="<?= (int)$row['category_id'] ?>">

                                                <button type="submit"
                                                    class="btn btn-sm btn-danger">

                                                    Delete

                                                </button>

                                            </form>

                                        </td>

                                    </tr>

                                <?php endwhile; ?>

                            <?php else: ?>

                                <tr>

                                    <td colspan="5" class="text-center">
                                        No categories found.
                                    </td>

                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>