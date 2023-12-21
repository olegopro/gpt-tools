<?php

// Путь в каталогу
$directoryPath = '/Volumes/SSD256/www/vk-bot/backend-laravel/app/Http';

// Расширения файлов для включения
$extensions = ['php', 'vue', 'js'];

function drawTree($directory, $prefix = '', $isRoot = true, $extensions = [])
{
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

	foreach ($files as $file) {
		$fileCount++;
		$isLast = ($fileCount === $totalFiles);
		$isFirst = ($fileCount === 1);

		// Изменяем символ для первого элемента в корневой директории
		if ($isRoot && $isFirst) {
			$output .= '┌── ' . $file . PHP_EOL;
		} elseif ($isLast) {
			$output .= $prefix . '└── ' . $file . PHP_EOL;
		} else {
			$output .= $prefix . '├── ' . $file . PHP_EOL;
		}

		$filePath = $directory . DIRECTORY_SEPARATOR . $file;
		if (is_dir($filePath)) {
			$newPrefix = $prefix . ($isLast ? '    ' : '│   ');
			$output .= drawTree($filePath, $newPrefix, false, $extensions);
		}
	}
	return $output;
}

// Включение буферизации вывода
ob_start();
echo drawTree($directoryPath, '', true, $extensions);
$treeOutput = ob_get_clean();

// Запись вывода в файл
file_put_contents('directory_tree.txt', $treeOutput);

echo 'Дерево сформировано в directory_tree.txt' . PHP_EOL;
