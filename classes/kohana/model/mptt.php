<?php defined('SYSPATH') OR die('No direct access allowed.');
/**
 * Modified Preorder Tree Traversal Class.
 *
 * @author     Alexey Popov
 * @author     Kiall Mac Innes
 * @author     Mathew Davies
 * @author     Mike Parkin
 * @license    BSD
 * @copyright  (c) 2008-2011
 * @package    Model_MPTT
 */
class Kohana_Model_MPTT extends ORM
{
	/**
	 * @access protected
	 * @var string Left column name
	 */
	protected $_left_column = 'lft';

	/**
	 * @access protected
	 * @var string Right column name
	 */
	protected $_right_column = 'rgt';

	/**
	 * @access protected
	 * @var string Level column name
	 */
	protected $_level_column = 'lvl';

	/**
	 * @access protected
	 * @var string Parent column name
	 */
	protected $_parent_column = 'parent';

	/**
	 * @access protected
	 * @var boolean Enable/Disable path calculation
	 */
	protected $_path_calculation = FALSE;

	/**
	 * @access protected
	 * @var string Full pre-calculated path
	 */
	protected $_path_column = 'path';

	/**
	 * @access protected
	 * @var string Single path element
	 */
	protected $_path_part_column = 'path_part';

	/**
	 * @access protected
	 * @var string Path separator
	 */
	protected $_path_separator = '/';

	/**
	 * @access protected
	 * @var array Scope array
	 */
	protected $_scopes = array();

	/**
	 * Adding new condition to the scope array
	 *
	 * @param string Row name
	 * @param mixed Row value
	 * @return Model_MPTT
	 */
	public function scope($row, $val)
	{
		$prohibited = array
		(
			$this->_left_column,
			$this->_right_column,
			$this->_level_column,
			$this->_parent_column,
			$this->_path_column
		);

		if (in_array($row, $prohibited)) return FALSE;

		$this->_scopes[$row] = $val;

		return $this;
	}

	/**
	 * New root creating
	 *
	 * @param array Additional fields array
	 * @return Model_MPTT|boolean
	 **/
	public function new_root(array $additional_fields = NULL)
	{
		// Make sure the specified scope doesn't already exist.
		$search = $this->_apply_scopes(Model_MPTT::factory($this->_object_name))
			->find_all();

		if ($search->count() > 0) return FALSE;

		// Create a new root node in the new scope.
		$this->{$this->_left_column} = 1;
		$this->{$this->_level_column} = 0;
		$this->{$this->_parent_column} = 0;

		// Don't forget about scope columns
		if (!empty($this->_scopes))
		{
			foreach ($this->_scopes as $key => $val)
			{
				$this->{$key} = $val;
			}
		}

		// Other fields may be required
		if ( ! empty($additional_fields))
		{
			// Don't give to sabotage the structure
			$prohibited = array
			(
				$this->_left_column,
				$this->_right_column,
				$this->_level_column,
				$this->_parent_column,
				$this->_path_column
			);

			if ( ! empty($this->_scopes)) $prohibited = array_keys($this->_scopes) + $prohibited;

			foreach ($additional_fields as $key => $val)
			{
				if ( ! in_array($key, $prohibited))
				{
					$this->{$key} = $val;
				}
			}
		}

		parent::save();

		$this->{$this->_right_column} = $this->{$this->_primary_key} + 1;

		parent::save();

		return $this;
	}

	/**
	 * Locks table
	 *
	 * @access private
	 */
	protected function lock()
	{return $this;
		$lock = $this->_db->query(Database::SELECT, 'SELECT GET_LOCK("'.Kohana::$environment.'-'.$this->_table_name.'", 30) AS l', TRUE);

		if ($lock['l']->l == 0)
		{
			return $this->lock(); // Unable to obtain lock, retry.
		}
		elseif ($lock['l']->l == 1)
		{
			return $this; // Success
		}
		else
		{
			throw new Kohana_Exception('Unable to obtain MPTT lock'); // Unknown Error handle this.. better
		}
	}

