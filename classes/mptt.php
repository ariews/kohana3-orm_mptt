<?php defined('SYSPATH') OR die('No direct access allowed.');
/**
 * Its a combination of a traditional MPTT tree and additional usefull data (parent id, level value)
 *
 * @see http://dev.mysql.com/tech-resources/articles/hierarchical-data.html
 *
 * @package    Leemo
 * @author     Leemo team
 * @copyright  Copyright (c) 2010 Leemo team
 */
class MPTT extends Kohana_MPTT { }