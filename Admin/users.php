<?php
include("../includes/auth.php");
require_login();

include("../includes/dbconfig.php");

if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

function users_escape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function users_redirect_with_message($type, $message)
{
    $_SESSION['user_management_message'] = [
        'type' => $type,
        'text' => $message,
    ];
    header("Location: users.php");
    exit;
}

function users_statement_error($statement)
{
    $errorNumber = mysqli_stmt_errno($statement);

    if ($errorNumber === 1062) {
        return 'That username or email is already in use.';
    }

    if ($errorNumber === 1451) {
        return 'This user cannot be deleted because existing orders refer to the account.';
    }

    return 'Database error: ' . mysqli_stmt_error($statement);
}

if (!isset($_SESSION['user_management_csrf'])) {
    $_SESSION['user_management_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['user_management_csrf'];

$message = $_SESSION['user_management_message'] ?? null;
unset($_SESSION['user_management_message']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $error = 'The form expired. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_user' || $action === 'update_user') {
            $fullNameInput = $_POST['full_name'] ?? '';
            $usernameInput = $_POST['username'] ?? '';
            $emailInput = $_POST['email'] ?? '';
            $passwordInput = $_POST['password'] ?? '';
            $roleInput = $_POST['role'] ?? '';
            $statusInput = $_POST['status'] ?? '';
            $userIdInput = $_POST['user_id'] ?? '';
            $fullName = is_string($fullNameInput) ? trim($fullNameInput) : '';
            $username = is_string($usernameInput) ? trim($usernameInput) : '';
            $email = is_string($emailInput) ? trim($emailInput) : '';
            $password = is_string($passwordInput) ? $passwordInput : '';
            $role = is_string($roleInput) ? $roleInput : '';
            $statusValue = is_string($statusInput) ? $statusInput : '';
            $status = (int)$statusValue;
            $userIdValue = is_string($userIdInput) || is_int($userIdInput)
                ? filter_var($userIdInput, FILTER_VALIDATE_INT)
                : false;
            $userId = $userIdValue === false ? 0 : $userIdValue;

            if ($fullName === '' || strlen($fullName) > 100) {
                $error = 'Enter a full name of no more than 100 characters.';
            } elseif ($username === '' || strlen($username) > 50) {
                $error = 'Enter a username of no more than 50 characters.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
                $error = 'Enter a valid email address of no more than 100 characters.';
            } elseif (!in_array($role, ['admin', 'cashier'], true)) {
                $error = 'Choose a valid user role.';
            } elseif (!in_array((string)$statusValue, ['0', '1'], true)) {
                $error = 'Choose a valid user status.';
            } elseif ($action === 'create_user' && ($password === '' || strlen($password) > 255)) {
                $error = 'Enter a password of no more than 255 characters.';
            } elseif ($action === 'update_user' && $userId < 1) {
                $error = 'The selected user is invalid.';
            } elseif (
                $action === 'update_user'
                && $userId === (int)$_SESSION['user_id']
                && ($role !== 'admin' || $status !== 1)
            ) {
                $error = 'You cannot remove your own administrator access or deactivate your account.';
            } elseif ($action === 'update_user' && $password !== '' && strlen($password) > 255) {
                $error = 'The new password must be no more than 255 characters.';
            } else {
                $passwordToStore = null;

                if ($action === 'create_user' || $password !== '') {
                    $passwordToStore = password_hash($password, PASSWORD_DEFAULT);

                    if (!is_string($passwordToStore)) {
                        die('Unable to secure the user password.');
                    }
                }

                if ($action === 'create_user') {
                    $statement = mysqli_prepare(
                        $conn,
                        "INSERT INTO users (full_name, username, email, password, role, status)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );

                    if (!$statement) {
                        die('Unable to prepare user creation: ' . users_escape(mysqli_error($conn)));
                    }

                    mysqli_stmt_bind_param($statement, 'sssssi', $fullName, $username, $email, $passwordToStore, $role, $status);
                    $successMessage = 'User added successfully.';
                } elseif ($password !== '') {
                    $statement = mysqli_prepare(
                        $conn,
                        "UPDATE users
                         SET full_name = ?, username = ?, email = ?, password = ?, role = ?, status = ?
                         WHERE user_id = ?"
                    );

                    if (!$statement) {
                        die('Unable to prepare user update: ' . users_escape(mysqli_error($conn)));
                    }

                    mysqli_stmt_bind_param($statement, 'sssssii', $fullName, $username, $email, $passwordToStore, $role, $status, $userId);
                    $successMessage = 'User updated successfully.';
                } else {
                    $statement = mysqli_prepare(
                        $conn,
                        "UPDATE users
                         SET full_name = ?, username = ?, email = ?, role = ?, status = ?
                         WHERE user_id = ?"
                    );

                    if (!$statement) {
                        die('Unable to prepare user update: ' . users_escape(mysqli_error($conn)));
                    }

                    mysqli_stmt_bind_param($statement, 'ssssii', $fullName, $username, $email, $role, $status, $userId);
                    $successMessage = 'User updated successfully.';
                }

                if (!mysqli_stmt_execute($statement)) {
                    $error = users_statement_error($statement);
                } elseif ($action === 'update_user' && mysqli_stmt_affected_rows($statement) === 0) {
                    $checkStatement = mysqli_prepare($conn, "SELECT user_id FROM users WHERE user_id = ?");

                    if (!$checkStatement) {
                        die('Unable to verify user update: ' . users_escape(mysqli_error($conn)));
                    }

                    mysqli_stmt_bind_param($checkStatement, 'i', $userId);

                    if (!mysqli_stmt_execute($checkStatement)) {
                        die('Unable to verify user update: ' . users_escape(mysqli_stmt_error($checkStatement)));
                    }

                    mysqli_stmt_store_result($checkStatement);

                    if (mysqli_stmt_num_rows($checkStatement) === 0) {
                        $error = 'No user was found to update.';
                    } else {
                        mysqli_stmt_close($statement);
                        mysqli_stmt_close($checkStatement);
                        users_redirect_with_message('success', $successMessage);
                    }

                    mysqli_stmt_close($checkStatement);
                } else {
                    mysqli_stmt_close($statement);
                    users_redirect_with_message('success', $successMessage);
                }

                mysqli_stmt_close($statement);
            }
        } elseif ($action === 'delete_user' || $action === 'toggle_status') {
            $userIdInput = $_POST['user_id'] ?? '';
            $userId = is_string($userIdInput) || is_int($userIdInput)
                ? filter_var($userIdInput, FILTER_VALIDATE_INT)
                : false;
            $userId = $userId === false ? 0 : $userId;

            if ($userId < 1) {
                $error = 'The selected user is invalid.';
            } elseif ($userId === (int)$_SESSION['user_id']) {
                $error = 'You cannot delete or deactivate your own account.';
            } else {
                if ($action === 'delete_user') {
                    $statement = mysqli_prepare($conn, "DELETE FROM users WHERE user_id = ?");
                    $successMessage = 'User deleted successfully.';
                } else {
                    $statement = mysqli_prepare(
                        $conn,
                        "UPDATE users
                         SET status = CASE WHEN status = 1 THEN 0 ELSE 1 END
                         WHERE user_id = ?"
                    );
                    $successMessage = 'User status changed successfully.';
                }

                if (!$statement) {
                    die('Unable to prepare user action: ' . users_escape(mysqli_error($conn)));
                }

                mysqli_stmt_bind_param($statement, 'i', $userId);

                if (!mysqli_stmt_execute($statement)) {
                    $error = users_statement_error($statement);
                } elseif (mysqli_stmt_affected_rows($statement) === 0) {
                    $error = 'No user was found to update.';
                } else {
                    mysqli_stmt_close($statement);
                    users_redirect_with_message('success', $successMessage);
                }

                mysqli_stmt_close($statement);
            }
        } else {
            $error = 'Unknown user management action.';
        }
    }
}

$searchInput = $_GET['search'] ?? '';
$search = is_string($searchInput) ? trim($searchInput) : '';

if (isset($_GET['edit'])) {
    $editInput = $_GET['edit'];
    $editId = is_string($editInput) || is_int($editInput)
        ? filter_var($editInput, FILTER_VALIDATE_INT)
        : false;

    if ($editId === false || $editId < 1) {
        $error = 'The selected user is invalid.';
    } else {
        $editStatement = mysqli_prepare(
            $conn,
            "SELECT user_id, full_name, username, email, role, status
             FROM users
             WHERE user_id = ?"
        );

        if (!$editStatement) {
            die('Unable to prepare user lookup: ' . users_escape(mysqli_error($conn)));
        }

        mysqli_stmt_bind_param($editStatement, 'i', $editId);

        if (!mysqli_stmt_execute($editStatement)) {
            die('Unable to load user: ' . users_escape(mysqli_stmt_error($editStatement)));
        }

        $editResult = mysqli_stmt_get_result($editStatement);

        if (!$editResult) {
            die('Unable to retrieve user: ' . users_escape(mysqli_stmt_error($editStatement)));
        }

        $editUser = mysqli_fetch_assoc($editResult);
        mysqli_stmt_close($editStatement);

        if (!$editUser) {
            $error = 'The selected user could not be found.';
        }
    }
} else {
    $editUser = null;
}

if ($search !== '') {
    $statement = mysqli_prepare(
        $conn,
        "SELECT user_id, full_name, username, email, role, status, created_at
         FROM users
         WHERE full_name LIKE CONCAT('%', ?, '%')
            OR username LIKE CONCAT('%', ?, '%')
            OR email LIKE CONCAT('%', ?, '%')
            OR role LIKE CONCAT('%', ?, '%')
         ORDER BY user_id DESC"
    );

    if (!$statement) {
        die('Unable to prepare user search: ' . users_escape(mysqli_error($conn)));
    }

    mysqli_stmt_bind_param($statement, 'ssss', $search, $search, $search, $search);

    if (!mysqli_stmt_execute($statement)) {
        die('Unable to search users: ' . users_escape(mysqli_stmt_error($statement)));
    }

    $result = mysqli_stmt_get_result($statement);

    if (!$result) {
        die('Unable to retrieve search results: ' . users_escape(mysqli_stmt_error($statement)));
    }

    mysqli_stmt_close($statement);
} else {
    $result = mysqli_query(
        $conn,
        "SELECT user_id, full_name, username, email, role, status, created_at
         FROM users
         ORDER BY user_id DESC"
    );

    if (!$result) {
        die('Unable to load users: ' . users_escape(mysqli_error($conn)));
    }
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

        .header,
        .search-bar,
        .actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .header {
            margin-bottom: 20px;
        }

        .header a,
        .button {
            display: inline-block;
            background: #2457d6;
            color: #fff;
            padding: 10px 15px;
            text-decoration: none;
            border: 0;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        .button.secondary {
            background: #64748b;
        }

        .button.danger {
            background: #c0392b;
        }

        .panel {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .field label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 600;
        }

        .field input,
        .field select,
        .search-bar input {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 5px;
        }

        .form-actions {
            margin-top: 16px;
        }

        .search-bar {
            justify-content: flex-start;
            margin-bottom: 16px;
        }

        .search-bar input {
            max-width: 360px;
        }

        .message {
            padding: 12px 14px;
            margin-bottom: 16px;
            border-radius: 5px;
            background: #e4f8eb;
            color: #16803d;
        }

        .message.error {
            background: #ffe9e7;
            color: #c0392b;
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

        .badge.inactive {
            background: #ffe9e7;
            color: #c0392b;
        }

        .empty {
            text-align: center;
            padding: 25px;
        }

        .actions {
            justify-content: flex-start;
        }

        .actions form {
            margin: 0;
        }

        @media (max-width: 760px) {
            .form-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 520px) {
            .container {
                margin: 24px auto;
            }

            .header,
            .search-bar {
                align-items: stretch;
                flex-direction: column;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h2>Manage Users</h2>
            <a href="dashboard.php">Back to Dashboard</a>
        </div>

        <?php if ($message): ?>
            <div class="message"><?= users_escape($message['text']) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="message error"><?= users_escape($error) ?></div>
        <?php endif; ?>

        <section class="panel">
            <h3><?= $editUser ? 'Edit User' : 'Add New User' ?></h3>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= users_escape($csrfToken) ?>">
                <input type="hidden" name="action" value="<?= $editUser ? 'update_user' : 'create_user' ?>">
                <?php if ($editUser): ?>
                    <input type="hidden" name="user_id" value="<?= (int)$editUser['user_id'] ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="field">
                        <label for="full_name">Full Name</label>
                        <input id="full_name" name="full_name" type="text" maxlength="100" required
                            value="<?= users_escape($editUser['full_name'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="username">Username</label>
                        <input id="username" name="username" type="text" maxlength="50" required
                            value="<?= users_escape($editUser['username'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" maxlength="100" required
                            value="<?= users_escape($editUser['email'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="password"><?= $editUser ? 'New Password (optional)' : 'Password' ?></label>
                        <input id="password" name="password" type="password" maxlength="255"
                            <?= $editUser ? '' : 'required' ?>>
                    </div>
                    <div class="field">
                        <label for="role">Role</label>
                        <select id="role" name="role" required>
                            <option value="cashier" <?= ($editUser['role'] ?? 'cashier') === 'cashier' ? 'selected' : '' ?>>Cashier</option>
                            <option value="admin" <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status" required>
                            <option value="1" <?= (int)($editUser['status'] ?? 1) === 1 ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= isset($editUser['status']) && (int)$editUser['status'] === 0 ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="button" type="submit"><?= $editUser ? 'Save Changes' : 'Add User' ?></button>
                    <?php if ($editUser): ?>
                        <a class="button secondary" href="users.php">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="panel">
            <h3>User List</h3>
            <form class="search-bar" method="get" action="users.php">
                <input type="search" name="search" value="<?= users_escape($search) ?>"
                    placeholder="Search name, username, email or role">
                <button class="button" type="submit">Search</button>
                <?php if ($search !== ''): ?>
                    <a class="button secondary" href="users.php">Clear</a>
                <?php endif; ?>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($result) > 0): ?>
                            <?php while ($user = mysqli_fetch_assoc($result)): ?>
                                <tr>
                                    <td><?= (int)$user['user_id'] ?></td>
                                    <td><?= users_escape($user['full_name']) ?></td>
                                    <td><?= users_escape($user['username']) ?></td>
                                    <td><?= users_escape($user['email']) ?></td>
                                    <td><?= users_escape(ucfirst($user['role'])) ?></td>
                                    <td>
                                        <?php if ((int)$user['status'] === 1): ?>
                                            <span class="badge">Active</span>
                                        <?php else: ?>
                                            <span class="badge inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= users_escape($user['created_at'] ?? '') ?></td>
                                    <td>
                                        <div class="actions">
                                            <a class="button secondary"
                                                href="users.php?edit=<?= (int)$user['user_id'] ?><?= $search !== '' ? '&amp;search=' . urlencode($search) : '' ?>">Edit</a>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= users_escape($csrfToken) ?>">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">
                                                <button class="button" type="submit">
                                                    <?= (int)$user['status'] === 1 ? 'Deactivate' : 'Activate' ?>
                                                </button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('Delete this user? This action cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?= users_escape($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">
                                                <button class="button danger" type="submit">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="empty">No users found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</body>

</html>

<?php
mysqli_free_result($result);
mysqli_close($conn);
?>