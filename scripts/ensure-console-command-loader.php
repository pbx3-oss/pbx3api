#!/usr/bin/env php
<?php

/**
 * Symfony Console 7.4+ lazy CommandLoader skips Illuminate\Console\Application::add(),
 * so Artisan commands run with $laravel === null → "Call to a member function make() on null".
 *
 * Idempotently patch Laravel's ContainerCommandLoader::get() to call setLaravel().
 * Safe no-op when already patched or file missing (composer not installed yet).
 *
 * Tracked: product TODO #5l
 */

declare(strict_types=1);

$path = dirname(__DIR__).'/vendor/laravel/framework/src/Illuminate/Console/ContainerCommandLoader.php';

if (! is_file($path)) {
    fwrite(STDOUT, "ensure-console-command-loader: skip (vendor not present)\n");
    exit(0);
}

$src = file_get_contents($path);
if ($src === false) {
    fwrite(STDERR, "ensure-console-command-loader: cannot read {$path}\n");
    exit(1);
}

if (str_contains($src, 'setLaravel($this->container)')) {
    fwrite(STDOUT, "ensure-console-command-loader: already patched\n");
    exit(0);
}

$needle = <<<'PHP'
    public function get(string $name): Command
    {
        if (! $this->has($name)) {
            throw new CommandNotFoundException(sprintf('Command "%s" does not exist.', $name));
        }

        return $this->container->get($this->commandMap[$name]);
    }
PHP;

$replacement = <<<'PHP'
    public function get(string $name): Command
    {
        if (! $this->has($name)) {
            throw new CommandNotFoundException(sprintf('Command "%s" does not exist.', $name));
        }

        $command = $this->container->get($this->commandMap[$name]);

        if ($command instanceof \Illuminate\Console\Command) {
            $command->setLaravel($this->container);
        }

        return $command;
    }
PHP;

if (! str_contains($src, $needle)) {
    fwrite(STDERR, "ensure-console-command-loader: unexpected ContainerCommandLoader shape — update patch\n");
    exit(1);
}

$out = str_replace($needle, $replacement, $src, $count);
if ($count !== 1 || file_put_contents($path, $out) === false) {
    fwrite(STDERR, "ensure-console-command-loader: patch failed\n");
    exit(1);
}

fwrite(STDOUT, "ensure-console-command-loader: patched {$path}\n");
exit(0);
