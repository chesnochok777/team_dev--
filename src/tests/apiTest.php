<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

if (!function_exists('curl_init')) {
    echo "Ошибка: требуется расширение PHP cURL.\n";
    exit(1);
}

$baseUrl = rtrim(getenv('TEST_BASE_URL') ?: 'http://localhost', '/');
$authUrl = getenv('TEST_AUTH_URL') ?: ($baseUrl . '/api/auth.php');
$historyUrl = getenv('TEST_HISTORY_URL') ?: ($baseUrl . '/api/history.php');

$dbSettings = [
    'host' => getenv('TEST_DB_HOST') ?: 'localhost',
    'name' => getenv('TEST_DB_NAME') ?: 'currency_converter',
    'user' => getenv('TEST_DB_USER') ?: 'root',
    'pass' => getenv('TEST_DB_PASS') ?: '',
];

$passed = 0;
$failed = 0;
$failures = [];

function isCli(): bool
{
    return PHP_SAPI === 'cli';
}

function colorize(string $text, string $color): string
{
    if (!isCli()) {
        return $text;
    }

    $colors = [
        'green' => "\033[32m",
        'red' => "\033[31m",
        'yellow' => "\033[33m",
        'reset' => "\033[0m",
    ];

    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

function shortDetails(string $details): string
{
    $details = trim((string)preg_replace('/\s+/', ' ', $details));

    if (strlen($details) > 220) {
        return substr($details, 0, 220) . '…';
    }

    return $details;
}

function writeLine(string $status, string $name, string $details = ''): void
{
    $label = $status === 'PASS'
        ? colorize('PASS', 'green')
        : colorize('FAIL', 'red');

    $line = sprintf('[%s] %s', $label, $name);

    if ($details !== '') {
        $line .= ' — ' . shortDetails($details);
    }

    echo $line . PHP_EOL;
}

function ok(bool $condition, string $name, string $details = ''): void
{
    global $passed, $failed, $failures;

    if ($condition) {
        $passed++;
        writeLine('PASS', $name);
    } else {
        $failed++;
        $failures[] = $name;
        writeLine('FAIL', $name, $details);
    }
}

function apiRequest(string $method, string $url, array $fields = [], ?string $cookieJar = null): array
{
    $ch = curl_init();

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];

    if ($cookieJar !== null) {
        $options[CURLOPT_COOKIEJAR] = $cookieJar;
        $options[CURLOPT_COOKIEFILE] = $cookieJar;
    }

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => 0,
            'json' => null,
            'body' => '',
            'error' => $error,
        ];
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [
        'status' => $status,
        'json' => json_decode((string)$response, true),
        'body' => (string)$response,
        'error' => null,
    ];
}

function pdo(): ?PDO
{
    static $pdo = null;
    static $attempted = false;

    if ($attempted) {
        return $pdo;
    }

    $attempted = true;

    global $dbSettings;

    try {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $dbSettings['host'],
            $dbSettings['name']
        );

        $pdo = new PDO(
            $dsn,
            $dbSettings['user'],
            $dbSettings['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable $e) {
        echo colorize(
            'Предупреждение: не удалось подключиться к БД для очистки тестовых данных: ' . $e->getMessage(),
            'yellow'
        ) . PHP_EOL;

        $pdo = null;
    }

    return $pdo;
}

function cleanupTestData(string $loginPrefix): void
{
    $pdo = pdo();

    if (!$pdo) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'DELETE c
             FROM conversions c
             INNER JOIN users u ON u.id = c.user_id
             WHERE u.login LIKE ?'
        );
        $stmt->execute([$loginPrefix . '%']);

        $stmt = $pdo->prepare('DELETE FROM users WHERE login LIKE ?');
        $stmt->execute([$loginPrefix . '%']);
    } catch (Throwable $e) {
        echo colorize(
            'Предупреждение: очистка тестовых данных завершилась ошибкой: ' . $e->getMessage(),
            'yellow'
        ) . PHP_EOL;
    }
}

function makeCookieJar(string $name): string
{
    $file = tempnam(sys_get_temp_dir(), $name);

    if ($file === false) {
        throw new RuntimeException('Не удалось создать временный файл для cookies');
    }

    return $file;
}

echo 'PHP API tests' . PHP_EOL;
echo 'Base URL: ' . $baseUrl . PHP_EOL;
echo 'Auth URL: ' . $authUrl . PHP_EOL;
echo 'History URL: ' . $historyUrl . PHP_EOL . PHP_EOL;

