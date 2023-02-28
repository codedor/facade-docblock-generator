<?php

use App\Generator;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->filesystem = app(Filesystem::class);

    $this->path = __DIR__ . '/../Fixtures/Facades';

    if ($this->filesystem->exists($this->path)) {
        $this->filesystem->deleteDirectory($this->path);
    }

    $this->filesystem->makeDirectory($this->path, 0777, true, true);

    foreach ($this->filesystem->files(__DIR__ . '/../stubs/Facades') as $file) {
        $file->filename();
        $this->filesystem->put($this->path, $this->filesystem->get(__DIR__ . '/../stubs/Facades/Http.stub'));
    }


    $this->generator = new Generator("Tests\\Fixtures\\Facades", 'tests/Fixtures/Facades', false);
});

afterEach(function () {
    if ($this->filesystem->exists(dirname($this->path))) {
        $this->filesystem->deleteDirectory(dirname($this->path));
    }
});

test('can execute', function () {
    $this->generator->execute();

    expect($this->filesystem->get($this->path))
        ->toBe($this->filesystem->get(__DIR__ . '/../__snapshots__/HttpSnapshot'));
});
