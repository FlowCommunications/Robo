<?php
namespace Robo\Common;

trait TaskIO 
{
    use IO;

    protected static function getNamePadded($name)
    {

        $GLOBALS['longestTaskName'] = isset($GLOBALS['longestTaskName']) ? $GLOBALS['longestTaskName'] : 0;

        $GLOBALS['longestTaskName'] = strlen($name) > $GLOBALS['longestTaskName'] ? strlen($name) : $GLOBALS['longestTaskName'];

        $char = strncasecmp(PHP_OS, 'WIN', 3) == 0 ? '>' : '➜';

        return ' ' . str_pad($name, $GLOBALS['longestTaskName'], ' ') . " $char ";
    }

    protected function printTaskInfo($text, $task = null)
    {
        $name = static::getNamePadded($this->getPrintedTaskName($task));

        $this->printMultiLine($name, $text, "");
    }

    protected function printTaskSuccess($text, $task = null)
    {
        $name = static::getNamePadded($this->getPrintedTaskName($task));
        $this->printMultiLine($name, $text, "fg=green");
    }

    protected function printTaskError($text, $task = null)
    {
        $name = static::getNamePadded($this->getPrintedTaskName($task));
        $this->printMultiLine($name, $text, "fg=red");
    }

    protected function formatBytes($size, $precision = 2)
    {
        if ($size === 0) {
            return 0;
        }
        $base = log($size, 1024);
        $suffixes = array('', 'k', 'M', 'G', 'T');
        return round(pow(1024, $base - floor($base)), $precision) . $suffixes[floor($base)];
    }

    protected function getPrintedTaskName($task = null)
    {
        if (!$task) {
            $task = $this;
        }
        $name = get_class($task);
        $name = preg_replace('~Stack^~', '' , $name);
        $name = str_replace('Robo\Task\Base\\', '' , $name);
        $name = str_replace('Robo\Task\\', '' , $name);
        return $name;
    }

    protected function printMultiLine($name, $text, $textColour)
    {

        if (!isset($GLOBALS['cols'])) {
            $GLOBALS['cols'] = `tput cols`;
        }

        if (strpos($text, "\r")) {
            $writeFunc = "write";
        } else {
            $writeFunc = "writeln";
            $text = trim($text);
        }

        $lines = explode("\n", wordwrap($text, $GLOBALS['cols'] - strlen($name)));

        $textColourOpen = $textColour ? "<$textColour>": "";
        $textColourClose = $textColour ? "</$textColour>": "";

        foreach ($lines as $line) {
            call_user_func([$this->getOutput(), $writeFunc], "<bg=white;fg=black>$name</bg=white;fg=black> {$textColourOpen}{$line}{$textColourClose}");
        }

    }


}