$checkConnection = apiRequest('GET', $authUrl . '?action=check');

if ($checkConnection['status'] === 0) {
    echo colorize(
        'Не удалось подключиться к серверу: ' . ($checkConnection['error'] ?? 'неизвестная ошибка'),
        'red'
    ) . PHP_EOL;

    echo 'Если проект находится в подпапке, задайте TEST_BASE_URL.' . PHP_EOL;
    echo 'Пример: TEST_BASE_URL=http://localhost/currency_converter php tests/api.test.php' . PHP_EOL;

    exit(1);
}

$loginPrefix = 'apitest_';
cleanupTestData($loginPrefix);

$login = $loginPrefix . date('YmdHis') . '_' . bin2hex(random_bytes(3));
$password = 'Password123';

$jarRegister = makeCookieJar('api_test_register_');
$jarLogin = makeCookieJar('api_test_login_');
$jarSecond = makeCookieJar('api_test_second_');

// 1. Проверка auth/check без сессии
$r = apiRequest('GET', $authUrl . '?action=check');
ok(
    $r['status'] === 200 && ($r['json']['logged_in'] ?? null) === false,
    'auth check без сессии: logged_in = false',
    $r['body']
);

// 2. Неизвестное действие в auth
$r = apiRequest('POST', $authUrl, ['action' => 'unknown']);
ok(
    $r['status'] === 400 && ($r['json']['success'] ?? null) === false,
    'auth: неизвестное действие возвращает 400',
    $r['body']
);

// 3. Регистрация с пустыми полями
$r = apiRequest('POST', $authUrl, ['action' => 'register']);
ok(
    $r['status'] === 200
    && ($r['json']['success'] ?? null) === false
    && !empty($r['json']['error']),
    'auth register: пустые поля отклоняются',
    $r['body']
);

// 4. Регистрация с коротким логином
$r = apiRequest('POST', $authUrl, [
    'action' => 'register',
    'login' => 'ab',
    'password' => $password,
]);
ok(
    ($r['json']['success'] ?? null) === false,
    'auth register: короткий логин отклоняется',
    $r['body']
);

// 5. Регистрация с коротким паролем
$r = apiRequest('POST', $authUrl, [
    'action' => 'register',
    'login' => $login,
    'password' => '123',
]);
ok(
    ($r['json']['success'] ?? null) === false,
    'auth register: короткий пароль отклоняется',
    $r['body']
);

// 6. Успешная регистрация
$r = apiRequest('POST', $authUrl, [
    'action' => 'register',
    'login' => $login,
    'password' => $password,
], $jarRegister);

ok(
    $r['status'] === 200
    && ($r['json']['success'] ?? null) === true
    && ($r['json']['login'] ?? '') === $login,
    'auth register: валидный пользователь создаётся',
    $r['body']
);

// 7. Повторная регистрация с тем же логином
$r = apiRequest('POST', $authUrl, [
    'action' => 'register',
    'login' => $login,
    'password' => $password,
], $jarSecond);

ok(
    ($r['json']['success'] ?? null) === false,
    'auth register: дубликат логина отклоняется',
    $r['body']
);

// 8. Проверка сессии после регистрации
$r = apiRequest('GET', $authUrl . '?action=check', [], $jarRegister);
ok(
    ($r['json']['logged_in'] ?? null) === true
    && ($r['json']['login'] ?? '') === $login,
    'auth check после регистрации: пользователь залогинен',
    $r['body']
);

// 9. Вход с неверным паролем
$r = apiRequest('POST', $authUrl, [
    'action' => 'login',
    'login' => $login,
    'password' => 'WrongPassword123',
], $jarSecond);

ok(
    ($r['json']['success'] ?? null) === false,
    'auth login: неверный пароль отклоняется',
    $r['body']
);

// 10. Успешный вход
$r = apiRequest('POST', $authUrl, [
    'action' => 'login',
    'login' => $login,
    'password' => $password,
], $jarLogin);

ok(
    $r['status'] === 200 && ($r['json']['success'] ?? null) === true,
    'auth login: правильный логин и пароль принимаются',
    $r['body']
);

// 11. История без авторизации
$r = apiRequest('GET', $historyUrl . '?action=get');
ok(
    $r['status'] === 401 && ($r['json']['success'] ?? null) === false,
    'history get без сессии: 401',
    $r['body']
);

