const CURRENCIES = ['USD', 'EUR', 'RUB', 'JPY', 'GBP', 'CNY', 'CHF', 'CAD', 'AUD', 'NZD', 'HKD', 'SGD', 'INR', 'KRW', 'AED'];
const HOUR = 60 * 60 * 1000;
const PROVIDERS = [
    { url: 'https://open.er-api.com/v6/latest/USD', name: 'ExchangeRate-API', link: 'https://www.exchangerate-api.com/', hourly: false }
];

let rates = null;
let loading = false;
let lastAttempt = 0;
let currentProvider = null;
let rateTimestamp = 0;
let authMode = 'login';
let currentUser = null;

const amountInput = document.getElementById('amount');
const fromInput = document.getElementById('fromCurrency');
const toInput = document.getElementById('toCurrency');
const refreshButton = document.getElementById('refreshBtn');
const result = document.getElementById('result');
const rateInfo = document.getElementById('rateInfo');
const providerLink = document.getElementById('providerLink');
const formatter = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 });

const authModal = document.getElementById('authModal');
const authTitle = document.getElementById('authTitle');
const loginInput = document.getElementById('loginInput');
const passwordInput = document.getElementById('passwordInput');
const togglePasswordBtn = document.getElementById('togglePassword');
const authSubmitBtn = document.getElementById('authSubmitBtn');
const authSwitchText = document.getElementById('authSwitchText');
const authSwitchLink = document.getElementById('authSwitchLink');
const authMessage = document.getElementById('authMessage');
const converterBlock = document.getElementById('converterBlock');
const currentUserSpan = document.getElementById('currentUser');
const logoutBtn = document.getElementById('logoutBtn');
const convertBtn = document.getElementById('convertBtn');
const historyList = document.getElementById('historyList');
const clearHistoryBtn = document.getElementById('clearHistoryBtn');

function showResult(message, error = false) {
    result.textContent = message;
    result.classList.toggle('error', error);
}

async function convertCurrency(saveToHistory = true) {
    if (!rates) return;
    const raw = amountInput.value.trim();
    const amount = Number(raw);
    if (!raw || !Number.isFinite(amount) || amount < 0 || !amountInput.validity.valid) {
        showResult('Введите сумму от 0 и выше.', true);
        return;
    }
    const from = fromInput.value;
    const to = toInput.value;
    const converted = amount * (rates[to] / rates[from]);
    if (!Number.isFinite(converted)) {
        showResult('Сумма слишком большая. Введите меньшее значение.', true);
        return;
    }
    showResult(`${formatter.format(amount)} ${from} = ${formatter.format(converted)} ${to}`);

    if (saveToHistory && currentUser) {
        const rate = rates[to] / rates[from];
        try {
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('amount', amount);
            formData.append('from_currency', from);
            formData.append('to_currency', to);
            formData.append('converted_amount', converted);
            formData.append('rate', rate);

            await fetch('api/history.php', { method: 'POST', body: formData });
            renderHistory();
        } catch (error) {
            console.error('Ошибка сохранения истории:', error);
        }
    }
}

function describeRates(warning = '') {
    const updated = new Date(rateTimestamp).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
    rateInfo.textContent = `Все 15 валют: обновление примерно раз в сутки. Данные на ${updated}.${warning ? ' ' + warning : ''}`;
    providerLink.href = currentProvider.link;
    providerLink.textContent = `Курсы: ${currentProvider.name}`;
}

async function fetchRates(provider) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20000);
    try {
        const response = await fetch(provider.url, { signal: controller.signal, cache: 'no-store' });
        if (!response.ok) throw new Error(response.status === 429 ? 'Сервис ограничил запросы.' : `Сервис ответил ошибкой ${response.status}.`);
        const data = await response.json();
        const base = provider.hourly ? data.base : data.base_code;
        const timestamp = (provider.hourly ? data.timestamp : data.time_last_update_unix) * 1000;
        if (base !== 'USD' || (!provider.hourly && data.result !== 'success') ||
            !CURRENCIES.every(code => Number.isFinite(data.rates?.[code]) && data.rates[code] > 0) ||
            data.rates.USD !== 1 || !Number.isFinite(timestamp) || timestamp <= 0 ||
            timestamp > Date.now() + 5 * 60 * 1000 ||
            Date.now() - timestamp > (provider.hourly ? 3 * HOUR : 48 * HOUR)) {
            throw new Error('Источник вернул устаревшие или неполные курсы.');
        }
        return { rates: Object.fromEntries(CURRENCIES.map(code => [code, data.rates[code]])), timestamp };
    } catch (error) {
        if (error.name === 'AbortError') throw new Error('Источник не ответил вовремя.');
        if (error instanceof TypeError) throw new Error('Не удалось подключиться к источнику.');
        throw error;
    } finally {
        clearTimeout(timeout);
    }
}

async function loadRates() {
    if (loading) return;
    loading = true;
    lastAttempt = Date.now();
    refreshButton.disabled = true;
    refreshButton.textContent = 'Загрузка…';
    if (!rates) showResult('Загружаем курсы…');
    let loaded = false;
    try {
        for (const provider of PROVIDERS) {
            try {
                let data;
                try {
                    data = await fetchRates(provider);
                } catch (error) {
                    if (!provider.hourly) throw error;
                    data = await fetchRates(provider);
                }
                if (rates && data.timestamp < rateTimestamp) continue;
                rates = data.rates;
                rateTimestamp = data.timestamp;
                currentProvider = provider;
                loaded = true;
                describeRates();
                convertCurrency(false);
                break;
            } catch (error) {
                rateInfo.textContent = error.message;
            }
        }
        if (!loaded) {
            if (rates) {
                describeRates('Обновление не удалось — используются последние курсы.');
                convertCurrency(false);
            } else {
                rateInfo.textContent = '';
                showResult('Не удалось загрузить курсы. Проверьте интернет.', true);
            }
        }
    } finally {
        loading = false;
        refreshButton.disabled = false;
        refreshButton.textContent = 'Обновить курсы';
    }
}

