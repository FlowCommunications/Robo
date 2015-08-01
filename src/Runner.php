<?php
namespace Robo;

use Robo\Common\IO;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;

class Runner
{
    use IO;

    const VERSION = '0.5.3';
    const ROBOCLASS = 'RoboFile';
    const ROBOFILE = 'RoboFile.php';
    const DOTFILE = '.robo.php';

    protected $currentDir = '.';
    protected $passThroughArgs = null;

    /**
     * @var ConsoleOutput
     */
    protected static $printer;

    protected function beginRoboFileInit()
    {
        $this->writeln("<comment>  " . self::ROBOFILE . " nor .robo.php were found in this dir </comment>");

        $answer = $this->ask("  Should I create RoboFile here? (y/n)  \n");

        if (strtolower(trim($answer)) === 'y') {
            $this->initRoboFile();
        }

        exit;
    }

    protected function loadDotFiles()
    {
        $config = [];

        if (file_exists(self::DOTFILE)) {
            $config = require(self::DOTFILE);

            if (isset($config['load'])) {
                foreach ($config['load'] as $i => $file) {
                    $realpath = realpath($file);
                    if (!$realpath) {
                        exit(".robo.php: $file could not be found");
                    }
                    $config['load'][$i] = $realpath;
                }
            }

        }

        return $config;
    }

    public function execute($input = null)
    {
        register_shutdown_function(array($this, 'shutdown'));

        set_error_handler(array($this, 'handleError'));

        Config::setOutput(new ConsoleOutput());

        $dotfileConfig = $this->loadDotFiles();

        $inputArr = $input ?: $_SERVER['argv'];

        $input = $this->prepareInput($inputArr);

        $initConfig = [
            'load' => [],
            'args' => [],
        ];

        $initConfig = array_merge($initConfig, $dotfileConfig);

        if (file_exists(self::ROBOFILE)) {
            $initConfig['load'][self::ROBOCLASS] = self::ROBOFILE;
        }

        if (empty($initConfig['load'])) {
            $this->beginRoboFileInit();
        }

        $app = new Application('Robo', self::VERSION);

        foreach ($initConfig['load'] as $class => $file) {
            require_once($file);

            if (!class_exists($class)) {
                $this->writeln("<error>Class {$class} was not loaded</error>");

                $app = new Application('Robo', self::VERSION);
                $app->add(new Init('init'));
                $app->run();

                return;
            }

            $this->addTasksFromRoboFile($app, $class, $initConfig['args']);
        }

        $app->run($input);
    }

    public function addTasksFromRoboFile($app, $className, array $defaultArgs = [])
    {
        $roboTasks = new $className;

        $commandNames = array_filter(get_class_methods($className), function ($m) {
            return !in_array($m, ['__construct']);
        });

        $passThrough = $this->passThroughArgs;

        foreach ($commandNames as $commandName) {
            $command = $this->createCommand(new TaskInfo($className, $commandName));

            $command->setCode(function (InputInterface $input) use ($roboTasks, $commandName, $passThrough, $defaultArgs) {
                // get passthru args
                $args = $input->getArguments();

                array_shift($args);

                if ($passThrough) {
                    $args[key(array_slice($args, -1, 1, true))] = $passThrough;
                }

                $args[] = $input->getOptions();

                $res = call_user_func_array([$roboTasks, $commandName], $args);

                if (is_int($res)) {
                    exit($res);
                }

                if (is_bool($res)) {
                    exit($res ? 0 : 1);
                }

                if ($res instanceof Result) {
                    exit($res->getExitCode());
                }

            });
            $app->add($command);
        }


        return $app;
    }

    protected function prepareInput($argv)
    {
        $pos = array_search('--', $argv);
        if ($pos !== false) {
            $this->passThroughArgs = implode(' ', array_slice($argv, $pos + 1));
            $argv = array_slice($argv, 0, $pos);
        }

        return new ArgvInput($argv);
    }

    public function createCommand(TaskInfo $taskInfo)
    {
        $task = new Command($taskInfo->getName());
        $task->setDescription($taskInfo->getDescription());
        $task->setHelp($taskInfo->getHelp());

        $args = $taskInfo->getArguments();
        foreach ($args as $name => $val) {
            $description = $taskInfo->getArgumentDescription($name);
            if ($val === TaskInfo::PARAM_IS_REQUIRED) {
                $task->addArgument($name, InputArgument::REQUIRED, $description);
            } elseif (is_array($val)) {
                $task->addArgument($name, InputArgument::IS_ARRAY, $description, $val);
            } else {
                $task->addArgument($name, InputArgument::OPTIONAL, $description, $val);
            }
        }
        $opts = $taskInfo->getOptions();
        foreach ($opts as $name => $val) {
            $description = $taskInfo->getOptionDescription($name);

            $fullname = $name;
            $shortcut = '';
            if (strpos($name, '|')) {
                list($fullname, $shortcut) = explode('|', $name, 2);
            }

            if (is_bool($val)) {
                $task->addOption($fullname, $shortcut, InputOption::VALUE_NONE, $description);
            } else {
                $task->addOption($fullname, $shortcut, InputOption::VALUE_OPTIONAL, $description, $val);
            }
        }

        return $task;
    }

    protected function initRoboFile()
    {
        file_put_contents(
            self::ROBOFILE,
            '<?php'
            . "\n/**"
            . "\n * This is project's console commands configuration for Robo task runner."
            . "\n *"
            . "\n * @see http://robo.li/"
            . "\n */"
            . "\nclass " . self::ROBOCLASS . " extends \\Robo\\Tasks\n{\n    // define public methods as commands\n}"
        );
        $this->writeln(self::ROBOFILE . " created");

    }

    public function shutdown()
    {
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }
        $this->writeln(sprintf("<error>ERROR: %s \nin %s:%d\n</error>", $error['message'], $error['file'],
            $error['line']));
    }

    /**
     * This is just a proxy error handler that checks the current error_reporting level.
     * In case error_reporting is disabled the error is marked as handled, otherwise
     * the normal internal error handling resumes.
     *
     * @return bool
     */
    public function handleError()
    {
        if (error_reporting() === 0) {
            return true;
        }

        return false;
    }
}

