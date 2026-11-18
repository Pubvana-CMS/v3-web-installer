<?php

namespace PubvanaInstaller\Source;

use PubvanaInstaller\Result;

/**
 * Last-resort source. It is always available but always fails with a message.
 * prompting the user to upload the zip manually via the UI.
 */
class ManualSource implements SourceInterface
{
    public function __construct(
        private readonly string $zipUrl,
    ) {}

    public function getLabel(): string
    {
        return 'Manual Upload';
    }

    public function canExecute(): bool
    {
        return true;
    }

    public function download(string $targetDir): Result
    {
        return Result::fail(
            'Automatic download failed. Upload the release zip below.'
        );
    }

    public function getZipUrl(): string
    {
        return $this->zipUrl;
    }
}
