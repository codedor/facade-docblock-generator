<?php

use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->filesystem = app(Filesystem::class);

    $this->path = __DIR__ . '/../Fixtures/Facades/Http.php';

    if ($this->filesystem->exists(dirname($this->path))) {
        $this->filesystem->deleteDirectory(dirname($this->path));
    }

    $this->filesystem->makeDirectory(dirname($this->path), 0777, true, true);
    $this->filesystem->put($this->path, $this->filesystem->get(__DIR__ . '/../stubs/Facades/Http.stub'));
});

afterEach(function () {
    // if ($this->filesystem->exists(dirname($this->path))) {
    //     $this->filesystem->deleteDirectory(dirname($this->path));
    // }
});

it('generates docblocks for facade', function () {
    $this->artisan('facade:generate-docblocks Tests\\\Fixtures\\\Facades tests/Fixtures/Facades')->assertExitCode(0);
});
