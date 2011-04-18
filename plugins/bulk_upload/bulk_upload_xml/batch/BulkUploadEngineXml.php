<?php
/**
 * Class for the handling Bulk upload using XML in the system 
 * 
 * @package Scheduler
 * @subpackage Provision
 */
class BulkUploadEngineXml extends KBulkUploadEngine
{
	//(not the final version i still checking the code please don't kill me :) )
	const ADD_ACTION_STRING = "add";
	 
	/**
	 * 
	 * The engine xsd file path
	 * @var string
	 */
	private $xsdFilePath = "/../lib/ingestion.xsd";
	
	/**
	 * 
	 * The current flavor id
	 * @var int
	 */
	private $currentFlavorId;

	/**
	 * 
	 * The current handled content element
	 * @var SimpleXMLElement
	 */
	private $currentContentElement;
	
	/**
	 * 
	 * Maps the flavor params name to id
	 * @var array()
	 */
	private $flavorParamsNameToId = null;
	
	/**
	 * 
	 * Maps the converstion profile name to id
	 * @var array()
	 */
	private $conversionProfileNameToId = null;
	
	/**
	 * 
	 * Maps the storage profile name to id
	 * @var array()
	 */
	private $storageProfileNameToId = null;
	
	/**
	 * 
	 * Maps the thumb params name to id
	 * @var array()
	 */
	private $thumbParamsNameToId = null;
	
	/* (non-PHPdoc)
	 * @see KBulkUploadEngine::HandleBulkUpload()
	 */
	public function handleBulkUpload() 
	{
		$this->validate();
	    $this->parse();
	}
	
	/**
	 * 
	 * Validates that the xml is valid using the XSD
	 */
	protected function validate() 
	{
		$xdoc = new DomDocument;
		$xdoc->Load($this->data->filePath);
		//Validate the XML file against the schema
		if(!$xdoc->schemaValidate(dirname(__FILE__) . $this->xsdFilePath)) 
		{
			throw new KalturaException("Validate files failed on job [{$this->job->id}]", KalturaBatchJobAppErrors::BULK_VALIDATION_FAILED);
		}
		
		return true;
	}

	/**
	 * 
	 * Parses the Xml file lines and creates the right actions in the system
	 */
	protected function parse() 
	{
		$xdoc = simplexml_load_file($this->data->filePath);
		
		foreach( $xdoc->channel as $channel)
		{
			KalturaLog::debug("Handling channel");
			$this->handleChannel($channel);
		}
	}

	/**
	 * 
	 * Gets and handles a channel from the mrss
	 * @param SimpleXMLElement $channel
	 */
	private function handleChannel(SimpleXMLElement $channel)
	{
		//Gets all items from the channel
		foreach( $channel->item as $item)
		{
			KalturaLog::debug("Validating item [{$item->name}]");
			$this->validateItem($item);
			
			//TODO: add check if the bulk count has reached its max size and send the data
			KalturaLog::debug("Handling item [{$item->name}]");
			$this->handleItem($item);
		}	
	}
	
	/**
	 * 
	 * Validates the given item so it's valid (some validation can't be enforced in the schema)
	 * @param SimpleXMLElement $item
	 */
	private function validateItem(SimpleXMLElement $item)
	{
		//Validates that the item type has a matching type element
		$this->checkTypeToTypedElement($item);
	}		
	
	/**
	 * 
	 * Gets and handles an item from the channel
	 * @param SimpleXMLElement $item
	 */
	private function handleItem(SimpleXMLElement $item)
	{
		$actionToPerform = self::ADD_ACTION_STRING;
		$actionElement = (string)$item->action;
				
		if(!empty($actionElement))
		{
			$actionToPerform  = $actionElement;
		}
		
		switch(strtolower($actionToPerform))
		{
			case "add":
				$this->handleItemAdd($item);
				break;
			case "update":
				$this->handleItemUpdate($item);
				break;
			case "delete":
				$this->handleItemDelete($item);
				break;
			default :
				throw new KalturaException("Action: {$actionToPerform} is not supported", KalturaBatchJobAppErrors::BULK_ACTION_NOT_SUPPORTED);
		}
	}

	/**
	 * 
	 * Handles xml bulk upload update
	 * @param SimpleXMLElement $item
	 * @throws KalturaException
	 */
	private function handleItemUpdate(SimpleXMLElement $item)
	{
		throw new KalturaException("Action: Update is not supported", KalturaBatchJobAppErrors::BULK_ACTION_NOT_SUPPORTED);
	}

