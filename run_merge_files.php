<?php

require_once 'MergeFiles.php';

$config = [
    'projectDir' => '/project-directory',
    'dependencyScanRoot' => '/project-directory/src',
    'paths' => [
        '/folder',
        '/folder/file.php'
    ],
    'scanDependencies' => true,
    'extensions' => ['php', 'vue', 'js', 'json', 'ts', 'html', 'css', 'scss'],
    'removeStyleTag' => false,
    'removeHtmlComments' => false,
    'removeSingleLineComments' => false,
    'removeMultiLineComments' => false,
    'removeEmptyLines' => false,
    'ignoreFiles' => ['ignore_this.php', 'ignore_that.js'],
    'ignoreDirectories' => ['folder_to_ignore', 'another_folder_to_ignore'],
    'outputFile' => 'merged_files.txt'
];

$merger = new MergeFiles($config);
$merger->merge();
