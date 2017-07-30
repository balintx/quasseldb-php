<?php

abstract class QuasselDB_Constants
{
	const Search_String = 1;
	const Search_Sender = 2;
	const Search_Date = 3;
	
	const BacklogEntry_Type_Plain = 0x00001;
	const BacklogEntry_Type_Notice = 0x00002;
	const BacklogEntry_Type_Action = 0x00004;
	const BacklogEntry_Type_Nick = 0x00008;
	const BacklogEntry_Type_Mode = 0x00010;
	const BacklogEntry_Type_Join = 0x00020;
	const BacklogEntry_Type_Part = 0x00040;
	const BacklogEntry_Type_Quit = 0x00080;
	const BacklogEntry_Type_Kick = 0x00100;
	const BacklogEntry_Type_Kill = 0x00200;
	const BacklogEntry_Type_Server = 0x00400;
	const BacklogEntry_Type_Info = 0x00800;
	const BacklogEntry_Type_Error = 0x01000;
	const BacklogEntry_Type_DayChange = 0x02000;
	const BacklogEntry_Type_Topic = 0x04000;
	const BacklogEntry_Type_NetsplitJoin = 0x08000;
	const BacklogEntry_Type_NetsplitQuit = 0x10000;
	const BacklogEntry_Type_Invite = 0x20000;
	
	const BacklogEntry_Type_All = [
		self::BacklogEntry_Type_Plain,
		self::BacklogEntry_Type_Notice,
		self::BacklogEntry_Type_Action,
		self::BacklogEntry_Type_Nick,
		self::BacklogEntry_Type_Mode,
		self::BacklogEntry_Type_Join,
		self::BacklogEntry_Type_Part,
		self::BacklogEntry_Type_Quit,
		self::BacklogEntry_Type_Kick,
		self::BacklogEntry_Type_Kill,
		self::BacklogEntry_Type_Server,
		self::BacklogEntry_Type_Info,
		self::BacklogEntry_Type_Error,
		self::BacklogEntry_Type_DayChange,
		self::BacklogEntry_Type_Topic,
		self::BacklogEntry_Type_NetsplitJoin,
		self::BacklogEntry_Type_NetsplitQuit,
		self::BacklogEntry_Type_Invite
	];
}

class QuasselDB
{
	private $db; // PDO pointer
	private $buffers; // This should never be public as internal search functions trust it
	var $user_id;
	