	/**
	 * 
	 * Handles xml bulk upload delete
	 * @param SimpleXMLElement $item
	 * @throws KalturaException
	 */
	private function handleItemDelete(SimpleXMLElement $item)
	{
		throw new KalturaException("Action: Delete is not supported", KalturaBatchJobAppErrors::BULK_ACTION_NOT_SUPPORTED);
	}
	
	/**
	 * 
	 * Gets an item and insert it into the system
	 * @param SimpleXMLElement $item
	 */
	private function handleItemAdd(SimpleXMLElement $item)
	{
		KalturaLog::debug("In handleItemAdd");
		$entryToInsert = $this->createMediaEntryFromItem($item);
		
		//For each content in the item element
		foreach ($item->content as $contentElement)
		{
			$this->currentContentElement = $contentElement;
			$resource = $this->getResource();
			
			$this->setContentElementValues(&$entryToInsert);
			
			KalturaLog::debug("Entry to add is: {$entryToInsert->name}");
			
			$this->startMultiRequest(true);
			$result = $this->kClient->media->add($entryToInsert, $resource);
			$this->doMultiRequestForPartner();
			
			KalturaLog::debug("result is: " .var_dump($result));
		}
	}

	/**
	 * 
	 * Gets an item and returns the resource
	 * @param SimpleXMLElement $item
	 */
	private function getResource()
	{
		$resource = $this->getResourceInstance(); 
				
		if(is_null($resource))
		{
			throw new KalturaBatchException("Resource is not supported: {$this->currentContentElement->textContent}", KalturaBatchJobAppErrors::BULK_FILE_NOT_FOUND); //The job was aborted
		}
	}
	
	/**
	 * 
	 * Returns the right resource instance for the source content of the item
	 */
	private function getResourceInstance()
	{
		KalturaLog::debug("In getResourceInstance");
		
		$resource = null;
			
		if($this->currentContentElement->localFileContentResource)
		{
			KalturaLog::debug("Resource is : localFileContentResource");
			$resource = new KalturaLocalFileResource();
			$localContentResource = $this->currentContentElement->localFileContentResource;
			$resource->localFilePath = kXml::getXmlAttributeAsString($localContentResource, "filePath");
			
			//TODO: Roni - what to do with those?
//		<xs:choice minOccurs="1" maxOccurs="1">
//			<xs:element name="fileSize" type="xs:int" minOccurs="1" maxOccurs="1"/>
//			<xs:element name="fileChecksum" type="xs:string" minOccurs="1" maxOccurs="1"/>
//		</xs:choice>
		}
		elseif($this->currentContentElement->urlContentResource)
		{
			KalturaLog::debug("Resource is : urlContentResource");
			$resource = new KalturaUrlResource();
			$urlContentResource = $this->currentContentElement->urlContentResource;
			$resource->url = kXml::getXmlAttributeAsString($urlContentResource, "url");
		}
		elseif($this->currentContentElement->remoteStorageContentResource)
		{
			KalturaLog::debug("Resource is : remoteStorageContentResource");
			$resource = new KalturaRemoteStorageResource();
			$remoteContentResource = $this->currentContentElement->remoteStorageContentResource;
			$resource->url = kXml::getXmlAttributeAsString($remoteContentResource, "url");
			$resource->storageProfileId = $this->getStorageProfileId($remoteContentResource);
		}
		elseif($this->currentContentElement->entryContentResource)
		{
			KalturaLog::debug("Resource is : entryContentResource");
			$resource = new KalturaEntryResource();
			$entryContentResource = $this->currentContentElement->entryContentResource;
			$resource->entryId = kXml::getXmlAttributeAsString($entryContentResource, "entryId");
			$resource->flavorParamsId = $this->getFlavorParamsId($entryContentResource, false);
		}
		elseif($this->currentContentElement->assetContentResource)
		{
			KalturaLog::debug("Resource is : assetContentResource");
			$resource = new KalturaAssetResource();
			$assetContentResource = $this->currentContentElement->assetContentResource;
			$resource->assetId = kXml::getXmlAttributeAsString($assetContentResource, "assetId");
		}
		
		return $resource;
	}
	
