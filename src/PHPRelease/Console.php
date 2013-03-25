<?php
namespace PHPRelease;
use CLIFramework\Application;
use Exception;
use RuntimeException;
use PHPRelease\VersionReader;

function findbin($bin)
{
    $paths = explode(':',getenv('PATH') );
    foreach( $paths as $path ) {
        if ( file_exists($path . DIRECTORY_SEPARATOR . $bin ) ) {
            return true;
        }
    }
    return false;
}

class Console extends Application
{
    const NAME = "PHPRelease";
    const VERSION = "1.1.12";

    public function brief()
    {
        return "PHPRelease - The Fast PHP Release Manager.";
    }

    public $config = array();

    public function options($opts)
    {
        parent::options($opts);
        $opts->add('dryrun','dryrun mode.');
        $opts->add('skip+','skip specific step');

        foreach( $this->getTaskObjects() as $task ) {
            $task->options($opts);
        }
    }

    public function init()
    {
        parent::init();
        $this->registerCommand('init');
    }

    public function findTaskClass($step)
    {
        if ( class_exists( $step, true ) ) {
            return $step;
        } else {
            // built-in task
            $class = 'PHPRelease\\Tasks\\' . $step;
            if ( class_exists($class, true ) ) {
                return $class;
            }
        }
    }

    public function createTaskObject($class)
    {
        $task = $this->createCommand($class);
        $task->setConfig($this->getConfig());
        $task->setOptions($this->options);
        return $task;
    }

    public function getTaskObjects()
    {
        $tasks = array();
        $steps = $this->getSteps();
        foreach( $steps as $step ) {
            if ( file_exists($step)  || findbin($step) ) {
                continue;
            }

            $taskClass = $this->findTaskClass($step);
            if ( ! $taskClass ) {
                throw new RuntimeException("Task class for $step not found.");
            }
            $task = $this->createTaskObject($taskClass);
            $tasks[] = $task;
        }
        return $tasks;
    }


    public function getVersionFromFiles()
    {
        if ( isset($this->config['VersionFrom']) && $this->config['VersionFrom'] ) {
            return preg_split('#\s*,\s*#', $this->config['VersionFrom']);
        }
        return array();
    }

    public function getCurrentVersion()
    {
        // XXX: Refactor to FindVersion task.
        $reader = new VersionReader;
        $versionFromFiles = $this->getVersionFromFiles();
        if ( ! empty($versionFromFiles) ) {
            if ( $versionString = $reader->readFromSourceFiles($versionFromFiles) ) {
                $this->logger->debug("Found version from source files.");
                return $versionString;
            }
        }

        if ( $versionString = $reader->readFromComposerJson() ) {
            $this->logger->debug("Found version from composer.json");
            return $versionString;
        }

        if ( $versionString = $reader->readFromPackageINI() ) {
            $this->logger->debug("Found version from package.ini");
            return $versionString;
        }

        $this->logger->error("Version string not found, aborting...");
        return false;
    }

    public function runSteps($steps, $dryrun = false)
    {
        foreach( $steps as $step ) {
            if ( file_exists( $step ) || findbin($step) ) {
                $this->logger->info("===> Running $step");
                if ( ! $dryrun ) {
                    $lastline = system($step, $retval);
                    if ( $retval ) {
                        $this->logger->error($lastline);
                        $this->logger->error("===> $step failed, aborting...");
                        exit(0);
                    }
                }
                continue;
            }

            $taskClass = $this->findTaskClass($step);
            if ( ! $taskClass ) {
                $this->logger->error("===> Taks $step not found, aborting...");
                exit(0);
            }

            $task = $this->createTaskObject($taskClass);

            $this->logger->info("===> Running $taskClass");
            if ( ! $dryrun ) {
                $retval = $task->execute();
                if ( false === $retval ) {
                    $this->logger->error("===> $taskClass failed, aborting...");
                    exit(0);
                }
            }
        }
    }


    public function getSteps()
    {
        $config = $this->getConfig();
        $steps = array();
        if ( isset($config['Steps']) ) {
            $steps = preg_split('#\s*,\s*#', $config['Steps'] );
        }

        if ( $this->options && $this->options->skip ) {
            $keys = array_combine( $steps , $steps );
            foreach( $this->options->skip as $s ) {
                unset($keys[$s]);
            }
            $steps = array_keys($keys);
        }
        return $steps;
    }

    public function getConfig()
    {
        if ( $this->config ) {
            return $this->config;
        }

        if ( file_exists('phprelease.ini') ) {
            $config = parse_ini_file('phprelease.ini');
            return $this->config = $config;
        }
        return $this->config = array();
    }

    public function execute()
    {
        if ( ! file_exists('phprelease.ini') ) {
            throw new RuntimeException("phprelease.ini not found, please run `phprelease init` command to get one.");
        }

        $input = $this->ask("Are you sure to release? [Press Enter To Continue]");


        $config = $this->getConfig();
        if ( isset($config['Autoload']) ) {
            if ( $a = $config['Autoload'] ) {
                $this->logger->info("===> Found autoload script, loading $a");
                $loader = require $a;
            }
        }
        elseif ( file_exists('vendor/autoload.php') ) {
            $this->logger->info("===> Found autoload script from composer, loading...");
            $loader = require "vendor/autoload.php";
        }

        $steps = $this->getSteps();
        $this->runSteps($steps, $this->options->dryrun);
    }
}