	/**
	 * Unlock table.
	 *
	 * @access private
	 */
	protected function unlock()
	{
		//$this->_db->query(Database::SELECT, 'SELECT RELEASE_LOCK("'.Kohana::$environment.'-'.$this->_table_name.'") AS l', TRUE);

		return $this;
	}

	/**
	 * Does the current node have children?
	 *
	 * @access public
	 * @return bool
	 */
	public function has_children()
	{
		return (($this->{$this->_right_column} - $this->{$this->_left_column}) > 1);
	}

	/**
	 * Is the current node a leaf node?
	 *
	 * @access public
	 * @return bool
	 */
	public function is_leaf()
	{
		return ! $this->has_children();
	}

	/**
	 * Is the current node a descendant of the supplied node.
	 *
	 * @access public
	 * @param Model_MPTT $target Target
	 * @return bool
	 */
	public function is_descendant($target)
	{
		return ($this->{$this->_left_column} > $target->{$this->_left_column} AND $this->{$this->_right_column} < $target->{$this->_right_column} /* AND $this->{$this->scope_column} = $target->{$this->scope_column}*/);
	}

	/**
	 * Is the current node a direct child of the supplied node?
	 *
	 * @access public
	 * @param Model_MPTT $target Target
	 * @return bool
	 */
	public function is_child($target)
	{
		return ($this->parent->{$this->_primary_key} === $target->{$this->_primary_key});
	}

	/**
	 * Is the current node the direct parent of the supplied node?
	 *
	 * @access public
	 * @param Model_MPTT $target Target
	 * @return bool
	 */
	public function is_parent($target)
	{
		return ($this->{$this->_primary_key} === $target->parent->{$this->_primary_key});
	}

	/**
	 * Is the current node a sibling of the supplied node
	 *
	 * @access public
	 * @param Model_MPTT $target Target
	 * @return bool
	 */
	public function is_sibling($target)
	{
		if ($this->{$this->_primary_key} === $target->{$this->_primary_key})
			return FALSE;

		return ($this->parent->{$this->_primary_key} === $target->parent->{$this->_primary_key});
	}

	/**
	 * Is the current node a root node?
	 *
	 * @access public
	 * @return bool
	 */
	public function is_root()
	{
		return ($this->{$this->_left_column} === 1);
	}

	/**
	 * Returns the root node.
	 *
	 * @access protected
	 * @return Model_MPTT
	 */
	public function root()
	{
		return $this->_apply_scopes(Model_MPTT::factory($this->_object_name))
			->where($this->_left_column, '=', 1)
			->find();
	}

	/**
	 * Returns the parent of the current node.
	 *
	 * @access public
	 * @return Model_MPTT
	 */
	public function parent()
	{
		return Model_MPTT::factory($this->_object_name, $this->{$this->_parent_column});
	}

	/**
	 * Returns the parents of the current node.
	 *
	 * @access public
	 * @param bool $root include the root node?
	 * @param string $direction direction to order the left column by.
	 * @return Model_MPTT
	 */
	public function parents($root = TRUE, $direction = 'ASC')
	{
		$parents =  $this->_apply_scopes(Model_MPTT::factory($this->_object_name)
			->where($this->_left_column, '<=', $this->{$this->_left_column})
			->where($this->_right_column, '>=', $this->{$this->_right_column})
			->where($this->_primary_key, '<>', $this->{$this->_primary_key}));

		if ( ! $root)
		{
			$parents = $parents->where($this->_left_column, '!=', 1);
		}

		return $parents->order_by($this->_left_column, $direction);
	}

	/**
	 * Returns the children of the current node.
	 *
	 * @access public
	 * @param bool $self include the current loaded node?
	 * @param string $direction direction to order the left column by.
	 * @return Model_MPTT
	 */
	public function children($self = FALSE, $direction = 'ASC')
	{
		if ($self)
		{
			return $this->descendants($self, $direction)->where($this->_level_column, '<=', $this->{$this->_level_column} + 1)->where($this->_level_column, '>=', $this->{$this->_level_column});
		}

		return $this->descendants($self, $direction)->where($this->_level_column, '=', $this->{$this->_level_column} + 1);
	}

