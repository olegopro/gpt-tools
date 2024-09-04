<?php

// Путь к папке проекта (абсолютный путь)
$projectDir = '/project-directory';

// Абсолютный путь к корневой директории поиска зависимостей (например, src)
$dependencyScanRoot = '/project-directory/src';

// Массив с путями/файлами для сканирования (относительные пути)
$paths = [
    '/folder',
    '/folder/file.php'
];

// Поиск зависимостей
$scanDependencies = true;

// Расширения файлов для включения или '*' для включения всех файлов
$extensions = ['php', 'vue', 'js', 'json', 'ts', 'html', 'css', 'scss']; // Для обхода всех файлов

// Хардкод расширений для файлов, в которых будут искаться зависимости
const DEPENDENCY_EXTENSIONS = ['vue', 'js', 'ts'];

// Флаги для включения/выключения обработки содержимого
$removeStyleTag = false;
$removeHtmlComments = false;
$removeSingleLineComments = false;
$removeMultiLineComments = false;

// Массив для игнорирования определенных файлов
$ignoreFiles = ['ignore_this.php', 'ignore_that.js'];

// Массив для игнорирования определенных папок (относительные пути от корневой папки проекта)
$ignoreDirectories = ['folder_to_ignore', 'another_folder_to_ignore'];

// Имя файла результата
$outputFile = 'merged_files.txt';

function makeRelativePath($filePath, $projectDir)
{
    $relativePath = str_replace($projectDir, '', $filePath);
    return ltrim($relativePath, '/');
}

function isIgnoredDirectory($filePath, $ignoreDirectories, $projectDir)
{
    $relativeFilePath = trim(str_replace($projectDir, '', $filePath), '/');
    
    foreach ($ignoreDirectories as $ignoredDir) {
        $ignoredDir = trim($ignoredDir, '/');
        if (strpos($relativeFilePath . '/', $ignoredDir . '/') === 0 || $relativeFilePath === $ignoredDir) {
            return true;
        }
    }

    return false;
}

function shouldIncludeFile($filename, $extensions)
{
    if (in_array('*', $extensions)) {
        return true;
    }
    $fileExtension = pathinfo($filename, PATHINFO_EXTENSION);
    return in_array($fileExtension, $extensions);
}

function scanDependencies($file, $scanRootDir, $projectDir, $ignoreDirectories, &$scannedFiles = [])
{
    echo PHP_EOL . "Сканирование зависимостей для файла: $file\n";

    $fullPath = $projectDir . '/' . ltrim($file, '/');
    
    // Проверяем, что файл имеет допустимое расширение для поиска зависимостей (только .vue, .js, .ts)
    if (!shouldIncludeFile($fullPath, DEPENDENCY_EXTENSIONS)) {
        echo "  Файл $file пропущен (не соответствует расширениям для зависимостей).\n";
        return [];
    }

    if (!file_exists($fullPath) || is_dir($fullPath) || in_array($file, $scannedFiles) || isIgnoredDirectory($fullPath, $ignoreDirectories, $scanRootDir)) {
        return [];
    }

    $scannedFiles[] = $file;
    $content = file_get_contents($fullPath);
    $dependencies = [];

    // Регулярное выражение для поиска импортов
    $importRegex = '/import\s+(?:{[^}]+}|\w+)\s+from\s+[\'"]([^\'"]+)[\'"]/';

    if (preg_match_all($importRegex, $content, $matches)) {
        foreach ($matches[1] as $match) {
            echo "  Найден импорт: $match\n";
            $dependencyPath = resolveDependencyPath($match, $file, $scanRootDir, $projectDir, $ignoreDirectories);
            if ($dependencyPath && !isIgnoredDirectory($projectDir . '/' . $dependencyPath, $ignoreDirectories, $scanRootDir)) {
                echo "  Разрешен путь: $dependencyPath\n";
                $dependencies[] = $dependencyPath;
                $dependencies = array_merge($dependencies, scanDependencies($dependencyPath, $scanRootDir, $projectDir, $ignoreDirectories, $scannedFiles));
            } else {
                echo "  Не удалось разрешить путь для: $match или путь находится в игнорируемой директории\n";
            }
        }
    }

    return array_unique($dependencies);
}

function resolveDependencyPath($importPath, $currentFile, $scanRootDir, $projectDir, $ignoreDirectories)
{
    $currentDir = dirname($currentFile);

    // Обработка относительных путей
    if (strpos($importPath, './') === 0 || strpos($importPath, '../') === 0) {
        $resolvedPath = realpath($scanRootDir . '/' . $currentDir . '/' . $importPath);
        if ($resolvedPath && !isIgnoredDirectory($resolvedPath, $ignoreDirectories, $scanRootDir)) {
            return makeRelativePath($resolvedPath, $projectDir);
        }
    }

    // Рекурсивный поиск файла только внутри указанной директории (scanRootDir)
    $foundPath = findFileRecursively($scanRootDir, $importPath, $ignoreDirectories);
    if ($foundPath) {
        return makeRelativePath($foundPath, $projectDir);
    }

    // Если файл не найден, попробуем добавить расширение
    $extensions = ['.js', '.vue', '.ts'];
    foreach ($extensions as $ext) {
        $foundPath = findFileRecursively($scanRootDir, $importPath . $ext, $ignoreDirectories);
        if ($foundPath) {
            return makeRelativePath($foundPath, $projectDir);
        }
    }

    return null;
}

function findFileRecursively($dir, $filename, $ignoreDirectories)
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            if (isIgnoredDirectory($file->getPathname(), $ignoreDirectories, $dir)) {
                $iterator->next();
                continue;
            }
        }

        if ($file->isFile() && $file->getFilename() === basename($filename)) {
            if (!isIgnoredDirectory($file->getPathname(), $ignoreDirectories, $dir)) {
                return $file->getPathname();
            }
        }
    }

    return null;
}

