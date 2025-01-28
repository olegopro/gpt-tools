<?php

/**
 * Класс MergeFiles предназначен для объединения файлов из различных директорий в один выходной файл.
 * Он поддерживает фильтрацию файлов по расширениям, удаление ненужных фрагментов кода (комментариев, стилей, пустых строк) и сканирование зависимостей.
 * Основное использование — автоматическая сборка файлов проекта, включая рекурсивные зависимости.
 */
class MergeFiles
{
    // Определяем константы для методов индексации
    private const FILE_INDEXING_PHP = 'php';
    private const FILE_INDEXING_SYSTEM = 'system';
    private const AVAILABLE_INDEXING_METHODS = [self::FILE_INDEXING_PHP, self::FILE_INDEXING_SYSTEM];

    private string $projectDir;
    private array $paths;
    private bool $scanDependencies;
    private array $extensions;
    private bool $removeStyleTag;
    private bool $removeHtmlComments;
    private bool $removeSingleLineComments;
    private bool $removeMultiLineComments;
    private bool $removeEmptyLines;
    private bool $includeInstructions;
    private array $ignoreFiles;
    private array $ignoreDirectories;
    private string $outputFile;
    private int $maxDepth;
    private string $fileListOutputFile;
    private string $fileIndexingMethod;

    // Внутренние кеши для оптимизации
    private array $fileIndex = [];  // Индекс файлов в проекте для быстрого поиска по имени.
    private array $dependencyCache = [];  // Кеш зависимостей для предотвращения повторного сканирования.
    private array $contentCache = [];  // Кеш содержимого файлов для предотвращения повторного чтения.
    private array $scannedFiles = [];  // Список уже сканированных файлов для избежания зацикливания.

    // Постоянные данные — поддерживаемые расширения для поиска зависимостей
    private const array DEPENDENCY_EXTENSIONS = ['vue', 'js', 'ts'];

    /**
     * Конструктор класса MergeFiles.
     * Инициализирует объект с настройками для процесса объединения файлов.
     *
     * @param array $config Массив с конфигурацией, содержащий параметры:
     *  - projectDir (string): Корневая директория проекта.
     *  - dependencyScanRoot (string, опционально): Корневая директория для поиска зависимостей, по умолчанию совпадает с projectDir.
     *  - paths (array): Массив путей, которые нужно обработать.
     *  - scanDependencies (bool): Указывает, нужно ли сканировать зависимости (import'ы).
     *  - extensions (array): Массив расширений файлов, которые будут включены в объединение.
     *  - removeStyleTag (bool): Удалять ли теги <style>
     *  - removeHtmlComments (bool): Удалять ли HTML-комментарии.
     *  - removeSingleLineComments (bool): Удалять ли однострочные комментарии.
     *  - removeMultiLineComments (bool): Удалять ли многострочные комментарии.
     *  - removeEmptyLines (bool): Удалять ли пустые строки из файлов.
     *  - includeInstructions (bool): Включать ли инструкции в консольный вывод.
     *  - ignoreFiles (array): Массив файлов для игнорирования при объединении.
     *  - ignoreDirectories (array): Массив директорий для игнорирования.
     *  - outputFile (string): Имя файла, в который будет записан результат объединения.
     *  - maxDepth (int, опционально): Максимальная глубина рекурсивного сканирования зависимостей.
     *  - fileIndexingMethod (string): Метод индексации файлов ('php' или 'system').
     */
    public function __construct(array $config)
    {
        // Инициализация основных параметров
        $this->projectDir = $config['projectDir'];
        $this->paths = $config['paths'];
        $this->scanDependencies = $config['scanDependencies'];
        $this->extensions = $config['extensions'];
        $this->removeStyleTag = $config['removeStyleTag'];
        $this->removeHtmlComments = $config['removeHtmlComments'];
        $this->removeSingleLineComments = $config['removeSingleLineComments'];
        $this->removeMultiLineComments = $config['removeMultiLineComments'];
        $this->removeEmptyLines = $config['removeEmptyLines'];
        $this->includeInstructions = $config['includeInstructions'] ?? true;
        $this->ignoreFiles = $config['ignoreFiles'];
        $this->ignoreDirectories = $config['ignoreDirectories'];
        $this->outputFile = $config['outputFile'];
        $this->maxDepth = $config['maxDepth'] ?? 1000;
        $this->fileListOutputFile = $config['fileListOutputFile'] ?? 'file_list.txt';
        $this->fileIndexingMethod = $this->validateAndGetIndexingMethod($config['fileIndexingMethod'] ?? self::FILE_INDEXING_PHP);
    }

