<?php declare(strict_types=1);

namespace SocialPost\Command;

use SocialPost\Service\PostService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Sendet fällige geplante Beiträge, z. B. per Cronjob: bin/console social:senden */
#[AsCommand(name: 'social:senden', description: 'Sendet fällige geplante Social-Media-Beiträge')]
class SendCommand extends Command
{
    public function __construct(private readonly PostService $posts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('max', null, InputOption::VALUE_REQUIRED, 'Höchstzahl Beiträge je Lauf', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $done = $this->posts->runDue(max(1, (int) $input->getOption('max')));
        if ($done === []) {
            $output->writeln('Keine fälligen Beiträge.');

            return Command::SUCCESS;
        }
        foreach ($done as $p) {
            $output->writeln(sprintf('%s: %s (%s)', $p['id'], $p['title'], $p['status']));
        }

        return Command::SUCCESS;
    }
}
