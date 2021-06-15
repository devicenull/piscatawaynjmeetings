<?php
class BaseDBObject implements ArrayAccess
{
	// Name of the primary key
	var $db_key = '';
	// Name of the database table
	var $db_table = '';
	// Full list of all DB fields (including primary key)
	var $fields = [];
	// Fields that don't exist in the database, but can be generated at runtime
	var $virtual_fields = [];

	// last error message encountered
	var $error = '';

	var $record = [];

	public function __construct($params=[])
	{
		if (isset($params['record']))
		{
			$this->record = $params['record'];
		}
		else if (isset($params[$this->db_key]))
		{
			global $db;
			$res = $db->Execute('select * from '.$this->db_table.' where '.$this->db_key.'=?', [$params[$this->db_key]]);
			if ($res->RecordCount() == 1)
			{
				$this->record = $res->fields;
			}
		}
	}

	public function isInitialized(): bool
	{
		return isset($this->record[$this->db_key]);
	}

	public function set(array $params): bool
	{
		global $db;
		$this->error = '';

		$update_fields = $update_values = [];
		foreach ($this->fields as $field)
		{
			if (!isset($params[$field]) || $params[$field] == $this->record[$field])
			{
				continue;
			}

			$update_fields[] = $field;
			$update_values[] = $params[$field];
		}

		if (count($update_fields) > 0)
		{
			$update_values[] = $this->record[$this->db_key];
			if (!$db->Execute('update '.$this->db_table.' set '.implode('=?, ', $update_fields).'=? where '.$this->db_key.'=?', $update_values))
			{
				$this->error = $db->errorMsg();
				return false;
			}
			return true;
		}

		// nothing changed?
		return true;
	}

	public function add(array $params): bool
	{
		global $db;
		$this->error = '';

		$add_cols = [];
		$add_vals = [];
		foreach ($this->fields as $field)
		{
			if (!isset($params[$field]))
			{
				continue;
			}

			$add_cols[] = $field;
			$add_vals[] = $db->qstr($params[$field]);
		}

		if (count($add_cols) > 0)
		{
			if (!$db->Execute('insert into '.$this->db_table.'('.implode(',', $add_cols).') values('.implode(', ', $add_vals).')'))
			{
				$this->error = $db->ErrorMsg();
				return false;
			}

			$this->record = $params;
			$this->record[$this->db_key] = $db->insert_Id();

			return true;
		}

		// no data provided?
		$this->error = 'no data provided';
		return false;
	}

	public function delete(): bool
	{
		global $db;
		$this->error = '';

		if (!$db->Execute('delete from '.$this->db_table.' WHERE '.$this->db_key.'=?', [$this->record[$this->db_key]]))
		{
			$this->error = $db->ErrorMsg();
			return false;
		}

		return true;
	}

	public function offsetExists($offset)
	{
		return in_array($offset, $this->fields) || in_array($offset, $this->virtual_fields);
	}

	public function offsetGet($offset)
	{
		if (in_array($offset, $this->virtual_fields))
		{
			return $this->get($offset);
		}

		return $this->record[$offset] ?? '';
	}

	public function offsetSet($offset, $value)
	{
		throw new Exception('not implemented');
	}

	public function offsetUnset ($offset)
	{
		throw new Exception('not implemented');
	}

	public function get($offset)
	{
		return 'unimplemented';
	}
}