    /**
     * Основной метод для объединения файлов.
     * Он собирает все файлы, фильтрует их содержимое и записывает результат в указанный файл.
     */
    public function merge(): void
    {
        // Создаём индекс всех файлов проекта, чтобы ускорить дальнейшие операции.
        $this->buildFileIndex();

        // Сканируем файлы и зависимости, если это необходимо.
        $allPaths = $this->scanAllDependencies();

        // Объединяем содержимое всех файлов в один строковый блок.
        $mergedContent = $this->mergePaths($allPaths);

        // Записываем результат в выходной файл.
        file_put_contents($this->outputFile, $mergedContent);

        // Выводим информацию о файлах в консоль, сохраняем в файл и копируем в буфер обмена
        $this->printAndSaveFileList($this->calculateFileLinesInfo($mergedContent));
    }

    /**
     * Основной метод построения индекса файлов, который выбирает
     * нужную реализацию в зависимости от конфигурации.
     */
    private function buildFileIndex(): void
    {
        if ($this->fileIndexingMethod === self::FILE_INDEXING_SYSTEM) {
            $this->buildFileIndexUsingSystemFind();
        } else {
            $this->buildFileIndexUsingPhp();
        }
    }

    /**
     * Построение индекса файлов с использованием PHP-итератора.
     * Этот метод является кросс-платформенным решением и работает на всех системах.
     * Использует встроенные возможности PHP для рекурсивного обхода директорий.
     * 
     * Особенности реализации:
     * - Использует RecursiveIteratorIterator для обхода директорий
     * - Проверяет каждый файл на соответствие условиям
     * - Создает двойную индексацию для быстрого поиска
     * - Поддерживает игнорирование директорий
     */
    private function buildFileIndexUsingPhp(): void
    {
        // Создаем итератор для рекурсивного обхода директорий
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->projectDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        // Обходим все файлы в директории
        foreach ($iterator as $file) {
            // Проверяем, является ли элемент файлом и соответствует ли расширение
            if ($file->isFile() && $this->shouldIncludeFile($file->getFilename(), $this->extensions)) {
                // Получаем относительный путь к файлу
                $relativePath = $this->makeRelativePath($file->getPathname(), $this->projectDir);
                // Проверяем, не находится ли файл в игнорируемой директории
                if (!$this->isIgnoredDirectory($file->getPathname(), $this->ignoreDirectories, $this->projectDir)) {
                    // Создаем два индекса для быстрого поиска
                    $this->fileIndex[$file->getBasename()] = $relativePath;
                    $this->fileIndex[$relativePath] = $relativePath;
                }
            }
        }
    }

    /**
     * Построение индекса файлов с использованием системной команды find.
     * Этот метод оптимизирован для Unix-подобных систем и обеспечивает 
     * более высокую производительность на больших директориях.
     * 
     * Особенности реализации:
     * - Использует системную команду find для быстрого поиска
     * - Работает только на Unix-подобных системах
     * - Обеспечивает лучшую производительность на больших проектах
     * - Создает тот же формат индекса, что и PHP-метод
     */
    private function buildFileIndexUsingSystemFind(): void
    {
        // Формируем и выполняем системную команду find
        $command = "find {$this->projectDir} -type f";
        $files = explode("\n", shell_exec($command));

        // Обрабатываем каждый найденный файл
        foreach ($files as $file) {
            // Пропускаем пустые строки от команды find
            if (empty($file)) {
                continue;
            }

            // Получаем базовое имя файла
            $filename = basename($file);
            // Проверяем соответствие расширения файла
            if ($this->shouldIncludeFile($filename, $this->extensions)) {
                // Получаем относительный путь, используя быстрый substr
                $relativePath = substr($file, strlen($this->projectDir) + 1);
                // Проверяем, не находится ли файл в игнорируемой директории
                if (!$this->isIgnoredDirectory($file, $this->ignoreDirectories, $this->projectDir)) {
                    // Создаем два индекса для быстрого поиска
                    $this->fileIndex[$filename] = $relativePath;
                    $this->fileIndex[$relativePath] = $relativePath;
                }
            }
        }
    }

