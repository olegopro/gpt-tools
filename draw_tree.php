<?php

function drawTree($directory, $prefix = '', $isRoot = true)
{
	$files = array_diff(scandir($directory), array('.', '..'));

	$totalFiles = count($files);
	$fileCount = 0;
	$output = '';

	foreach ($files as $file) {
		$fileCount++;
		$isLast = ($fileCount === $totalFiles);

		// Изменяем логику для корневого каталога
		if ($isRoot && $fileCount === 1) {
			$output .= '└── ' . $file . PHP_EOL;
		} else {
			$output .= $prefix . ($isLast ? '└── ' : '├── ') . $file . PHP_EOL;
		}

		if (is_dir($directory . DIRECTORY_SEPARATOR . $file)) {
			$newPrefix = $prefix . ($isLast ? '    ' : '│   ');
			$output .= drawTree($directory . DIRECTORY_SEPARATOR . $file, $newPrefix, false);
		}
	}
	return $output;
}


// Путь в каталогу
$directoryPath = '/folder';

// Включение буферизации вывода
ob_start();
echo drawTree($directoryPath, '', true);
$treeOutput = ob_get_clean();

// Запись вывода в файл
file_put_contents('directory_tree.txt', $treeOutput);
