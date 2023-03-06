<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->filesystem = app(Filesystem::class);

    $this->path = __DIR__ . '/../Fixtures/Facades';

    if ($this->filesystem->exists($this->path)) {
        $this->filesystem->deleteDirectory($this->path);
    }

    $this->filesystem->makeDirectory($this->path, 0777, true, true);

    foreach ($this->filesystem->files(__DIR__ . '/../stubs/Facades') as $file) {
        $this->filesystem->put(
            $this->path . '/' . Str::replace('.stub', '.php', $file->getFilename()),
            $this->filesystem->get($file->getPathname())
        );
    }
});

afterEach(function () {
    if ($this->filesystem->exists($this->path)) {
        $this->filesystem->deleteDirectory($this->path);
    }
});

it('generates docblocks for facade', function () {
});