    /**
     * Сканирует все указанные пути и их зависимости, возвращая массив уникальных файлов.
     *
     * @return array Список всех файлов, которые должны быть включены в объединение.
     */
    private function scanAllDependencies(): array
    {
        $allFiles = [];  // Массив для хранения всех найденных файлов.

        // Если paths пустой, сканируем весь projectDir
        if (empty($this->paths)) {
            $this->processDirectory('', $allFiles);
        } else {
            // Обрабатываем каждый путь из списка путей для сканирования.
            foreach ($this->paths as $path) {
                $fullPath = $this->projectDir . '/' . ltrim($path, '/');  // Формируем полный путь.

                if (is_file($fullPath)) {
                    // Если это файл, обрабатываем его.
                    $this->processFile($fullPath, $allFiles);
                } elseif (is_dir($fullPath)) {
                    // Если это директория, сканируем её.
                    $this->processDirectory($path, $allFiles);
                } else {
                    // Если путь не существует, выводим предупреждение.
                    echo "Предупреждение: Путь не существует или не соответствует условиям: $fullPath" . PHP_EOL;
                }
            }
        }

        // Возвращаем массив уникальных файлов.
        return array_unique($allFiles);
    }

    /**
     * Обрабатывает файл, добавляя его в массив всех файлов и сканируя его зависимости.
     *
     * @param string $fullPath Полный путь к файлу.
     * @param array &$allFiles Массив всех файлов для объединения.
     */
    private function processFile(string $fullPath, array &$allFiles): void
    {
        // Преобразуем полный путь в относительный для хранения.
        $relativePath = $this->makeRelativePath($fullPath, $this->projectDir);

        // Проверяем, должен ли файл быть включен, и не находится ли он в списке игнорируемых.
        if (
            $this->shouldIncludeFile(basename($fullPath), $this->extensions) &&
            !$this->isIgnoredFile($fullPath) &&
            !$this->isIgnoredDirectory($fullPath, $this->ignoreDirectories, $this->projectDir)
        ) {
            // Добавляем файл в список.
            $allFiles[] = $relativePath;

            // Если включено сканирование зависимостей, сканируем файл на предмет import'ов.
            if ($this->scanDependencies) {
                $allFiles = array_merge($allFiles, $this->scanDependencies($relativePath));
            }
        }
    }

    /**
     * Обрабатывает директорию, добавляя все файлы, соответствующие критериям.
     *
     * @param string $path Относительный путь к директории.
     * @param array &$allFiles Массив всех файлов для объединения.
     */
    private function processDirectory(string $path, array &$allFiles): void
    {
        $pathWithSlash = $path === '' ? '' : rtrim($path, '/') . '/';  // Убедимся, что путь оканчивается на слэш или пуст

        foreach ($this->fileIndex as $relativePath) {
            // Проверяем, что файл находится точно в директории или её поддиректории,
            // или обрабатываем все файлы, если путь пустой
            if ($path === '' || str_starts_with($relativePath, $pathWithSlash)) {
                if (
                    !$this->isIgnoredFile($this->projectDir . '/' . $relativePath) &&
                    !$this->isIgnoredDirectory($this->projectDir . '/' . $relativePath, $this->ignoreDirectories, $this->projectDir) &&
                    $this->shouldIncludeFile(basename($relativePath), $this->extensions)
                ) {

                    $allFiles[] = $relativePath;

                    // Если включено сканирование зависимостей, продолжаем сканирование
                    if ($this->scanDependencies) {
                        $allFiles = array_merge($allFiles, $this->scanDependencies($relativePath));
                    }
                }
            }
        }
    }

