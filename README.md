
# Инструменты для работы с файлами

Этот проект содержит набор утилит для работы с файлами, такими как объединение файлов, генерация коммитов и построение структуры директорий.

## Содержание
1. [run_merge_files.php](#run_merge_filesphp)
2. [commit_generator.php](#commit_generatorphp)
3. [draw_tree.php](#draw_treephp)

---

## run_merge_files.php

Этот скрипт объединяет файлы в один.

### Конфигурация

| Параметр                | Тип      | Описание                                                                                          |
|-------------------------|----------|---------------------------------------------------------------------------------------------------|
| `projectDir`            | string   | Путь к директории проекта. Определяет корневую директорию для работы.                              |
| `dependencyScanRoot`     | string   | Директория, откуда начинается сканирование зависимостей.                                           |
| `paths`                 | array    | Список путей к папкам или файлам, которые должны быть объединены.                                  |
| `scanDependencies`       | boolean  | Определяет, нужно ли сканировать зависимости для файлов.                                           |
| `extensions`            | array    | Массив допустимых расширений файлов. Можно указать `*`, чтобы включить все файлы.                  |
| `removeStyleTag`        | boolean  | Удалять ли теги `<style>` из файлов.                                                              |
| `removeHtmlComments`    | boolean  | Удалять ли HTML комментарии.                                                                      |
| `removeSingleLineComments` | boolean | Удалять ли однострочные комментарии.                                                             |
| `removeMultiLineComments` | boolean | Удалять ли многострочные комментарии.                                                            |
| `removeEmptyLines`      | boolean  | Удалять ли пустые строки.                                                                         |
| `ignoreFiles`           | array    | Массив файлов, которые нужно игнорировать при объединении.                                         |
| `ignoreDirectories`     | array    | Массив директорий, которые нужно игнорировать при сканировании.                                    |
| `outputFile`            | string   | Путь к файлу, в который будет записан объединенный результат.                                      |

### Пример использования

```php
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
```

---

## commit_generator.php

Этот скрипт генерирует сообщения для коммитов, используя API OpenAI. Он получает информацию о разнице файлов из stdin и формирует название и описание коммита на основе этой информации.

### Пример использования

```bash
git diff | php commit_generator.php "Дополнительные инструкции"
```

---

## draw_tree.php

Этот скрипт создает дерево файловой структуры для заданной директории и сохраняет его в файл `directory_tree.txt`.

### Конфигурация

| Параметр             | Тип    | Описание                                                                    |
|----------------------|--------|-----------------------------------------------------------------------------|
| `directoryPath`      | string | Путь к директории, для которой нужно построить дерево файлов.                |
| `extensions`         | array  | Массив расширений файлов для включения в дерево.                             |
| `ignoredDirectories` | array  | Массив папок, которые нужно игнорировать при построении дерева.              |

### Пример использования

```php
$directoryPath = '/folder';
$extensions = ['php', 'vue', 'js'];
$ignoredDirectories = ['node_modules', 'vendor', '.git'];

echo drawTree($directoryPath, '', true, $extensions, $ignoredDirectories);
```

Дерево будет записано в файл `directory_tree.txt`.