	/**
	 * Returns the descendants of the current node.
	 *
	 * @access public
	 * @param bool $self include the current loaded node?
	 * @param string $direction direction to order the left column by.
	 * @return Model_MPTT
	 */
	public function descendants($self = FALSE, $direction = 'ASC')
	{
		$left_operator = $self ? '>=' : '>';
		$right_operator = $self ? '<=' : '<';

		return Model_MPTT::factory($this->_object_name)
			->where($this->_left_column, $left_operator, $this->{$this->_left_column})
			->where($this->_right_column, $right_operator, $this->{$this->_right_column})
			// ->where($this->scope_column, '=', $this->{$this->scope_column})
			->order_by($this->_left_column, $direction);
	}

	/**
	 * Returns the siblings of the current node
	 *
	 * @access public
	 * @param bool $self include the current loaded node?
	 * @param string $direction direction to order the left column by.
	 * @return Model_MPTT
	 */
	public function siblings($self = FALSE, $direction = 'ASC')
	{
		$siblings = Model_MPTT::factory($this->_object_name)
			->where($this->_left_column, '>', $this->parent->find()->{$this->_left_column})
			->where($this->_right_column, '<', $this->parent->find()->{$this->_right_column})
			// ->where($this->scope_column, '=', $this->{$this->scope_column})
			->where($this->_level_column, '=', $this->{$this->_level_column})
			->order_by($this->_left_column, $direction);

		if ( ! $self)
		{
			$siblings->where($this->_primary_key, '<>', $this->{$this->_primary_key});
		}

		return $siblings;
	}

	/**
	 * Returns leaves under the current node.
	 *
	 * @access public
	 * @return Model_MPTT
	 */
	public function leaves()
	{
		return Model_MPTT::factory($this->_object_name)
			->where($this->_left_column, '=', new Database_Expression('(`'.$this->_right_column.'` - 1)'))
			->where($this->_left_column, '>=', $this->{$this->_left_column})
			->where($this->_right_column, '<=', $this->{$this->_right_column})
			// ->where($this->scope_column, '=', $this->{$this->scope_column})
			->order_by($this->_left_column, 'ASC');
	}

	/**
	 * Get Size
	 *
	 * @access protected
	 * @return integer
	 */
	protected function get_size()
	{
		return ($this->{$this->_right_column} - $this->{$this->_left_column}) + 1;
	}

	/**
	 * Create a gap in the tree to make room for a new node
	 *
	 * @access private
	 * @param integer $start start position.
	 * @param integer $size the size of the gap (default is 2).
	 */
	private function _create_space($start, $size = 2)
	{
		DB::update($this->_table_name)
			->set(array($this->_right_column =>  DB::expr('"'.$this->_right_column.'" + '.$size)))
			->where($this->_right_column, '>=', $start)
			->execute($this->_db);

		DB::update($this->_table_name)
			->set(array($this->_left_column => DB::expr('"'.$this->_left_column.'" + '.$size)))
			->where($this->_left_column, '>=', $start)
			->execute($this->_db);
	}

	/**
	 * Closes a gap in a tree. Mainly used after a node has
	 * been removed.
	 *
	 * @access private
	 * @param integer $start start position.
	 * @param integer $size the size of the gap (default is 2).
	 */
	private function _delete_space($start, $size = 2)
	{
		DB::update($this->_table_name)
			->set(array($this->_left_column => DB::expr('"'.$this->_left_column.'" - '.$size)))
			->where($this->_left_column, '>', $start)
			->execute($this->_db);

		DB::update($this->_table_name)
			->set(array($this->_right_column => DB::expr('"'.$this->_right_column.'" - '.$size)))
			->where($this->_right_column, '>', $start)
			->execute($this->_db);
	}

