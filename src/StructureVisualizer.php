<?php

class StructureVisualizer
{
    private $structure = [];
    private $totalSize = 0;
    private $templatePath;

    public function __construct($templatePath)
    {
        $this->templatePath = $templatePath;
    }

    public function generateHtml(array $data): string
    {
        $jsonData = json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        $template = file_get_contents($this->templatePath);
        return str_replace('{{JSON_DATA}}', $jsonData, $template);
    }

    public function generateJson(): string
    {
        $processedStructure = $this->processStructure($this->structure);

        return json_encode($processedStructure, JSON_PRETTY_PRINT);
    }

    public function parseFileStructure(string $filePath): void
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (preg_match('/^\/(.+) \(строки (\d+) - (\d+)\)$/', $line, $matches)) {
                $path = $matches[1];
                $startLine = (int) $matches[2];
                $endLine = (int) $matches[3];
                $size = $endLine - $startLine + 1;
                $this->addToStructure($path, $size);
                $this->totalSize += $size;
            }
        }
    }

    private function addToStructure(string $path, int $size): void
    {
        $parts = explode('/', trim($path, '/'));
        $current = &$this->structure;

        foreach ($parts as $index => $part) {
            if (!isset($current[$part])) {
                $current[$part] = [
                    'name'     => $part,
                    'size'     => 0,
                    'children' => [],
                    'type'     => ($index === count($parts) - 1) ? 'file' : 'folder'
                ];
            }
            $current[$part]['size'] += $size;
            if ($index < count($parts) - 1) {
                $current = &$current[$part]['children'];
            }
        }
    }

    private function processStructure(array $node): array
    {
        $result = [];
        foreach ($node as $name => $data) {
            $item = [
                'name'       => $name,
                'size'       => $data['size'],
                'percentage' => round(($data['size'] / $this->totalSize) * 100, 1),
                'type'       => $data['type']
            ];
            if (!empty($data['children'])) {
                $item['children'] = $this->processStructure($data['children']);
            }
            $result[] = $item;
        }
        usort($result, function ($a, $b) {
            return $b['size'] - $a['size'];
        });

        return $result;
    }
}


