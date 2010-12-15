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
class Kohana_MPTT extends ORM
{
	/**
	 * @var string
	 */
	protected $_left_column = 'left';

	/**
	 * @var string
	 */
	protected $_right_column = 'right';

	/**
	 * @var string
	 */
	protected $_level_column = 'level';

	/**
	 * @var string
	 */
	protected $_parent_column = 'parent';

	/**
	 * @var array
	 */
	protected $_scope_columns = array();

	/**
	 * @var void
	 */
	protected $_sorting;

	/**
	 * Class constructor (overload basic ORM::__construct() method)
	 *
	 * @param  mixed  parameter for find()
	 */
	public function __construct($id = NULL)
	{
		//We need to determine ORM-rows, that we use
		foreach(array($this->_left_column,
			$this->_right_column,
			$this->_level_column,
			$this->_parent_column) as $row)
		{
			$this->_table_columns[$row] = array
			(
				'data_type'		=> 'int',
				'is_nullable'	=> FALSE
			);
		}

		//We need to determine scope-columns to
		foreach($this->_scope_columns as $row => $condition)
		{
			$this->_table_columns[$row] = $condition;
		}

		//How do we sort?
		if(!isset($this->_sorting))
		{
			$this->_sorting = array($this->_left_column => 'ASC');
		}

		parent::__construct($id);
	}

	/*
	 * Save current tree (overload basic ORM::save() method)
	 *
	 * @return Kohana_ORM
	 */
	public function save()
	{
		if(!$this->loaded())
		{
			if($this->{$this->_parent_column})
			{
				return $this->make_child($this->{$this->_parent_column});
			}
			else
			{
				return $this->make_root();
			}
		}
		else
		{
			return parent::save();
		}
	}

	/**
	 * Creates the root element
	 *
	 * @param  mixed  Scope values
	 * @return  Kohana_ORM
	 */
	public function make_root($scope = NULL)
	{
		// Save node as root
		if($this->loaded()) throw new Kohana_Exception('Cannot insert the same node twice');

		$this->{$this->_left_column} = $this->{$this->_level_column} = 1;
		$this->{$this->_right_column} = 2;
		$this->{$this->_parent_column} = NULL;

		return parent::save();
	}

	/**
	 * inserts node as direct child for $id node
	 *
	 * @param  mixed    ID or parent object
	 * @param  boolean  First flag
	 * @return  Kohana_ORM
	 */
	public function make_child($id, $first = FALSE)
	{
		$this->lock();

		if(!is_a($id, get_class($this)))
		{
			$id = self::factory($this->_object_name, $id);
		}

		if($first === TRUE)
		{
			$left = $id->{$this->_left_column} + 1;
		}
		else
		{
			$left = $id->{$this->_right_column};
		}

		$this->add_space($left, 2);

		$this->{$this->_parent_column} = $id->{$this->_primary_key};
		$this->{$this->_level_column} = $id->{$this->_level_column} + 1;
		$this->{$this->_left_column} = $left;
		$this->{$this->_right_column} = $left + 1;

		parent::save();

		$this->unlock();

		return $this;
	}

	/**
	 * Inserts node as next/prev sibling
	 *
	 * @param  mixed    Sibling ID
	 * @param  boolean  Next/prev flag
	 * @return Kohana_ORM
	 */
	public function insert_near($id, $before = FALSE)
	{
		// inserts node as next/prev sibling
		if($this->loaded()) throw new Kohana_Exception('Cannot insert the same node twice');
		if($this->size() > 2) throw new Kohana_Exception('Cannot use a node with children');

		if(!is_a($id, get_class($this)))
		{
			$id = self::factory($this->_object_name, $id);
		}

		if($before)
		{
			$left = $id->{$this->_left_column};
		}
		else
		{
			$left = $id->{$this->_right_column} + 1;
		}

		$this->lock();
		$this->add_space($left);

		$this->{$this->_left_column} = $left;
		$this->{$this->_right_column} = $left + 1;
		$this->{$this->_parent_column} = $id->{$this->_parent_column};
		$this->{$this->_level_column} = $id->{$this->_level_column};

		parent::save();
		$this->unlock();
		return $this;
	}

	/**
	 * Deletes applied node with descendants
	 *
	 * @param <type> $id
	 * @return <type>
	 */
	public function delete($id = NULL)
	{
		if(!is_null($id))
		{
			$target = self::factory($this->_object_name, $id);
		}
		else
		{
			$target = $this;
		}
		if(!$target->loaded()) return FALSE;

		$target->lock();

		DB::delete($target->_table_name)
			->where($target->_left_column, '>=', $target->{$this->_left_column})
			->where($target->_left_column, '<=', $target->{$this->_right_column})
			->execute($target->_db);

		$target->clear_space($target->{$this->_left_column}, $target->size());
		$target->unlock();
	}

