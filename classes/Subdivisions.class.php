<?php

class Subdivisions {
	
	public $sub_code = '';
	public $sub_name = '';
	
	public $error = false;
	public $errorMsg = '';
	
	public function validate($sub_code, $saveToClass){
		// Valid
		$valid = false;
		
		// Trim
		$sub_code = trim($sub_code);
		
		if(!empty($sub_code)){
			// Prep for Database
			$db = new Database();
			$dbSubCode = $db->escape($sub_code);
			$db->query("SELECT sub_name FROM subdivisions WHERE sub_code='$dbSubCode'");
			if(!$db->error){
				if($db->result->num_rows == 1){
					// Valid
					$valid = true;
					
					if($saveToClass){
						$this->sub_code = $sub_code;
						$this->sub_name = $db->singleResult('sub_name');
					}
				}elseif($db->result->num_rows > 1){
					// Undesirable number of results
					$this->error = true;
					$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
					
					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 56;
					$errorLog->errorMsg = 'Duplicate sub_codes';
					$errorLog->badData = "sub_code: $sub_code";
					$errorLog->filename = 'API / Subdivisions.class.php';
					$errorLog->write();
				}
			}else{
				// Database Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
			}
		}else{
			// Missing Subdivision Code
			$this->error = true;
			$this->errorMsg = 'Sorry, we seem to be missing the subdivision code (sub_code) you\'re requesting';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 55;
			$errorLog->errorMsg = 'Missing sub_code';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Subdivisions.class.php';
			$errorLog->write();
		}
		
		// Return
		return $valid;
	}
}