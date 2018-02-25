<?php
/**
 * Created by PhpStorm.
 * User: cod1k
 * Date: 25/02/2018
 * Time: 19:38
 */

namespace Jobby\Tests;


class SampleClass
{
    protected $args;
    protected $fh;

    public function __construct()
    {
        $this->args = func_get_args();
    }

    public function index()
    {
        $args = func_get_args();
        echo(__FUNCTION__.(!empty($this->args) ? " " . implode(', ', $this->args):'').(!empty($args) ? ' '.implode(', ', $args) : '').PHP_EOL);
    }

    public function custom()
    {
        $args = func_get_args();
        echo(__FUNCTION__.(!empty($this->args) ? " " . implode(', ', $this->args):'').(!empty($args) ? ' '.implode(', ', $args) : '').PHP_EOL);
    }
}