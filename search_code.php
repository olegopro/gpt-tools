<?php
require_once 'src/CodeEmbeddingsSearch.php';

// Ваш API ключ Voyage
$apiKey = '';
$model = 'voyage-code-3';

try {
    // Создаем экземпляр поисковика кода
    $searcher = new CodeEmbeddingsSearch($apiKey, 'merged_files.txt', 'code_embeddings.json', $model);
    
    // Запускаем процесс поиска с обработкой аргументов командной строки
    $searcher->runSearchFromCommandLine();
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}