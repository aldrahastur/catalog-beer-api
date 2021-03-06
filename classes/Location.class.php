<?php

class Location {
	
	public $id = '';
	public $brewerID = '';
	public $name = '';
	public $url = '';
	public $countryCode = '';
	public $countryShortName = 'United States of America';
	public $latitude = 0;
	public $longitude = 0;
	
	public $error = false;
	public $errorMsg = '';
	public $validState = array('brewer_id'=>'', 'name'=>'', 'url'=>'', 'country_code'=>'');
	public $validMsg = array('brewer_id'=>'', 'name'=>'', 'url'=>'', 'country_code'=>'');
	
	private $gAPIKey = '';
	
	// Add Functions
	public function add($brewerID, $name, $url, $countryCode){
		// Save to Class
		$this->brewerID = $brewerID;
		$this->name = $name;
		$this->url = $url;
		$this->countryCode = $countryCode;
		
		// Validate brewerID
		$brewer = new Brewer();
		if(!$brewer->validate($this->brewerID, false)){
			// Invalid Brewer
			$this->error = true;
			$this->validState['brewer_id'] = 'invalid';
			$this->validMsg['brewer_id'] = 'Sorry, we don\'t have any brewers with that brewer_id. Please check your request and try again.';
		}
		
		// Validate Name
		$this->validateName();
		
		// Validate URL
		$this->url = $brewer->validateURL($this->url, 'url');
		if(!$brewer->error){
			// Valid URL
			$this->validState['url'] = 'valid';
		}else{
			// Invalid URL
			$this->error = true;
			$this->validState['url'] = $brewer->validState['url'];
			$this->validMsg['url'] = $brewer->validMsg['url'];
		}
		
		// Validate Country Code
		$this->validateCC();
		
		// Generate LocationID
		$uuid = new uuid();
		$this->id = $uuid->generate('location');
		if($uuid->error){
			// locationID Generation Error
			$this->error = true;
			$this->errorMsg = $uuid->errorMsg;
		}
		
		if(!$this->error){
			// Prep for Database
			$db = new Database();
			$dbLocationID = $db->escape($this->id);
			$dbBrewerID = $db->escape($this->brewerID);
			$dbName = $db->escape($this->name);
			$dbURL = $db->escape($this->url);
			$dbCC = $db->escape($this->countryCode);
			
			// Add to Database
			$db->query("INSERT INTO location (id, brewerID, name, url, countryCode) VALUES ('$dbLocationID', '$dbBrewerID', '$dbName', '$dbURL', '$dbCC')");
			if(!$db->error){
				// Update Brewer lastModified Timestamp
				$brewer->updateModified($this->brewerID);
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
			}
		}
	}
	
	public function addLatLong($locationID, $addressString){
		
		// Request Parameters
		$address = urlencode($addressString);

		// Headers & Options
		$headerArray = array(
			"accept: application/json"
		);

		$optionsArray = array(
			CURLOPT_URL => 'https://maps.googleapis.com/maps/api/geocode/json?address=' . $address . '&key=' . $this->gAPIKey,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_HTTPHEADER => $headerArray
		);

		// Create cURL Request
		$curl = curl_init();
		curl_setopt_array($curl, $optionsArray);
		$response = curl_exec($curl);
		$err = curl_error($curl);
		curl_close($curl);

		if(!empty($err)){			
			// cURL Error -- Log It
			$errorLog = new LogError();
			$errorLog->errorNumber = 111;
			$errorLog->errorMsg = 'cURL Error';
			$errorLog->badData = $err;
			$errorLog->filename = 'Location.class.php';
			$errorLog->write();
		}else{
			// Get Latitude and Longitude
			$jsonResponse = json_decode($response);
			var_dump($jsonResponse);
			if($jsonResponse->status == 'OK'){
				if(count($jsonResponse->results) == 1){
					// Valid Request
					$this->latitude = $jsonResponse->results[0]->geometry->location->lat;
					$this->longitude = $jsonResponse->results[0]->geometry->location->lng;
					
					// Add to Database
					if($this->validate($locationID, false)){
						// Valid Location, Prep for Database
						$db = new Database();
						$dbLocationID = $db->escape($locationID);
						$dbLatitude = $db->escape($this->latitude);
						$dbLongitude = $db->escape($this->longitude);
						
						// Update Query
						$db->query("UPDATE location SET latitude='$dbLatitude', longitude='$dbLongitude' WHERE id='$dbLocationID'");
					}else{
						// Invalid Location ID
						$errorLog = new LogError();
						$errorLog->errorNumber = 114;
						$errorLog->errorMsg = 'Invalid locationID';
						$errorLog->badData = $locationID;
						$errorLog->filename = 'Location.class.php';
						$errorLog->write();
					}
				}else{
					// More than one result, ambiguous
					$errorLog = new LogError();
					$errorLog->errorNumber = 113;
					$errorLog->errorMsg = 'Multiple Google Maps API Results';
					$errorLog->badData = $jsonResponse;
					$errorLog->filename = 'Location.class.php';
					$errorLog->write();
				}
			}else{
				// Google Maps API Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 112;
				$errorLog->errorMsg = 'Google Maps Error';
				$errorLog->badData = 'Status: ' . $jsonResponse->status . ' / Error Message: ' . $jsonResponse->error_message;
				$errorLog->filename = 'Location.class.php';
				$errorLog->write();
			}
		}
	}
	
