<?php
namespace Wing\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wing\Library\Worker;

class ServerStart extends Command
{
    protected function configure()
    {
        $this
            ->setName('start')
            ->setDescription('服务启动')
            ->addOption("d", null, InputOption::VALUE_NONE, "守护进程")
            ->addOption("debug", null, InputOption::VALUE_NONE, "调试模式")
            ->addOption("n", null, InputOption::VALUE_REQUIRED, "进程数量", 4);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $daemon      = $input->getOption("d");
        $debug       = $input->getOption("debug");
        $workers     = $input->getOption("n");
        $worker = new Worker([
                "daemon"  => !!$daemon,
                "debug"   => !!$debug,
                "workers" => $workers
            ]);
        $worker->start();
    }
}
