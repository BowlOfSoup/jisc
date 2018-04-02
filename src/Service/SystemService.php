<?php

namespace Jisc\Service;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SystemService
{
    const FILE_READ_ARRAY = true;

    /**
     * @param string $fullPath
     * @param bool $readLinesAsArray
     *
     * @throws \InvalidArgumentException
     *
     * @return string|array
     */
    public function getFileContent(string $fullPath, $readLinesAsArray = false)
    {
        if (!file_exists($fullPath)) {
            throw new \InvalidArgumentException(sprintf('File %s does not exist', $fullPath));
        }

        if ($readLinesAsArray) {
            $content = file_get_contents($fullPath);

            return explode("\n", $content);
        }

        return file_get_contents($fullPath);
    }

    /**
     * @param string $command
     *
     * @throws \Symfony\Component\Process\Exception\ProcessFailedException
     *
     * @return string
     */
    public function runCommand(string $command): string
    {
        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }
}
