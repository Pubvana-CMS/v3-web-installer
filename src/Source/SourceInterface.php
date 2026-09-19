<?php

namespace PubvanaInstaller\Source;

use PubvanaInstaller\Result;

interface SourceInterface
{
    public function canExecute(): bool;
    public function download(string $targetDir): Result;
    public function getLabel(): string;
}
