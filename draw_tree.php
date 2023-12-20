<?php

function drawTree($directory, $prefix = '', $isRoot = true, $extensions = [])
{
	$files = array_diff(scandir($directory), array('.', '..'));

	$totalFiles = count($files);
	$fileCount = 0;
	$output = '';

	foreach ($files as $file) {
		$filePath = $directory . DIRECTORY_SEPARATOR . $file;
		$fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);

		// Пропускаем файл, если он не соответствует заданным расширениям
		if (!empty($extensions) && !in_array($fileExtension, $extensions) && !is_dir($filePath)) {
			continue;
		}

		$fileCount++;
		$isLast = ($fileCount === $totalFiles);

		if ($isRoot && $fileCount === 1) {
			$output .= '└── ' . $file . PHP_EOL;
		} else {
			$output .= $prefix . ($isLast ? '└── ' : '├── ') . $file . PHP_EOL;
		}

		if (is_dir($filePath)) {
			$newPrefix = $prefix . ($isLast ? '    ' : '│   ');
			$output .= drawTree($filePath, $newPrefix, false, $extensions);
		}
	}
	return $output;
}

// Путь в каталогу
$directoryPath = '/folder';

// Расширения файлов для включения
$extensions = ['php', 'vue', 'js'];

// Включение буферизации вывода
ob_start();
echo drawTree($directoryPath, '', true, $extensions);
$treeOutput = ob_get_clean();

// Запись вывода в файл
file_put_contents('directory_tree.txt', $treeOutput);
