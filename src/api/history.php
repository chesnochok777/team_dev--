<?php
require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Необходима авторизация']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

try {
    if ($action === 'save') {
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $convertedAmount = filter_var($_POST['converted_amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $rate = filter_var($_POST['rate'] ?? null, FILTER_VALIDATE_FLOAT);
        $fromCurrency = $_POST['from_currency'] ?? '';
        $toCurrency = $_POST['to_currency'] ?? '';

        if ($amount === false || $amount <= 0 || $convertedAmount === false || $rate === false ||
            !preg_match('/^[A-Z]{3}$/', $fromCurrency) || !preg_match('/^[A-Z]{3}$/', $toCurrency)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверные данные']);
            exit;
        }

        $stmt = $pdo->prepare('INSERT INTO conversions (user_id, amount, from_currency, to_currency, converted_amount, rate) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $amount, $fromCurrency, $toCurrency, $convertedAmount, $rate]);
        echo json_encode(['success' => true]);
    } elseif ($action === 'get') {
        $stmt = $pdo->prepare('SELECT * FROM conversions WHERE user_id = ? ORDER BY timestamp DESC LIMIT 50');
        $stmt->execute([$userId]);
        echo json_encode(['success' => true, 'history' => $stmt->fetchAll()]);
    } elseif ($action === 'clear') {
        $stmt = $pdo->prepare('DELETE FROM conversions WHERE user_id = ?');
        $stmt->execute([$userId]);
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
    }
} catch (PDOException $e) {
    error_log('History error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка работы с историей']);
}