	/**
	 * Insert a node
	 */
	protected function insert($target, $copy_left_from, $left_offset, $right_offset, $level_offset)
	{
		// Insert should only work on new nodes.. if its already it the tree it needs to be moved!
		if ($this->loaded()) throw new Kohana_Exception('Cannot insert the same node twice');

		// TO-DO
		// if ($this->size() > 2) throw new Kohana_Exception('Cannot insert a node with children');

		$this->lock();

		if ( ! $target instanceof $this)
		{
			$target = Model_MPTT::factory($this->_object_name, $target);
		}
		else
		{
			// Ensure we're using the latest version of $target
			$target->reload();
		}

		$this->{$this->_left_column}   = $target->{$copy_left_from} + $left_offset;
		$this->{$this->_right_column}  = $target->{$copy_left_from} + $right_offset;
		$this->{$this->_level_column}  = $target->{$this->_level_column} + $level_offset;
		$this->{$this->_parent_column} = $target->{$this->_primary_key};

		if ( ! empty($this->_scopes))
		{
			foreach ($this->_scopes as $key => $val)
			{
				$this->{$key} = $val;
			}
		}

		parent::save();

		$this->_create_space($this->{$this->_left_column});

		if ($this->_path_calculation)
		{
			$this->_update_path();
			parent::save();
		}

		$this->unlock();

		return $this;
	}

	/**
	 * Inserts a new node to the left of the target node.
	 *
	 * @access public
	 * @param Model_MPTT $target target node id or Model_MPTT object.
	 * @return Model_MPTT
	 */
	public function insert_as_first_child($target)
	{
		return $this->insert($target, $this->_left_column, 1, 1);
	}

	/**
	 * Inserts a new node to the right of the target node.
	 *
	 * @access public
	 * @param Model_MPTT Target node id or Model_MPTT object.
	 * @return Model_MPTT
	 */
	public function insert_as_last_child($target)
	{
		return $this->insert($target, $this->_right_column, 0, 1, 1);
	}

	/**
	 * Inserts a new node as a previous sibling of the target node.
	 *
	 * @access public
	 * @param Model_MPTT|integer $target target node id or Model_MPTT object.
	 * @return Model_MPTT
	 */
	public function insert_as_prev_sibling($target)
	{
		return $this->insert($target, $this->_left_column, 0, 0);
	}

	/**
	 * Inserts a new node as the next sibling of the target node.
	 *
	 * @access public
	 * @param Model_MPTT|integer $target target node id or Model_MPTT object.
	 * @return Model_MPTT
	 */
	public function insert_as_next_sibling($target)
	{
		return $this->insert($target, $this->_right_column, 1, 0);
	}

	/**
	 * Overloaded save method
	 *
	 * @access public
	 * @param  Validation $validation Validation object
	 * @return Model_MPTT|bool
	 */
	public function save(Validation $validation = NULL)
	{
		if ($this->loaded() === TRUE)
		{
			return parent::save($validation);
		}

		return FALSE;
	}

	/**
	 * Removes a node and it's descendants.
	 *
	 * $usless_param prevents a strict error that breaks PHPUnit like hell!
	 * @access public
	 * @param bool $descendants remove the descendants?
	 */
	public function delete($usless_param = NULL)
	{
		$this->lock()->reload();

		$result = DB::delete($this->_table_name)
			->where($this->_left_column, '>=', $this->{$this->_left_column})
			->where($this->_right_column, '<=', $this->{$this->_right_column})
			// ->where($this->scope_column, '=', $this->{$this->scope_column})
			->execute($this->_db);
		if ($result > 0)
		{
			$this->_delete_space($this->{$this->_left_column}, $this->get_size());
		}

		$this->unlock();
	}

	/**
	 * Overloads the select_list method to
	 * support indenting.
	 *
	 * @param string $key first table column.
	 * @param string $val second table column.
	 * @param string $indent character used for indenting.
	 * @return array
	 */
	public function select_list($key = NULL, $val = NULL, $indent = NULL)
	{
		if (is_string($indent))
		{
			if ($key === NULL)
			{
				// Use the default key
				$key = $this->_primary_key;
			}

			if ($val === NULL)
			{
				// Use the default value
				$val = $this->_primary_val;
			}

			$result = $this->load_result(TRUE);

			$array = array();
			foreach ($result as $row)
			{
				$array[$row->$key] = str_repeat($indent, $row->{$this->_level_column}).$row->$val;
			}

			return $array;
		}

		return parent::select_list($key, $val);
	}