function scanAllDependencies($paths, $projectDir, $extensions, $ignoreFiles, $ignoreDirectories, $scanDependencies, $scanRootDir)
{
    $allFiles = [];
    $dependencyFiles = [];

    echo "Project Directory: $projectDir\n";

    if (!is_dir($projectDir)) {
        echo "Ошибка: Директория проекта не существует: $projectDir\n";
        exit(1);
    }

    foreach ($paths as $path) {
        $fullPath = $projectDir . '/' . ltrim($path, '/');
        if (is_file($fullPath)) {
            if (!in_array(basename($fullPath), $ignoreFiles) && 
                shouldIncludeFile(basename($fullPath), $extensions) && 
                !isIgnoredDirectory($fullPath, $ignoreDirectories, $projectDir)) {
                $relativePath = makeRelativePath($fullPath, $projectDir);
                $allFiles[] = $relativePath;
                if ($scanDependencies) {
                    $dependencies = scanDependencies($relativePath, $scanRootDir, $projectDir, $ignoreDirectories);
                    $dependencyFiles = array_merge($dependencyFiles, $dependencies);
                }
            }
        } elseif (is_dir($fullPath)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $relativeFilePath = makeRelativePath($file->getPathname(), $projectDir);
                    if (!in_array($file->getBasename(), $ignoreFiles) &&
                        !isIgnoredDirectory($file->getPathname(), $ignoreDirectories, $projectDir) &&
                        shouldIncludeFile($file->getBasename(), $extensions)
                    ) {
                        $allFiles[] = $relativeFilePath;
                        if ($scanDependencies) {
                            $dependencies = scanDependencies($relativeFilePath, $scanRootDir, $projectDir, $ignoreDirectories);
                            $dependencyFiles = array_merge($dependencyFiles, $dependencies);
                        }
                    }
                }
            }
        } else {
            echo "Предупреждение: Путь не существует или не соответствует условиям: $fullPath\n";
        }
    }

    $result = $scanDependencies ? array_unique(array_merge($allFiles, $dependencyFiles)) : $allFiles;

    // Дополнительная фильтрация результатов
    $result = array_filter($result, function($path) use ($projectDir, $ignoreDirectories) {
        return !isIgnoredDirectory($projectDir . '/' . $path, $ignoreDirectories, $projectDir);
    });

    echo PHP_EOL . "Итоговый результат scanAllDependencies():\n";
    print_r($result);

    return $result;
}

$usedFiles = [];
$mergedContent = '';
$currentLine = 1;
$fileLinesInfo = [];

$allPaths = scanAllDependencies($paths, $projectDir, $extensions, $ignoreFiles, $ignoreDirectories, $scanDependencies, $dependencyScanRoot);

foreach ($allPaths as $relativePath) {
    if (in_array($relativePath, $usedFiles) || isIgnoredDirectory($projectDir . '/' . $relativePath, $ignoreDirectories, $projectDir)) {
        continue;
    }

    $usedFiles[] = $relativePath;
    $absoluteFilePath = $projectDir . '/' . $relativePath;

    if (!file_exists($absoluteFilePath)) {
        echo "Предупреждение: Файл не существует: $absoluteFilePath\n";
        continue;
    }

    $content = file_get_contents($absoluteFilePath);

    if ($removeStyleTag) {
        $content = preg_replace('/<style.*?>.*?<\/style>/s', '', $content);
    }

    if ($removeHtmlComments) {
        $content = preg_replace('/<!--.*?-->/s', '', $content);
    }

    if ($removeSingleLineComments) {
        $content = preg_replace('!^\s*//.*?(\r?\n|\r)!m', '', $content);
    }

    if ($removeMultiLineComments) {
        $content = preg_replace('!/\*[\s\S]*?\*/\s*!', '', $content);
    }

    $content = rtrim($content);

    $lineCount = substr_count($content, PHP_EOL) + 1;
    $startLine = $currentLine;
    $endLine = $startLine + $lineCount - 1;

    $rootFolderName = basename($projectDir);
    $fullRelativePath = '/' . $rootFolderName . '/' . $relativePath;

    $fileLinesInfo[] = "$fullRelativePath (строки $startLine - $endLine)";

    $mergedContent .= "// Начало файла -> $fullRelativePath" . PHP_EOL;
    $mergedContent .= $content . PHP_EOL;
    $mergedContent .= "// Конец файла -> $fullRelativePath" . str_repeat(PHP_EOL, 2);

    $currentLine = $endLine + 3;
}

$mergedContent = rtrim($mergedContent, PHP_EOL);

file_put_contents($outputFile, $mergedContent);

$consoleOutput = PHP_EOL . 'Ниже написано содержание прикреплённого файла в котором объединён код нескольких файлов проекта. Это содержание указывает на начальные и конечные строки файлов которые были объеденины в прикреплённый файл к этому сообщению:' . PHP_EOL;

foreach ($fileLinesInfo as $info) {
    $consoleOutput .= $info . PHP_EOL;
}

$consoleOutput .= PHP_EOL . 'Отвечай, как опытный программист с более чем 10-летним стажем. Когда отвечаешь выбирай современные практики (лучшие подходы). После прочтения жди вопросы по этому коду, просто жди вопросов, не нужно ничего отвечать.' . str_repeat(PHP_EOL, 2);

echo $consoleOutput;

exec("which pbcopy > /dev/null && printf " . escapeshellarg(trim($consoleOutput)) . " | pbcopy");