	/**
	 * 
	 * Gets the flavor params id from the source content element
	 * @param $elementToSearchIn - The element to search in
	 * @return int - The id of the flavor params
	 */
	private function getFlavorParamsId(SimpleXMLElement $elementToSearchIn, $isAttribute = true)
	{
		if($isAttribute) //Gets value from attributes
		{
			$flavorParamsId = kXml::getXmlAttributeAsString($elementToSearchIn, "flavorParamsId"); 
			$flavorParamsName = kXml::getXmlAttributeAsString($elementToSearchIn,"flavorParams");
		}
		else //Gets value from elements
		{
			$flavorParamsId = (string)$elementToSearchIn->flavorParamsId; 
			$flavorParamsName = (string)$elementToSearchIn->flavorParams;
		}
			
		return $this->getFlavorParamsByIdAndName($flavorParamsId, $flavorParamsName);
	}
	
	/**
	 * 
	 * Returns from the given object it's flavor params ids
	 * @param SimpleXMLElement $elementToSearchIn
	 */
	private function getFlavorParamsIds(SimpleXMLElement $elementToSearchIn)
	{
		KalturaLog::debug("In getFlavorParamsIds");
		
		$flavorParamsIds = "";
		
		//Can be null
		$flavorParamsIdsElement = $elementToSearchIn->flavorParamsIds;
		 
		if(is_null($flavorParamsIdsElement)) // id is null so we get by names
		{
			//get the names
			$flavorParamsElement = $elementToSearchIn->flavorParam;
			$flavorParamsNamesArray = explode(",", $this->getStringFromElement($flavorParamsElement));
			
			//Load the names to id array
			$this->initFlavorParamsNameToId();
			
			foreach ($flavorParamsNamesArray as $flavorParamName) 
			{
				if(!is_null($flavorParamName) || empty($flavorParamName))
				{
					$flavorParamsIds = $flavorParamsIds . trim($this->flavorParamsNameToId[$flavorParamName]) .',';
				}
				KalturaLog::debug("Flavor params name [$flavorParamName]");
			}
		}
		else
		{
			$flavorParamsIds = $this->getStringFromElement($flavorParamsIdsElement);
		}
		
		KalturaLog::debug("In getFlavorParamsIds - flavorParamsIds [$flavorParamsIds]");
		return $flavorParamsIds;
	}
		
	/**
	 * 
	 * Gets the flavor params id from the source content element
	 * @param $elementToSearchIn - The element to search in
	 * @return int - The id of the flavor params
	 */
	private function getAccessControlId(SimpleXMLElement $elementToSearchIn)
	{
		//TODO: fix this
		$accessControlId = (string)$elementToSearchIn->accessControlId;
		$accessControlName = $elementToSearchIn->accessControl;
							
		return $this->getAccessControlIdByIdAndName($accessControlId, $accessControlName);
	}
		
	/**
	 * 
	 * Gets the access control id by it's id or name   
	 * @param $accessControlId - the storage profile id
	 * @param string $accessControlName - the storage profile system name 
	 * @throws KalturaBatchException - in case ther is not such storage profile with the given name
	 */
	private function getAccessControlIdByIdAndName($accessControlId, $accessControlName)
	{
		if(isset($accessControlId) && !empty($accessControlId))
		{
			$this->currentFlavorId = trim($accessControlId);
			return;
		}
		
		if(!empty($accessControlName))//if we have no id then we search by name
		{
			if(is_null($this->flavorParamsNameToId))
			{
				$this->initFlavorParamsNameToId();
			}
			
			if(isset($this->flavorParamsNameToId[$accessControlName]))
			{
				$this->currentFlavorId = trim($this->flavorParamsNameToId[$accessControlName]);
				return;
			}
		}

		//If we got here then the id or name weren't found
		throw new KalturaBatchException("Can't find flavor params with id [$accessControlId], name [$accessControlName]", KalturaBatchJobAppErrors::BULK_OBJECT_NOT_FOUND);	
	}
		
	/**
	 * 
	 * 
	 * Gets the storage profile id from the source content element
	 * @param $elementToSearchIn - The element to search in
	 * @return int - The id of the storage profile
	 */
	private function getStorageProfileId(SimpleXMLElement $elementToSearchIn)
	{
		$storageProfileId = kXml::getXmlAttributeAsString($elementToSearchIn, "storageProfileId"); 
		$storageProfileName = kXml::getXmlAttributeAsString($elementToSearchIn, "storageProfile");
			
		//TODO: implement this (after validation of the flavor params)
		return $this->getStorageProfileByIdAndName($storageProfileId, $storageProfileName);
	}
	
