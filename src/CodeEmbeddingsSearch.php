<?php

/**
 * Класс для работы с векторными представлениями кода и поиском релевантных частей
 * Интегрируется с системой объединения файлов и использует Voyage AI для семантического поиска
 * 
 * Этот класс предоставляет функциональность для:
 * - Разбиения объединенного файла с кодом на отдельные фрагменты
 * - Создания векторных представлений (embeddings) для фрагментов кода через API Voyage
 * - Кэширования векторных представлений для повторного использования
 * - Семантического поиска по коду на основе текстовых запросов
 * - Вычисления релевантности фрагментов кода к запросу
 * - Сохранения результатов поиска в файл в удобном для чтения формате
 * 
 * Принцип работы:
 * 1. Класс разбивает объединенный файл с кодом на отдельные фрагменты по маркерам начала и конца файлов
 * 2. Для каждого фрагмента создается векторное представление с помощью API Voyage
 * 3. Векторные представления сохраняются в JSON-файл для повторного использования
 * 4. При поиске запрос также преобразуется в векторное представление
 * 5. Вычисляется косинусная близость между вектором запроса и векторами фрагментов кода
 * 6. Возвращаются наиболее релевантные фрагменты кода, отсортированные по степени близости
 * 
 * Пример использования:
 * ```php
 * // Создаем экземпляр класса
 * $searcher = new CodeEmbeddingsSearch('ваш_api_ключ_voyage');
 * 
 * // Инициализируем векторные представления
 * $searcher->initialize();
 * 
 * // Выполняем поиск по запросу
 * $results = $searcher->search('Как обрабатываются зависимости', 3);
 * 
 * // Сохраняем результаты в файл
 * $outputFile = $searcher->createSearchResultFile($results, 'Как обрабатываются зависимости');
 * ```
 * 
 * @see https://docs.voyageai.com/ Документация API Voyage
 */
class CodeEmbeddingsSearch
{
    private string $apiKey;
    private string $model;
    private string $mergedFilePath;
    private string $embeddingsFilePath;
    private array $codeChunks = [];
    private int $totalTokensUsed = 0;

    /**
     * Конструктор класса
     * 
     * @param string $apiKey Ключ API Voyage
     * @param string $mergedFilePath Путь к объединенному файлу с кодом
     * @param string $embeddingsFilePath Путь для сохранения/загрузки embeddings
     * @param string $model Модель для создания embeddings
     */
    public function __construct(
        string $apiKey,
        string $mergedFilePath = 'merged_files.txt',
        string $embeddingsFilePath = 'code_embeddings.json',
        string $model = 'voyage-code-3'
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->mergedFilePath = $mergedFilePath;
        $this->embeddingsFilePath = $embeddingsFilePath;
    }

