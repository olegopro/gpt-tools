<?php

// Определение констант
const API_URL = "https://api.openai.com/v1/chat/completions";
const AUTH_TOKEN = "api_token";
const PROXY = "user:password@ip:port";

// Проверяем, есть ли '@' в строке прокси
if (strpos(PROXY, '@') !== false) {
    // Разбиваем строку на части, если есть данные аутентификации
    list($credentials, $proxyAddress) = explode('@', PROXY);
} else {
    // Если данных аутентификации нет, то вся строка - это адрес прокси
    $proxyAddress = PROXY;
    $credentials = null;
}

echo 'Робот думает, ждите...' . PHP_EOL;

// Получаем все аргументы, начиная со второго (индекс 1) и объединяем их в одну строку
$additionalInstructions = implode(' ', array_slice($argv, 1));

// Считываем данные из stdin
$stdin = fopen('php://stdin', 'r');
$diffData = '';
while ($line = fgets($stdin)) {
    $diffData .= $line;
}
fclose($stdin);

// Записываем данные в файл
file_put_contents('diff_data.txt', $diffData);

// Преобразуем данные из stdin в JSON-совместимую строку
$jsonCompatibleDiffData = json_encode($diffData);

// Формируем основную инструкцию
$baseInstruction = "Отвечай всегда на русском. Напиши название коммита и его описание. Название коммита должна быть до 50 символов и начинаться с глагола в прошедшем времени (например, Реализовал, Улучшил, Создал). Описание коммита в виде списка с тире. После названия коммита оставлять пустую сроку, далее описание.";

// Добавляем дополнительные инструкции, если они есть
if (!empty($additionalInstructions)) {
    $baseInstruction .= " Это важно -> $additionalInstructions";
}

// Формируем JSON структуру для запроса
$requestData = [
    "model" => "gpt-3.5-turbo-16k",
    // "temperature" => 0.4,
    "messages" => [
        [
            "role" => "system",
            "content" => $baseInstruction
        ]
    ]
];

// Добавляем сообщение пользователя
$requestData['messages'][] = [
    "role" => "user",
    "content" => $jsonCompatibleDiffData
];

// Преобразуем массив в JSON
$jsonRequest = json_encode($requestData);

// Настройки cURL для отправки запроса
$ch = curl_init(API_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonRequest);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . AUTH_TOKEN
]);

// Для использования HTTPS прокси
curl_setopt($ch, CURLOPT_PROXY, $proxyAddress);

// Устанавливаем учетные данные для прокси, если они есть
if ($credentials !== null) {
    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $credentials);
}

curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

// Установка тайм-аута в 60 секунд
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

// Отправляем запрос и получаем ответ
$response = curl_exec($ch);
if (curl_error($ch)) {
    echo 'Ошибка cURL: ' . curl_error($ch) . PHP_EOL;
    curl_close($ch);
    exit; // Выход из скрипта в случае ошибки cURL
}
curl_close($ch);

// Обрабатываем ответ
if ($response) {
    $responseArray = json_decode($response, true);

    // Проверяем наличие ключа error
    if (isset($responseArray['error'])) {
        echo "Ошибка API: " . $responseArray['error']['message'] . PHP_EOL;
        exit; // Выход из скрипта в случае ошибки API
    }

    // Проверяем наличие ключа choices
    if (isset($responseArray['choices'])) {
        $message = $responseArray['choices'][0]['message']['content'];
        echo $message . PHP_EOL;
    } else {
        echo "Ключ 'choices' не найден в ответе API.";
    }
} else {
    echo "Ошибка при получении ответа от API OpenAI.";
}
