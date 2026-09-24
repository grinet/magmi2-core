<?php

declare(strict_types=1);

namespace Magmi\Core\Remote;

interface RemoteAdapterInterface
{
    /**
     * Connect to remote server.
     */
    public function connect(array $config): void;

    /**
     * Disconnect from remote server.
     */
    public function disconnect(): void;

    /**
     * List files in a remote directory.
     *
     * @return string[]
     */
    public function list(string $remotePath): array;

    /**
     * Download a remote file to local path.
     */
    public function download(string $remotePath, string $localPath): void;

    /**
     * Upload a local file to remote path.
     */
    public function upload(string $localPath, string $remotePath): void;
}
