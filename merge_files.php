<?php

// Путь к папке проекта
$projectDir = '/project-directory';

// Массив с путями/файлам для сканирования
$paths = [
    '/folder',
    '/folder/file.php'
];

// Расширения файлов для включения
$extensions = ['php', 'vue', 'js', 'ts'];

// Массив для игнорирования определенных файлов
$ignoreFiles = ['ignore_this.php', 'ignore_that.js'];

// Имя файла результата
$outputFile = 'merged_files.txt';

// Функция для создания относительного пути файла
function makeRelativePath($filePath, $projectDir)
{
    // Удаление полного пути к папке проекта из пути к файлу, оставляя только относительный путь
    return str_replace($projectDir, '', $filePath);
}


// Функция для сканирования пути
function scanPath($path, $extensions, $ignoreFiles, $projectDir)
{
    if (is_dir($path)) {
        return scanFolder($path, $extensions, $ignoreFiles, $projectDir);
    } else if (is_file($path) && !in_array(basename($path), $ignoreFiles)) {
        return [makeRelativePath($path, $projectDir)];
    }

    return []; // Возвращаем пустой массив, если путь не подходит
}

// Рекурсивный поиск файлов в папке
function scanFolder($folder, $extensions, $ignoreFiles, $projectDir)
{
    $files = [];
    if (is_dir($folder)) {
        $dir = new RecursiveDirectoryIterator($folder);
        $iterator = new RecursiveIteratorIterator($dir);

        foreach ($iterator as $file) {
            if (in_array($file->getExtension(), $extensions) && !in_array($file->getFilename(), $ignoreFiles)) {
                $files[] = makeRelativePath($file->getPathname(), $projectDir);
            }
        }
    }
    return $files;
}

// Массив для отслеживания обработанных файлов
$usedFiles = [];

// Объединенное содержимое
$mergedContent = '';

// Переменная для подсчета строк
$currentLine = 1;

// Массив для хранения информации о файлах
$fileLinesInfo = [];

// Перебираем пути
foreach ($paths as $path) {
    $files = scanPath($path, $extensions, $ignoreFiles, $projectDir);

    foreach ($files as $relativePath) {
        if (in_array($relativePath, $usedFiles)) {
            continue;
        }

        $usedFiles[] = $relativePath;
        $absoluteFilePath = $projectDir . '/' . ltrim($relativePath, '/');
        $content = file_get_contents($absoluteFilePath);
        $content = preg_replace('/<style.*?>.*?<\/style>/s', '', $content);
        $content = rtrim($content);

        // Подсчет строк в текущем файле
        $lineCount = substr_count($content, PHP_EOL) + 1;
        $startLine = $currentLine + 1;
        $endLine = $startLine + $lineCount - 1;

        // Получаем имя корневой папки
        $rootFolderName = basename($projectDir);

        // Добавляем имя корневой папки к relativePath
        $fullRelativePath = '/' . $rootFolderName . $relativePath;

        // Сохраняем информацию о строках для файла
        $fileLinesInfo[] = "$fullRelativePath (строки $startLine - $endLine)";

        // Добавляем комментарии и содержимое файла к результату
        $mergedContent .= "// Начало файла -> $fullRelativePath" . PHP_EOL;
        $mergedContent .= $content . PHP_EOL;
        $mergedContent .= "// Конец файла -> $fullRelativePath" . str_repeat(PHP_EOL, 3);

        // Обновляем текущую строку для следующего файла
        $currentLine = $endLine + 4; // Учитываем строки с комментариями и переносами
    }
}

// // Обрезаем переносы в конце основного содержимого
$mergedContent = rtrim($mergedContent, PHP_EOL);

// Записываем содержимое в файл
file_put_contents($outputFile, $mergedContent);

// Подготовка и вывод информации о файлах и заключительного сообщения
$consoleOutput = PHP_EOL . 'Список файлов с указанием строк начала и конца кода файла:' . PHP_EOL;

foreach ($fileLinesInfo as $info) {
    $consoleOutput .= $info . PHP_EOL;
}

$consoleOutput .= PHP_EOL . 'Отвечай, как опытный программист с более чем 10-летним стажем. Когда отвечаешь выбирай современные практики (лучшие подходы). После прочтения жди вопросы по этому коду.' . str_repeat(PHP_EOL, 2);

// Вывод в консоль
echo $consoleOutput;
// Проверяем наличие команды pbcopy и выполняем копирование в буфер обмена, если она доступна
exec("which pbcopy > /dev/null && printf " . escapeshellarg(trim($consoleOutput)) . " | pbcopy");
