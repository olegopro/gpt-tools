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

    // Регулярные выражения для фильтрации различных типов контента в файлах
    private const STYLE_TAG_PATTERN = '/<style.*?>.*?<\/style>/s';              // Удаляет теги style с их содержимым
    private const HTML_COMMENTS_PATTERN = '/<!--.*?-->/s';                      // Удаляет HTML комментарии
    private const SINGLE_LINE_COMMENTS_PATTERN = '!^\s*//.*?(\r?\n|\r)!m';      // Удаляет однострочные комментарии
    private const MULTI_LINE_COMMENTS_PATTERN = '!/\*[\s\S]*?\*/\s*!';          // Удаляет многострочные комментарии 
    private const EMPTY_LINES_PATTERN = "/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/";   // Удаляет пустые строки

    /**
     * Все регулярные выражения для поиска различных типов импортов в JavaScript/TypeScript/Vue файлах.
     * Оптимизированы для работы с ES6+, CommonJS, динамическими импортами и специфичными для Vue/TypeScript конструкциями.
     */
    private const PATTERNS = [
        // ES6+ импорты с деструктуризацией и множественными экспортами
        // import DefaultExport from './module'
        // import { export1, export2 as alias2 } from './module' 
        // import * as name from './module'
        // import DefaultExport, { export1, export2 } from './module'
        // import { Nullable }, asd from './types'
        // import { Type }, DefaultExport, { Other } from './module'
        'ES6_IMPORTS' => '/import\s+(?:(?:{[^}]+}|\*\s+as\s+[^,]+|[\w\d$_]+)\s*,?\s*)*from\s+[\'"]([^\'"]+)[\'"]/',

        // Импорты для побочных эффектов
        // import './styles.css'
        // import '@/plugins/vuetify'
        // import 'normalize.css'
        // import type { Type } from './types'
        'SIDE_EFFECT_IMPORTS' => '/import\s+(?:type\s+)?[\'"]([^\'"]+)[\'"]/',

        // CommonJS require
        // const module = require('./module')
        // require('@/utils/helper')
        // let { method } = require('some-module')
        // const { default: alias } = require('./module')
        'COMMONJS_REQUIRE' => '/require\s*\(\s*[\'"]([^\'"]+)[\'"]/',

        // Динамические импорты
        // import('./module').then(module => {})
        // const module = await import('./module')
        // require.ensure(['./module'], function() {})
        // const module = await require('./module')
        'DYNAMIC_IMPORTS' => '/(?:import|require)\s*\(\s*[\'"]([^\'"]+)[\'"]/',

        // Vue специфичные импорты через defineAsyncComponent
        // defineAsyncComponent(() => import('./AsyncComponent.vue'))
        // defineAsyncComponent(() => import('@/components/MyComponent.vue'))
        // defineAsyncComponent(async () => await import('./AsyncComponent.vue'))
        'VUE_ASYNC_COMPONENT' => '/defineAsyncComponent\s*\(\s*(?:async\s*)?\(\s*\)\s*=>\s*(?:await\s*)?import\s*\(\s*[\'"]([^\'"]+)[\'"]/',

        // Vue компонент с динамическим импортом
        // component: () => import('./LazyComponent.vue')
        // components: { AsyncComponent: () => import('./AsyncComponent.vue') }
        // component: async () => await import('./LazyComponent.vue')
        'VUE_LAZY_COMPONENT' => '/component:\s*(?:async\s*)?\(\s*\)\s*=>\s*(?:await\s*)?import\s*\(\s*[\'"]([^\'"]+)[\'"]/',

        // TypeScript типы импорты
        // import type { Type } from './types'
        // import type DefaultType from './types'
        'TS_TYPE_IMPORTS' => '/import\s+type\s+(?:{[^}]+}|[\w\d$_]+)\s+from\s+[\'"]([^\'"]+)[\'"]/',

        // Комбинированные импорты типов TypeScript
        // import { type Type, OtherExport } from './types'
        // import { type Type as AliasType, OtherExport } from './types'
        'TS_COMBINED_IMPORTS' => '/import\s+{[^}]*?(?:type\s+[\w\d$_]+\s*(?:as\s+[\w\d$_]+\s*)?)[^}]*?}\s+from\s+[\'"]([^\'"]+)[\'"]/',

        // Vue 3 определения компонентов
        // defineCustomElement(() => import('./MyElement.ce.vue'))
        // defineCustomElement(async () => await import('./MyElement.ce.vue'))
        'VUE_CUSTOM_ELEMENT' => '/defineCustomElement\s*\(\s*(?:async\s*)?\(\s*\)\s*=>\s*(?:await\s*)?import\s*\(\s*[\'"]([^\'"]+)[\'"]/'
    ];

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
    private array $fileIndex = [];          // Индекс файлов в проекте для быстрого поиска по имени.
    private array $dependencyCache = [];    // Кеш зависимостей для предотвращения повторного сканирования.
    private array $contentCache = [];       // Кеш содержимого файлов для предотвращения повторного чтения.
    private array $scannedFiles = [];       // Список уже сканированных файлов для избежания зацикливания.

    // Постоянные данные — поддерживаемые расширения для поиска зависимостей
    private const array DEPENDENCY_EXTENSIONS = ['vue', 'js', 'ts'];

    /**
     * Конструктор класса MergeFiles.
     * Инициализирует объект с конфигурацией для процесса объединения и обработки файлов.
     * Поддерживает различные методы индексации, фильтрации и обработки зависимостей.
     * 
     * @param array $config Ассоциативный массив параметров конфигурации:
     *     @type string $projectDir           Корневая директория проекта (абсолютный путь)
     *     @type array  $paths               Массив относительных путей для обработки
     *     @type bool   $scanDependencies    Включает сканирование зависимостей (import/require)
     *     @type array  $extensions          Допустимые расширения файлов ['js', 'vue', 'ts' и т.д.]
     *     @type bool   $removeStyleTag      Удаление тегов <style> и их содержимого
     *     @type bool   $removeHtmlComments  Удаление HTML-комментариев <!-- -->
     *     @type bool   $removeSingleLineComments Удаление однострочных комментариев
     *     @type bool   $removeMultiLineComments  Удаление многострочных комментариев
     *     @type bool   $removeEmptyLines    Удаление пустых строк и лишних пробелов
     *     @type bool   $includeInstructions Добавление инструкций в консольный вывод
     *     @type array  $ignoreFiles         Массив имен файлов для исключения
     *     @type array  $ignoreDirectories   Массив путей директорий для исключения
     *     @type string $outputFile          Путь к файлу для сохранения результата
     *     @type int    $maxDepth            Максимальная глубина рекурсии (по умолчанию 1000)
     *     @type string $fileListOutputFile  Путь к файлу для списка обработанных файлов
     *     @type string $fileIndexingMethod  Метод индексации ('php'|'system', по умолчанию 'php')
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
    public function buildFileIndexUsingPhp(): void
    {
        $queue = new SplQueue();
        $queue->enqueue($this->projectDir);

        while (!$queue->isEmpty()) {
            $currentDir = $queue->dequeue();
            $iterator = new DirectoryIterator($currentDir);

            foreach ($iterator as $file) {
                if ($file->isDot()) continue;

                if ($file->isDir()) {
                    if (!$this->isIgnoredDirectory($file->getPathname(), $this->ignoreDirectories, $this->projectDir)) {
                        $queue->enqueue($file->getPathname());
                    }
                } elseif ($file->isFile() && $this->shouldIncludeFile($file->getFilename(), $this->extensions)) {
                    $relativePath = $this->makeRelativePath($file->getPathname(), $this->projectDir);
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
        if (strpos($content, 'import') === false && strpos($content, 'require') === false) {
            unset($this->scannedFiles[$file]);
            return [];
        }

        // Извлекаем прямые зависимости
        $directDependencies = $this->extractDependencies($content, $file, $depth);

        // Инициализируем массив всех зависимостей, начиная с прямых
        $allDependencies = $directDependencies;

        // Рекурсивно обрабатываем каждую найденную зависимость
        foreach ($directDependencies as $dependency) {
            // Избегаем повторной обработки текущего файла для предотвращения зацикливания
            if ($dependency !== $file) {
                // Рекурсивный вызов для получения зависимостей зависимостей
                $nestedDependencies = $this->scanDependencies($dependency, $depth + 1);

                // Объединяем с общим массивом зависимостей
                if (!empty($nestedDependencies)) {
                    $allDependencies = array_merge($allDependencies, $nestedDependencies);
                }
            }
        }

        // Удаляем дубликаты и сохраняем результат в кэш
        $uniqueDependencies = array_unique($allDependencies);
        $this->dependencyCache[$file] = $uniqueDependencies;

        // Очищаем маркер сканирования
        unset($this->scannedFiles[$file]);

        return $uniqueDependencies;
    }

    /**
     * Извлекает все зависимости из файла, учитывая его тип (Vue/JS/TS)
     * и различные форматы импортов
     *
     * @param string $content Содержимое файла для анализа
     * @param string $currentFile Текущий обрабатываемый файл
     * @param int $depth Текущая глубина рекурсии
     * @return array Массив найденных зависимостей
     */
    private function extractDependencies(string $content, string $currentFile, int $depth): array
    {
        $dependencies = [];
        $extension = pathinfo($currentFile, PATHINFO_EXTENSION);

        if ($extension === 'vue') {
            // Для Vue файлов извлекаем содержимое всех script тегов
            preg_match_all('/<script(?:\s+[^>]*)?>(.*?)<\/script>/s', $content, $matches);

            foreach ($matches[1] as $scriptContent) {
                // Обрабатываем каждый script блок отдельно, передавая текущий файл
                $dependencies = array_merge($dependencies, $this->extractScriptDependencies($scriptContent, $currentFile));
            }
        } else {
            // Для JS/TS файлов обрабатываем весь контент, передавая текущий файл
            $dependencies = $this->extractScriptDependencies($content, $currentFile);
        }

        return array_unique($dependencies);
    }

    /**
     * Извлекает зависимости из JavaScript/TypeScript кода.
     * Использует кэширование результатов и предварительные проверки для оптимизации производительности.
     * Поддерживает различные форматы импортов: ES6+, CommonJS, динамические импорты,
     * Vue компоненты и TypeScript конструкции.
     * 
     * @param string $content Содержимое JavaScript/TypeScript кода
     * @param string $currentFile Текущий обрабатываемый файл для разрешения относительных путей
     * @return array Массив разрешенных путей к файлам зависимостей
     */
    private function extractScriptDependencies(string $content, string $currentFile): array
    {
        // Кэшируем результаты для оптимизации повторных проверок
        static $dependencyCache = [];

        // Создаём уникальный ключ для кэша
        $cacheKey = md5($content . $currentFile);

        // Проверяем наличие результата в кэше
        if (isset($dependencyCache[$cacheKey])) {
            return $dependencyCache[$cacheKey];
        }

        // Быстрая предварительная проверка на наличие импортов
        if (strpos($content, 'import') === false && strpos($content, 'require') === false) {
            return $dependencyCache[$cacheKey] = [];
        }

        // Инициализируем массивы для сбора зависимостей
        $dependencies = [];
        $importPaths = [];

        // Извлекаем все пути импортов используя предопределенные паттерны
        foreach (self::PATTERNS as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                $importPaths = array_merge($importPaths, $matches[1]);
            }
        }

        // Обрабатываем найденные пути импортов
        if (!empty($importPaths)) {
            // Удаляем дубликаты путей
            $importPaths = array_unique($importPaths);

            // Разрешаем каждый путь импорта
            foreach ($importPaths as $importPath) {
                $resolvedPath = $this->resolveDependencyPath($importPath, $currentFile);
                if ($resolvedPath) {
                    $dependencies[] = $resolvedPath;
                }
            }
        }

        // Кэшируем и возвращаем уникальные зависимости
        return $dependencyCache[$cacheKey] = array_values(array_unique($dependencies));
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
     * Использует предопределенные константы паттернов для оптимизации производительности.
     * Поддерживает удаление стилей, комментариев и пустых строк на основе конфигурации.
     * 
     * @param string $content Исходное содержимое файла
     * @return string Отфильтрованное содержимое
     */
    private function applyFilters(string $content): string
    {
        // Формируем массивы паттернов и замен на основе конфигурации
        $patterns = [];
        $replacements = [];

        // Добавляем фильтр для тегов style
        if ($this->removeStyleTag) {
            $patterns[] = self::STYLE_TAG_PATTERN;
            $replacements[] = '';
        }

        // Добавляем фильтр для HTML комментариев
        if ($this->removeHtmlComments) {
            $patterns[] = self::HTML_COMMENTS_PATTERN;
            $replacements[] = '';
        }

        // Добавляем фильтр для однострочных комментариев
        if ($this->removeSingleLineComments) {
            $patterns[] = self::SINGLE_LINE_COMMENTS_PATTERN;
            $replacements[] = '';
        }

        // Добавляем фильтр для многострочных комментариев
        if ($this->removeMultiLineComments) {
            $patterns[] = self::MULTI_LINE_COMMENTS_PATTERN;
            $replacements[] = '';
        }

        // Добавляем фильтр для пустых строк
        if ($this->removeEmptyLines) {
            $patterns[] = self::EMPTY_LINES_PATTERN;
            $replacements[] = PHP_EOL;
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
