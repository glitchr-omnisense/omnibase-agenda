<?php

// Checked out on its own (its own vendor/), or installed in an application's
// vendor/omnibase/agenda (a path repository's symlink: /srv/omnibase/agenda in
// the containers) - the application's autoloader then, which does not know
// this bundle's autoload-dev: the test namespace is registered here.
foreach ([
    __DIR__.'/../vendor/autoload.php',
    getcwd().'/vendor/autoload.php',
    __DIR__.'/../../../autoload.php',
] as $autoload) {
    if (is_file($autoload)) {
        $loader = require $autoload;
        if ($loader instanceof \Composer\Autoload\ClassLoader) {
            $loader->addPsr4('Base\\Agenda\\Tests\\', __DIR__);
        }

        // Its own classes (prepended: this checkout's, whatever the host installs)
        // and those of the omnibase/* bundles it requires or suggests, read beside this checkout
        // (the workspace, the harness's vendor/omnibase/) after the host's own: the
        // omnibase harness installs only some of them.
        $composer = static fn (string $directory): array => json_decode((string) file_get_contents($directory.'/composer.json'), true) ?: [];
        foreach ($composer(__DIR__.'/..')['autoload']['psr-4'] ?? [] as $namespace => $path) {
            $loader->addPsr4($namespace, __DIR__.'/../'.$path, true);
        }
        $required = array_keys(($composer(__DIR__.'/..')['require'] ?? []) + ($composer(__DIR__.'/..')['suggest'] ?? []));
        for ($seen = []; null !== $package = array_shift($required);) {
            $sibling = __DIR__.'/../../'.substr($package, \strlen('omnibase/'));
            if (!str_starts_with($package, 'omnibase/') || isset($seen[$package]) || !is_file($sibling.'/composer.json')) {
                continue;
            }
            $seen[$package] = true;
            foreach ($composer($sibling)['autoload']['psr-4'] ?? [] as $namespace => $path) {
                $loader->addPsr4($namespace, $sibling.'/'.$path);
            }
            array_push($required, ...array_keys(($composer($sibling)['require'] ?? []) + ($composer($sibling)['suggest'] ?? [])));
        }

        return;
    }
}

throw new RuntimeException('No composer autoloader found.');
