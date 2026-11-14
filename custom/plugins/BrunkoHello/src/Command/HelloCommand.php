<?php declare(strict_types=1);

namespace Brunko\Hello\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'brunko:hello', description: 'Vypíše text, že plugin funguje')]
class HelloCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Plugin funguje');

        return Command::SUCCESS;
    }
}
