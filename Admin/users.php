<?php
include("../includes/auth.php");
require_login();

include("../includes/dbconfig.php");

// Only admin can access this page
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get all users
$sql = "SELECT user_id, full_name, username, role, status, created_at
        FROM users
        ORDER BY user_id DESC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Query Error: " . htmlspecialchars(mysqli_error($conn)));
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Users</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f6fa;
            margin: 0;
        }

        .container {
            width: 95%;
            max-width: 1100px;
            margin: 40px auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            gap: 10px;
        }

        .header a {
            background: #2457d6;
            color: white;
            padding: 10px 15px;
            text-decoration: none;
            border-radius: 5px;
        }

        .panel {
            background: white;
            border-radius: 8px;
            padding: 20px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th,
        td {
            padding: 14px;
            border-bottom: 1px solid #eee;
            white-space: nowrap;
        }

        th {
            background: #f8f9fa;
        }

        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            background: #e4f8eb;
            color: #16803d;
        }

        .inactive {
            background: #ffe9e7;
            color: #c0392b;
        }

        .empty {
            text-align: center;
            padding: 25px;
        }
    </style>

</head>

<body>

    <div class="container">

        <div class="header">

            <h2>Manage Users</h2>

            <a href="dashboard.php">Back to Dashboard</a>

        </div>

        <div class="panel">

            <table>

                <thead>

                    <tr>
                        <th>ID</th>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created At</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if (mysqli_num_rows($result) > 0): ?>

                        <?php while ($user = mysqli_fetch_assoc($result)): ?>

                            <tr>

                                <td>
                                    <?= (int)$user['user_id'] ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($user['full_name']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($user['username']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(ucfirst($user['role'])) ?>
                                </td>

                                <td>

                                    <?php if ($user['status'] == 1): ?>

                                        <span class="badge">Active</span>

                                    <?php else: ?>

                                        <span class="badge inactive">Inactive</span>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?= htmlspecialchars($user['created_at'] ?? '') ?>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6" class="empty">
                                No users found.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</body>

</html>

<?php
mysqli_free_result($result);
mysqli_close($conn);
?>