<?php

class MergeFiles
{
    private string $projectDir;
    private string $dependencyScanRoot;
    private array $paths;
    private bool $scanDependencies;
    private array $extensions;
    private bool $removeStyleTag;
    private bool $removeHtmlComments;
    private bool $removeSingleLineComments;
    private bool $removeMultiLineComments;
    private bool $removeEmptyLines;
    private array $ignoreFiles;
    private array $ignoreDirectories;
    private string $outputFile;
    private int $maxDepth;

    private array $fileIndex = [];
    private array $dependencyCache = [];
    private array $contentCache = [];
    private array $scannedFiles = [];

    private const array DEPENDENCY_EXTENSIONS = ['vue', 'js', 'ts'];

    public function __construct(array $config)
    {
        $this->projectDir = $config['projectDir'];
        $this->dependencyScanRoot = $config['dependencyScanRoot'] ?? $this->projectDir;
        $this->paths = $config['paths'];
        $this->scanDependencies = $config['scanDependencies'];
        $this->extensions = $config['extensions'];
        $this->removeStyleTag = $config['removeStyleTag'];
        $this->removeHtmlComments = $config['removeHtmlComments'];
        $this->removeSingleLineComments = $config['removeSingleLineComments'];
        $this->removeMultiLineComments = $config['removeMultiLineComments'];
        $this->removeEmptyLines = $config['removeEmptyLines'];
        $this->ignoreFiles = $config['ignoreFiles'];
        $this->ignoreDirectories = $config['ignoreDirectories'];
        $this->outputFile = $config['outputFile'];
        $this->maxDepth = $config['maxDepth'] ?? 1000;
    }

    public function merge(): void
    {
        $this->buildFileIndex();
        $allPaths = $this->scanAllDependencies();
        $mergedContent = $this->mergePaths($allPaths);
        file_put_contents($this->outputFile, $mergedContent);
        $this->printConsoleOutput($this->calculateFileLinesInfo($mergedContent));
    }

