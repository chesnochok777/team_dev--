// Демо-курсы валют (относительно доллара США)
// В реальном проекте эти данные нужно получать с API
const rates = {
    RUB: 92.50,  // 1 USD = 92.50 RUB
    USD: 1.00,   // 1 USD = 1.00 USD
    JPY: 148.00  // 1 USD = 148.00 JPY
};

// Функция конвертации
function convertCurrency() {
    const amount = parseFloat(document.getElementById('amount').value);
    const from = document.getElementById('fromCurrency').value;
    const to = document.getElementById('toCurrency').value;
    const resultBlock = document.getElementById('result');

    // Проверка на корректность ввода
    if (isNaN(amount) || amount <= 0) {
        resultBlock.textContent = "⚠️ Введите корректную сумму!";
        resultBlock.style.color = "red";
        return;
    }

    // Логика конвертации через базовую валюту (USD)
    const amountInUSD = amount / rates[from];
    const finalAmount = amountInUSD * rates[to];

    // Форматирование и вывод результата
    resultBlock.style.color = "#2c3e50";
    resultBlock.textContent = `${amount.toLocaleString()} ${from} = ${finalAmount.toFixed(2)} ${to}`;
}

// Привязка обработчиков событий
document.addEventListener('DOMContentLoaded', () => {
    // Конвертация по клику на кнопку
    document.getElementById('convertBtn').addEventListener('click', convertCurrency);

    // Конвертация в реальном времени при изменении полей
    document.getElementById('amount').addEventListener('input', convertCurrency);
    document.getElementById('fromCurrency').addEventListener('change', convertCurrency);
    document.getElementById('toCurrency').addEventListener('change', convertCurrency);
});