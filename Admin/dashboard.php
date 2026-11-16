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
    <style>
        :root {
            color-scheme: light;
            font-family: Arial, Helvetica, sans-serif;
            color: #172033;
            background: #f4f6fa;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 18px max(24px, calc((100% - 1120px) / 2));
            background: #fff;
            border-bottom: 1px solid #e6eaf0;
        }

        .brand {
            font-size: 18px;
            font-weight: 700;
        }

        .topbar a,
        .button {
            color: #fff;
            background: #2457d6;
            padding: 10px 14px;
            border-radius: 7px;
            text-decoration: none;
            font-size: 14px;
        }

        main {
            width: min(1120px, calc(100% - 40px));
            margin: 36px auto;
        }

        .heading {
            margin-bottom: 24px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        .muted {
            color: #687386;
            margin: 0;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 26px;
        }

        .card,
        .panel {
            background: #fff;
            border: 1px solid #e6eaf0;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(27, 39, 61, .04);
        }

        .card {
            padding: 20px;
        }

        .card-label {
            color: #687386;
            font-size: 14px;
        }

        .card-value {
            display: block;
            margin-top: 12px;
            font-size: 30px;
            font-weight: 700;
        }

        .panel-heading {
            padding: 18px 20px;
            border-bottom: 1px solid #e6eaf0;
        }

        h2 {
            margin: 0;
            font-size: 18px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th,
        td {
            padding: 14px 20px;
            border-bottom: 1px solid #edf0f4;
            white-space: nowrap;
        }

        th {
            color: #687386;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            background: #edf4ff;
            color: #2457d6;
            font-size: 12px;
        }

        .badge.inactive {
            background: #fff0ef;
            color: #bd3428;
        }

        .empty {
            color: #687386;
            text-align: center;
            padding: 24px;
        }

        @media (max-width: 760px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            main {
                margin-top: 26px;
            }
        }

        @media (max-width: 440px) {
            .topbar {
                padding: 16px 20px;
            }

            .brand {
                font-size: 16px;
            }

            .cards {
                gap: 10px;
            }

            .card {
                padding: 15px;
            }

            .card-value {
                font-size: 25px;
            }
        }
    </style>
</head>

<body>
    <header class="topbar">
        <div class="brand">Restaurant Management</div>
        <a href="../logout.php">Log out</a>
    </header>
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
</body>

</html>
<?php