    private function buildFileIndex(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->projectDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $this->shouldIncludeFile($file->getFilename(), $this->extensions)) {
                $relativePath = $this->makeRelativePath($file->getPathname(), $this->projectDir);
                $this->fileIndex[$file->getBasename()] = $relativePath;
            }
        }
    }

    private function scanAllDependencies(): array
    {
        $allFiles = [];
        foreach ($this->paths as $path) {
            $fullPath = $this->projectDir . '/' . ltrim($path, '/');
            if (is_file($fullPath)) {
                $this->processFile($fullPath, $allFiles);
            } elseif (is_dir($fullPath)) {
                $this->processDirectory($path, $allFiles);
            } else {
                echo "Предупреждение: Путь не существует или не соответствует условиям: $fullPath" . PHP_EOL;
            }
        }

        return array_unique($allFiles);
    }

    private function processFile(string $fullPath, array &$allFiles): void
    {
        $relativePath = $this->makeRelativePath($fullPath, $this->projectDir);
        if ($this->shouldIncludeFile(basename($fullPath), $this->extensions) &&
            !$this->isIgnoredFile($fullPath) &&
            !$this->isIgnoredDirectory($fullPath, $this->ignoreDirectories, $this->projectDir)) {
            $allFiles[] = $relativePath;
            if ($this->scanDependencies) {
                $allFiles = array_merge($allFiles, $this->scanDependencies($relativePath));
            }
        }
    }

    private function processDirectory(string $path, array &$allFiles): void
    {
        foreach ($this->fileIndex as $relativePath) {
            if (str_starts_with($relativePath, $path) &&
                !$this->isIgnoredFile($this->projectDir . '/' . $relativePath) &&
                !$this->isIgnoredDirectory($this->projectDir . '/' . $relativePath, $this->ignoreDirectories, $this->projectDir) &&
                $this->shouldIncludeFile(basename($relativePath), $this->extensions)) {
                $allFiles[] = $relativePath;
                if ($this->scanDependencies) {
                    $allFiles = array_merge($allFiles, $this->scanDependencies($relativePath));
                }
            }
        }
    }

    private function scanDependencies(string $file, int $depth = 0): array
    {
        if ($depth > $this->maxDepth || isset($this->dependencyCache[$file])) {
            return $this->dependencyCache[$file] ?? [];
        }

        $fullPath = $this->projectDir . '/' . ltrim($file, '/');
        if (!file_exists($fullPath) || is_dir($fullPath) ||
            !$this->shouldIncludeFile($fullPath, self::DEPENDENCY_EXTENSIONS) ||
            $this->isIgnoredDirectory($fullPath, $this->ignoreDirectories, $this->projectDir)) {
            return [];
        }

        if (in_array($file, $this->scannedFiles)) {
            return [];
        }
        $this->scannedFiles[] = $file;

        $content = $this->getFileContent($fullPath);
        $dependencies = $this->extractDependencies($content, $file, $depth);

        $this->dependencyCache[$file] = $dependencies;
        array_pop($this->scannedFiles);
        return $dependencies;
    }

    private function extractDependencies(string $content, string $currentFile, int $depth): array
    {
        $dependencies = [];
        $importRegex = '/import\s+(?:{[^}]+}|\w+)\s+from\s+[\'"]([^\'"]+)[\'"]/';
        if (preg_match_all($importRegex, $content, $matches)) {
            foreach ($matches[1] as $match) {
                $dependencyPath = $this->resolveDependencyPath($match, $currentFile);
                if ($dependencyPath && !in_array($dependencyPath, $dependencies)) {
                    $dependencies[] = $dependencyPath;
                    if ($depth < $this->maxDepth) {
                        $dependencies = array_merge($dependencies, $this->scanDependencies($dependencyPath, $depth + 1));
                    }
                }
            }
        }
        return array_unique($dependencies);
    }

    private function resolveDependencyPath(string $importPath, string $currentFile): ?string
    {
        $currentDir = dirname($currentFile);
        if (str_starts_with($importPath, './') || str_starts_with($importPath, '../')) {
            $resolvedPath = realpath($this->dependencyScanRoot . '/' . $currentDir . '/' . $importPath);
            if ($resolvedPath && !$this->isIgnoredDirectory($resolvedPath, $this->ignoreDirectories, $this->dependencyScanRoot)) {
                return $this->makeRelativePath($resolvedPath, $this->projectDir);
            }
        }

        $filename = basename($importPath);
        foreach (self::DEPENDENCY_EXTENSIONS as $ext) {
            $filenameWithExt = $filename . '.' . $ext;
            if (isset($this->fileIndex[$filenameWithExt])) {
                $fullPath = $this->projectDir . '/' . $this->fileIndex[$filenameWithExt];
                if (!$this->isIgnoredDirectory($fullPath, $this->ignoreDirectories, $this->projectDir)) {
                    return $this->fileIndex[$filenameWithExt];
                }
            }
        }

        // Попытка найти файл без расширения
        if (isset($this->fileIndex[$filename])) {
            $fullPath = $this->projectDir . '/' . $this->fileIndex[$filename];
            if (!$this->isIgnoredDirectory($fullPath, $this->ignoreDirectories, $this->projectDir)) {
                return $this->fileIndex[$filename];
            }
        }

        return null;
    }

    private function mergePaths(array $paths): string
    {
        $mergedContent = '';
        foreach ($paths as $relativePath) {
            $absoluteFilePath = $this->projectDir . '/' . $relativePath;
            if (!file_exists($absoluteFilePath)) {
                echo "Предупреждение: Файл не существует: $absoluteFilePath" . PHP_EOL;
                continue;
            }

            $content = $this->getFileContent($absoluteFilePath);
            $content = $this->applyFilters($content);

            $rootFolderName = basename($this->projectDir);
            $fullRelativePath = '/' . $rootFolderName . '/' . $relativePath;

            $mergedContent .= "// Начало файла -> $fullRelativePath" . PHP_EOL;
            $mergedContent .= $content . PHP_EOL;
            $mergedContent .= "// Конец файла -> $fullRelativePath" . str_repeat(PHP_EOL, 2);
        }
        return rtrim($mergedContent, PHP_EOL);
    }

    private function getFileContent(string $filePath): string
    {
        if (!isset($this->contentCache[$filePath])) {
            $this->contentCache[$filePath] = file_get_contents($filePath);
        }
        return $this->contentCache[$filePath];
    }

    private function applyFilters(string $content): string
    {
        $patterns = [];
        $replacements = [];

        if ($this->removeStyleTag) {
            $patterns[] = '/<style.*?>.*?<\/style>/s';
            $replacements[] = '';
        }
        if ($this->removeHtmlComments) {
            $patterns[] = '/<!--.*?-->/s';
            $replacements[] = '';
        }
        if ($this->removeSingleLineComments) {
            $patterns[] = '!^\s*//.*?(\r?\n|\r)!m';
            $replacements[] = '';
        }
        if ($this->removeMultiLineComments) {
            $patterns[] = '!/\*[\s\S]*?\*/\s*!';
            $replacements[] = '';
        }
        if ($this->removeEmptyLines) {
            $patterns[] = "/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/";
            $replacements[] = PHP_EOL;
        }

        return $patterns ? rtrim(preg_replace($patterns, $replacements, $content)) : $content;
    }

    private function calculateFileLinesInfo(string $mergedContent): array
    {
        $lines = explode(PHP_EOL, $mergedContent);
        $fileLinesInfo = [];
        $currentFile = null;
        $startLine = 0;
        $lineCount = 0;

        foreach ($lines as $index => $line) {
            if (preg_match('/\/\/ Начало файла -> (.+)/', $line, $matches)) {
                if ($currentFile) {
                    $fileLinesInfo[] = "$currentFile (строки $startLine - " . ($startLine + $lineCount - 1) . ")";
                }
                $currentFile = $matches[1];
                $startLine = $index + 2;
                $lineCount = 0;
            } elseif (preg_match('/\/\/ Конец файла -> /', $line)) {
                if ($currentFile) {
                    $fileLinesInfo[] = "$currentFile (строки $startLine - " . ($startLine + $lineCount - 1) . ")";
                    $currentFile = null;
                }
            } elseif ($currentFile) {
                $lineCount++;
            }
        }

        return $fileLinesInfo;
    }

    private function printConsoleOutput(array $fileLinesInfo): void
    {
        $consoleOutput = 'Ниже написано содержание прикреплённого файла в котором объединён код нескольких файлов проекта. Это содержание указывает на начальные и конечные строки файлов которые были объеденины в прикреплённый файл к этому сообщению:' . PHP_EOL;

        foreach ($fileLinesInfo as $info) {
            $consoleOutput .= $info . PHP_EOL;
        }

        $consoleOutput .= PHP_EOL . 'Отвечай, как опытный программист с более чем 10-летним стажем. Когда отвечаешь выбирай современные практики (лучшие подходы). После прочтения жди вопросы по этому коду, просто жди вопросов, не нужно ничего отвечать.' . str_repeat(PHP_EOL, 2);
        echo $consoleOutput;

        exec("which pbcopy > /dev/null && printf " . escapeshellarg(trim($consoleOutput)) . " | pbcopy");
    }

    private function makeRelativePath(string $filePath, string $basePath): string
    {
        $relativePath = str_replace($basePath, '', $filePath);
        return ltrim($relativePath, '/');
    }

    private function isIgnoredDirectory(string $filePath, array $ignoreDirectories, string $basePath): bool
    {
        $relativeFilePath = trim(str_replace($basePath, '', $filePath), '/');
        foreach ($ignoreDirectories as $ignoredDir) {
            $ignoredDir = trim($ignoredDir, '/');
            if (str_starts_with($relativeFilePath, $ignoredDir)) {
                return true;
            }
        }
        return false;
    }

    private function isIgnoredFile(string $filePath): bool
    {
        return in_array(basename($filePath), $this->ignoreFiles);
    }

    private function shouldIncludeFile(string $filename, array $extensions): bool
    {
        return in_array('*', $extensions) || in_array(pathinfo($filename, PATHINFO_EXTENSION), $extensions);
    }
}
