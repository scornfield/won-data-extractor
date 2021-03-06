<?php

class DataAccess {
	protected $conn = false;
	
	protected $errno = false;
	protected $errorMsg = false;
	protected $errorSql = false;
	
	// Constructor - 
	// Parameters: Host, Username, Password, Database
	// Returns: Nothing
	function DataAccess($in_dbhost, $in_dbuser, $in_dbpass, $in_dbname) {
		$this->errno = false;
		$this->errorMsg = false;
		$this->errorSql = false;

		$this->connect($in_dbhost, $in_dbuser, $in_dbpass, $in_dbname);
	}

	protected function connect($in_dbhost, $in_dbuser, $in_dbpass, $in_dbname) {
		try {
			$this->errno = false;
			$this->errorMsg = false;
			$this->errorSql = false;
			
			$attributes = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);
			$this->conn = new PDO("mysql:host=$in_dbhost;dbname=$in_dbname", $in_dbuser, $in_dbpass, $attributes);
			$this->conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
		} catch (PDOException $e) {
		    $this->errno = $e->getCode();
			$this->errorMsg = $e->getMessage();			
		}
	}
	
	function hasError() {
		return !($this->errno === false && $this->errorMsg === false);
	}
	
	function getError() {
		return array('ErrorSql' => $this->errorSql, 'ErrorCode' => $this->errno, 'ErrorMessage' => $this->errorMsg);
	}
	
	protected function PrepareAndExecuteQuery($query, $params) {
		$this->errno = false;
		$this->errorMsg = false;
		$this->errorSql = false;
		
		try {
			$stmt = $this->conn->prepare($query);
		} catch (PDOException $e) {
			$this->errno = $e->getCode();
			$this->errorMsg = $e->getMessage();
			$this->errorSql = "Error Preparing Statement: \r\n".$query."\r\n".print_r($params, true);
			return false;
		}
		
		try {
			$stmt->execute($params);
		} catch (PDOException $e) {
		    $this->errno = $e->getCode();
			$this->errorMsg = $e->getMessage();
			$this->errorSql = "Error Executing Statement: \r\n".$query."\r\n".print_r($params, true);

			return false;
		}
		
		return $stmt;
	}
	
	// Insert - Executes the passed insert statement
	// Parameters: Query, Array of Parameters
	// Returns: The new ID number on success, false otherwise
	function Insert($query, $params) {
		foreach($params as $key => $param) {
			if(!isset($params[$key])) {
				$params[$key] = null;
			}
				
		}

		//echo "Insert: $query\r\n";
		//var_dump($params);

		$stmt = $this->PrepareAndExecuteQuery($query, $params);
		
		if(!$stmt)
			return 0;

		$id = $this->conn->lastInsertID();

		//echo "ID: $id\r\n";
		
		return $id;
	}
	
	// Update - Executes the passed insert statement
	// Parameters: Query, Array of Parameters
	// Returns: The number of rows updated
	function Update($query, $params = array()) {
		foreach($params as $key => $param) {
			if(!isset($params[$key])) {
				$params[$key] = null;
			}
		}

		//echo "Update: $query\r\n";
		//var_dump($params);

		$stmt = $this->PrepareAndExecuteQuery($query, $params);
		
		if(!$stmt)
			return 0;

		$count = $stmt->rowCount();

		//echo "Count: $count\r\n";
		
		return $count;
	}
	
	// SelectValue - Executes the passed Select statement, returns 1 value
	// Parameters: Query, Array of Parameters
	// Returns: The value from the select statement on success, FALSE otherwise
	function SelectValue($query, $params) {
		$stmt = $this->PrepareAndExecuteQuery($query, $params);
		
		// If the statement failed to execute, return false
		if (!$stmt) return false;
		
		try {
			$retVal = $stmt->fetchColumn();
		} catch (PDOException $e) {
		    echo "Fetching selected column failed: ". $e->getMessage();
			return false;
		}
		
		return $retVal;
	}
	
	// Select - Executes the passed Select statement, returns array of all results
	// Parameters: Query, Array of Parameters
	// Returns: The array of results from the select statement on success, FALSE otherwise
	function Select($query, $params = array()) {
		$stmt = $this->PrepareAndExecuteQuery($query, $params);
		
		// If the statement failed to execute, return false
		if (!$stmt) return false;
		
		try {
			$retVal = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
		    echo "Fetching results failed: ". $e->getMessage();
			return false;
		}
		
		return $retVal;
	}
	
	function SelectOne($query, $params = array()) {
		return $this->Select($query, $params)[0];
	}

	// Execute - Executes the passed statement
	// Parameters: Query, Array of Parameters
	// Returns: True if successful, False otherwise
	function Execute($query, $params) {
		foreach($params as $key => $param) {
			if(!isset($params[$key])) {
				$params[$key] = null;
			}
		}

		$stmt = $this->PrepareAndExecuteQuery($query, $params);
		
		if(!$stmt || $this->errno !== false)
			return false;
		
		return true;
	}

	// Select - Executes the passed Select statement, returns array of all results
	// Parameters: Query, Array of Parameters
	// Returns: The statement object from the select query on success, FALSE otherwise
	function SelectFetch($query, $params = array()) {
		$stmt = $this->PrepareAndExecuteQuery($query, $params);
		
		// If the statement failed to execute, return false
		if (!$stmt) return false;
		
		return $stmt;
	}
}

?>