<?php

declare(strict_types=1);

namespace openvk\CLI;

use openvk\Web\Models\Repositories\Posts;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PublishPostponedPostsCommand extends Command
{
    protected static $defaultName = "publish-postponed-posts";

    protected function configure(): void
    {
        $this->setDescription("Publish postponed wall posts whose time has come")
             ->addOption(
                 "limit",
                 "l",
                 InputOption::VALUE_OPTIONAL,
                 "Maximum posts to publish per run (default: 500)",
                 500
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $published = (new Posts())->publishDuePostponed((int) $input->getOption("limit"));
        $output->writeln("<info>Published {$published} postponed posts</info>");

        return Command::SUCCESS;
    }
}