// 12. История: неизвестное действие
$r = apiRequest('POST', $historyUrl, ['action' => 'unknown'], $jarRegister);
ok(
    $r['status'] === 400 && ($r['json']['success'] ?? null) === false,
    'history: неизвестное действие возвращает 400',
    $r['body']
);

// 13. История: сохранение без полей
$r = apiRequest('POST', $historyUrl, ['action' => 'save'], $jarRegister);
ok(
    $r['status'] === 400 && ($r['json']['success'] ?? null) === false,
    'history save без полей: 400',
    $r['body']
);

// 14. История: отрицательная сумма
$r = apiRequest('POST', $historyUrl, [
    'action' => 'save',
    'amount' => '-5',
    'converted_amount' => '1',
    'rate' => '1',
    'from_currency' => 'USD',
    'to_currency' => 'EUR',
], $jarRegister);

ok(
    $r['status'] === 400 && ($r['json']['success'] ?? null) === false,
    'history save: отрицательная сумма отклоняется',
    $r['body']
);

// 15. История: неверный код валюты
$r = apiRequest('POST', $historyUrl, [
    'action' => 'save',
    'amount' => '10',
    'converted_amount' => '9',
    'rate' => '0.9',
    'from_currency' => 'USD',
    'to_currency' => 'eur',
], $jarRegister);

ok(
    $r['status'] === 400 && ($r['json']['success'] ?? null) === false,
    'history save: неверный код валюты отклоняется',
    $r['body']
);

// 16. История: успешное сохранение
$validHistoryData = [
    'action' => 'save',
    'amount' => '100',
    'converted_amount' => '90',
    'rate' => '0.9',
    'from_currency' => 'USD',
    'to_currency' => 'EUR',
];

$r = apiRequest('POST', $historyUrl, $validHistoryData, $jarRegister);
ok(
    $r['status'] === 200 && ($r['json']['success'] ?? null) === true,
    'history save: валидные данные сохраняются',
    $r['body']
);

// 17. История: получение сохранённой записи
$r = apiRequest('GET', $historyUrl . '?action=get', [], $jarRegister);

$history = $r['json']['history'] ?? [];
$found = false;

if (is_array($history)) {
    foreach ($history as $item) {
        $amount = (float)($item['amount'] ?? 0);
        $convertedAmount = (float)($item['converted_amount'] ?? 0);
        $from = $item['from_currency'] ?? '';
        $to = $item['to_currency'] ?? '';

        if ($amount === 100.0 && $convertedAmount === 90.0 && $from === 'USD' && $to === 'EUR') {
            $found = true;
            break;
        }
    }
}

ok(
    $r['status'] === 200
    && ($r['json']['success'] ?? null) === true
    && is_array($history)
    && count($history) > 0
    && $found,
    'history get: содержит сохранённую операцию',
    $r['body']
);

// 18. История: очистка
$r = apiRequest('POST', $historyUrl, ['action' => 'clear'], $jarRegister);
ok(
    $r['status'] === 200 && ($r['json']['success'] ?? null) === true,
    'history clear: очистка выполняется',
    $r['body']
);

// 19. История: после очистки пусто
$r = apiRequest('GET', $historyUrl . '?action=get', [], $jarRegister);
$history = $r['json']['history'] ?? null;

ok(
    $r['status'] === 200
    && ($r['json']['success'] ?? null) === true
    && is_array($history)
    && count($history) === 0,
    'history get после очистки: пустой список',
    $r['body']
);

// 20. Выход
$r = apiRequest('POST', $authUrl, ['action' => 'logout'], $jarRegister);
ok(
    $r['status'] === 200 && ($r['json']['success'] ?? null) === true,
    'auth logout: выход выполняется',
    $r['body']
);

// 21. После выхода сессия сброшена
$r = apiRequest('GET', $authUrl . '?action=check', [], $jarRegister);
ok(
    ($r['json']['logged_in'] ?? null) === false,
    'auth check после logout: logged_in = false',
    $r['body']
);

cleanupTestData($loginPrefix);

foreach ([$jarRegister, $jarLogin, $jarSecond] as $file) {
    if (is_string($file) && file_exists($file)) {
        @unlink($file);
    }
}

echo PHP_EOL;
echo colorize("Итого: {$passed} успешно, {$failed} с ошибками.", $failed ? 'red' : 'green') . PHP_EOL;

if ($failed > 0) {
    echo colorize('Проваленные тесты:', 'red') . PHP_EOL;

    foreach ($failures as $failure) {
        echo '- ' . $failure . PHP_EOL;
    }
}

exit($failed === 0 ? 0 : 1);