	/**
	 * Move to First Child
	 *
	 * Moves the current node to the first child of the target node.
	 *
	 * @param Model_MPTT|integer $target target node id or Model_MPTT object.
	 * @return Model_MPTT
	 */
	public function move_to_first_child($target)
	{
		return $this->move($target, TRUE, 1, 1, TRUE);
	}

	/**
	 * Move to Last Child
	 *
	 * Moves the current node to the last child of the target node.
	 *
	 * @param Model_MPTT|integer $target target node id or Model_MPTT object.
	 * @return Model_MPTT
	 */
	public function move_to_last_child($target)
	{
		return $this->move($target, FALSE, 0, 1, TRUE);
	}

	/**
	 * Move to Previous Sibling.
	 *
	 * Moves the current node to the previous sibling of the target node.
	 *
	 * @param Model_MPTT|integer $target target node id or Model_MPTT object.
	 * @return Model_MPTT
	 */
	public function move_to_prev_sibling($target)
	{
		return $this->move($target, TRUE, 0, 0, FALSE);
	}

	/**
	 * Move to Next Sibling.
	 *
	 * Moves the current node to the next sibling of the target node.
	 *
	 * @param Model_MPTT|integer $target target node id or Model_MPTT object.
	 * @return Model_MPTT
	 */
	public function move_to_next_sibling($target)
	{
		return $this->move($target, FALSE, 1, 0, FALSE);
	}

