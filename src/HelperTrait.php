<?php
/**
 * Created by PhpStorm.
 * User: cod1k
 * Date: 25/02/2018
 * Time: 23:02
 */

namespace Jobby;


/**
 * Trait HelperTrait
 * @package Jobby
 */
trait HelperTrait
{
    protected $helper;
    /**
     * @return Helper
     */
    protected function getHelper()
    {
        if ($this->helper === null) {
            $this->helper = new Helper();
        }

        return $this->helper;
    }
}