<?php

class MergeFiles
{
    private $projectDir;
    private $dependencyScanRoot;
    private $paths;
    private $scanDependencies;
    private $extensions;
    private $removeStyleTag;
    private $removeHtmlComments;
    private $removeSingleLineComments;
    private $removeMultiLineComments;
    private $removeEmptyLines;
    private $ignoreFiles;
    private $ignoreDirectories;
    private $outputFile;

    const DEPENDENCY_EXTENSIONS = ['vue', 'js', 'ts'];

    public function __construct(array $config)
    {
        $this->projectDir = $config['projectDir'];
        $this->dependencyScanRoot = $config['dependencyScanRoot'];
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
    }

    public function merge()
    {
        $usedFiles = [];
        $mergedContent = '';

        $allPaths = $this->scanAllDependencies();

        foreach ($allPaths as $relativePath) {
            if (in_array($relativePath, $usedFiles) || $this->isIgnoredDirectory($this->projectDir . '/' . $relativePath, $this->ignoreDirectories, $this->projectDir)) {
                continue;
            }

            $usedFiles[] = $relativePath;
            $absoluteFilePath = $this->projectDir . '/' . $relativePath;

            if (!file_exists($absoluteFilePath)) {
                echo "Предупреждение: Файл не существует: $absoluteFilePath" . PHP_EOL;
                continue;
            }

            $content = file_get_contents($absoluteFilePath);

            // Применяем фильтры к содержимому файла
            $content = $this->applyFilters($content);

            $rootFolderName = basename($this->projectDir);
            $fullRelativePath = '/' . $rootFolderName . '/' . $relativePath;

            $mergedContent .= "// Начало файла -> $fullRelativePath" . PHP_EOL;
            $mergedContent .= $content . PHP_EOL;
            $mergedContent .= "// Конец файла -> $fullRelativePath" . str_repeat(PHP_EOL, 2);
        }

        $mergedContent = rtrim($mergedContent, PHP_EOL);

        file_put_contents($this->outputFile, $mergedContent);

        $fileLinesInfo = $this->calculateFileLinesInfo($mergedContent);

        $this->printConsoleOutput($fileLinesInfo);
    }

    private function applyFilters($content)
    {
        if ($this->removeStyleTag) {
            $content = preg_replace('/<style.*?>.*?<\/style>/s', '', $content);
        }

        if ($this->removeHtmlComments) {
            $content = preg_replace('/<!--.*?-->/s', '', $content);
        }

        if ($this->removeSingleLineComments) {
            $content = preg_replace('!^\s*//.*?(\r?\n|\r)!m', '', $content);
        }

        if ($this->removeMultiLineComments) {
            $content = preg_replace('!/\*[\s\S]*?\*/\s*!', '', $content);
        }

        if ($this->removeEmptyLines) {
            $content = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", PHP_EOL, $content);
        }

        return rtrim($content);
    }

    private function calculateFileLinesInfo($mergedContent)
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

    private function makeRelativePath($filePath, $projectDir)
    {
        $relativePath = str_replace($projectDir, '', $filePath);
        return ltrim($relativePath, '/');
    }

    private function isIgnoredDirectory($filePath, $ignoreDirectories, $projectDir)
    {
        $relativeFilePath = trim(str_replace($projectDir, '', $filePath), '/');

        foreach ($ignoreDirectories as $ignoredDir) {
            $ignoredDir = trim($ignoredDir, '/');
            if (strpos($relativeFilePath . '/', $ignoredDir . '/') === 0 || $relativeFilePath === $ignoredDir) {
                return true;
            }
        }

        return false;
    }

    private function shouldIncludeFile($filename, $extensions)
    {
        if (in_array('*', $extensions)) {
            return true;
        }
        $fileExtension = pathinfo($filename, PATHINFO_EXTENSION);
        return in_array($fileExtension, $extensions);
    }

    private function scanDependencies($file, &$scannedFiles = [])
    {
        echo PHP_EOL . "Сканирование зависимостей для файла: $file" . PHP_EOL;

        $fullPath = $this->projectDir . '/' . ltrim($file, '/');

        if (!$this->shouldIncludeFile($fullPath, self::DEPENDENCY_EXTENSIONS)) {
            echo "  Файл $file пропущен (не соответствует расширениям для зависимостей)." . PHP_EOL;
            return [];
        }

        if (!file_exists($fullPath) || is_dir($fullPath) || in_array($file, $scannedFiles) || $this->isIgnoredDirectory($fullPath, $this->ignoreDirectories, $this->dependencyScanRoot)) {
            return [];
        }

        $scannedFiles[] = $file;
        $content = file_get_contents($fullPath);
        $dependencies = [];

        $importRegex = '/import\s+(?:{[^}]+}|\w+)\s+from\s+[\'"]([^\'"]+)[\'"]/';

        if (preg_match_all($importRegex, $content, $matches)) {
            foreach ($matches[1] as $match) {
                echo "  Найден импорт: $match" . PHP_EOL;
                $dependencyPath = $this->resolveDependencyPath($match, $file);
                if ($dependencyPath && !$this->isIgnoredDirectory($this->projectDir . '/' . $dependencyPath, $this->ignoreDirectories, $this->dependencyScanRoot)) {
                    echo "  Разрешен путь: $dependencyPath" . PHP_EOL;
                    $dependencies[] = $dependencyPath;
                    $dependencies = array_merge($dependencies, $this->scanDependencies($dependencyPath, $scannedFiles));
                } else {
                    echo "  Не удалось разрешить путь для: $match или путь находится в игнорируемой директории" . PHP_EOL;
                }
            }
        }

        return array_unique($dependencies);
    }

