<?php
namespace Wing\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Wing\Library\Worker;

class ServerStatus extends Command
{
    protected function configure()
    {
        $this
            ->setName('status')
            ->setDescription('服务状态');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        Worker::showStatus();
        sleep(1);
        echo file_get_contents(HOME."/logs/status.log");
        return Command::SUCCESS;
    }
}
