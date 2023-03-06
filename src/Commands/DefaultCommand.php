<?php

namespace FacadeDocblockGenerator\Commands;

use FacadeDocblockGenerator\Generator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DefaultCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'default';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Generate the docblocks for facades in the given path';

    /**
     * The configuration of the command.
     *
     * @return void
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setDefinition(
                [
                    new InputArgument('namespace', InputArgument::REQUIRED, 'The namespace of the facade'),
                    new InputArgument('path', InputArgument::OPTIONAL, 'The path to generate docblocks in', './src/Facades/'),
                ]
            );
    }

    /**
     * Execute the console command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new Generator($input->getArgument('namespace'), $input->getArgument('path')))
            ->execute();

        return Command::SUCCESS;
    }
}