    /**
     * Рекурсивно сканирует зависимости файла и возвращает список всех найденных зависимостей.
     * Реализует оптимизированный алгоритм с множественными проверками для раннего выхода
     * и предотвращения лишней работы.
     *
     * @param string $file Относительный путь к файлу для сканирования
     * @param int $depth Текущая глубина рекурсии для контроля вложенности
     * @return array Массив уникальных путей к файлам зависимостей
     *
     * Оптимизации производительности:
     * - Кэширование результатов
     * - Быстрые проверки перед тяжелыми операциями
     * - Использование ассоциативного массива для scannedFiles
     * - Предварительная проверка наличия импортов
     */
    private function scanDependencies(string $file, int $depth = 0): array
    {
        // Быстрая проверка глубины рекурсии
        if ($depth > $this->maxDepth) {
            return [];
        }

        // Проверка кэша зависимостей
        if (isset($this->dependencyCache[$file])) {
            return $this->dependencyCache[$file];
        }

        // Формируем полный путь и проверяем расширение
        $fullPath = $this->projectDir . '/' . $file;
        $ext = pathinfo($fullPath, PATHINFO_EXTENSION);

        // Быстрая проверка поддерживаемых расширений
        if (!in_array($ext, self::DEPENDENCY_EXTENSIONS, true)) {
            return [];
        }

        // Проверка на циклические зависимости
        if (isset($this->scannedFiles[$file])) {
            return [];
        }

        // Отмечаем файл как сканируемый
        $this->scannedFiles[$file] = true;

        // Получаем содержимое файла
        $content = $this->getFileContent($fullPath);

        // Быстрая предварительная проверка наличия импортов
        if (strpos($content, 'import') === false) {
            unset($this->scannedFiles[$file]);
            return [];
        }

        // Извлекаем и обрабатываем зависимости
        $dependencies = $this->extractDependencies($content, $file, $depth);

        // Сохраняем результат в кэш и очищаем маркер сканирования
        $this->dependencyCache[$file] = $dependencies;
        unset($this->scannedFiles[$file]);

        return $dependencies;
    }

    /**
     * Извлекает все зависимости из содержимого файла.
     * Использует построчный анализ и упрощенное регулярное выражение
     * для повышения производительности.
     *
     * @param string $content Содержимое файла для анализа
     * @param string $currentFile Текущий обрабатываемый файл
     * @param int $depth Текущая глубина рекурсии
     * @return array Массив уникальных путей к файлам зависимостей
     *
     * Оптимизации производительности:
     * - Построчный анализ вместо анализа всего файла
     * - Предварительная проверка строки на наличие import
     * - Использование ассоциативного массива для уникальности
     * - Упрощенное регулярное выражение
     */
    private function extractDependencies(string $content, string $currentFile, int $depth): array
    {
        // Используем ассоциативный массив для автоматической дедупликации
        $dependencies = [];

        // Разбиваем содержимое на строки для построчного анализа
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            // Быстрая проверка наличия import в строке
            if (strpos($line, 'import') === false) continue;

            // Используем упрощенное регулярное выражение для извлечения пути
            if (preg_match('/[\'"]([^\'"]+)[\'"]/', $line, $match)) {
                $dependencyPath = $this->resolveDependencyPath($match[1], $currentFile);

                // Проверяем валидность пути и отсутствие дубликата
                if ($dependencyPath && !isset($dependencies[$dependencyPath])) {
                    $dependencies[$dependencyPath] = true;

                    // Рекурсивно обрабатываем зависимости если не достигнут максимум
                    if ($depth < $this->maxDepth) {
                        foreach ($this->scanDependencies($dependencyPath, $depth + 1) as $dep) {
                            $dependencies[$dep] = true;
                        }
                    }
                }
            }
        }