	function Connect($db_credentials, $db_type = 'pgsql')
	{
		/*
			This function's return value must be checked with strict comparison: (QuasselDB::Connect(...) === true) -> connetion succeeded
			Anything else: connection failed, return value is the error message (it may contain sensitive information!)

			PostgreSQL: db_credentials = ['host', 'username', 'password', 'database']
			SQLite: db_credentials = ['/path/to/quassel-storage.sqlite']
		*/
		
		// For SQLite, only the first column is used (the path to Quassel SQLite storage file)
		@list($db_host, $db_username, $db_password, $db_schema) = $db_credentials;
		try
		{
			$this->db = new PDO($this->Get_PDO_DSNString($db_type, $db_host, $db_schema), $db_username, $db_password);
		}
		catch (PDOException $e)
		{
			return $e->GetMessage();
		}
		$this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_NUM);
		return true;
	}
	
	function Authenticate($username, $password, $userid = NULL)
	{
		if (!$userid && !$userid = $this->Get_UserID($username)) return false;
		
		list($hash, $hash_version) = $this->Get_HashData($userid);
		
		switch ($hash_version)
		{
			case 0:
				$try_hash = sha1($password);
			break;
			case 1:
				if (!in_array('sha512', hash_algos()))
					throw new Exception("Hash algorithm 'sha512' is not available in your PHP setup, QuasselDB::Authenticate() failed.");
				
				list(, $salt) = explode(':', $hash);
				$try_hash = hash('sha512', $password.$salt).":$salt";
			break;
			default:
				throw new Exception("Unknown hash version for user $username: '$hashversion', QuasselDB::Authenticate() failed.");
			break;
		}
		
		return $this->Authenticate_WithHash($userid, $try_hash, $hash);
	}
	
	function Authenticate_WithHash($userid, $try_hash, $hash = NULL)
	{
		/*
			Separating this function so it can be used directly as well.
			If used internally, it is not beneficial to call QuasselDB::Get_HashData twice,
			so the correct password hash is passed as third argument by the caller function.
		*/
		
		if (NULL === $hash) // function has been called directly
			list($hash,) = $this->Get_HashData($userid);
		
		if ($hash === $try_hash)
		{
			$this->user_id = $userid;
			return true;
		}
		return false;
	}
		
	function Get_Buffers()
	{
		/*
			Returns authenticated user's buffers and saves in QuasselDB::buffers
			Usage:
			foreach (QuasselDB::Get_Buffers() as $networkid => $bufferdata)
				list($bufferid, $buffername) = $bufferdata;
		*/
		
		$this->buffers = [];
		$query = $this->db->prepare('SELECT networkid, bufferid, buffername FROM buffer WHERE userid = ? ORDER BY networkid');
		if (!$query->execute([$this->user_id]))
			return $this->buffers;
		
		foreach ($query->fetchAll() as $row)
		{
			$this->buffers[$row[0]][] = [$row[1], $row[2]];
		}
		$query->closeCursor();
		
		return $this->buffers;
	}
	
	function Is_VisibleBuffer($bufferid)
	{
		if (!$this->buffers)
			$this->Get_Buffers();

		foreach ($this->buffers as $network) // buffer = [bufferid, buffername]
		{
			foreach ($network as $buffer)
			{
				if ($buffer[0] == $bufferid)
					return true;
			}
		}

		return false;
	}
	
	function Get_MessagesNearID($messageid, $bufferid, $proximity = 10)
	{
		if (!$this->Is_VisibleBuffer($bufferid)) return [];
		if (!is_numeric($proximity) || $proximity < 0) $proximity = 10;
		
		$query = $this->db->prepare("SELECT * from backlog WHERE bufferid = ? AND messageid <= ? ORDER BY messageid DESC LIMIT $proximity");
		$query->execute([$bufferid, $messageid]);
		$messages = $query->fetchAll(PDO::FETCH_ASSOC);
		$query->closeCursor();
		$messages = array_reverse($messages);
		$query = $this->db->prepare("SELECT * from backlog WHERE bufferid = ? AND messageid > ? ORDER BY messageid ASC LIMIT $proximity");
		$query->execute([$bufferid, $messageid]);
		$messages = array_merge($messages, $query->fetchAll(PDO::FETCH_ASSOC));
		$query->closeCursor();
		
		return $messages;
	}
	
	function Search($search_array, $buffers, $types = QuasselDB_Constants::BacklogEntry_Type_All)
	{
		/*
			First parameter is an array with search options,
			where key is one of QuasselDB_Constants::Search_* and value is the search subject.
			For QuasselDB_Constants::Search_ByDate search subject is an array containing two UNIX timestamps.
		*/
		
		$buffers = $this->Filter_VisibleBuffers($buffers);
		$buffers = implode(',', $buffers);
		/*
			We assume $buffers to contain only numerics thus can be used unescaped
			as QuasselDB::buffers is private and its value is safe unless database is corrupted
			in which case there is no point in altering the db from outside		
		*/
		$types = $this->Filter_NumericValues($types);
		$types = implode(', ', $types);
		$condition = '';
		$parameters = [];
		$found = false;
		foreach ($search_array as $type => $value)
		{
			switch ($type)
			{
				case QuasselDB_Constants::Search_String:
					if (empty(trim($value))) continue;
					$condition .= ' AND message LIKE :paramByString';
					$parameters['paramByString'] = "%$value%";
					$found = true;
				break;
				case QuasselDB_Constants::Search_Sender:
					$sender = $this->Get_SenderID($value);
					$sender = implode(', ', $sender);
					$condition .= " AND senderid IN ($sender)";
					$found = true;
				break;
				case QuasselDB_Constants::Search_Date:
					if (!is_array($value) || count($value) != 2) continue;
					$condition .= ' AND time BETWEEN :paramByDateStart AND :paramByDateEnd';
					sort($value);
					$parameters['paramByDateStart'] = date('Y-m-d H:i:s.u', $value[0]);
					$parameters['paramByDateEnd'] = date('Y-m-d H:i:s.u', $value[1]);
					$found = true;
				break;
			}
		}
		if (!$found) return [];
		$query = $this->db->prepare("SELECT * FROM backlog WHERE bufferid IN ($buffers) AND type IN ($types)$condition");
		$query->execute($parameters);
		$results = $query->fetchAll(PDO::FETCH_ASSOC);
		$query->closeCursor();
		return $results;
	}
	
	function Get_SenderID($sendernick)
	{
		/*
			This function returns an array, because a nick can have multiple hosts
		*/
		$query = $this->db->prepare("SELECT senderid FROM sender WHERE sender LIKE ?");
		$query->execute(["$sendernick%"]);
		$results = $query->fetchAll(PDO::FETCH_COLUMN);
		$query->closeCursor();
		return $results;
	}
	
	function Get_Sender($senderid)
	{
		$query = $this->db->prepare("SELECT sender FROM sender WHERE senderid = ?");
		$query->execute([$senderid]);
		$sender = $query->fetchColumn();
		$query->closeCursor();
		return $sender;
	}
	
	function Filter_VisibleBuffers($buffers)
	{
		return array_filter($buffers, [$this, 'Is_VisibleBuffer']);
	}
	
	function Filter_NumericValues($array)
	{
		return array_filter($array, function($s) { return is_numeric($s); });
	}
	
	function Get_HashData($userid)
	{
		$query = $this->db->prepare('SELECT password, hashversion FROM quasseluser WHERE userid = ?');
		$query->execute([$userid]);
		$hashdata = $query->fetch();
		$query->closeCursor();
		return $hashdata;
	}
	
	function Get_UserID($username)
	{
		$query = $this->db->prepare('SELECT userid FROM quasseluser WHERE username = ?');
		$query->execute([$username]);
		$userid = $query->fetchColumn();
		$query->closeCursor();
		return $userid;
	}
	
	function Get_Username($userid)
	{
		$query = $this->db->prepare('SELECT username FROM quasseluser WHERE userid = ?');
		$query->execute([$userid]);
		$username = $query->fetchColumn();
		$query->closeCursor();
		return $username;
	}
	
	function Change_Password($new_password, $old_password = NULL)
	{
		/*
			If your application needs to check the old password before changing it (which is recommended),
			pass the old password as the third parameter. It will only change the password if the old is correct.
		*/
		if (!$this->user_id) return false;
		if (NULL !== $old_password)
		{
			/*
				QuasselDB::Authenticate() is used to verify the current (old) password, if it is incorrect
				we do not want to logout the user so the data is saved.
			*/
			$current_user_id = $this->user_id;
			if (!$this->Authenticate(NULL, $old_password, $current_user_id))
			{
				$this->user_id = $current_user_id; // re-authenticate the current user
				return false;
			}
		}
		
		$query = $this->db->prepare('UPDATE quasseluser SET password = ?, hashversion = ? WHERE userid = ?');
		
		if (in_array('sha512', hash_algos()))
		{
			$hashversion = 1;
			$salt = hash("sha512", openssl_random_pseudo_bytes(64));
			$hash = hash("sha512", $new_password.$salt).':'.$salt;
		}
		else
		{
			$hashversion = 0;
			$hash = sha1($new_password);
		}
		
        return $query->execute([$hash, $hashversion, $this->user_id]);
	}
	
	function CreateUser($username, $password)
	{
		/*
			It is recommended to limit the usage of this function to specific users in your application
			(list of admin usernames, for example)
		*/
        if (!$username || !preg_match("/^([a-zA-Z0-9]*)$/", $username)) return false;

        $exists = $this->db->prepare("SELECT userid FROM quasseluser WHERE username = ?");
        $exists->execute([$username]);
        if ($exists->rowCount() > 0) return false;
        unset($exists);

        $create = $this->db->prepare("INSERT INTO quasseluser (username, password, hashversion) VALUES (?, 'x', 0)");
        $create->execute([$username]);
        unset($create);
		
		$current_user_id = $this->user_id;
		$this->user_id = $this->Get_Username($username);
		$this->Change_Password($password);
		$this->user_id = $current_user_id;
		
        return true;
	}
	
	function DeactivateUser($username)
	{
		/*
			It is recommended to limit the usage of this function to specific users in your application
			(list of admin usernames, for example)
			
			This function does not delete any entries, just prevents login and auto-reconnecting to servers.
			It is recommended to perform a core restart just after using QuasselDB::DeactivateUser()
			
			The effect of this function can be reversed with QuasselDB::ActivateUser()
		*/
		
		$userid = $this->Get_UserID($username);
		if (!$userid) return false;
		
		$query = $this->db->prepare("UPDATE quasseluser SET username = CONCAT('DEACTIVATED_', username, '_', CAST(DATE('now') AS TEXT)), password = CONCAT('DEACTIVATED_', password) WHERE userid = ?");
		$query->execute([$userid]);
		$query->closeCursor();
		$query = $this->db->prepare("UPDATE network SET useautoreconnect = false, connected = false WHERE userid = ?");
		$query->execute([$userid]);
		return true;
	}
	
	function ActivateUser($username)
	{
		/*
			Make user able to login again after being deactivated with their old password.
			They will not auto reconnect to any servers.
		*/

		$query = $this->db->prepare("SELECT userid, username, password FROM quasseluser WHERE username LIKE ? ORDER BY username DESC LIMIT 1");
		$query->execute(["DEACTIVATED_${'username'}_%"]);
		if (!$data = $query->fetch()) return false;
		$query->closeCursor();
		list($userid, , $password) = $data;
		list(, $password) = explode('_', $password, 2);

		$query = $this->db->prepare("UPDATE quasseluser SET username = ?, password = ? WHERE userid = ?");
		$query->execute([$username, $password, $userid]);
		$query->closeCursor();

		return true;
	}

	
	private function Get_PDO_DSNString($db_type, $db_host, $db_schema)
	{
		// For SQLite "host=" is not present in the DSN
		return $db_type.':'.
			($db_type == 'sqlite' ? '' : 'host=').
			$db_host.
			($db_schema ? ';dbname='.$db_schema : '')
		;
	}
}
