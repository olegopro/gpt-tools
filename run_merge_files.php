<?php

require_once 'src/MergeFiles.php';
require_once 'src/StructureVisualizer.php';
require_once 'src/StructureVisualizerManager.php';

$config = [
    'projectDir' => '/project-directory',
    'paths'      => [
        '/folder',
        '/folder/file.php'
    ],
    'scanDependencies'         => true,
    'extensions'               => ['php', 'vue', 'js', 'json', 'ts', 'html', 'css', 'scss'],
    'removeStyleTag'           => false,
    'removeHtmlComments'       => false,
    'removeSingleLineComments' => false,
    'removeMultiLineComments'  => false,
    'removeEmptyLines'         => false,
    'includeInstructions'      => false,
    'ignoreFiles'              => [],
    'ignoreDirectories'        => ['node_modules', '.git', '.idea'],
    'outputFile'               => 'merged_files.txt',
    'fileListOutputFile'       => 'file_list.txt',
    'fileIndexingMethod'       => 'php'
];

(new MergeFiles($config))->merge();

// Проверка лимита строк
$outputFile = $config['outputFile'];
if (file_exists($outputFile) &&
    !empty(array_filter(
        file($outputFile, FILE_IGNORE_NEW_LINES),
        fn ($line) => strlen($line) > 100
    ))) {

    echo "Превышен лимит длины строк ⚠️" . PHP_EOL;
}

(new StructureVisualizerManager())->visualize();