        // Возвращаем только ключи, так как значения были использованы только для дедупликации
        return array_keys($dependencies);
    }

    /**
     * Разрешает относительный путь зависимости относительно текущего файла.
     *
     * @param string $importPath Путь, указанный в import.
     * @param string $currentFile Текущий файл, для которого разрешается путь.
     * @return string|null Разрешённый относительный путь к файлу зависимости или null, если путь не найден.
     */
    private function resolveDependencyPath(string $importPath, string $currentFile): ?string
    {
        $currentDir = dirname($currentFile);

        // Проверяем относительные пути
        if (str_starts_with($importPath, './') || str_starts_with($importPath, '../')) {
            $resolvedPath = realpath($this->projectDir . '/' . $currentDir . '/' . $importPath);
            if ($resolvedPath && !$this->isIgnoredDirectory($resolvedPath, $this->ignoreDirectories, $this->projectDir)) {
                $relativePath = $this->makeRelativePath($resolvedPath, $this->projectDir);
                if (isset($this->fileIndex[$relativePath])) {
                    return $relativePath;
                }
            }
        }

        // Проверяем абсолютные пути (относительно корня проекта)
        $absolutePath = $this->projectDir . '/' . ltrim($importPath, '/');
        if (file_exists($absolutePath) && !$this->isIgnoredDirectory($absolutePath, $this->ignoreDirectories, $this->projectDir)) {
            $relativePath = $this->makeRelativePath($absolutePath, $this->projectDir);
            if (isset($this->fileIndex[$relativePath])) {
                return $relativePath;
            }
        }

        // Проверяем файл по базовому имени
        $basename = basename($importPath);
        if (isset($this->fileIndex[$basename])) {
            $fullPath = $this->projectDir . '/' . $this->fileIndex[$basename];
            if (!$this->isIgnoredDirectory($fullPath, $this->ignoreDirectories, $this->projectDir)) {
                return $this->fileIndex[$basename];
            }
        }

        // Проверяем с добавлением расширений
        foreach (self::DEPENDENCY_EXTENSIONS as $ext) {
            $filenameWithExt = $basename . '.' . $ext;
            if (isset($this->fileIndex[$filenameWithExt])) {
                $fullPath = $this->projectDir . '/' . $this->fileIndex[$filenameWithExt];
                if (!$this->isIgnoredDirectory($fullPath, $this->ignoreDirectories, $this->projectDir)) {
                    return $this->fileIndex[$filenameWithExt];
                }
            }
        }

        return null;  // Путь не найден
    }

    /**
     * Объединяет содержимое всех файлов в один выходной файл.
     * Использует оптимизированный подход с массивом чанков вместо прямой конкатенации строк.
     * Кэширует часто используемые значения для повышения производительности.
     *
     * @param array $paths Массив относительных путей к файлам для объединения
     * @return string Объединенное содержимое всех файлов с разделителями
     *
     * Особенности реализации:
     * - Использование static кэширования для rootFolderName
     * - Массив чанков для эффективного объединения строк
     * - Оптимизированные проверки существования файлов
     * - Улучшенное форматирование выходного контента
     */
    private function mergePaths(array $paths): string
    {
        // Кэшируем имя корневой директории между вызовами
        // Используем оператор null coalescing для инициализации
        static $rootFolderName;
        $rootFolderName ??= basename($this->projectDir);

        // Инициализируем массив для хранения частей контента
        // Это эффективнее чем прямая конкатенация строк
        $chunks = [];

        foreach ($paths as $relativePath) {
            // Формируем полный путь к файлу
            $absoluteFilePath = $this->projectDir . '/' . $relativePath;

            // Проверяем существование файла через is_file
            // Это быстрее чем file_exists, так как проверяет только файлы
            if (!is_file($absoluteFilePath)) {
                echo "Предупреждение: Файл не существует: $absoluteFilePath" . PHP_EOL;
                continue;
            }

            // Добавляем разделители и содержимое в массив чанков
            // Это позволяет избежать многократной конкатенации строк
            $chunks[] = "// Начало файла -> /$rootFolderName/$relativePath";
            $chunks[] = $this->applyFilters($this->getFileContent($absoluteFilePath));
            $chunks[] = "// Конец файла -> /$rootFolderName/$relativePath\n";
        }

        // Объединяем все чанки одной операцией
        // implode эффективнее чем последовательная конкатенация
        return implode(PHP_EOL, $chunks);
    }

    /**
     * Получает содержимое файла с оптимизированным кэшированием.
     * Учитывает наличие OPcache для выбора оптимального метода чтения.
     * Реализует ленивую загрузку содержимого файлов.
     *
     * @param string $filePath Полный путь к файлу
     * @return string Содержимое файла
     *
     * Особенности реализации:
     * - Проверка доступности OPcache
     * - Использование stream_get_contents как альтернативы
     * - Кэширование результатов проверки OPcache
     * - Оптимизированное хранение содержимого файлов
     */
    private function getFileContent(string $filePath): string
    {
        // Кэшируем результат проверки OPcache между вызовами
        static $opcache;
        $opcache ??= function_exists('opcache_get_status') && opcache_get_status(false);

        // Используем nullsafe оператор для комбинации проверки и присваивания
        // Выбираем оптимальный метод чтения в зависимости от наличия OPcache
        return $this->contentCache[$filePath] ??= ($opcache ?
            file_get_contents($filePath) :
            stream_get_contents(fopen($filePath, 'r'))
        );
    }

    /**
     * Применяет настроенные фильтры к содержимому файла.
     * Использует кэширование регулярных выражений для оптимизации производительности.
     * Поддерживает различные типы фильтрации: стили, комментарии, пустые строки.
     *
     * @param string $content Исходное содержимое файла
     * @return string Отфильтрованное содержимое
     *
     * Особенности реализации:
     * - Статическое кэширование паттернов
     * - Ленивая инициализация фильтров
     * - Оптимизированные регулярные выражения
     * - Условное применение фильтров
     */
    private function applyFilters(string $content): string
    {
        // Кэшируем массивы паттернов и замен между вызовами
        static $patterns = null;
        static $replacements = null;

        // Ленивая инициализация фильтров при первом вызове
        if ($patterns === null) {
            $patterns = [];
            $replacements = [];

            // Добавляем фильтры только если они включены в настройках
            if ($this->removeStyleTag) {
                $patterns[] = '/<style.*?>.*?<\/style>/s';
                $replacements[] = '';
            }
            if ($this->removeHtmlComments) {
                $patterns[] = '/<!--.*?-->/s';
                $replacements[] = '';
            }
            if ($this->removeSingleLineComments) {
                $patterns[] = '!^\s*//.*?(\r?\n|\r)!m';
                $replacements[] = '';
            }
            if ($this->removeMultiLineComments) {
                $patterns[] = '!/\*[\s\S]*?\*/\s*!';
                $replacements[] = '';
            }
            if ($this->removeEmptyLines) {
                $patterns[] = "/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/";
                $replacements[] = PHP_EOL;
            }
        }

        // Применяем фильтры только если они определены
        return $patterns ? rtrim(preg_replace($patterns, $replacements, $content)) : $content;
    }

    /**
     * Рассчитывает и возвращает информацию о строках файлов в итоговом контенте.
     *
     * @param string $mergedContent Объединённое содержимое всех файлов.
     * @return array Массив информации о начальных и конечных строках каждого файла.
     */
    private function calculateFileLinesInfo(string $mergedContent): array
    {
        $lines = explode(PHP_EOL, $mergedContent);  // Разбиваем контент на строки.
        $fileLinesInfo = [];  // Массив для хранения информации о файлах.
        $currentFile = null;  // Текущий файл, для которого собирается информация.
        $startLine = 0;  // Номер строки, с которой начинается файл в итоговом контенте.
        $lineCount = 0;  // Счётчик строк текущего файла.

        // Проходим по каждой строке.
        foreach ($lines as $index => $line) {
            // Если строка содержит начало файла, сохраняем информацию о предыдущем файле.
            if (preg_match('/\/\/ Начало файла -> (.+)/', $line, $matches)) {
                if ($currentFile) {
                    $fileLinesInfo[] = "$currentFile (строки $startLine - " . ($startLine + $lineCount - 1) . ")";
                }
                // Обновляем данные для нового файла.
                $currentFile = $matches[1];
                $startLine = $index + 2;
                $lineCount = 0;
            } elseif (preg_match('/\/\/ Конец файла -> /', $line)) {
                // Если строка содержит конец файла, сохраняем информацию о текущем файле.
                if ($currentFile) {
                    $fileLinesInfo[] = "$currentFile (строки $startLine - " . ($startLine + $lineCount - 1) . ")";
                    $currentFile = null;
                }
            } elseif ($currentFile) {
                $lineCount++;  // Увеличиваем счётчик строк для текущего файла.
            }
        }

        return $fileLinesInfo;  // Возвращаем собранную информацию о строках файлов.
    }

    /**
     * Выводит информацию о строках файлов в итоговом контенте в консоль.
     *
     * @param array $fileLinesInfo Массив информации о начальных и конечных строках каждого файла.
     */
    private function printConsoleOutput(array $fileLinesInfo): void
    {
        $consoleOutput = '';

        if ($this->includeInstructions) {
            $consoleOutput .= 'Ниже написано содержание прикреплённого файла в котором объединён код нескольких файлов проекта. Это содержание указывает на начальные и конечные строки файлов которые были объеденины в прикреплённый файл к этому сообщению:' . PHP_EOL;
        }

        foreach ($fileLinesInfo as $info) {
            $consoleOutput .= $info . PHP_EOL;
        }

        if ($this->includeInstructions) {
            $consoleOutput .= PHP_EOL . 'Отвечай, как опытный программист с более чем 10-летним стажем. Когда отвечаешь выбирай современные практики (лучшие подходы). После прочтения жди вопросы по этому коду, просто жди вопросов, не нужно ничего отвечать.' . str_repeat(PHP_EOL, 2);
        }

        echo $consoleOutput;

        // Если доступна команда pbcopy (для macOS), копируем результат в буфер обмена.
        exec("which pbcopy > /dev/null && printf " . escapeshellarg(trim($consoleOutput)) . " | pbcopy");
    }

    /**
     * Выводит информацию о строках файлов в итоговом контенте в консоль,
     * сохраняет эту информацию в файл и копирует в буфер обмена (если доступно).
     *
     * @param array $fileLinesInfo Массив информации о начальных и конечных строках каждого файла.
     */
    private function printAndSaveFileList(array $fileLinesInfo): void
    {
        $consoleOutput = '';
        $fileListContent = '';

        if ($this->includeInstructions) {
            $consoleOutput .= 'Ниже написано содержание прикреплённого файла в котором объединён код нескольких файлов проекта. Это содержание указывает на начальные и конечные строки файлов которые были объеденины в прикреплённый файл к этому сообщению:' . PHP_EOL;
        }

        foreach ($fileLinesInfo as $info) {
            $consoleOutput .= $info . PHP_EOL;
            $fileListContent .= $info . PHP_EOL;
        }

        if ($this->includeInstructions) {
            $consoleOutput .= PHP_EOL . 'Отвечай, как опытный программист с более чем 10-летним стажем. Когда отвечаешь выбирай современные практики (лучшие подходы). После прочтения жди вопросы по этому коду, просто жди вопросов, не нужно ничего отвечать.' . str_repeat(PHP_EOL, 2);
        }

        echo $consoleOutput;
        file_put_contents($this->fileListOutputFile, $fileListContent);

        // Если доступна команда pbcopy (для macOS), копируем результат в буфер обмена.
        exec("which pbcopy > /dev/null && printf " . escapeshellarg(trim($consoleOutput)) . " | pbcopy");
    }

    /**
     * Преобразует абсолютный путь файла в относительный путь.
     * Реализует максимально эффективный способ получения относительного пути
     * без использования дополнительных функций и промежуточных преобразований.
     *
     * @param string $filePath Абсолютный путь к файлу
     * @param string $basePath Базовый путь, относительно которого нужно получить путь
     * @return string Относительный путь к файлу
     *
     * Особенности реализации:
     * - Использует substr вместо str_replace для повышения производительности
     * - Избегает дополнительных проверок и преобразований
     * - Предполагает корректность входных данных для максимальной скорости
     */
    private function makeRelativePath(string $filePath, string $basePath): string
    {
        // Используем substr для прямого извлечения относительного пути
        // Добавляем 1 к длине для пропуска разделяющего слеша
        return substr($filePath, strlen($basePath) + 1);
    }

    /**
     * Проверяет, находится ли указанный файл в игнорируемой директории.
     * Метод использует эффективное кэширование результатов и оптимизированную 
     * обработку путей для повышения производительности при повторных проверках 
     * одних и тех же директорий.
     *
     * @param string $filePath Полный путь к проверяемому файлу
     * @param array $ignoreDirectories Массив путей к игнорируемым директориям
     * @param string $basePath Базовый путь проекта для создания относительных путей
     * @return bool Возвращает true, если файл находится в игнорируемой директории
     *
     * Особенности реализации:
     * - Использует статическое кэширование для хранения результатов проверок
     * - Оптимизирует работу с путями через substr вместо makeRelativePath
     * - Применяет раннее возвращение результата при наличии кэша
     */
    private function isIgnoredDirectory(string $filePath, array $ignoreDirectories, string $basePath): bool
    {
        // Создаем статический кэш для хранения результатов между вызовами метода.
        // Это особенно эффективно при повторных проверках одних и тех же путей.
        static $cache = [];

        // Проверяем наличие результата в кэше перед выполнением проверок
        if (isset($cache[$filePath])) {
            return $cache[$filePath];
        }

        // Получаем относительный путь напрямую через substr вместо вызова makeRelativePath
        // Это быстрее, так как избегает создания промежуточных строк
        $relativeFilePath = substr($filePath, strlen($basePath) + 1);

        // Проверяем каждую игнорируемую директорию
        foreach ($ignoreDirectories as $ignoredDir) {
            // Используем str_starts_with для эффективной проверки начала строки
            // trim удаляет слеши для унификации формата путей
            if (str_starts_with($relativeFilePath, trim($ignoredDir, '/'))) {
                // Сохраняем положительный результат в кэш и возвращаем его
                return $cache[$filePath] = true;
            }
        }

        // Сохраняем отрицательный результат в кэш и возвращаем его
        return $cache[$filePath] = false;
    }

    /**
     * Проверяет, находится ли файл в списке игнорируемых файлов.
     *
     * @param string $filePath Полный путь к файлу.
     * @return bool Возвращает true, если файл игнорируется.
     */
    private function isIgnoredFile(string $filePath): bool
    {
        return in_array(basename($filePath), $this->ignoreFiles);
    }

    /**
     * Определяет, должен ли файл быть включен в обработку на основе его расширения.
     * Метод реализует эффективное кэширование результатов и оптимизированную проверку
     * расширений файлов для ускорения повторных проверок.
     *
     * @param string $filename Имя проверяемого файла
     * @param array $extensions Массив разрешенных расширений файлов
     * @return bool Возвращает true, если файл должен быть обработан
     *
     * Особенности реализации:
     * - Использует статическое кэширование результатов проверок
     * - Оптимизирует извлечение расширения через strrchr вместо pathinfo
     * - Применяет строгое сравнение для повышения надежности
     * - Реализует быструю проверку для wildcards
     */
    private function shouldIncludeFile(string $filename, array $extensions): bool
    {
        // Создаем статический кэш для хранения результатов проверок.
        // Это значительно ускоряет работу при повторных проверках одних и тех же файлов.
        static $cache = [];

        // Проверяем наличие результата в кэше
        if (isset($cache[$filename])) {
            return $cache[$filename];
        }

        // Быстрая проверка на wildcard в списке расширений
        // Это позволяет быстро вернуть результат для случая, когда принимаются все файлы
        if (in_array('*', $extensions, true)) {
            return $cache[$filename] = true;
        }

        // Извлекаем расширение файла используя strrchr
        // Это быстрее чем pathinfo, так как выполняет только одну операцию
        $ext = substr(strrchr($filename, '.'), 1);

        // Сохраняем результат в кэш и возвращаем его
        // Проверяем наличие расширения и его присутствие в списке разрешенных
        return $cache[$filename] = $ext && in_array($ext, $extensions, true);
    }

    /**
     * Проверяет и возвращает валидный метод индексации файлов.
     * Если указан неверный метод, возвращает метод по умолчанию (PHP).
     *
     * @param string $method Метод индексации из конфигурации
     * @return string Валидный метод индексации
     */
    private function validateAndGetIndexingMethod(string $method): string
    {
        $method = strtolower($method);
        if (!in_array($method, self::AVAILABLE_INDEXING_METHODS, true)) {
            trigger_error(
                sprintf(
                    'Неверный метод индексации файлов "%s". Используется метод по умолчанию "%s"',
                    $method,
                    self::FILE_INDEXING_PHP
                ),
                E_USER_NOTICE
            );
            return self::FILE_INDEXING_PHP;
        }
        return $method;
    }
}
