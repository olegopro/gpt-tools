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
// Возвращает массив найденных файлов
function scanPath($path, $extensions)
{
	// Если директория - рекурсивный поиск
	if (is_dir($path)) {
		return scanFolder($path, $extensions);

		// Если файл - возвращаем массив с ним одним
	} else if (is_file($path)) {
		return [$path];
	}
}

// Рекурсивный поиск файлов в папке 
function scanFolder($folder, $extensions)
{
	// Массив для результатов
	$files = [];

	// Сканируем рекурсивно
	if (is_dir($folder)) {
		$dir = new RecursiveDirectoryIterator($folder);
		$iterator = new RecursiveIteratorIterator($dir);

		foreach ($iterator as $file) {
			// Проверяем расширение 
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


// Перебираем пути
foreach ($paths as $path) {

	// Получаем файлы
	$files = scanPath($path, $extensions);

	$lastFile = '';
	// Обрабатываем файлы
	foreach ($files as $filePath) {

		// Имя файла
		$filename = basename($filePath);

		// Проверка на повторение
		if (in_array($filename, $usedFiles)) {
			continue;
		}

		$usedFiles[] = $filename;

		// Читаем содержимое
		$content = file_get_contents($filePath);

		// Обработка содержимого (удаляем тег style с его содержимым)
		$content = preg_replace('/<style.*?>.*?<\/style>/s', '', $content);
		$content = rtrim($content);

		// Добавляем к результату  
		$mergedContent .= "// Начало файла -> $filename" . PHP_EOL . $content . PHP_EOL;

		// Добавляем имя последнего файла в конец
		$mergedContent .= "// Конец файла -> " . $filename . PHP_EOL . PHP_EOL . PHP_EOL;
	}
}


// Обрезаем переносы в конце
$mergedContent = rtrim($mergedContent, PHP_EOL);

// Записываем итоговый файл 
file_put_contents($outputFile, $mergedContent);

echo 'Файлы склеены в $outputFile' . PHP_EOL;
