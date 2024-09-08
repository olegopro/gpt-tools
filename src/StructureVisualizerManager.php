<?php

class StructureVisualizerManager
{
    private string $templatePath = 'templates/template.html';
    private string $fileListPath = 'file_list.txt';
    private string $outputPath = 'folder_structure.html';

    public function visualize(): void
    {
        $visualizer = new StructureVisualizer($this->templatePath);
        $visualizer->parseFileStructure($this->fileListPath);
        $jsonData = json_decode($visualizer->generateJson(), true);

        if (empty($jsonData)) {
            echo "Ошибка: Сгенерированные данные пусты. Проверьте содержимое файла {$this->fileListPath}\n";
            exit(1);
        }

        $htmlOutput = $visualizer->generateHtml($jsonData);
        file_put_contents($this->outputPath, $htmlOutput);

        // Удаление временных файлов
        unlink($this->fileListPath);
    }
}
