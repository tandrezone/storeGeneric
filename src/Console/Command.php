<?php

declare(strict_types=1);

namespace App\Console;

/** A bin/console command. Commands are built by the container, so they get their dependencies injected. */
interface Command
{
    /** Name used on the command line, e.g. "images:add". */
    public function name(): string;

    /** One-line summary shown by `bin/console list`. */
    public function description(): string;

    /** Argument synopsis, e.g. "<product_id> <url> [--gallery]". */
    public function usage(): string;

    /** @return int exit code (0 = success) */
    public function run(Input $input, Output $output): int;
}
