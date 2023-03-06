<?php

uses(Tests\TestCase::class)->in('Feature');

expect()->extend('toHasFileSnapshot', function () {
    $file = $this->value;

    $file->getFilenameWithoutExtension();

    $snapshotFile = __DIR__ . "/__snapshots__/{$file->getFilenameWithoutExtension()}Snapshot";

    // file content of $this must be equal to content of snapshot
    return $this->getContents()->toEqual(file_get_contents($snapshotFile));
});
