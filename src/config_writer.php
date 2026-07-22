<?php

declare(strict_types=1);

function writeConfig(array $config): void
{
    $text = "<?php\n\nreturn ";
    $text .= var_export($config, true);
    $text .= ";\n";

    $path = __DIR__ . '/config.php';
    if (file_put_contents($path, $text) === false) {
        throw new RuntimeException('Konnte die Konfigurationsdatei nicht schreiben: ' . $path);
    }
}
