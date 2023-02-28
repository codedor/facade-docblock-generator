<?php

namespace App\Commands;

use App\Generator;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class GenerateCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'facade:generate-docblocks';

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
                    new InputOption('lint', '', InputOption::VALUE_NONE, 'Lint the docblocks'),
                ]
            );
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        (new Generator($this->argument('namespace'), $this->argument('path'), $this->option('lint')))
            ->execute();
    }
}