	/**
	 * 
	 * Gets the storage profile id by it's id or name   
	 * @param $storageProfileId - the storage profile id
	 * @param string $storageProfileName - the storage profile system name 
	 * @throws KalturaBatchException - in case ther is not such storage profile with the given name
	 */
	private function getStorageProfileByIdAndName($storageProfileId, $storageProfileName)
	{
		if(isset($storageProfileId) && !empty($storageProfileId))
		{
			$this->currentFlavorId = trim($storageProfileId);
			return;
		}
		
		if(!empty($storageProfileName))//if we have no id then we search by name
		{
			if(is_null($this->flavorParamsNameToId))
			{
				$this->initFlavorParamsNameToId();
			}
			
			if(isset($this->flavorParamsNameToId[$storageProfileName]))
			{
				$this->currentFlavorId = trim($this->flavorParamsNameToId[$storageProfileName]);
				return;
			}
		}

		//If we got here then the id or name weren't found
		throw new KalturaBatchException("Can't find flavor params with id [$storageProfileId], name [$storageProfileName]", KalturaBatchJobAppErrors::BULK_OBJECT_NOT_FOUND);
	}
	
	/**
	 * 
	 * Gets the flavor params id by it's id or name   
	 * @param $flavorParamsId - the flavor params id
	 * @param $flavorParamsName - the flavor params name
	 * @throws KalturaBatchException - in case there is not flavor params by the given name
	 */
	private function getFlavorParamsByIdAndName($flavorParamsId, $flavorParamsName)
	{
		if(isset($flavorParamsId) && !empty($flavorParamsId))
		{
			$this->currentFlavorId = trim($flavorParamsId);
			return;
		}
		
		if(!empty($flavorParamsName)) //If we have no id then we search by name
		{
			if(is_null($this->flavorParamsNameToId))
			{
				$this->initFlavorParamsNameToId();
			}
			
			if(isset($this->flavorParamsNameToId[$flavorParamsName]))
			{
				$this->currentFlavorId = trim($this->flavorParamsNameToId[$flavorParamsName]);
				return;
			}
		}

		//If we got here then the id or name weren't found
		throw new KalturaBatchException("Can't find flavor params with id [], name [$flavorParamsName]", KalturaBatchJobAppErrors::BULK_OBJECT_NOT_FOUND);
	}
	
	/**
	 * 
	 * Inits the array of flavor params to Id (with all given flavor params)
	 */
	private function initFlavorParamsNameToId()
	{
		$allFlavorParams = $this->kClient->flavorParams->listAction(null, null);
		
		foreach ($allFlavorParams as $flavorParam)
		{
			$this->flavorParamsNameToId[$flavorParam->systemName] = $flavorParam->id;
		}
	}
		
	/**
	 * 
	 * Inits the array of flavor params to Id (with all given flavor params)
	 */
	private function initThumbParamsNameToId()
	{
		$allThumbParams = $this->kClient->thumbParams->listAction(null, null);
		
		foreach ($allThumbParams as $thumbParam)
		{
			$this->flavorParamsNameToId[$thumbParam->systemName] = $thumbParam->id;
		}
	}
		
	/**
	 * 
	 * Inits the array of conversion profile to Id (with all given flavor params)
	 */
	private function initConversionProfileNameToId()
	{
		$allConversionProfile = $this->kClient->conversioProfile->listAction(null, null);
		
		foreach ($allConversionProfile as $conversionProfile)
		{
			$this->flavorParamsNameToId[$conversionProfile->systemName] = $conversionProfile->id;
		}
	}

	/**
	 * 
	 * Inits the array of storage profile to Id (with all given flavor params)
	 */
	private function initStorageProfileNameToId()
	{
		$allStorageProfiles = $this->kClient->storageProfile->listAction(null, null);		
		foreach ($allStorageProfiles as $storageProfile)
		{
			$this->flavorParamsNameToId[$storageProfile->systemName] = $storageProfile->id;
		}
	}
		
