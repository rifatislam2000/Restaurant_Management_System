<?php
include("../includes/auth.php");
require_login();

include("../includes/dbconfig.php");

function dashboard_escape($value)
{
return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$summaryResult = mysqli_query(
$conn,
"SELECT
COUNT(*) AS total_users,
COALESCE(SUM(status = 1), 0) AS active_users,
COALESCE(SUM(role = 'admin'), 0) AS admin_users,
COALESCE(SUM(role = 'cashier'), 0) AS cashier_users
FROM users"
);

if (!$summaryResult) {
die('Unable to load dashboard summary: ' . dashboard_escape(mysqli_error($conn)));
}

$summary = mysqli_fetch_assoc($summaryResult);
mysqli_free_result($summaryResult);

$usersResult = mysqli_query(
$conn,
"SELECT full_name, username, role, status, created_at
FROM users
ORDER BY user_id DESC
LIMIT 5"
);

if (!$usersResult) {
die('Unable to load recent users: ' . dashboard_escape(mysqli_error($conn)));
}

$welcomeName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard | Restaurant Management</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
    <header class="topbar">
        <div class="brand">Restaurant Management</div>
        <a href="../logout.php">Log out</a>
    </header>
    <div class="dashboard-layout">
        <aside class="sidebar" aria-label="Admin sidebar">
            <p class="sidebar-title">Admin menu</p>
            <nav aria-label="Admin navigation">
                <a href="dashboard.php" aria-current="page">Dashboard</a>
                <a href="users.php">Users</a>
            </nav>
        </aside>
        <div class="dashboard-content">
            <main>
                <section class="heading">
                    <h1>Dashboard</h1>
                    <p class="muted">Welcome, <?= dashboard_escape($welcomeName) ?>. Here is your user account overview.</p>
                </section>

                <section class="cards" aria-label="User summary">
                    <article class="card">
                        <span class="card-label">Total users</span>
                        <strong class="card-value"><?= (int)$summary['total_users'] ?></strong>
                    </article>
                    <article class="card">
                        <span class="card-label">Active users</span>
                        <strong class="card-value"><?= (int)$summary['active_users'] ?></strong>
                    </article>
                    <article class="card">
                        <span class="card-label">Administrators</span>
                        <strong class="card-value"><?= (int)$summary['admin_users'] ?></strong>
                    </article>
                    <article class="card">
                        <span class="card-label">Cashiers</span>
                        <strong class="card-value"><?= (int)$summary['cashier_users'] ?></strong>
                    </article>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <h2>Recently added users</h2>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($usersResult) > 0): ?>
                                    <?php while ($user = mysqli_fetch_assoc($usersResult)): ?>
                                        <tr>
                                            <td><?= dashboard_escape($user['full_name']) ?></td>
                                            <td><?= dashboard_escape($user['username']) ?></td>
                                            <td><?= dashboard_escape(ucfirst($user['role'])) ?></td>
                                            <td>
                                                <span class="badge<?= (int)$user['status'] === 1 ? '' : ' inactive' ?>">
                                                    <?= (int)$user['status'] === 1 ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </td>
                                            <td><?= dashboard_escape($user['created_at'] ?? '—') ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td class="empty" colspan="5">No users have been added yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
        </div>
    </div>
</body>

</html>
<?php