    /**
     * Получает аргументы командной строки
     * 
     * @return array Массив с аргументами
     */
    public function getCommandLineArgs(): array
    {
        global $argv;

        $query = '';
        $mergedFile = 'merged_files.txt';
        $outputFile = 'search_results.txt';
        $rebuild = false;

        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] === '--query' && isset($argv[$i + 1])) {
                $query = $argv[$i + 1];
                $i++;
            } elseif ($argv[$i] === '--file' && isset($argv[$i + 1])) {
                $mergedFile = $argv[$i + 1];
                $i++;
            } elseif ($argv[$i] === '--output' && isset($argv[$i + 1])) {
                $outputFile = $argv[$i + 1];
                $i++;
            } elseif ($argv[$i] === '--rebuild') {
                $rebuild = true;
            } elseif ($argv[$i] === '--help') {
                $this->showHelp();
                exit(0);
            } elseif (empty($query) && !str_starts_with($argv[$i], '--')) {
                // Если запрос не указан через --query, берем первый аргумент без префикса
                $query = $argv[$i];
            }
        }

        return [
            'query' => $query,
            'mergedFile' => $mergedFile,
            'outputFile' => $outputFile,
            'rebuild' => $rebuild
        ];
    }

    /**
     * Выводит справку по использованию программы
     */
    public function showHelp(): void
    {
        echo "Использование: php search_code.php [опции] \"запрос\"\n";
        echo "Опции:\n";
        echo "  --query \"запрос\"       Текст поискового запроса\n";
        echo "  --file merged_files.txt Путь к объединенному файлу с кодом\n";
        echo "  --output results.txt    Путь для сохранения результатов поиска\n";
        echo "  --rebuild               Принудительно пересоздать embeddings\n";
        echo "  --help                  Показать эту справку\n\n";
        echo "Пример: php search_code.php \"Как обрабатываются зависимости\"\n";
        echo "Или:    php search_code.php --query \"Как обрабатываются зависимости\" --file code.txt --output results.txt\n";
    }

    /**
     * Запрашивает у пользователя ввод поискового запроса
     * 
     * @return string Введенный запрос
     */
    public function promptForQuery(): string
    {
        echo "Введите поисковый запрос: ";
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);
        return trim($line);
    }

    /**
     * Запускает процесс поиска, обрабатывая аргументы командной строки
     */
    public function runSearchFromCommandLine(): void
    {
        // Получаем аргументы командной строки
        $args = $this->getCommandLineArgs();

        // Если указан путь к объединенному файлу, обновляем его
        if ($args['mergedFile'] !== 'merged_files.txt') {
            $this->mergedFilePath = $args['mergedFile'];
        }

        // Если запрос не указан, запрашиваем его интерактивно
        if (empty($args['query'])) {
            $args['query'] = $this->promptForQuery();

            if (empty($args['query'])) {
                echo "Запрос не может быть пустым.\n";
                $this->showHelp();
                exit(1);
            }
        }

        // Инициализируем embeddings (создаем или загружаем)
        echo "Инициализация векторных представлений...\n";
        $this->initialize($args['rebuild']);

        // Ищем релевантные фрагменты кода
        echo "Поиск по запросу: \"{$args['query']}\"\n";
        $results = $this->search($args['query'], 3);

        if (empty($results)) {
            echo "По вашему запросу ничего не найдено.\n";
            exit(0);
        }

        // Создаем файл с результатами
        $outputFile = $this->createSearchResultFile($results, $args['query'], $args['outputFile']);

        echo "Найдено " . count($results) . " релевантных файлов.\n";
        echo "Результаты сохранены в файл: $outputFile\n\n";

        // Выводим краткую информацию о найденных файлах
        foreach ($results as $index => $result) {
            echo "Файл #" . ($index + 1) . " - " .
                $result['file'] . " (релевантность: " .
                number_format($result['similarity'] * 100, 2) . "%)\n";
        }

        echo "\nДля просмотра полных результатов откройте файл: $outputFile\n";

        echo "Всего использовано токенов за сессию: " . $this->getTotalTokensUsed() . "\n";
    }

    /**
     * Разбивает объединенный файл на фрагменты кода по маркерам
     * 
     * @param string $mergedContent Содержимое объединенного файла
     * @return array Массив фрагментов кода с метаданными
     */
    public function splitMergedFile(string $mergedContent): array
    {
        $lines = explode("\n", $mergedContent);
        $chunks = [];
        $currentChunk = '';
        $currentFile = '';
        $inFile = false;

        foreach ($lines as $line) {
            if (strpos($line, '// Начало файла ->') === 0) {
                // Начало нового файла - сбрасываем текущий фрагмент
                $currentFile = trim(str_replace('// Начало файла ->', '', $line));
                $currentChunk = $line . "\n";
                $inFile = true;
            } elseif (strpos($line, '// Конец файла ->') === 0 && $inFile) {
                // Конец файла - добавляем полный фрагмент и сбрасываем переменные
                $currentChunk .= $line . "\n";
                $chunks[] = [
                    'content' => $currentChunk,
                    'file' => $currentFile
                ];
                $currentChunk = '';
                $currentFile = '';
                $inFile = false;
            } elseif ($inFile) {
                // Добавляем строку к текущему фрагменту
                $currentChunk .= $line . "\n";
            }
        }

        // Проверяем, остался ли незавершенный фрагмент (на случай отсутствия маркера конца)
        if ($inFile && $currentChunk !== '' && $currentFile !== '') {
            $chunks[] = [
                'content' => $currentChunk,
                'file' => $currentFile
            ];
        }

        return $chunks;
    }

    /**
     * Получает векторные представления для массива файлов
     * 
     * @param array $texts Массив содержимого файлов для преобразования
     * @return array Массив векторных представлений
     */
    public function getEmbeddings(array $texts): array
    {
        if (count($texts) == 1) {
            echo "Отправляем файл в API для создания векторных представлений...\n";
        } else {
            $fileNames = [];
            foreach ($texts as $text) {
                // Пытаемся извлечь имя файла из текста
                if (preg_match('/\/\/ Начало файла \-\> (.+)/', $text, $matches)) {
                    $fileNames[] = $matches[1];
                }
            }

            if (!empty($fileNames)) {
                echo "Создаем векторные представления для " . count($fileNames) . " " .
                    $this->getPluralForm(count($fileNames), ['файл', 'файла', 'файлов']) . "...\n";
                echo "Отправляем файлы в API для создания векторных представлений:\n";
                // Выводим каждый файл с новой строки
                foreach ($fileNames as $index => $fileName) {
                    echo ($index + 1) . ". " . $fileName . "\n";
                }
            } else {
                echo "Отправляем " . count($texts) . " " .
                    $this->getPluralForm(count($texts), ['файл', 'файла', 'файлов']) .
                    " в API для создания векторных представлений...\n";
            }
        }

        $curl = curl_init();

        $data = [
            'model' => $this->model,
            'input' => $texts
        ];

        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.voyageai.com/v1/embeddings',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ]
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($err) {
            throw new Exception("Ошибка cURL: $err");
        }

        // Отладочная информация
        if ($httpCode != 200) {
            echo "Код ответа HTTP: $httpCode\n";
            echo "Ответ API: $response\n";
        }

        $result = json_decode($response, true);

        // Проверка успешного декодирования JSON
        if ($result === null) {
            throw new Exception("Ошибка декодирования JSON: " . json_last_error_msg() . "\nОтвет: " . substr($response, 0, 1000));
        }

        // Проверка наличия ошибки в ответе API
        if (isset($result['error'])) {
            throw new Exception("Ошибка API: " . json_encode($result['error']));
        }

        // Проверка наличия ключа 'data' в ответе
        if (!isset($result['data'])) {
            throw new Exception("Неверный формат ответа API: ключ 'data' отсутствует. Ответ: " . json_encode($result));
        }

        // Вывод информации о токенах, если она доступна
        if (isset($result['usage'])) {
            $tokensInfo = $result['usage'];
            echo "Использовано токенов: " . $tokensInfo['total_tokens'] . "\n";

            // Обновляем общий счетчик токенов
            $this->totalTokensUsed += $tokensInfo['total_tokens'];

            if (isset($tokensInfo['prompt_tokens'])) {
                echo "- Токенов запроса: " . $tokensInfo['prompt_tokens'] . "\n";
            }

            if (isset($tokensInfo['completion_tokens'])) {
                echo "- Токенов ответа: " . $tokensInfo['completion_tokens'] . "\n";
            }
        }

        return $result['data'];
    }

    /**
     * Вычисляет косинусную близость между двумя векторами
     * 
     * @param array $vec1 Первый вектор
     * @param array $vec2 Второй вектор
     * @return float Значение близости (от -1 до 1)
     */
    public function cosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;

        foreach ($vec1 as $i => $val1) {
            $val2 = $vec2[$i];
            $dotProduct += $val1 * $val2;
            $norm1 += $val1 * $val1;
            $norm2 += $val2 * $val2;
        }

        if ($norm1 <= 0.0 || $norm2 <= 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($norm1) * sqrt($norm2));
    }

    /**
     * Инициализирует или загружает векторные представления для фрагментов кода
     * 
     * @param bool $forceRebuild Принудительно пересоздать embeddings
     * @return bool Успешность операции
     */
    public function initialize(bool $forceRebuild = false): bool
    {
        // Проверяем, существует ли файл с embeddings и нужно ли их перестраивать
        if (!$forceRebuild && file_exists($this->embeddingsFilePath)) {
            $this->loadEmbeddings();
            return true;
        }

        // Читаем объединенный файл
        if (!file_exists($this->mergedFilePath)) {
            throw new Exception("Файл с объединенным кодом не найден: {$this->mergedFilePath}");
        }

        $mergedContent = file_get_contents($this->mergedFilePath);
        if ($mergedContent === false) {
            throw new Exception("Не удалось прочитать файл: {$this->mergedFilePath}");
        }

        // Разбиваем на фрагменты
        $chunks = $this->splitMergedFile($mergedContent);

        // Готовим массив текстов для создания embeddings
        $textChunks = array_map(function ($chunk) {
            return $chunk['content'];
        }, $chunks);

        // Получаем embeddings для всех фрагментов
        echo "Создаем векторные представления для " . count($textChunks) . " файлов кода...\n";
        $embeddings = $this->getEmbeddings($textChunks);

        // Объединяем фрагменты с их embeddings
        foreach ($chunks as $i => &$chunk) {
            $chunk['embedding'] = $embeddings[$i]['embedding'];
        }

        $this->codeChunks = $chunks;

        // Сохраняем результаты
        return $this->saveEmbeddings();
    }

    /**
     * Сохраняет embeddings в файл
     * 
     * @return bool Успешность операции
     */
    private function saveEmbeddings(): bool
    {
        return file_put_contents($this->embeddingsFilePath, json_encode($this->codeChunks)) !== false;
    }

    /**
     * Загружает embeddings из файла
     * 
     * @return bool Успешность операции
     */
    private function loadEmbeddings(): bool
    {
        $content = file_get_contents($this->embeddingsFilePath);
        if ($content === false) {
            return false;
        }

        $this->codeChunks = json_decode($content, true);
        return !empty($this->codeChunks);
    }

    /**
     * Находит наиболее релевантные фрагменты кода для запроса
     * 
     * @param string $query Запрос для поиска
     * @param int $topN Количество возвращаемых фрагментов
     * @return array Наиболее релевантные фрагменты кода
     */
    public function search(string $query, int $topN = 3): array
    {
        if (empty($this->codeChunks)) {
            throw new Exception("Embeddings не инициализированы. Сначала вызовите метод initialize()");
        }

        // Получаем embedding для запроса
        $queryEmbeddings = $this->getEmbeddings([$query]);
        $queryVector = $queryEmbeddings[0]['embedding'];

        $similarities = [];

        // Вычисляем близость для каждого фрагмента
        foreach ($this->codeChunks as $i => $chunk) {
            $similarity = $this->cosineSimilarity($queryVector, $chunk['embedding']);
            $similarities[$i] = $similarity;
        }

        // Сортируем по убыванию близости
        arsort($similarities);

        // Берем top N фрагментов
        $result = [];
        $count = 0;
        foreach (array_keys($similarities) as $index) {
            if ($count >= $topN) break;
            $chunk = $this->codeChunks[$index];
            $result[] = [
                'content' => $chunk['content'],
                'file' => $chunk['file'],
                'similarity' => $similarities[$index]
            ];
            $count++;
        }

        return $result;
    }

    /**
     * Получает полное содержимое файла из объединенного файла
     * 
     * @param string $fileName Имя файла для получения
     * @return string Полное содержимое файла
     */
    public function getFullFileContent(string $fileName): string
    {
        // Извлекаем из объединенного файла
        $mergedContent = file_get_contents($this->mergedFilePath);
        $lines = explode("\n", $mergedContent);
        $inTargetFile = false;
        $fileContent = '';

        foreach ($lines as $line) {
            if (strpos($line, '// Начало файла -> ' . $fileName) === 0) {
                $inTargetFile = true;
                $fileContent = $line . "\n";
            } elseif (strpos($line, '// Конец файла -> ' . $fileName) === 0) {
                $fileContent .= $line . "\n";
                break;
            } elseif ($inTargetFile) {
                $fileContent .= $line . "\n";
            }
        }

        return $fileContent ?: "// Файл не найден: $fileName";
    }

    /**
     * Создает файл с результатами поиска для дальнейшего использования
     * 
     * @param array $searchResults Результаты поиска
     * @param string $query Исходный запрос
     * @param string $outputFile Имя выходного файла
     * @return string Путь к созданному файлу
     */
    public function createSearchResultFile(array $searchResults, string $query, string $outputFile = 'search_results.txt'): string
    {
        $content = "# Результаты поиска по запросу: " . $query . "\n\n";

        foreach ($searchResults as $index => $result) {
            $content .= "## Файл #" . ($index + 1) . " (релевантность: " . number_format($result['similarity'] * 100, 2) . "%)\n";
            $content .= "Путь: " . $result['file'] . "\n\n";

            // Получаем содержимое файла из объединенного файла
            $fileContent = $this->getFullFileContent($result['file']);
            $extension = pathinfo($result['file'], PATHINFO_EXTENSION);

            $content .= "```" . ($extension ?: 'php') . "\n" . $fileContent . "\n```\n\n";
        }

        file_put_contents($outputFile, $content);
        return $outputFile;
    }

    /**
     * Возвращает правильную форму слова в зависимости от числа
     * 
     * @param int $number Число для склонения
     * @param array $forms Три формы слова ["файл", "файла", "файлов"]
     * @return string Строка с числом и правильной формой слова
     */
    public function getPluralForm(int $number, array $forms): string
    {
        $cases = [2, 0, 1, 1, 1, 2];
        return $number . ' ' . $forms[($number % 100 > 4 && $number % 100 < 20) ? 2 : $cases[min($number % 10, 5)]];
    }

    public function getTotalTokensUsed(): int
    {
        return $this->totalTokensUsed;
    }
}
