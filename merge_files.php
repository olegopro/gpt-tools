<?php

// Путь к папке проекта
$projectDir = '/project-directory';

// Массив с путями/файлам для сканирования
$paths = [
    '/folder',
    '/folder/file.php'
];

// Расширения файлов для включения
$extensions = ['php', 'vue', 'js','json', 'ts', 'html', 'css', 'scss'];

// Флаг для включения/выключения вырезания тега <style>
$removeStyleTag = false;

// Флаг для включения/выключения удаления HTML-комментариев <!-- -->
$removeHtmlComments = false;

// Флаг для включения/выключения удаления однострочных комментариев //
$removeSingleLineComments = false;

// Флаг для включения/выключения удаления многострочных комментариев /* */
$removeMultiLineComments = false;

// Массив для игнорирования определенных файлов
$ignoreFiles = ['ignore_this.php', 'ignore_that.js'];

// Массив для игнорирования определенных папок (относительные пути от корневой папки проекта)
$ignoreDirectories = ['/folder_to_ignore', '/another_folder_to_ignore'];

// Имя файла результата
$outputFile = 'merged_files.txt';

// Функция для создания относительного пути файла
function makeRelativePath($filePath, $projectDir)
{
    // Удаление полного пути к папке проекта из пути к файлу, оставляя только относительный путь
    return str_replace($projectDir, '', $filePath);
}

// Функция для проверки, является ли директория игнорируемой
function isIgnoredDirectory($filePath, $ignoreDirectories)
{
    foreach ($ignoreDirectories as $ignoredDir) {
        // Проверка, начинается ли относительный путь файла с пути, указанного в ignoreDirectories
        if (strpos($filePath, $ignoredDir) === 0) {
            return true; // Если да, то возвращаем true, указывая, что директория игнорируется
        }
    }
    
    return false; // Если ни один из путей не совпадает, возвращаем false
}

// Функция для сканирования пути
function scanPath($path, $extensions, $ignoreFiles, $ignoreDirectories, $projectDir)
{
    if (is_dir($path)) {
        // Если путь является директорией, сканируем ее рекурсивно
        return scanFolder($path, $extensions, $ignoreFiles, $ignoreDirectories, $projectDir);
    } else if (is_file($path) && !in_array(basename($path), $ignoreFiles)) {
        // Если путь является файлом и не находится в списке игнорируемых файлов, возвращаем относительный путь
        return [makeRelativePath($path, $projectDir)];
    }

    return []; // Возвращаем пустой массив, если путь не подходит
}

// Рекурсивный поиск файлов в папке
function scanFolder($folder, $extensions, $ignoreFiles, $ignoreDirectories, $projectDir)
{
    $files = [];
    if (is_dir($folder)) {
        $dir = new RecursiveDirectoryIterator($folder);
        $iterator = new RecursiveIteratorIterator($dir);

        foreach ($iterator as $file) {
            $relativePath = makeRelativePath($file->getPathname(), $projectDir);

            // Пропускаем файл, если он находится в игнорируемой директории
            if (isIgnoredDirectory($relativePath, $ignoreDirectories)) {
                continue;
            }

            // Добавляем файл в массив, если его расширение соответствует и он не находится в списке игнорируемых файлов
            if (
                in_array($file->getExtension(), $extensions) &&
                !in_array($file->getFilename(), $ignoreFiles)
            ) {
                $files[] = $relativePath;
            }
        }
    }
    
    return $files; // Возвращаем массив найденных файлов
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
    // Сканируем текущий путь
    $files = scanPath($path, $extensions, $ignoreFiles, $ignoreDirectories, $projectDir);

    foreach ($files as $relativePath) {
        // Пропускаем файл, если он уже был обработан
        if (in_array($relativePath, $usedFiles)) {
            continue;
        }

        // Добавляем файл в список обработанных
        $usedFiles[] = $relativePath;
        $absoluteFilePath = $projectDir . '/' . ltrim($relativePath, '/');
        $content = file_get_contents($absoluteFilePath);

        // Удаление тега <style>, если это указано в настройках
        if ($removeStyleTag) {
            $content = preg_replace('/<style.*?>.*?<\/style>/s', '', $content);
        }

        // Удаление HTML-комментариев, если это указано в настройках
        if ($removeHtmlComments) {
            $content = preg_replace('/<!--.*?-->/s', '', $content);
        }

        // Удаление однострочных комментариев, если это указано в настройках
        if ($removeSingleLineComments) {
            $content = preg_replace('!^\s*//.*?(\r?\n|\r)!m', '', $content);
        }

        // Удаление многострочных комментариев, если это указано в настройках
        if ($removeMultiLineComments) {
            $content = preg_replace('!/\*[\s\S]*?\*/\s*!', '', $content);
        }
        
        // Удаляем лишние пробелы и переносы строк в конце содержимого
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

// Обрезаем переносы в конце основного содержимого
$mergedContent = rtrim($mergedContent, PHP_EOL);

// Записываем содержимое в файл
file_put_contents($outputFile, $mergedContent);

// Подготовка и вывод информации о файлах и заключительного сообщения
$consoleOutput = PHP_EOL . 'Ниже написано содержание прикреплённого файла в котором объединён код нескольких файлов проекта. Это содержание указывает на начальные и конечные строки файлов которые были объеденины в прикреплённый файл к этому сообщению:' . PHP_EOL;

foreach ($fileLinesInfo as $info) {
    $consoleOutput .= $info . PHP_EOL;
}

$consoleOutput .= PHP_EOL . 'Отвечай, как опытный программист с более чем 10-летним стажем. Когда отвечаешь выбирай современные практики (лучшие подходы). После прочтения жди вопросы по этому коду, просто жди вопросов, не нужно ничего отвечать.' . str_repeat(PHP_EOL, 2);

// Вывод в консоль
echo $consoleOutput;

// Проверяем наличие команды pbcopy и выполняем копирование в буфер обмена, если она доступна
exec("which pbcopy > /dev/null && printf " . escapeshellarg(trim($consoleOutput)) . " | pbcopy");
