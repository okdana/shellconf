# \Dana\ShellConf

`\Dana\ShellConf` is a PHP project which provides methods for parsing and
manipulating shell-style (specifically bash-style) variable assignments, which
can be useful for simple configuration needs. (But see *Rationale* below.)

## Usage

Install via Composer:

```
composer require dana/shellconf
```

Given a `config.sh` like this:

```bash
# comment here
MYVAR1=foo # another comment here
MYVAR2='foo bar'
MYVAR3="foo \"bar\" baz"
```

And a `config.php` like this:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

$conf = new \Dana\ShellConf\ShellConf();
$conf->load('config.sh');

echo json_encode($conf->toArray(), \JSON_PRETTY_PRINT), "\n";
```

`php config.php` produces:

```json
{
    "MYVAR1": "foo",
    "MYVAR2": "foo bar",
    "MYVAR3": "foo \"bar\" baz"
}
```

Useful methods:

* `load($file)` — Load config file `$file`
* `parse($string)` — Parse config string `$string`
* `reset()` — Reset any loaded variables between `parse()` calls
* `set($name, $value)` — Create and set variable `$name` to `$value`
* `unset($name)` — Unset variable `$name`
* `get($name, $default)` — Get the value of variable `$name`
* `toArray()` — Get all loaded variables and their values as an array
* `toString()` — Get all loaded variables and their values as a bash-compatible
  config string (to write to a file for example)

## Rationale

This project is extremely similar to a number of others designed to read
'dotenv' files, but i had some specific goals in mind that didn't necessarily
align with theirs:

* Most of them are focussed on loading files into environment variables, which
  isn't what i needed. I just want to parse the file, and maybe write it back.

* Some of them don't seem to handle escaping and unescaping in exactly the same
  way as bash.

* Many of them are overly clever for my purposes, supporting things like
  bash-incompatible extensions, variable interpolation, even command substition,
  which i don't want.

* I was interested in some finer-grained methods related to escaping as well as
  writing bash-compatible lines back out.

For all of those reasons, i decided to write my own implementation. But if you
don't care about any of that, you should absolutely check out one of these, much
more widely used, projects:

* [symfony/dotenv](https://github.com/symfony/dotenv)
* [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv)
* [josegonzalez/php-dotenv](https://github.com/josegonzalez/php-dotenv)

## To do

More tests.

