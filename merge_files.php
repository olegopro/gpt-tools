<?php

// Массив с путями/файлам для сканирования
$paths = [
	'/folder',
	'/folder/file.php'
];

// Расширения файлов для включения
$extensions = ['php', 'vue', 'js'];

// Имя файла результата
$outputFile = 'merged_files.txt';

// Функция для сканирования пути
function scanPath($path, $extensions)
{
	if (is_dir($path)) {
		return scanFolder($path, $extensions);
	} else if (is_file($path)) {
		return [$path];
	}
}

// Рекурсивный поиск файлов в папке
function scanFolder($folder, $extensions)
{
	$files = [];
	if (is_dir($folder)) {
		$dir = new RecursiveDirectoryIterator($folder);
		$iterator = new RecursiveIteratorIterator($dir);

		foreach ($iterator as $file) {
			if (in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions)) {
				$files[] = $file;
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
	$files = scanPath($path, $extensions);

	foreach ($files as $filePath) {
		$filename = basename($filePath);

		if (in_array($filename, $usedFiles)) {
			continue;
		}

		$usedFiles[] = $filename;

		$content = file_get_contents($filePath);
		$content = preg_replace('/<style.*?>.*?<\/style>/s', '', $content);
		$content = rtrim($content);

		// Подсчет строк в текущем файле
		$lineCount = substr_count($content, PHP_EOL) + 1;
		$startLine = $currentLine + 1; // +1, начинаем после комментария "Начало файла"
		$endLine = $startLine + $lineCount - 1;

		// Сохраняем информацию о строках для файла
		$fileLinesInfo[] = "$filename (строки $startLine - $endLine)";

		// Добавляем комментарии и содержимое файла к результату
		$mergedContent .= "// Начало файла -> $filename" . PHP_EOL;
		$mergedContent .= $content . PHP_EOL;
		$mergedContent .= "// Конец файла -> $filename" . PHP_EOL . PHP_EOL . PHP_EOL;

		// Обновляем текущую строку для следующего файла
		$currentLine = $endLine + 4; // Учитываем строки с комментариями и переносами
	}
}

// Обрезаем переносы в конце основного содержимого
$mergedContent = rtrim($mergedContent, PHP_EOL);

// Добавляем пустую строку перед информацией о файлах
$mergedContent .= PHP_EOL . PHP_EOL . 'Список файлов с указанием строк начала и конца кода файла:' . PHP_EOL;

// Добавляем информацию о файлах в конец содержимого
foreach ($fileLinesInfo as $info) {
	$mergedContent .= $info . PHP_EOL;
}

$mergedContent .= PHP_EOL . 'Отвечай, как опытный программист с более чем 10-летним стажем. Когда отвечаешь выбирай современные практики (лучшие подходы). После прочтения жди вопросы по этому коду.';

// Записываем итоговый файл
file_put_contents($outputFile, $mergedContent);

echo "Файлы склеены в $outputFile" . PHP_EOL;
