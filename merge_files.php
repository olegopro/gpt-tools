<?php

// Путь к папке проекта (абсолютный путь)
$projectDir = '/project-directory';

// Массив с путями/файлам для сканирования (относительные пути)
$paths = [
    '/folder',
    '/folder/file.php'
];

// Расширения файлов для включения или '*' для включения всех файлов
$extensions = ['php', 'vue', 'js', 'json', 'ts', 'html', 'css', 'scss'];

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
function isIgnoredDirectory($filePath, $ignoreDirectories, $projectDir)
{
    // Удаляем путь проекта из пути файла и обрезаем слэши
    $filePath = trim(str_replace($projectDir, '', $filePath), '/');

    foreach ($ignoreDirectories as $ignoredDir) {
        // Обрезаем слэши у игнорируемой директории
        $ignoredDir = trim($ignoredDir, '/');

        // Разбиваем пути на части
        $filePathParts = explode('/', $filePath);
        $ignoredDirParts = explode('/', $ignoredDir);

        // Проверяем, что путь файла не короче игнорируемого пути
        if (count($filePathParts) < count($ignoredDirParts)) {
            continue;
        }

        // Флаг для отслеживания совпадения частей пути
        $match = true;

        // Сравниваем каждую часть пути
        for ($i = 0; $i < count($ignoredDirParts); $i++) {
            if ($filePathParts[$i] !== $ignoredDirParts[$i]) {
                $match = false;
                break;
            }
        }

        // Если все части совпали, считаем директорию игнорируемой
        if ($match) {
            return true;
        }
    }

    // Если ни один путь не совпал, директория не игнорируется
    return false;
}

// Функция для проверки, должен ли файл быть включен на основе его расширения
function shouldIncludeFile($filename, $extensions) {
    // Если в массиве расширений есть '*', включаем все файлы
    if (in_array('*', $extensions)) {
        return true;
    }
    // Иначе проверяем, соответствует ли расширение файла списку разрешенных расширений
    $fileExtension = pathinfo($filename, PATHINFO_EXTENSION);
    return in_array($fileExtension, $extensions);
}

// Функция для сканирования пути
function scanPath($path, $extensions, $ignoreFiles, $ignoreDirectories, $projectDir)
{
    $fullPath = $projectDir . '/' . ltrim($path, '/');
    if (is_dir($fullPath)) {
        // Если путь является директорией, сканируем ее рекурсивно
        return scanFolder($fullPath, $extensions, $ignoreFiles, $ignoreDirectories, $projectDir);
    } else if (is_file($fullPath) && !in_array(basename($fullPath), $ignoreFiles) && shouldIncludeFile(basename($fullPath), $extensions)) {
        // Если путь является файлом, не находится в списке игнорируемых файлов и соответствует критериям включения, возвращаем относительный путь
        return [makeRelativePath($fullPath, $projectDir)];
    }

    return []; // Возвращаем пустой массив, если путь не подходит
}

// Рекурсивный поиск файлов в папке
function scanFolder($folder, $extensions, $ignoreFiles, $ignoreDirectories, $projectDir)
{
    $files = [];
    if (is_dir($folder)) {
        // Используем RecursiveDirectoryIterator для обхода всех файлов и папок
        $dir = new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($dir);

        foreach ($iterator as $file) {
            // Проверяем, находится ли файл в игнорируемой директории
            if (isIgnoredDirectory($file->getPathname(), $ignoreDirectories, $projectDir)) {
                continue;
            }

            $relativePath = makeRelativePath($file->getPathname(), $projectDir);

            // Добавляем файл в массив, если он соответствует критериям включения и не находится в списке игнорируемых файлов
            if (shouldIncludeFile($file->getFilename(), $extensions) && !in_array($file->getFilename(), $ignoreFiles)) {
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