	// Validation Functions
	private function validateName(){
		// Must set $this->name
		$this->name = trim($this->name);
		
		if(!empty($this->name)){
			if(strlen($this->name) <= 255){
				// Valid
				$this->validState['name'] = 'valid';
			}else{
				// Name Too Long
				$this->error = true;
				$this->validState['name'] = 'invalid';
				$this->validMsg['name'] = 'We hate to say it but your location name is too long for our database. Location names are limited to 255 bytes. Any chance you can shorten it?';
				
				// Log Error
				$errorLog = new LogError();
				$errorLog->errorNumber = 49;
				$errorLog->errorMsg = 'Location name too long (>255 Characters)';
				$errorLog->badData = $this->name;
				$errorLog->filename = 'API / Location.class.php';
				$errorLog->write();
			}
		}else{
			// Missing Name
			$this->error = true;
			$this->validState['name'] = 'invalid';
			$this->validMsg['name'] = 'Please give us the name of the location you\'d like to add.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 50;
			$errorLog->errorMsg = 'Missing location name';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Location.class.php';
			$errorLog->write();
		}
	}
	
	private function validateCC(){
		// ISO 3166-1 Alpha-2 Code
		// https://www.iso.org/iso-3166-country-codes.html
		// Valid?
		$valid = false;
		
		// Trim
		$this->countryCode = trim($this->countryCode);
		
		// Validate
		if($this->countryCode == 'US'){
			// Valid
			$this->validState['country_code'] = 'valid';
		}else{
			// Invalid
			$this->error = true;
			$this->validState['country_code'] = 'invalid';
			$this->validMsg['country_code'] = 'Sorry, at this time we are only collecting brewery locations for the United States of America.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 51;
			$errorLog->errorMsg = 'Invalid country code';
			$errorLog->badData = $this->countryCode;
			$errorLog->filename = 'API / Location.class.php';
			$errorLog->write();
		}
		
		// Return
		return $valid;
	}
	
	public function validate($locationID, $saveToClass){
		// Valid?
		$valid = false;
		
		// Trim
		$locationID = trim($locationID);
		
		if(!empty($locationID)){
			// Prep for Database
			$db = new Database();
			$dbLocationID = $db->escape($locationID);
			
			// Query
			$db->query("SELECT brewerID, name, url, countryCode, latitude, longitude FROM location WHERE id='$dbLocationID'");
			if(!$db->error){
				if($db->result->num_rows == 1){
					// Valid Location
					$valid = true;
					
					// Save to Class?
					if($saveToClass){
						$array = $db->resultArray();
						$this->id = $locationID;
						$this->brewerID = $array['brewerID'];
						$this->name = stripcslashes($array['name']);
						$this->url = $array['url'];
						$this->countryCode = $array['countryCode'];
						$this->latitude = floatval($array['latitude']);
						$this->longitude = floatval($array['longitude']);
					}
				}elseif($db->result->num_rows > 1){
					// Too Many Rows
					$this->error = true;
					$this->errorMsg = 'Whoops, looks like a bug on our end. We\'ve logged the issue and our support team will look into it.';
					
					// Log Error
					$errorLog = new LogError();
					$errorLog->errorNumber = 54;
					$errorLog->errorMsg = 'More than one location with the same ID';
					$errorLog->badData = "locationID: $locationID";
					$errorLog->filename = 'API / Location.class.php';
					$errorLog->write();
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
			}
		}else{
			// Missing LocationID
			$this->error = true;
			$this->errorMsg = 'Whoops, we seem to be missing the location_id for the location. Please check your request and try again.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 53;
			$errorLog->errorMsg = 'Missing locationID';
			$errorLog->badData = '';
			$errorLog->filename = 'API / Location.class.php';
			$errorLog->write();
		}
		
		// Return
		return $valid;
	}
	
	// Locations by Brewer
	public function brewerLocations($brewerID){
		// Return Array
		$locationArray = array();
		
		// Validate BrewerID
		$brewer = new Brewer();
		if($brewer->validate($brewerID, false)){
			// Prep for Database
			$db = new Database();
			$dbBrewerID = $db->escape($brewerID);
			$db->query("SELECT id, name FROM location WHERE brewerID='$dbBrewerID' ORDER BY name");
			if(!$db->error){
				while($array = $db->resultArray()){
					$locationInfo = array('id'=>$array['id'], 'name'=>$array['name']);
					$locationArray[] = $locationInfo;
				}
			}else{
				// Query Error
				$this->error = true;
				$this->errorMsg = $db->errorMsg;
			}
		}else{
			// Invalid Brewer
			$this->error = true;
			$this->errorMsg = 'Sorry, we don\'t have any breweries in our database with the brewer_id you submitted.';
			
			// Log Error
			$errorLog = new LogError();
			$errorLog->errorNumber = 75;
			$errorLog->errorMsg = 'Invalid BrewerID';
			$errorLog->badData = $brewerID;
			$errorLog->filename = 'API / Location.class.php';
			$errorLog->write();
		}
		
		// Return
		return $locationArray;
	}
}