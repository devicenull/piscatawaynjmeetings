<?php
class BaseDBObject implements ArrayAccess
{
	// Name of the primary key
	const DB_KEY = '';
	// Name of the database table
	const DB_TABLE = '';
	// Full list of all DB fields (including primary key)
	var $fields = [];
	// Fields that don't exist in the database, but can be generated at runtime
	var $virtual_fields = [];

	// should we do INSERT IGNORE instead of INSERT
	var $insert_ignore = false;

	// last error message encountered
	var $error = '';

	var $record = [];

	public function __construct($params=[])
	{
		if (isset($params['record']))
		{
			$this->record = $params['record'];
		}
		else if (isset($params[static::DB_KEY]))
		{
			$this->construct_by_column(static::DB_KEY, $params[static::DB_KEY]);
		}
	}

	public function construct_by_column($column, $data): bool
	{
		global $db;
		if (!in_array($column, $this->fields))
		{
			return false;
		}

		$res = $db->Execute('select * from '.static::DB_TABLE.' where '.$column.'=?', [$data]);
		if ($res->RecordCount() == 1)
		{
			$this->record = $res->fields;
			return true;
		}

		return false;
	}

	public function isInitialized(): bool
	{
		if (!in_array(static::DB_KEY, $this->fields))
		{
			die('Invalid primary key specified');
		}
		return isset($this->record[static::DB_KEY]);
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

		if (!empty($update_fields))
		{
			$update_values[] = $this->record[static::DB_KEY];
			if (!$db->Execute('update '.static::DB_TABLE.' set '.implode('=?, ', $update_fields).'=? where '.static::DB_KEY.'=?', $update_values))
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

		if (!empty($add_cols))
		{
			$ignore = $this->insert_ignore ? ' ignore ' : '';
			if (!$db->Execute('insert '.$ignore.' into '.static::DB_TABLE.'('.implode(',', $add_cols).') values('.implode(', ', $add_vals).')'))
			{
				$this->error = $db->ErrorMsg();
				return false;
			}

			$this->record = $params;
			if ($db->insert_Id() > 0)
			{
				$this->record[static::DB_KEY] = $db->insert_Id();
			}

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

		if (!$db->Execute('delete from '.static::DB_TABLE.' WHERE '.static::DB_KEY.'=?', [$this->record[static::DB_KEY]]))
		{
			$this->error = $db->ErrorMsg();
			return false;
		}

		return true;
	}

	public function offsetExists(mixed $offset): bool
	{
		return in_array($offset, $this->fields) || in_array($offset, $this->virtual_fields);
	}

	public function offsetGet(mixed $offset): mixed
	{
		if (in_array($offset, $this->virtual_fields))
		{
			return $this->get($offset);
		}

		return $this->record[$offset] ?? '';
	}

	public function offsetSet(mixed $offset, mixed $value): void
	{
		throw new BadFunctionCallException('not implemented');
	}

	public function offsetUnset (mixed $offset): void
	{
		throw new BadFunctionCallException('not implemented');
	}

	public function get($offset)
	{
		return 'unimplemented';
	}
}
