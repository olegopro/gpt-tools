<?php

// Путь в каталогу
$directoryPath = '/folder';

// Расширения файлов для включения
$extensions = ['php', 'vue', 'js'];

// Игнорируемые папки
$ignoredDirectories = ['node_modules', 'vendor', '.git', '.idea', '.vscode'];

function drawTree($directory, $prefix = '', $isRoot = true, $extensions = [], $ignoredDirectories = [])
{
    static $rootDisplayed = false;
    $files = array_diff(scandir($directory), array('.', '..'));

    // Фильтрация файлов по расширениям
    if (!empty($extensions)) {
        $files = array_filter($files, function ($file) use ($directory, $extensions) {
            $filePath = $directory . DIRECTORY_SEPARATOR . $file;
            return is_dir($filePath) || in_array(pathinfo($filePath, PATHINFO_EXTENSION), $extensions);
        });
    }

    $totalFiles = count($files);
    $fileCount = 0;
    $output = '';

    // Добавление названия корневой директории
    if ($isRoot && !$rootDisplayed) {
        $rootDirectoryName = basename($directory);
        $output .= $rootDirectoryName . PHP_EOL;
        $rootDisplayed = true; // Установка флага, чтобы название не повторялось
    }

    foreach ($files as $file) {
        $fileCount++;
        $isLast = ($fileCount === $totalFiles);

        $filePath = $directory . DIRECTORY_SEPARATOR . $file;

        // Перенесём проверку игнорируемых директорий сюда
        if (is_dir($filePath) && in_array(basename($file), $ignoredDirectories)) {
            continue; // Пропускаем эту директорию
        }

        if ($isLast) {
            $output .= $prefix . '└── ' . $file . PHP_EOL;
        } else {
            $output .= $prefix . '├── ' . $file . PHP_EOL;
        }

        if (is_dir($filePath)) {
            $newPrefix = $prefix . ($isLast ? '    ' : '│   ');
            $output .= drawTree($filePath, $newPrefix, false, $extensions, $ignoredDirectories);
        }
    }

    return $output;
}


// Включение буферизации вывода
ob_start();
echo drawTree($directoryPath, '', true, $extensions, $ignoredDirectories);
$treeOutput = ob_get_clean();

// Запись вывода в файл
file_put_contents('directory_tree.txt', $treeOutput);

echo 'Дерево сформировано в directory_tree.txt' . PHP_EOL;
