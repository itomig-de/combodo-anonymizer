<?php
class AnonymizationPlugIn implements iPopupMenuExtension, iPageUIExtension
{
	/**
	 * Get the list of items to be added to a menu.
	 *
	 * This method is called by the framework for each menu.
	 * The items will be inserted in the menu in the order of the returned array.
	 * @param int $iMenuId The identifier of the type of menu, as listed by the constants MENU_xxx
	 * @param mixed $param Depends on $iMenuId, see the constants defined above
	 * @return object[] An array of ApplicationPopupMenuItem or an empty array if no action is to be added to the menu
	 */
	public static function EnumItems($iMenuId, $param)
	{
		$aExtraMenus = array();
		$sJSUrl = utils::GetAbsoluteUrlModulesRoot().basename(__DIR__).'/js/anonymize.js';
		switch($iMenuId)
		{
			case iPopupMenuExtension::MENU_OBJLIST_ACTIONS:
			/**
			 * @var DBObjectSet $param
			 */
			if ($param->GetClass() == 'Person')
			{
				$aExtraMenus[] = new JSPopupMenuItem('Anonymize', Dict::S('Anonymization:AnonymizeAll'), 'AnonymizeAListOfPersons('.json_encode($param->GetFilter()->serialize()).', '.$param->Count().');', array($sJSUrl));
			}
			break;
			
			case iPopupMenuExtension::MENU_OBJDETAILS_ACTIONS:
			/**
			 * @var DBObject $param
			 */
			if ($param instanceof Person)
			{
				$aExtraMenus[] = new JSPopupMenuItem('Anonymize', Dict::S('Anonymization:AnonymizeOne'), 'AnonymizeOnePerson('.$param->GetKey().');', array($sJSUrl));
			}
			break;
			
			default:
				// Do nothing
		}
		return $aExtraMenus;
	}
	
	/**
	 * Add content to the North pane
	 * @param iTopWebPage $oPage The page to insert stuff into.
	 * @return string The HTML content to add into the page
	 */
	public function GetNorthPaneHtml(iTopWebPage $oPage)
	{
		$oPage->add_dict_entries('Anonymization');
	}
	
	/**
	 * Add content to the South pane
	 * @param iTopWebPage $oPage The page to insert stuff into.
	 * @return string The HTML content to add into the page
	 */
	public function GetSouthPaneHtml(iTopWebPage $oPage)
	{
		
	}
	/**
	 * Add content to the "admin banner"
	 * @param iTopWebPage $oPage The page to insert stuff into.
	 * @return string The HTML content to add into the page
	 */
	public function GetBannerHtml(iTopWebPage $oPage)
	{
		
	}
}

class AnonymisationBackgroundProcess implements iBackgroundProcess
{
	/**
	 * @param int $iUnixTimeLimit
	 *
	 * @return string status message
	 * @throws \ProcessException
	 * @throws \ProcessFatalException
	 * @throws MySQLHasGoneAwayException
	 */
	public function Process($iUnixTimeLimit)
	{
		$sModuleName = basename(__DIR__);
		$bCleanupNotification = MetaModel::GetModuleSetting($sModuleName, 'cleanup_notifications', false);
		$iCountDeleted = 0;
		if ($bCleanupNotification)
		{
			$iRetentionDays = MetaModel::GetModuleSetting($sModuleName, 'notifications_retention', -1);
			if ($iRetentionDays > 0)
			{
				$sOQL = "SELECT EventNotificationEmail WHERE date < :date";
				$oDateLimit = new DateTime();
				$oDateLimit->modify("-$iRetentionDays days");
				$sDateLimit = $oDateLimit->format(AttributeDateTime::GetSQLFormat());
				
				$oSet = new DBObjectSet(DBSearch::FromOQL($sOQL), array('date' => true), array('date' => $sDateLimit));
				while((time() < $iUnixTimeLimit) && ($oNotif = $oSet->Fetch()))
				{
					$oNotif->DBDelete();
					$iCountDeleted++;
				}
			}
		}
		$bAnonymizeObsoletePersons = MetaModel::GetModuleSetting($sModuleName, 'anonymize_obsolete_persons', false);
		$iCountAnonymized = 0;
		if ($bAnonymizeObsoletePersons)
		{
			$iRetentionDays = MetaModel::GetModuleSetting($sModuleName, 'obsolete_persons_retention', -1);
			if ($iRetentionDays > 0)
			{
				$sOQL = "SELECT Person WHERE obsolescence_flag = 1 AND anonymized = 1 AND obsolescence_date < :date"; 
				$oDateLimit = new DateTime();
				$oDateLimit->modify("-$iRetentionDays days");
				$sDateLimit = $oDateLimit->format(AttributeDateTime::GetSQLFormat());
				
				$oSet = new DBObjectSet(DBSearch::FromOQL($sOQL), array('obsolescence_date' => true), array('date' => $sDateLimit));
				while((time() < $iUnixTimeLimit) && ($oPerson = $oSet->Fetch()))
				{
					$oPerson->Anonymize();
					$iCountAnonymized++;
				}
			}
		}
		$sMessage = sprintf("%d notification(s) deleted, %d person(s) anonymized.", $iCountDeleted, $iCountAnonymized);
		return $sMessage;
	}
	
	/**
	 * @return int repetition rate in seconds
	 */
	public function GetPeriodicity()
	{
		// Run once per day
		return 15; // For debugging, run every 15 seconds...
		//return 24*60*60;
	}
}
