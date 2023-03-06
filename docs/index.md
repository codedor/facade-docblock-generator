# Facade Docblock Generator

-   [Introduction](#introduction)
-   [Installation](#installation)
-   [Running the generator](#running-the-generator)

<a name="introduction"></a>

## Introduction

Facade Docblock Generator is a package that will help you with generating the docblocks for your facades.
Based on the work of [Tim MacDonald](https://github.com/timacdonald) in the [Laravel Framework repository](https://github.com/laravel/framework/blob/10.x/bin/facades.php).

<a name="installation"></a>

## Installation

You can install the package via composer:

```bash
composer require codedor/facade-docblock-generator --dev
```

<a name="running-the-generator"></a>

## Running the generator

```shell
vendor/bin/facade-docblock-generator <namespace> <path>
```

The namespace is the namespace for your facades and the path the path to where we can find your facades.

E.g. for the Laravel Framework this would be:

```bash
vendor/bin/facade-docblock-generator Illuminate\\Support\\Facades src/Illuminate/Support/Facades
```