function showAuthMessage(text, type = 'error') {
    authMessage.textContent = text;
    authMessage.className = 'auth-message ' + type;
}

function clearAuthMessage() {
    authMessage.textContent = '';
    authMessage.className = 'auth-message';
}

function switchAuthMode() {
    authMode = (authMode === 'login') ? 'register' : 'login';
    if (authMode === 'login') {
        authTitle.textContent = '🔐 Вход в систему';
        authSubmitBtn.textContent = 'Войти';
        authSwitchText.textContent = 'Нет аккаунта?';
        authSwitchLink.textContent = 'Зарегистрироваться';
    } else {
        authTitle.textContent = '📝 Регистрация';
        authSubmitBtn.textContent = 'Зарегистрироваться';
        authSwitchText.textContent = 'Уже есть аккаунт?';
        authSwitchLink.textContent = 'Войти';
    }
    clearAuthMessage();
}

async function handleAuthSubmit() {
    const login = loginInput.value.trim();
    const password = passwordInput.value;
    clearAuthMessage();

    if (!login || !password) {
        showAuthMessage('Заполните все поля.');
        return;
    }

    const formData = new FormData();
    formData.append('action', authMode === 'register' ? 'register' : 'login');
    formData.append('login', login);
    formData.append('password', password);

    try {
        const response = await fetch('api/auth.php', { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            currentUser = data.login;
            onLoginSuccess(data.login);
        } else {
            showAuthMessage(data.error || 'Ошибка авторизации');
        }
    } catch (error) {
        showAuthMessage('Ошибка подключения к серверу');
    }
}

function onLoginSuccess(login) {
    currentUserSpan.textContent = login;
    authModal.classList.add('hidden');
    converterBlock.style.display = 'block';
    loginInput.value = '';
    passwordInput.value = '';
    clearAuthMessage();
    renderHistory();
    loadRates();
}

async function handleLogout() {
    if (!confirm('Выйти из аккаунта?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'logout');
        await fetch('api/auth.php', { method: 'POST', body: formData });
    } catch (error) {
        console.error('Ошибка выхода:', error);
    }
    
    currentUser = null;
    converterBlock.style.display = 'none';
    authModal.classList.remove('hidden');
}

function togglePasswordVisibility() {
    const isPassword = passwordInput.type === 'password';
    passwordInput.type = isPassword ? 'text' : 'password';
    togglePasswordBtn.textContent = isPassword ? '🙈' : '👁️';
}

async function renderHistory() {
    try {
        const response = await fetch('api/history.php?action=get');
        const data = await response.json();

        if (!data.success || !data.history || data.history.length === 0) {
            historyList.innerHTML = '<p class="history-empty">Пока нет сохранённых операций</p>';
            return;
        }

        historyList.innerHTML = data.history.map(item => {
            const date = new Date(item.timestamp).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
            return `
                <div class="history-item">
                    <div class="h-top">
                        <span>${formatter.format(item.amount)} ${item.from_currency} → ${item.to_currency}</span>
                        <span class="h-date">${date}</span>
                    </div>
                    <div class="h-result">= ${formatter.format(item.converted_amount)} ${item.to_currency}</div>
                </div>
            `;
        }).join('');
    } catch (error) {
        historyList.innerHTML = '<p class="history-empty">Ошибка загрузки истории</p>';
    }
}

async function clearHistory() {
    if (!confirm('Удалить всю историю операций?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'clear');
        await fetch('api/history.php', { method: 'POST', body: formData });
        renderHistory();
    } catch (error) {
        alert('Ошибка очистки истории');
    }
}

authSubmitBtn.addEventListener('click', handleAuthSubmit);
authSwitchLink.addEventListener('click', (e) => { e.preventDefault(); switchAuthMode(); });
togglePasswordBtn.addEventListener('click', togglePasswordVisibility);
logoutBtn.addEventListener('click', handleLogout);
convertBtn.addEventListener('click', () => convertCurrency(true));
refreshButton.addEventListener('click', loadRates);
amountInput.addEventListener('input', () => convertCurrency(false));
fromInput.addEventListener('change', () => convertCurrency(false));
toInput.addEventListener('change', () => convertCurrency(false));
clearHistoryBtn.addEventListener('click', clearHistory);

[loginInput, passwordInput].forEach(el => {
    el.addEventListener('keydown', (e) => { if (e.key === 'Enter') handleAuthSubmit(); });
});

setInterval(() => {
    const retryInterval = rates ? HOUR : 5 * 60 * 1000;
    if (!document.hidden && Date.now() - lastAttempt >= retryInterval) loadRates();
}, 60 * 1000);

document.addEventListener('visibilitychange', () => {
    if (!document.hidden && Date.now() - lastAttempt >= HOUR) loadRates();
});

(async function init() {
    try {
        const response = await fetch('api/auth.php?action=check');
        const data = await response.json();

        if (data.logged_in) {
            currentUser = data.login;
            currentUserSpan.textContent = data.login;
            authModal.classList.add('hidden');
            converterBlock.style.display = 'block';
            renderHistory();
            loadRates();
        } else {
            authModal.classList.remove('hidden');
            converterBlock.style.display = 'none';
        }
    } catch (error) {
        authModal.classList.remove('hidden');
        converterBlock.style.display = 'none';
    }
})();