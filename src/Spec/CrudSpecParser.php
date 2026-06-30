<?php

declare(strict_types=1);

namespace Curdder\Spec;

use RuntimeException;

final class CrudSpecParser
{
    public static function fromFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Spec file not found: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read spec file: {$path}");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'php') {
            $spec = require $path;
            if (!is_array($spec)) {
                throw new RuntimeException('PHP spec files must return an array.');
            }
            return $spec;
        }

        $spec = json_decode($contents, true);
        if (!is_array($spec)) {
            throw new RuntimeException('Spec file must contain valid JSON or PHP array data.');
        }

        return $spec;
    }
}
