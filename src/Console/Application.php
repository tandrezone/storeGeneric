<?php

declare(strict_types=1);

namespace App\Console;

use Psr\Container\ContainerInterface;
use Throwable;

/** Minimal command runner behind bin/console. */
final class Application
{
    /** @var array<string, class-string<Command>> */
    private array $commands = [];

    /** @param list<class-string<Command>> $commandClasses */
    public function __construct(private readonly ContainerInterface $container, array $commandClasses)
    {
        foreach ($commandClasses as $class) {
            $this->commands[$this->container->get($class)->name()] = $class;
        }
    }

    /** @param list<string> $argv full argv, script name first */
    public function run(array $argv, Output $output = new Output()): int
    {
        $name = $argv[1] ?? 'list';
        if ($name === 'list' || $name === '--help' || $name === '-h') {
            $this->list($output);

            return 0;
        }

        if (!isset($this->commands[$name])) {
            $output->error("Unknown command \"{$name}\". Run bin/console list.");

            return 1;
        }

        /** @var Command $command */
        $command = $this->container->get($this->commands[$name]);
        $input = Input::fromArgv(array_slice($argv, 2));
        if ($input->hasOption('help')) {
            $output->line($command->description());
            $output->line("Usage: bin/console {$name} {$command->usage()}");

            return 0;
        }

        try {
            return $command->run($input, $output);
        } catch (Throwable $e) {
            $output->error('Error: ' . $e->getMessage());

            return 1;
        }
    }

    private function list(Output $output): void
    {
        $output->line('Usage: bin/console <command> [arguments] [--help]');
        $output->line();
        $output->line('Commands:');
        ksort($this->commands);
        foreach ($this->commands as $name => $class) {
            $output->line(sprintf('  %-20s %s', $name, $this->container->get($class)->description()));
        }
    }
}