    private function resolveDependencyPath($importPath, $currentFile)
    {
        $currentDir = dirname($currentFile);

        if (strpos($importPath, './') === 0 || strpos($importPath, '../') === 0) {
            $resolvedPath = realpath($this->dependencyScanRoot . '/' . $currentDir . '/' . $importPath);
            if ($resolvedPath && !$this->isIgnoredDirectory($resolvedPath, $this->ignoreDirectories, $this->dependencyScanRoot)) {
                return $this->makeRelativePath($resolvedPath, $this->projectDir);
            }
        }

        $foundPath = $this->findFileRecursively($this->dependencyScanRoot, $importPath);
        if ($foundPath) {
            return $this->makeRelativePath($foundPath, $this->projectDir);
        }

        $extensions = ['.js', '.vue', '.ts'];
        foreach ($extensions as $ext) {
            $foundPath = $this->findFileRecursively($this->dependencyScanRoot, $importPath . $ext);
            if ($foundPath) {
                return $this->makeRelativePath($foundPath, $this->projectDir);
            }
        }

        return null;
    }

    private function findFileRecursively($dir, $filename)
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                if ($this->isIgnoredDirectory($file->getPathname(), $this->ignoreDirectories, $dir)) {
                    $iterator->next();
                    continue;
                }
            }

            if ($file->isFile() && $file->getFilename() === basename($filename)) {
                if (!$this->isIgnoredDirectory($file->getPathname(), $this->ignoreDirectories, $dir)) {
                    return $file->getPathname();
                }
            }
        }

        return null;
    }

    private function scanAllDependencies()
    {
        $allFiles = [];
        $dependencyFiles = [];

        echo "Project Directory: {$this->projectDir}" . PHP_EOL;

        if (!is_dir($this->projectDir)) {
            echo "Ошибка: Директория проекта не существует: {$this->projectDir}" . PHP_EOL;
            exit(1);
        }

        foreach ($this->paths as $path) {
            $fullPath = $this->projectDir . '/' . ltrim($path, '/');
            if (is_file($fullPath)) {
                if (!in_array(basename($fullPath), $this->ignoreFiles) &&
                    $this->shouldIncludeFile(basename($fullPath), $this->extensions) &&
                    !$this->isIgnoredDirectory($fullPath, $this->ignoreDirectories, $this->projectDir)) {
                    $relativePath = $this->makeRelativePath($fullPath, $this->projectDir);
                    $allFiles[] = $relativePath;
                    if ($this->scanDependencies) {
                        $dependencies = $this->scanDependencies($relativePath);
                        $dependencyFiles = array_merge($dependencyFiles, $dependencies);
                    }
                }
            } elseif (is_dir($fullPath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $relativeFilePath = $this->makeRelativePath($file->getPathname(), $this->projectDir);
                        if (!in_array($file->getBasename(), $this->ignoreFiles) &&
                            !$this->isIgnoredDirectory($file->getPathname(), $this->ignoreDirectories, $this->projectDir) &&
                            $this->shouldIncludeFile($file->getBasename(), $this->extensions)
                        ) {
                            $allFiles[] = $relativeFilePath;
                            if ($this->scanDependencies) {
                                $dependencies = $this->scanDependencies($relativeFilePath);
                                $dependencyFiles = array_merge($dependencyFiles, $dependencies);
                            }
                        }
                    }
                }
            } else {
                echo "Предупреждение: Путь не существует или не соответствует условиям: $fullPath" . PHP_EOL;
            }
        }

        $result = $this->scanDependencies ? array_unique(array_merge($allFiles, $dependencyFiles)) : $allFiles;

        $result = array_filter($result, function($path) {
            return !$this->isIgnoredDirectory($this->projectDir . '/' . $path, $this->ignoreDirectories, $this->projectDir);
        });

        echo PHP_EOL . "Итоговый результат scanAllDependencies():" . PHP_EOL;
        print_r($result);

        return $result;
    }

    private function printConsoleOutput($fileLinesInfo)
    {
        $consoleOutput = PHP_EOL . 'Ниже написано содержание прикреплённого файла в котором объединён код нескольких файлов проекта. Это содержание указывает на начальные и конечные строки файлов которые были объеденины в прикреплённый файл к этому сообщению:' . PHP_EOL;

        foreach ($fileLinesInfo as $info) {
            $consoleOutput .= $info . PHP_EOL;
        }

        $consoleOutput .= PHP_EOL . 'Отвечай, как опытный программист с более чем 10-летним стажем. Когда отвечаешь выбирай современные практики (лучшие подходы). После прочтения жди вопросы по этому коду, просто жди вопросов, не нужно ничего отвечать.' . str_repeat(PHP_EOL, 2);

        echo $consoleOutput;

        exec("which pbcopy > /dev/null && printf " . escapeshellarg(trim($consoleOutput)) . " | pbcopy");
    }
}
