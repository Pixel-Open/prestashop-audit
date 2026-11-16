<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Command;

use Pixel\Module\Audit\Storage\Repository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PurgeCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('pixel:audit:purge')->setDescription('Purge expired audit events in bounded batches (UTC).')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Row budget per resource and transaction (1-5000)', '500')
            ->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Maximum transactions per resource family (1-10000)', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batch = filter_var($input->getOption('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        $max = filter_var($input->getOption('max-batches'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($batch === false || $max === false) {
            $output->writeln('<error>Invalid batch limits.</error>');
            return 2;
        }
        $removed = (new Repository())->purgeAll($batch, $max);
        $output->writeln(sprintf('%d expired events, %d hidden states, %d expired capabilities and %d orphan operations removed.', $removed['events'], $removed['hidden'], $removed['tickets'], $removed['orphans']));
        return 0;
    }
}
