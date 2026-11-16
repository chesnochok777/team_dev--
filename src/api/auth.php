<?php
require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'register') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login === '' || $password === '') {
        echo json_encode(['success' => false, 'error' => 'Заполните все поля']);
        exit;
    }
    if (mb_strlen($login, 'UTF-8') < 3) {
        echo json_encode(['success' => false, 'error' => 'Логин должен быть не короче 3 символов']);
        exit;
    }
    if (strlen($password) < 4) {
        echo json_encode(['success' => false, 'error' => 'Пароль должен быть не короче 4 символов']);
        exit;
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE login = ? LIMIT 1');
        $stmt->execute([$login]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Пользователь с таким логином уже существует']);
            exit;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (login, password_hash) VALUES (?, ?)');
        $stmt->execute([$login, $passwordHash]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$pdo->lastInsertId();
        $_SESSION['login'] = $login;

        echo json_encode(['success' => true, 'login' => $login]);
    } catch (PDOException $e) {
        error_log('Registration error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Не удалось создать аккаунт. Проверьте данные и подключение к базе.']);
    }
} elseif ($action === 'login') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login === '' || $password === '') {
        echo json_encode(['success' => false, 'error' => 'Заполните все поля']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, login, password_hash FROM users WHERE login = ? LIMIT 1');
    $stmt->execute([$login]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Неверный логин или пароль']);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['login'] = $user['login'];

    echo json_encode(['success' => true, 'login' => $user['login']]);
} elseif ($action === 'check') {
    echo json_encode(isset($_SESSION['user_id'])
        ? ['logged_in' => true, 'login' => $_SESSION['login'] ?? '']
        : ['logged_in' => false]);
} elseif ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    echo json_encode(['success' => true]);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
}