	/**
	 * Moves current node with descendants to a node $id
	 *
	 * @param <type> $id
	 * @param <type> $first
	 */
	public function move_to($id, $first = FALSE)
	{
		if(!is_a($id, get_class($this)))
		{
			$id = self::factory($this->_object_name, $id);
		}

		if($this->is_in_descendants($id)) throw new Kohana_Exception('Cannot move nodes to themself');

		$ids = $this->get_subtree(TRUE)->primary_key_array();
		$left = ($first == TRUE ? $id->{$this->_left_column} + 1 : $id->{$this->_right_column});
		$oldlft = $this->{$this->_left_column};
		$level = $id->{$this->_level_column} + 1;
		$delta = $left - $this->{$this->_left_column};

		if($delta < 0) $delta = '('.$delta.')';

		$deltalevel = $level - $this->{$this->_level_column};

		if($deltalevel < 0) $deltalevel = '('.$deltalevel.')';

		$this->lock();

		$this->clear_space($oldlft, $this->size());
		$this->add_space($left, $this->size());

		DB::update($this->_table_name)
			->in($this->primary_key, $ids)
			->set(array
			(
				$this->_left_column => DB::expr('"'.$this->_left_column.'" + '.$delta),
				$this->_right_column => DB::expr('"'.$this->_right_column.'" + '.$delta),
				$this->_level_column => DB::expr('"'.$this->_level_column.'" + '.$deltalevel),
			))
			->execute($this->_db);

		$this->{$this->_parent_column} = $id->{$this->_primary_key};

		parent::save();

		$this->unlock();
	}

	/**
	 * Moves all descendants to $id node WITHOUT current node
	 *
	 * @param <type> $id
	 * @param <type> $first
	 * @return <type>
	 */
	public function move_children_to($id, $first = FALSE)
	{
		if(!$this->has_children()) return FALSE;

		if(!is_a($id, get_class($this)))
		{
			$id = self::factory($this->_object_name, $id);
		}

		$ids = $this->get_subtree(FALSE)->primary_key_array();
		$left = ($first == TRUE ? $id->{$this->_left_column} + 1 : $id->{$this->_right_column});
		$oldlft = $this->{$this->_left_column} + 1;
		$level = $id->{$this->_level_column} + 1;
		$delta = $left - $oldlft;

		if($delta < 0) $delta = '('.$delta.')';

		$deltalevel = $level - $this->{$this->_level_column} - 1;

		if($deltalevel < 0) $deltalevel = '('.$deltalevel.')';

		$this->lock();

		$this->clear_space($oldlft, $this->size() - 2);
		$this->add_space($left, $this->size() - 2);

		DB::update($this->_table_name)
			->in($this->primary_key, $ids)
			->set(array
			(
				$this->_left_column => DB::expr('"'.$this->_left_column.'" + '.$delta),
				$this->_right_column => DB::expr('"'.$this->_right_column.'" + '.$delta),
				$this->_level_column => DB::expr('"'.$this->_level_column.'" + '.$deltalevel),
			))->execute($this->_db);

		DB::update($this->_table_name)
			->set(array($this->_parent_column => $id->{$this->_primary_key}))
			->where($this->_level_column, "=", $id->{$this->_level_column} + 1)
			->in($this->primary_key, $ids)
			->execute($this->_db);

		$this->unlock();
		$this->reload();
	}

	/**
	 * Returns root of the tree
	 *
	 * @param array Scope columns
	 * @return Kohana_MPTT
	 */
	public function get_root(array $scope = NULL)
	{
		$root = self::factory($this->_object_name)
			->where($this->_left_column, '=', 1);

		if($scope !== NULL)
		{
			foreach($scope as $row => $value)
			{
				$root = $root->andwhere($row, '=', $value);
			}

			return $root->find();
		}

		return $root->find_all();
	}

	/**
	 * Returns aray of parents
	 *
	 * @uses $this->_get_somethink();
	 * @param  boolean  With self
	 * @param  array    Columns
	 * @return mixed
	 */
	public function get_parents($with_self = FALSE, array $columns = NULL)
	{
		return $this->_get_somethink(TRUE, $with_self, $columns);
	}

	/**
	 * Returns array of children
	 *
	 * @uses $this->_get_somethink();
	 * @param  boolean  With self
	 * @param  array    Columns
	 * @return mixed
	 */
	public function get_children($with_self = FALSE, array $columns = NULL)
	{
		return $this->_get_somethink(FALSE, $with_self, $columns);
	}

	/**
	 * Gets parents or children
	 */
	protected function _get_somethink($parents, $with_self, $columns)
	{
		if(!$this->loaded()) throw new Kohana_Exception('Load model first');

		$suffix = $with_self ? '=' : '';

		$left   = ($parents === TRUE) ? '<' : '>';
		$right  = ($parents === TRUE) ? '>' : '<';

		$parents = (sizeof($columns) > 0) ? DB::select($columns)->from($this->_table_name) : self::factory($this->_object_name);

		$parents
			->where($this->_left_column, $left.$suffix, $this->{$this->_left_column})
			->where($this->_right_column, $right.$suffix, $this->{$this->_right_column});

		// We have scopes?
		foreach($this->_scope_solumns as $column)
		{
			$query = $query->where($column, '=', $this->{$column});
		}

		return (sizeof($columns) > 0) ? $query->execute($this->_db) : $parents->find_all();
	}