	/**
  	 * Creates and returns a new media entry for the given job data and bulk upload result object
	 * @param SimpleXMLElement $bulkUploadResult
	 */
	private function createMediaEntryFromItem(SimpleXMLElement $item)
	{
		//Create the new media entry and set basic values
		$mediaEntry = new KalturaMediaEntry();

		$mediaEntry->name = (string)$item->name;
		$mediaEntry->description = (string)$item->description;
		$mediaEntry->tags = $this->getStringFromElement($item->tags);
		
		$categoriesElement = $item->categories;
		$mediaEntry->categories = $this->getStringFromElement($categoriesElement);
				
		$mediaEntry->userId = (string)$item->userId;;

		$mediaEntry->licenseType = (string)$item->licenseType;

		$mediaEntry->accessControlId =  $this->getAccessControlId($item);
				
		//TODO: Roni - Parse the date
		$mediaEntry->startDate = $item->startDate;

		$mediaEntry->type = $this->getEntryTypeByNumber($item->type); 

		//Handles the type element additional data
		$this->handleTypedElement(&$mediaEntry, $item);
			
		return $mediaEntry;
	}
	
	/**
	 * 
	 * Handles the type additional data for the given media entry
	 * @param KalturaMediaEntry $mediaEntry
	 * @param SimpleXMLElement $item
	 */
	private function handleTypedElement(KalturaMediaEntry $mediaEntry, SimpleXMLElement $item)
	{
		if($mediaEntry->type)
		{
			$mediaElement = $item->media;
			$this->setMediaElementValues(&$mediaEntry, $mediaElement);
		}
	} 
	/**
	 * 
	 * Check if the item type and the type element are matching
	 * @param SimpleXMLElement $item
	 * @throws KalturaBatchException - KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED ; 
	 */
	private function checkTypeToTypedElement(SimpleXMLElement $item) 
	{
		//Gets all the possible elements 
		$mediaElement = $item->media;
		$mixElement = $item->mix;
		$playlistElement = $item->playlist;
		$documentElement = $item->document;
		$liveStreamElement = $item->liveStream;
		$dataElement = $item->data;

		KalturaLog::info("Test - " . empty($mixElement) . empty($documentElement) . isset($liveStreamElement) .isset($playlistElement) . isset($dataElement));
		//Now we get the entry type and check if only the rigth element is not null
		$typeNumber = $item->type;
		
		switch(trim($typeNumber))
		{
			case KalturaEntryType::MEDIA_CLIP :
				if(empty($mediaElement))
				{
					KalturaLog::info("Media Element is missing for type [$typeNumber], using nulls / defaults");
				}
				if( !(empty($mixElement) &&
					  empty($documentElement) &&
					  empty($liveStreamElement) &&
					  empty($playlistElement) &&
					  empty($dataElement)
					 )
				  )
				{
					throw new KalturaBatchException("Conflicted typed element for type [$typeNumber] on item [$item->name] ", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
				}
				break;
			case KalturaEntryType::AUTOMATIC:
				//What to do here?
				
				break;
			case KalturaEntryType::DATA:
				if(is_null($dataElement))
				{
					KalturaLog::info("Data Element is missing for type [$typeNumber], using nulls / defaults");
				}
				if(! (is_null($mixElement) &&
					  is_null($documentElement) &&
					  is_null($liveStreamElement) &&
					  is_null($playlistElement) && 
					  is_null($mediaElement)
					 )
				  )
				{
					throw new KalturaBatchException("Conflicted typed element for type [$typeNumber] on item [$item->name] ", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
				}
				break;
			case KalturaEntryType::DOCUMENT:
				if(is_null($documentElement))
				{
					KalturaLog::info("Document Element is missing for type [$typeNumber], using nulls / defaults");
				}
				if(! (is_null($mixElement) &&
					  is_null($dataElement) &&
					  is_null($liveStreamElement) &&
					  is_null($playlistElement) && 
					  is_null($mediaElement)
					 )
				  )
				{
					throw new KalturaBatchException("Conflicted typed element for type [$typeNumber] on item [$item->name] ", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
				}
				break;
			case KalturaEntryType::LIVE_STREAM:
				if(is_null($liveStreamElement))
				{
					KalturaLog::info("Live Stream Element is missing for type [$typeNumber], using nulls / defaults");
				}
				if(! (is_null($mixElement) &&
					  is_null($documentElement) &&
					  is_null($dataElement) &&
					  is_null($playlistElement) && 
					  is_null($mediaElement)
					 )
				  )
				{
					throw new KalturaBatchException("Conflicted typed element for type [$typeNumber] on item [$item->name] ", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
				}
				break;
			case KalturaEntryType::MIX:
				if(is_null($mixElement))
				{
					KalturaLog::info("Mix Element is missing for type [$typeNumber], using nulls / defaults");
				}
				if(! (is_null($liveStreamElement) &&
					  is_null($documentElement) &&
					  is_null($dataElement) &&
					  is_null($playlistElement) && 
					  is_null($mediaElement)
					 )
				  )
				{
					throw new KalturaBatchException("Conflicted typed element for type [$typeNumber] on item [$item->name] ", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
				}
				break;
			case KalturaEntryType::PLAYLIST:
				if(is_null($playlistElement))
				{
					KalturaLog::info("Playlist Element is missing for type [$typeNumber], using nulls / defaults");
				}
				if(! (is_null($liveStreamElement) &&
					  is_null($documentElement) &&
					  is_null($dataElement) &&
					  is_null($mixElement) && 
					  is_null($mediaElement)
					 )
				  )
				{
					throw new KalturaBatchException("Conflicted typed element for type [$typeNumber] on item [$item->name] ", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
				}
				break;
			default:
				throw new KalturaBatchException("type [$typeNumber] is not supported ", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED); 
		}
	}
	
	/**
	 * 
	 * Sets values in the media entry by the given content element
	 * @param SimpleXMLElement $mediaEntry
	 */
	private function setContentElementValues(KalturaMediaEntry $mediaEntry)
	{
		//TODO: Roni - handle content element logic
		
	}
	
	/**
	 * 
	 * Sets the media values in the media entry according to the given media node
	 * @param KalturaMediaEntry $mediaEntry
	 * @param SimpleXMLElement $mediaElement
	 */
	private function setMediaElementValues(KalturaMediaEntry &$mediaEntry, SimpleXMLElement $mediaElement)
	{
		$mediaEntry->mediaType = $mediaElement->mediaType;
		 
		$this->checkMediaTypes($mediaEntry->type ,$mediaEntry->mediaType);
		$mediaEntry->ingestionProfileId = $this->data->conversionProfileId;
		
//		$mediaEntry->flavorParamsIds = $this->getFlavorParamsIds($mediaElement);
		
		//$mediaEntry->thumbParamsId = $mediaElement->getElementsByTagName("thumbParamsIds")->nodeValue;
		
//		$mediaEntry->playList = $this->addToPlaylists($mediaEntry, $mediaElement->getElementsByTagName("playlists"));
		
		//TODO: Roni - not found in the entry object what to do for each one
//		$mediaEntry->conversionProfileId = $this->getTypeByName($mediaElement->nodeValue);
		
		//TODO: Roni maybe add conversion quality to the xml
//		$mediaEntry->conversionQuality = $bulkUploadJobData->conversionProfileId;
	}
	
	/**
	 * 
	 * Checks if the media type and the type are valid
	 * @param KalturaEntryType $type
	 * @param KalturaMediaType $mediaType
	 */
	private function checkMediaTypes($type ,$mediaType)
	{
		//TODO: Roni - add support for all other types with TanTan
		switch ($type)
		{
			case KalturaEntryType::MEDIA_CLIP:
				if($mediaType != KalturaMediaType::VIDEO)
				{
					throw new KalturaBatchException("the entry type [$type] is not supported with media type [$mediaType]", KalturaBatchJobAppErrors::BULK_VALIDATION_FAILED);
				}
				break;
			case KalturaEntryType::DATA:
				//TODO: what to put here?
//				if($mediaType != KalturaMediaType::IMAGE)
//				{
//					throw new KalturaBatchException("the entry type [$type] is not supported with media type [$mediaType]", KalturaBatchJobAppErrors::BULK_VALIDATION_FAILED);
//				}
				break;
			case KalturaEntryType::DOCUMENT :
//				if($mediaType != KalturaMediaType::)
//				{
//					throw new KalturaBatchException("the entry type [$type] is not supported with media type [$mediaType]", KalturaBatchJobAppErrors::BULK_VALIDATION_FAILED);
//				}
				break;
			case KalturaEntryType::LIVE_STREAM : //if it is not one of the above
				if( !($mediaType == KalturaMediaType::LIVE_STREAM_FLASH) ||
					 ($mediaType == KalturaMediaType::LIVE_STREAM_QUICKTIME) ||
					 ($mediaType == KalturaMediaType::LIVE_STREAM_REAL_MEDIA) ||
					 ($mediaType == KalturaMediaType::LIVE_STREAM_WINDOWS_MEDIA)
				  )
				{
					throw new KalturaBatchException("the entry type [$type] is not supported with media type [$mediaType]", KalturaBatchJobAppErrors::BULK_VALIDATION_FAILED);
				}
				break;
			case KalturaEntryType::MIX :
				if($mediaType != KalturaMediaType::VIDEO)
				{
					throw new KalturaBatchException("the entry type [$type] is not supported with media type [$mediaType]", KalturaBatchJobAppErrors::BULK_VALIDATION_FAILED);
				}
				break;
			case KalturaEntryType::PLAYLIST :
				if($mediaType != KalturaMediaType::VIDEO)
				{
					throw new KalturaBatchException("the entry type [$type] is not supported with media type [$mediaType]", KalturaBatchJobAppErrors::BULK_VALIDATION_FAILED);
				}
				break;
			default:
				throw new KalturaBatchException("The type [$type] is not supported", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED);
		}
	}
	
	/**
	 * 
	 * Adds the given media entry to the given playlists in the element
	 * @param KalturaMediaEntry $mediaEntry
	 * @param SimpleXMLElement $playlistsElement
	 */
	private function addToPlaylists(KalturaMediaEntry $mediaEntry, SimpleXMLElement $playlistsElement)
	{
		foreach ($playlistsElement->childNodes as $playlist)
		{
		
			//TODO: Roni - add the media to the play list 
			//AddToPlaylist();
		}
	}
	
	/**
	 * 
	 * Retutrns the right media type by its name
	 * @param string $typeName
	 */
	private function getMediaTypeByName($mediaTypeName)
	{
		$mediaType = null;
		
		//TODO: Roni - Fix this to use numbers instead of string 
		//Set the content type
		switch(strtolower($mediaTypeName))
		{
			case 'image':
				$mediaType = KalturaMediaType::IMAGE;
				break;
			
			case 'audio':
				$mediaType = KalturaMediaType::AUDIO;
				break;
			
			default:
				$mediaType = KalturaMediaType::VIDEO;
				break;
		}	
		
		return $mediaType;
	}
	
	/**
	 * 
	 * Returns the right entry type by its name
	 * @param string $typeName
	 */
	private function getEntryTypeByNumber($typeNumber)
	{
		$entryType = null;
		
		//TODO: Roni - Fix this to use numbers instead of string 
		//Set the content type
		switch(trim($typeNumber))
		{
			case KalturaEntryType::MEDIA_CLIP :
				$entryType = KalturaEntryType::MEDIA_CLIP;
				break;
			case KalturaEntryType::AUTOMATIC:
				$entryType = KalturaEntryType::AUTOMATIC;
				break;
			case KalturaEntryType::DATA:
				$entryType = KalturaEntryType::DATA;
				break;
			case KalturaEntryType::DOCUMENT:
				$entryType = KalturaEntryType::DOCUMENT;
				break;
			case KalturaEntryType::LIVE_STREAM:
				$entryType = KalturaEntryType::LIVE_STREAM;
				break;
			case KalturaEntryType::MIX:
				$entryType = KalturaEntryType::MIX;
				break;
			case KalturaEntryType::PLAYLIST:
				$entryType = KalturaEntryType::PLAYLIST;
				break;
			default:
				throw new KalturaBatchException("type [$typeNumber] is not supported ", KalturaBatchJobAppErrors::BULK_ITEM_VALIDATION_FAILED); 
		}	
		
		return $entryType;
	}
	
	/**
	 * 
	 * Returns a comma seperated string with the values of the child nodes of the given element 
	 * @param SimpleXMLElement $element
	 */
	private function getStringFromElement(SimpleXMLElement $element)
	{
		$commaSeperatedString = ""; 
		
		//TODO: Roni - check if the ',' in the end is bad 
		foreach ($element->children() as $child)
		{
			if($child != null)
			{
				$childNodeValue = trim($child);
				if(!empty($childNodeValue))
				{
					KalturaLog::debug("In getStringFromElement - child value [". $childNodeValue . "]");
					$commaSeperatedString = $commaSeperatedString . $childNodeValue .',';
				}
			}
		}
		
		KalturaLog::debug("In getStringFromElement - The created string [$commaSeperatedString]");
		return $commaSeperatedString;
	}
}