<?php

/**
 * Класс MergeFiles предназначен для объединения файлов из различных директорий в один выходной файл.
 * Он поддерживает фильтрацию файлов по расширениям, удаление ненужных фрагментов кода (комментариев, стилей, пустых строк) и сканирование зависимостей.
 * Основное использование — автоматическая сборка файлов проекта, включая рекурсивные зависимости.
 */
class MergeFiles
{
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
     *  - fileListOutputFile (string): Имя файла, в который будет записан список объединённых файлов.
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
     * Строит индекс всех файлов проекта, соответствующих указанным расширениям.
     * Индекс необходим для ускоренного поиска файлов по их имени при сканировании зависимостей.
     */
    private function buildFileIndex(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->projectDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $this->shouldIncludeFile($file->getFilename(), $this->extensions)) {
                $relativePath = $this->makeRelativePath($file->getPathname(), $this->projectDir);
                if (!$this->isIgnoredDirectory($file->getPathname(), $this->ignoreDirectories, $this->projectDir)) {
                    $this->fileIndex[$file->getBasename()] = $relativePath;
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
        if ($this->shouldIncludeFile(basename($fullPath), $this->extensions) &&
            !$this->isIgnoredFile($fullPath) &&
            !$this->isIgnoredDirectory($fullPath, $this->ignoreDirectories, $this->projectDir)) {
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
        $pathWithSlash = $path === '' ? '' : rtrim($path, '/') . '/';  // Убедимся, что путь оканчивается на слеш или пуст

        foreach ($this->fileIndex as $relativePath) {
            // Проверяем, что файл находится точно в директории или её поддиректории,
            // или обрабатываем все файлы, если путь пустой
            if ($path === '' || str_starts_with($relativePath, $pathWithSlash)) {
                if (!$this->isIgnoredFile($this->projectDir . '/' . $relativePath) &&
                    !$this->isIgnoredDirectory($this->projectDir . '/' . $relativePath, $this->ignoreDirectories, $this->projectDir) &&
                    $this->shouldIncludeFile(basename($relativePath), $this->extensions)) {

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
     * Рекурсивно сканирует зависимости файла (например, import'ы) и добавляет их в список для объединения.
     *
     * @param string $file Относительный путь к файлу.
     * @param int $depth Текущая глубина рекурсии (используется для ограничения по maxDepth).
     * @return array Список зависимостей, которые нужно включить в объединение.
     */
    private function scanDependencies(string $file, int $depth = 0): array
    {
        if ($depth > $this->maxDepth || isset($this->dependencyCache[$file])) {
            return $this->dependencyCache[$file] ?? [];
        }

        $fullPath = $this->projectDir . '/' . $file;
        if (!file_exists($fullPath) || is_dir($fullPath) ||
            !$this->shouldIncludeFile($fullPath, self::DEPENDENCY_EXTENSIONS) ||
            $this->isIgnoredDirectory($fullPath, $this->ignoreDirectories, $this->projectDir)) {
            return [];
        }

        if (in_array($file, $this->scannedFiles)) {
            return [];
        }

        $this->scannedFiles[] = $file;

        $content = $this->getFileContent($fullPath);
        $dependencies = $this->extractDependencies($content, $file, $depth);

        $this->dependencyCache[$file] = $dependencies;
        array_pop($this->scannedFiles);

        return $dependencies;
    }

    /**
     * Извлекает зависимости (import'ы) из содержимого файла.
     *
     * @param string $content Содержимое файла.
     * @param string $currentFile Текущий файл, для которого извлекаются зависимости.
     * @param int $depth Текущая глубина рекурсии.
     * @return array Список зависимостей, найденных в файле.
     */
    private function extractDependencies(string $content, string $currentFile, int $depth): array
    {
        $dependencies = [];
        $importRegex = '/import\s+(?:(?:\w+\s*,\s*)?(?:{[^}]+})?|\w+|\*\s+as\s+\w+)\s+from\s+[\'"]([^\'"]+)[\'"]/';
        if (preg_match_all($importRegex, $content, $matches)) {
            foreach ($matches[1] as $match) {
                $dependencyPath = $this->resolveDependencyPath($match, $currentFile);
                if ($dependencyPath && !in_array($dependencyPath, $dependencies)) {
                    $dependencies[] = $dependencyPath;
                    if ($depth < $this->maxDepth) {
                        $dependencies = array_merge($dependencies, $this->scanDependencies($dependencyPath, $depth + 1));
                    }
                }
            }
        }

        return array_unique($dependencies);
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
     * Объединяет содержимое всех переданных путей в один строковый блок.
     *
     * @param array $paths Массив относительных путей к файлам.
     * @return string Объединённое содержимое всех файлов.
     */
    private function mergePaths(array $paths): string
    {
        $mergedContent = '';  // Переменная для хранения содержимого всех файлов.

        // Обрабатываем каждый путь в списке.
        foreach ($paths as $relativePath) {
            $absoluteFilePath = $this->projectDir . '/' . $relativePath;  // Преобразуем в абсолютный путь.
            if (!file_exists($absoluteFilePath)) {
                echo "Предупреждение: Файл не существует: $absoluteFilePath" . PHP_EOL;  // Предупреждение, если файл не найден.
                continue;
            }

            // Читаем содержимое файла и применяем к нему фильтры.
            $content = $this->getFileContent($absoluteFilePath);
            $content = $this->applyFilters($content);

            // Добавляем заголовок для файла в итоговый контент.
            $rootFolderName = basename($this->projectDir);
            $fullRelativePath = '/' . $rootFolderName . '/' . $relativePath;

            // Включаем комментарии, указывающие на начало и конец каждого файла.
            $mergedContent .= "// Начало файла -> $fullRelativePath" . PHP_EOL;
            $mergedContent .= $content . PHP_EOL;
            $mergedContent .= "// Конец файла -> $fullRelativePath" . str_repeat(PHP_EOL, 2);
        }

        return rtrim($mergedContent, PHP_EOL);  // Возвращаем итоговое содержимое.
    }

    /**
     * Получает содержимое файла с использованием кеша для повышения производительности.
     *
     * @param string $filePath Полный путь к файлу.
     * @return string Содержимое файла.
     */
    private function getFileContent(string $filePath): string
    {
        // Если файл уже закеширован, возвращаем содержимое из кеша.
        if (!isset($this->contentCache[$filePath])) {
            $this->contentCache[$filePath] = file_get_contents($filePath);  // Читаем содержимое файла и сохраняем в кеш.
        }

        return $this->contentCache[$filePath];  // Возвращаем содержимое.
    }

    /**
     * Применяет фильтры к содержимому файла (удаление стилей, комментариев, пустых строк).
     *
     * @param string $content Содержимое файла.
     * @return string Отфильтрованное содержимое файла.
     */
    private function applyFilters(string $content): string
    {
        $patterns = [];  // Массив регулярных выражений для фильтрации.
        $replacements = [];  // Массив замен для каждого паттерна.

        // Удаление тегов <style>, если это указано в настройках.
        if ($this->removeStyleTag) {
            $patterns[] = '/<style.*?>.*?<\/style>/s';
            $replacements[] = '';
        }
        // Удаление HTML-комментариев.
        if ($this->removeHtmlComments) {
            $patterns[] = '/<!--.*?-->/s';
            $replacements[] = '';
        }
        // Удаление однострочных комментариев.
        if ($this->removeSingleLineComments) {
            $patterns[] = '!^\s*//.*?(\r?\n|\r)!m';
            $replacements[] = '';
        }
        // Удаление многострочных комментариев.
        if ($this->removeMultiLineComments) {
            $patterns[] = '!/\*[\s\S]*?\*/\s*!';
            $replacements[] = '';
        }
        // Удаление пустых строк.
        if ($this->removeEmptyLines) {
            $patterns[] = "/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/";
            $replacements[] = PHP_EOL;
        }

        // Если есть фильтры, применяем их.
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
     * Преобразует абсолютный путь в относительный.
     *
     * @param string $filePath Полный путь к файлу.
     * @param string $basePath Базовая директория, относительно которой строится путь.
     * @return string Относительный путь.
     */
    private function makeRelativePath(string $filePath, string $basePath): string
    {
        $relativePath = str_replace($basePath, '', $filePath);  // Убираем базовую директорию из пути.

        return ltrim($relativePath, '/');  // Убираем начальный слеш, если он присутствует.
    }

    /**
     * Проверяет, находится ли файл в игнорируемой директории.
     *
     * @param string $filePath Полный путь к файлу.
     * @param array $ignoreDirectories Массив игнорируемых директорий.
     * @param string $basePath Базовая директория проекта.
     * @return bool Возвращает true, если файл находится в игнорируемой директории.
     */
    private function isIgnoredDirectory(string $filePath, array $ignoreDirectories, string $basePath): bool
    {
        $relativeFilePath = $this->makeRelativePath($filePath, $basePath);

        foreach ($ignoreDirectories as $ignoredDir) {
            $ignoredDir = trim($ignoredDir, '/');
            if (str_starts_with($relativeFilePath, $ignoredDir) || $relativeFilePath === $ignoredDir) {
                return true;
            }
        }

        return false;
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
     * Проверяет, должен ли файл быть включён в процесс объединения на основе его расширения.
     *
     * @param string $filename Имя файла.
     * @param array $extensions Массив разрешённых расширений файлов.
     * @return bool Возвращает true, если файл соответствует нужным расширениям.
     */
    private function shouldIncludeFile(string $filename, array $extensions): bool
    {
        return in_array('*', $extensions) || in_array(pathinfo($filename, PATHINFO_EXTENSION), $extensions);
    }
}