	/**
	 * Returns parent object
	 *
	 * @return mixed
	 */
	public function get_parent()
	{
		// If it is root
		if($this->is_root()) return FALSE;

		return self::factory($this->_object_name, $this->{$this->_parent_column});
	}

	/*
	 * Returns full tree (with or without scope checking)
	 *
	 * @param array Scopes
	 */
	public function get_fulltree(array $scopes = NULL)
	{
		$result = self::factory($this->_object_name);

		foreach($scopes as $column => $value)
		{
			$result = $result->where($column, '=', $value);
		}

		return $result->find_all();
	}

	/**
	 * Returns only leaves of current node
	 *
	 * @param  array  Columns
	 * @return mixed
	 */
	public function get_leaves(array $columns = NULL)
	{
		$leaves = (sizeof($columns) > 0) ? DB::select($columns)->from($this->_table_name) : self::factory($this->_object_name);

		$leaves->where($this->_parent_column, '=', $this->{$this->_primary_key});

		return (sizeof($columns) > 0) ? $leaves->execute() : $leaves->find_all();
	}

	/**
	 * Is current node a direct parent of $id node
	 *
	 * @param  mixed $id
	 * @return boolean
	 */
	public function is_parent($id)
	{
		if(!is_a($id, get_class($this)))
		{
			$id = self::factory($this->_object_name, $id);
		}

		return $id->{$this->_parent_column} == $this->{$this->_primary_key};
	}

	/**
	 * Is current node a direct child of $id node
	 *
	 * @param  mixed $id
	 * @return boolean
	 */
	public function is_child($id)
	{
		if(!is_a($id, get_class($this)))
		{
			$id = self::factory($this->_object_name, $id);
		}

		return $this->{$this->_parent_column} == $id->{$this->_primary_key};
	}

	/**
	 * Is current node one of a $id node child
	 *
	 * @param  mixed $id
	 * @return boolean
	 */
	public function is_in_descendants($id)
	{
		// is current node one of a $id node child
		if(!is_a($id, get_class($this)))
		{
			$id = self::factory($this->_object_name, $id);
		}

		if($this->{$this->_left_column} <= $id->{$this->_left_column}) return FALSE;
		if($this->{$this->_right_column} >= $id->{$this->_right_column}) return FALSE;

		return TRUE;
	}

	/**
	 * Is current node one of a $id node parents
	 *
	 * @param  mixed $id
	 * @return boolean
	 */
	public function is_in_parents($id)
	{
		if(!is_a($id, get_class($this)))
		{
			$id = self::factory($this->_object_name, $id);
		}

		return $id->is_in_descendants($this);
	}

	/**
	 * Is current node neighbor of $id node (the same direct parent)
	 *
	 * @param  mixed $id
	 * @return boolean
	 */
	public function is_neighbor($id)
	{
		if(!is_a($id, get_class($this)))
		{
			$id = self::factory($this->_object_name, $id);
		}

		return $this->{$this->_parent_column} == $id->{$this->_parent_column};
	}

	/**
	 * Is current node a root node?
	 *
	 * @return boolean
	 */
	public function is_root()
	{
		return $this->{$this->_level_column} == 1;
	}

	/**
	 * Add space for adding/inserting nodes.
	 * It should be set before adding space!
	 *
	 * @param integer $start
	 * @param integer $size
	 */
	protected function add_space($start, $size = 2)
	{
		DB::update($this->_table_name)
			->set(array($this->_left_column => DB::expr('"'.$this->_left_column.'" + ' . $size)))
			->where($this->_left_column, " >= ", $start)
			->execute($this->_db);

		DB::update($this->_table_name)
			->set(array($this->_right_column => DB::expr('"'.$this->_right_column.'" + ' . $size)))
			->where($this->_right_column, " >= ", $start)
			->execute($this->_db);
	}

	/**
	 * Remove space after deleting/moving node
	 *
	 * @param integer $start
	 * @param integer $size
	 */
	protected function clear_space($start, $size = 2)
	{
		DB::update($this->_table_name)
			->set(array($this->_left_column => DB::expr('"'.$this->_left_column.'" - ' . $size)))
			->where($this->_left_column, '>=', $start)
			->execute($this->_db);

		DB::update($this->_table_name)
			->set(array($this->_right_column => DB::expr('"'.$this->_right_column.'" - ' . $size)))
			->where($this->_right_column, '>=', $start)
			->execute($this->_db);
	}


	/**
	 * Locks table
	 */
	protected function lock()
	{
		// lock table
		DB::query('lock', 'LOCK TABLE ' . Kohana::config('database')->default['table_prefix'].$this->_table_name . ' WRITE')->execute($this->_db);
	}

	/**
	 * Unlocks table
	 */
	protected function unlock()
	{
		// unlock tables
		DB::query('unlock', 'UNLOCK TABLES')->execute($this->_db);
	}
}