	/**
	 * Move
	 *
	 * @param Model_MPTT|integer $target target node id or Model_MPTT object.
	 * @param bool $_left_column use the left column or right column from target
	 * @param integer $left_offset left value for the new node position.
	 * @param integer $level_offset level
	 * @param bool allow this movement to be allowed on the root node
	 */
	protected function move($target, $_left_column, $left_offset, $level_offset, $allow_root_target)
	{
		if ( ! $this->loaded()) return FALSE;

		// Make sure we have the most upto date version of this AFTER we lock
		$this->lock()->reload();

		if ( ! $target instanceof $this)
		{
			$target = Model_MPTT::factory($this->_object_name, $target);

			if ( ! $target->loaded())
			{
				$this->unlock();
				return FALSE;
			}
		}
		else
		{
			$target->reload();
		}

		// Stop $this being moved into a descendant or disallow if target is root
		if ($target->is_descendant($this) OR ($allow_root_target === FALSE AND $target->is_root()))
		{
			$this->unlock();
			return FALSE;
		}

		$left_offset = (($_left_column === TRUE) ? $target->{$this->_left_column} : $target->{$this->_right_column}) + $left_offset;
		$level_offset = $target->{$this->_level_column} - $this->{$this->_level_column} + $level_offset;

		$size = $this->get_size();

		$this->_create_space($left_offset, $size);

		// if node is moved to a position in the tree "above" its current placement
		// then its lft/rgt may have been altered by _create_space
		$this->reload();

		$offset = ($left_offset - $this->{$this->_left_column});

		// Update the values.
		$this->_db->query(Database::UPDATE, 'UPDATE '.$this->_table_name.' SET `'.$this->_left_column.'` = `'.$this->_left_column.'` + '.$offset.', `'.$this->_right_column.'` = `'.$this->_right_column.'` + '.$offset.'
		, `'.$this->_level_column.'` = `'.$this->_level_column.'` + '.$level_offset.'
		, `'.$this->scope_column.'` = '.$target->{$this->scope_column}.'
		WHERE `'.$this->_left_column.'` >= '.$this->{$this->_left_column}.' AND `'.$this->_right_column.'` <= '.$this->{$this->_right_column}.' AND `'.$this->scope_column.'` = '.$this->{$this->scope_column}, FALSE);

		$this->_delete_space($this->{$this->_left_column}, $size);


		if ($this->_path_calculation)
		{
			$this->_update_path();
			parent::save();
		}

		$this->unlock();

		return $this;
	}

	/**
	 *
	 * @access public
	 * @param $column - Which field to get.
	 * @return mixed
	 */
	public function __get($column)
	{
		switch ($column)
		{
			case 'parent':
				return $this->parent();
			case 'parents':
				return $this->parents();
			case 'children':
				return $this->children();
			case 'siblings':
				return $this->siblings();
			case 'root':
				return $this->root();
			case 'leaves':
				return $this->leaves();
			case 'descendants':
				return $this->descendants();
			default:
				return parent::__get($column);
		}
	}

	/**
	 * Verify the tree is in good order
	 *
	 * This functions speed is irrelevant - its really only for debugging and unit tests
	 *
	 * @todo Look for any nodes no longer contained by the root node.
	 * @todo Ensure every node has a path to the root via ->parents();
	 * @access public
	 * @return boolean
	 */
	public function verify_tree()
	{
		foreach ($this->_get_scopes() as $scope)
		{
			if ( ! $this->verify_scope($scope->{$this->scope_column})) return FALSE;
		}

		return TRUE;
	}

	/**
	 *
	 * @return void
	 */
	private function _get_scopes()
	{
		return DB::select('DISTINCT("'.implode('", "', array_keys($this->_scopes)).'")')
			->from($this->_table_name)
			->execute($this->_db)
			->as_array();
	}

	/**
	 *
	 * @param type $scope
	 * @return type
	 */
	public function verify_scope($scope)
	{ echo Kohana::debug($scope); die();
		$root = $this->root($scope);

		$end = $root->{$this->_right_column};
		// TODO: Replace this to normal queries
		// Find nodes that have slipped out of bounds.
		//$result = $this->_db->query(Database::SELECT, 'SELECT COUNT(*) as count FROM `'.$this->_table_name.'` WHERE `'.$this->scope_column.'` = '.$root->{$this->scope_column}.' AND (`'.$this->_left_column.'` > '.$end.' OR `'.$this->_right_column.'` > '.$end.')', FALSE);

		DB::select('COUNT("'.$this->_primary_key.'")', 'count')
			->from($this->_table_name);

		if ($result[0]->count > 0) return FALSE;

		// Find nodes that have the same left and right value
		$result = $this->_db->query(Database::SELECT, 'SELECT COUNT(*) as count FROM `'.$this->_table_name.'` WHERE `'.$this->scope_column.'` = '.$root->{$this->scope_column}.' AND `'.$this->_left_column.'` = `'.$this->_right_column.'`', FALSE);
		if ($result[0]->count > 0) return FALSE;

		// Find nodes that right value is less than the left value
		$result = $this->_db->query(Database::SELECT, 'SELECT COUNT(*) as count FROM `'.$this->_table_name.'` WHERE `'.$this->scope_column.'` = '.$root->{$this->scope_column}.' AND `'.$this->_left_column.'` > `'.$this->_right_column.'`', FALSE);
		if ($result[0]->count > 0) return FALSE;

		// Make sure no 2 nodes share a left/right value
		$i = 1;
		while ($i <= $end)
		{
			$result = $this->_db->query(Database::SELECT, 'SELECT count(*) as count FROM `'.$this->_table_name.'` WHERE `'.$this->scope_column.'` = '.$root->{$this->scope_column}.' AND (`'.$this->_left_column.'` = '.$i.' OR `'.$this->_right_column.'` = '.$i.')', FALSE);

			if ($result[0]->count > 1)
				return FALSE;

			$i++;
		}

		// Check to ensure that all nodes have a "correct" level
		// TODO

		return TRUE;
	}

	/**
	 * @return Model_MPTT
	 */
	public function _update_path()
	{
		$path = array();

		$parents = $this->parents(FALSE)->find_all();

		foreach ($parents as $parent)
		{
			$path[] = trim($parent->{$this->_path_part_column});
		}

		$path[] = trim($this->{$this->_path_part_column});

		$path = implode($this->_path_separator, $path);

		$this->{$this->_path_column} = $path;

		return $this;
	}

	/**
	 * Apply scopes
	 *
	 * @param Model_MPTT $model
	 * @return type Model_MPTT
	 */
	private function _apply_scopes(Model_MPTT $model)
	{
		if ( ! empty($this->_scopes))
		{
			foreach ($this->_scopes as $key => $val)
			{
				$model = $model->where($key, '=', $val);
			}
		}

		return $model;
	}
} // End Kohana_Model_MPTT
