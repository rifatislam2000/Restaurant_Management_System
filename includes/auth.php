
<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieParams = session_get_cookie_params();

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

if (!function_exists('require_login')) {
    function require_login()
    {
        if (!isset($_SESSION['user_id'])) {
            header("Location: ../login.php");
            exit;
        }
    }
}
