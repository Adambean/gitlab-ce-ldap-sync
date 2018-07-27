<?php

namespace AdamReece\GitlabCeLdapGroupSync;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class LdapGroupSyncCommand extends \Symfony\Component\Console\Command\Command
{
    /**
     * Configures the current command.
     * @return void
     */
    public function configure(): void
    {
        $this
            ->setName("ldap:groups:sync")
            ->setDescription("Sync LDAP groups with a Gitlab installation.")
            ->setHelp('This command allows you to greet a user based on the time of the day...')
            ->addOption("dryrun", "d", InputOption::VALUE_NONE, "Dry run: Do not write any changes.")
            ->addArgument("instance", InputArgument::OPTIONAL, "Sync with a specific instance, or leave unspecified to work with all.")
        ;
    }

    /**
     * Executes the current command.
     * @param  InputInterface  $input  Input interface
     * @param  OutputInterface $output Output interface
     * @return int|null                Error code, or null/zero for success
     */
    public function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $logger = new ConsoleLogger($output);
        $output->writeln("LDAP group sync script for Gitlab-CE");

        return 0;
    }
}
