<?php
namespace Wing\Command;

use Symfony\Component\Console\Command\Command;
use Wing\Library\Worker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ServerStop extends Command
{
    protected function configure()
    {
        $this
            ->setName('stop')
            ->setDescription('停止服务');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        #exec("php ".HOME."/services/tcp.php stop");
        #exec("php ".HOME."/services/websocket.php stop");
        Worker::stopAll();
        return Command::SUCCESS;
    }
}
