<?php
/*
 * Mailbox - an e107 plugin by Tijn Kuyper
 *
 * Copyright (C) 2019-2020 Tijn Kuyper (http://www.tijnkuyper.nl)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 * Class including all generic functions
 *
 */

/* NOTES
- Delete message from database:
=> check if message is ready to be deleted from database completely
1) should not be starred
2) should be deleted by both to and from

- Move to trash:
=> Remove starred status from recipient (set message_to_starred to 0)

- Empty trash:
=> last empty date updated each time when trash is emptied
=> only messages displayed where the message_to_deleted < latest_emptytrash_datestamp of user

- Delete draft
=> check for draft status (just to be sure)
=> set message_to_deleted=1

- MULTIPLE RECIPIENTS
=> message_to: as long as it is in draft may contain multiple comma separated id's. In sending process, new entry is made, for recipient unique row with unique message_to. No special outbox counting routine needed. If send to 5 people, 5 entries in outbox are visible. 


*/

if (!defined('e107_INIT')) { exit; }

// Include JQuery / Ajax code
e107::js('mailbox','js/mailbox.js', 'jquery', 5);

class Mailbox
{
	protected $plugprefs = array();

	public function __construct()
	{
		$this->plugprefs = e107::getPlugPref('mailbox');
	}

	public function get_current_mailbox($parm = '')
	{
		// If parm is not set, use current page
		if(!$parm)
		{
			$parm = e107::getParser()->filter($_GET['page']);
		}

		// All valid mailboxes in an array
		$mailbox_array = array("inbox", "outbox", "draftbox", "starbox", "trashbox");

		// Check user input to see if mailbox matches with array
		if(in_array($parm, $mailbox_array))
		{
			$current_mailbox = $parm;
		}
		// Invalid mailbox input, so default back to inbox to be sure
		else
		{
			$current_mailbox = 'inbox';
		}

		return $current_mailbox;
	}

	public function get_pagetitle($parm = '')
	{

      switch($parm)
      {
         case 'inbox':
         default:
            $title = LAN_MAILBOX_INBOX;
            break;
         case 'outbox':
            $title = LAN_MAILBOX_OUTBOX;
            break;
         case 'draftbox':
            $title = LAN_MAILBOX_DRAFTBOX;
            break;
         case 'starbox':
            $title = LAN_MAILBOX_STARBOX;
            break;
         case 'trashbox':
            $title = LAN_MAILBOX_TRASHBOX;
            break;
      }

      return $title;
	}


	public function get_database_queryargs($box = 'inbox', $filter = 'all')
	{
		// Default back to inbox to be sure
		if(!$box) { $box = 'inbox'; }

		if($filter == 'unread')
		{
			$unread =  ' AND message_read=0';
		}

		switch($box)
		{
			case 'inbox':
			default:
				$args = "message_to=".USERID." AND message_to_deleted=0 AND message_draft=0".$unread;
				break;
			case 'outbox':
				$args = "message_from=".USERID." AND message_to_deleted=0 AND message_draft=0".$unread;
				break;
			case 'draftbox':
				$args = "message_from=".USERID." AND message_draft IS NOT NULL AND message_sent=0 AND message_to_deleted=0".$unread;
				break;
			case 'starbox': // no, not Starbucks ;)
				$args = "	(message_to=".USERID." AND message_to_starred=1 AND message_to_deleted=0".$unread.") 
								OR 
							(message_from=".USERID." AND message_from_starred=1 AND message_to_deleted=0".$unread.")
						";
				break;
			case 'trashbox':
				$args = "message_to=".USERID." AND message_to_deleted!=0".$unread;
				break;
		}

		return $args;
	}

	public function process_compose($action = 'send', $post_data)
	{
		//print_a($post_data);

		// Check fields 
		if(empty($post_data['message_to']) || empty($post_data['message_subject']) || empty($post_data['message_text']))
		{
			return e107::getMessage()->addError("Required fields are left empty: todo list fields"); // MAILBOX TODO LAN
		}
		

		$tp  = e107::getParser();
		$sql = e107::getDb();

		// This is the default data set
		$default_data = array(
			'message_from' 			=> USERID,
			'message_draft'			=> '0',
			'message_sent' 			=> time(),
			'message_read' 			=> 0,
			'message_subject' 		=> $tp->toDb($post_data['message_subject']),
			'message_text'			=> $tp->toDb($post_data['message_text']),
			'message_to_starred' 	=> '0',
			'message_from_starred' 	=> '0',
			'message_to_deleted'	=> '0',
			'message_from_deleted' 	=> '0',
			'message_attachments' 	=> '',
		);

		// The insert data represent changes to the default data (which are specific to a message, such as a draft)
		$insert_data = $default_data;

		// If the message is only a draft, we need to make some changes to the default data
		if($action == 'draft')
		{
			// process_draft($insert_data);
			// Set draft status to current time (last edited)
			$insert_data['message_draft'] 	= time();
			$insert_data['message_sent'] 	= '0';
			$insert_data['message_to'] 		= $tp->toDb($post_data['message_to']);

			// If saving an existing draft, update rather than insert new record
			if($post_data['id'])
			{
				// Set WHERE clause to message ID
				$insert_data['WHERE'] = 'message_id = '.$tp->toDb($post_data['id']);

				if($sql->update("mailbox_messages", $insert_data))
				{
					return e107::getMessage()->addSuccess("Draft successfully updated");
				}
				else
				{
					return e107::getMessage()->addError("Something went wrong with updating the draft");
				}
			}

			// New draft - insert into database
			if($sql->insert("mailbox_messages", $insert_data))
			{
				return e107::getMessage()->addSuccess("Saved as draft");
			}
			else
			{
				// $sql->getLastErrorNumber();
				// $sql->getLastErrorText();
				// print_a($insert_data);
				return e107::getMessage()->addError("Something went wrong with saving the draft");
			}
		}

		// Ending up here, we are actually sending the message.
		// First, determine the sendmode: 1) individual, 2) multiple, 3) usserclass
		//print_a($post_data['message_to']);
		$message_to = $tp->toDb($post_data['message_to']);

		// individual
		if(is_numeric($message_to))
		{
			$sendmode = "individual";
			$insert_data['message_to'] = $message_to; 
			$this->send_message($insert_data); 
		}
		// multiple
		elseif(strrpos($message_to, ','))
		{
			$sendmode = "multiple";
		}
		// class
		else
		{
			$sendmode = "class";
		}


		print_a("sendmode: ".$sendmode);
		print_a("recipients: ".$message_to);
		return;
		// Prepare subject (message_subject)


		// Prepare message text (message_text)

		// Trigger event
		e107::getEvent()->trigger('user_mailbox_sent', $info);
	}

	public function send_message($insert_data = '')
	{
		print_a($insert_data); 
		print_a("done");
	}


	// Discard a draft message
	public function discard_message($data = '')
	{
		// Check if we have all the required data
		if(!$data || !$data['id'])
		{
			return e107::getMessage()->addError("Something went wrong when trying to discard the message...");
		}

		return e107::getMessage()->addInfo("Message should be discarded but this routine is not finished yet.");
	}


	public function ajaxReadUnread()
	{
		$sql = e107::getDb();
		$tp = e107::getParser();

		if(!isset($_POST['e-token'])) // Set the token if not included
		{
			$_POST['e-token'] = $_POST['etoken'];
		}

		//error_log(print_r($_POST, true));

		if(!e107::getSession()->check(false))
		{
			// Invalid token.
			error_log("Mailbox - invalid token!"); // MAILBOX TODO LOG
			exit;
		}

		$ids 	= $_POST["ids"];
		$values = $_POST["values"];

		$input_array = array_combine($ids, $values);
		$output_array = array();

		foreach($input_array as $id => $value)
		{	
			$id = $tp->filter($id);

			// Draft messages are always read. No need to do anything
			if($sql->retrieve('mailbox_messages', 'message_draft', 'message_id='.$id) != 0)
			{
				break;
			}
			
			// If currently 'read', set to unread (0)
			if($value == 'read')
			{
				$new_value = 0;
				$output_array[$id] = "unread";
			}
			// If currently 'unread', set to read (current timestamp)
			elseif($value == 'unread')
			{
				$new_value = time();
				$output_array[$id] = "read";
			}
			// If it's something else it may be malicious, stop script... 
			else
			{
				error_log("Mailbox - Possible malicious attempt!"); // TODO LOG 
				break;
			}

			// Prepare update statement
			$update = array(
			    'message_read' 	=> $new_value,
			    'WHERE' 		=> 'message_id = '.$id,
			);

			// Update database, and catch error if applicable 
			if($sql->update('mailbox_messages', $update) !== false) 
			{
				
			}
			else
			{
				// MAILBOX TODO LOG
				error_log("Mailbox - SQL ERROR"); 
				error_log('SQL Error #'.$sql->getLastErrorNumber().': '.$sql->getLastErrorText());
				error_log('$SQL Query'.print_r($sql->getLastQuery(),true));
			}
		}
		//error_log($output_array);
		echo json_encode($output_array);
		exit;
	}

	public function ajaxStar()
	{
		$sql = e107::getDb();
		$tp = e107::getParser();

		$ids 	= $_POST["ids"];
		
		$output_array = array();

		$current_mailbox = $this->get_current_mailbox();
		//error_log($current_mailbox);

		foreach($ids as $id)
		{	
			// Filter user input
			$id = $tp->filter($id);


			if($sql->retrieve('mailbox_messages', 'message_draft', 'message_id='.$id) != 0)
			{
				$column = 'message_from_starred';
			}
			else
			{
				$column = 'message_to_starred';
			}
			

			if($current_status = $sql->retrieve('mailbox_messages', $column, 'message_id='.$id))
			{
				$current_status = 'starred';
				$new_status = '0'; // set to not starred
			}
			else
			{
				$current_status = 'notstarred';
				$new_status = '1'; // set to starred
			}


			// Prepare update statement
			$update = array(
			    $column => $new_status,
			    'WHERE'	=> 'message_id = '.$id,
			);

			// Update database, and catch error if applicable 
			if($sql->update('mailbox_messages', $update) !== false) 
			{
				
				$output_array[$id] = $new_status;
			}
			else
			{
				// MAILBOX TODO LOG
				error_log("Mailbox - SQL ERROR"); 
				error_log('SQL Error #'.$sql->getLastErrorNumber().': '.$sql->getLastErrorText());
				error_log('$SQL Query'.print_r($sql->getLastQuery(),true));
			}
			
		}
		
		//error_log(print_r($output_array, true));
		echo json_encode($output_array);
		exit;
	}

	public function ajaxTrash()
	{
		//error_log("Mailbox - Trash TODO");
		//echo json_encode();
		exit;
	}

	/*
	 *	Send a message
	 *
	 *	@param	array $vars	- message information
	 *		['options'] - array of options
	 *		['attachments'] - list of attachments (if any) - each is an array('size', 'name')
	 *		['to_userclass'] - set TRUE if sending to a user class
	 *		['to_array'] = array of recipients
	 *		['pm_userclass'] = target user class
	 *		['to_info'] = recipients array of array('user_id', 'user_class')
	 *
	 *		May also be an array as received from the generic table, if sending via a cron job
	 *			identified by the existence of $vars['pm_from']
	 *
	 *	@return	string - text detailing result
	 */
	function send_message_OLD($vars)
	{
		$tp = e107::getParser();
		$sql = e107::getDb();
		$pmsize = 0;
		$attachlist = '';
		$pm_options = '';
		$ret = '';
		$maxSendNow = varset($this->plugprefs['pm_max_send'], 100); // Max # of messages before having to queue

		if (isset($vars['pm_from']))
		{	// Doing bulk send off cron task
			$info = array();
			foreach ($vars as $k => $v)
			{
				if (strpos($k, 'pm_') === 0)
				{
					$info[$k] = $v;
					unset($vars[$k]);
				}
			}
		}
		else
		{	// Send triggered by user - may be immediate or bulk dependent on number of recipients
			$vars['options'] = '';
			if(isset($vars['receipt']) && $vars['receipt']) {$pm_options .= '+rr+';	}
			if(isset($vars['uploaded']))
			{
				foreach($vars['uploaded'] as $u)
				{
					if (!isset($u['error']) || !$u['error'])
					{
						$pmsize += $u['size'];
						$a_list[] = $u['name'];
					}
				}
				$attachlist = implode(chr(0), $a_list);
			}
			$pmsize += strlen($vars['pm_message']);

			$pm_subject = trim($tp->toDB($vars['pm_subject']));
			$pm_message = trim($tp->toDB($vars['pm_message']));

			if (!$pm_subject && !$pm_message && !$attachlist)
			{  // Error - no subject, no message body and no uploaded files
				return LAN_PM_65;
			}

			// Most of the pm info is fixed - just need to set the 'to' user on each send
			$info = array(
				'pm_from' => $vars['from_id'],
				'pm_sent' => time(),					/* Date sent */
				'pm_read' => 0,							/* Date read */
				'pm_subject' => $pm_subject,
				'pm_text' => $pm_message,
				'pm_sent_del' => 0,						/* Set when can delete */
				'pm_read_del' => 0,						/* set when can delete */
				'pm_attachments' => $attachlist,
				'pm_option' => $pm_options,				/* Options associated with PM - '+rr' for read receipt */
				'pm_size' => $pmsize
				);
		}

		if(isset($vars['to_userclass']) || isset($vars['to_array']))
		{
			if(isset($vars['to_userclass']))
			{
				$toclass = e107::getUserClass()->uc_get_classname($vars['pm_userclass']);
				$tolist = $this->get_users_inclass($vars['pm_userclass']);
				$ret .= LAN_PM_38.": {$toclass}<br />";
				$class = TRUE;
			}
			else
			{
				$tolist = $vars['to_array'];
				$class = FALSE;
			}
			// Sending multiple PMs here. If more than some number ($maxSendNow), need to split into blocks.
			if (count($tolist) > $maxSendNow)
			{
				$totalSend = count($tolist);
				$targets = array_chunk($tolist, $maxSendNow);		// Split into a number of lists, each with the maximum number of elements (apart from the last block, of course)
				unset($tolist);
				$array = new ArrayData;
				$pmInfo = $info;
				$genInfo = array(
					'gen_type' => 'pm_bulk',
					'gen_datestamp' => time(),
					'gen_user_id' => USERID,
					'gen_ip' => ''
					);
				for ($i = 0; $i < count($targets) - 1; $i++)
				{	// Save the list in the 'generic' table
					$pmInfo['to_array'] = $targets[$i];			// Should be in exactly the right format
					$genInfo['gen_intdata'] = count($targets[$i]);
					$genInfo['gen_chardata'] = $array->WriteArray($pmInfo,TRUE);
					$sql->insert('generic', array('data' => $genInfo, '_FIELD_TYPES' => array('gen_chardata' => 'string')));	// Don't want any of the clever sanitising now
				}
				$toclass .= ' ['.$totalSend.']';
				$tolist = $targets[count($targets) - 1];		// Send the residue now (means user probably isn't kept hanging around too long if sending lots)
				unset($targets);
			}
			foreach($tolist as $u)
			{
				set_time_limit(30);
				$info['pm_to'] = intval($u['user_id']);		// Sending to a single user now

				if($pmid = $sql->insert('private_msg', $info))
				{
					$info['pm_id'] = $pmid;
					e107::getEvent()->trigger('user_pm_sent', $info);

					unset($info['pm_id']); // prevent it from being used on the next record.

					if($class == FALSE)
					{
						$toclass .= $u['user_name'].', ';
					}
					if(check_class($this->plugprefs['notify_class'], $u['user_class']))
					{
						$vars['to_info'] = $u;
						$this->pm_send_notify($u['user_id'], $vars, $pmid, count($a_list));
					}
				}
				else
				{
					$ret .= LAN_PM_39.": {$u['user_name']} <br />";
					e107::getMessage()->addDebug($sql->getLastErrorText());
				}
			}
			if ($addOutbox)
			{
				$info['pm_to'] = $toclass;		// Class info to put into outbox
				$info['pm_sent_del'] = 0;
				$info['pm_read_del'] = 1;
				if(!$pmid = $sql->insert('private_msg', $info))
				{
					$ret .= LAN_PM_41.'<br />';
				}
			}

		}
		else
		{	// Sending to a single person
			$info['pm_to'] = intval($vars['to_info']['user_id']);		// Sending to a single user now
			if($pmid = $sql->insert('private_msg', $info))
			{
				$info['pm_id'] = $pmid;
				e107::getEvent()->trigger('user_pm_sent', $info);
				if(check_class($this->plugprefs['notify_class'], $vars['to_info']['user_class']))
				{
					set_time_limit(30);
					$this->pm_send_notify($vars['to_info']['user_id'], $vars, $pmid, count($a_list));
				}
				$ret .= LAN_PM_40.": {$vars['to_info']['user_name']}<br />";
			}
		}
		return $ret;
	}

	/**
	 *	Mark a PM as read
	 *	If flag set, send read receipt to sender
	 *
	 *	@param	int $pm_id - ID of PM
	 *	@param	array $pm_info - PM details
	 *
	 *	@return	none
	 *
	 *	@todo - 'read_delete' pref doesn't exist - remove code? Or support?
	 */
	function pm_mark_read($pm_id, $pm_info)
	{
		$now = time();
		if($this->plugprefs['read_delete'])
		{
			$this->del($pm_id);
		}
		else
		{
			e107::getDb()->gen("UPDATE `#private_msg` SET `pm_read` = {$now} WHERE `pm_id`=".intval($pm_id)); // TODO does this work properly?
			if(strpos($pm_info['pm_option'], '+rr') !== FALSE)
			{
				$this->pm_send_receipt($pm_info);
			}
		  	e107::getEvent()->trigger('user_pm_read', $pm_id);
		}
	}

	/**
	 *	Delete a PM from a user's inbox/outbox.
	 *	PM is only actually deleted from DB once both sender and recipient have marked it as deleted
	 *	When physically deleted, any attachments are deleted as well
	 *
	 *	@param integer $pmid - ID of the PM
	 *	@param boolean $force - set to TRUE to force deletion of unread PMs
	 *	@return boolean|string - FALSE if PM not found, or other DB error. String if successful
	 */
	function del($pmid, $force = FALSE)
	{
		$sql = e107::getDb();
		$pmid = (int)$pmid;
		$ret = '';
		$newvals = '';
		if($sql->select('private_msg', '*', 'pm_id = '.$pmid.' AND (pm_from = '.USERID.' OR pm_to = '.USERID.')'))
		{
			$row = $sql->fetch();

			// if user is the receiver of the PM
			if (!$force && ($row['pm_to'] == USERID))
			{
				$newvals = 'pm_read_del = 1';
				$ret .= LAN_PM_42.'<br />';
				if($row['pm_sent_del'] == 1) { $force = TRUE; } // sender has deleted as well, set force to true so the DB record can be deleted
			}

			// if user is the sender of the PM
			if (!$force && ($row['pm_from'] == USERID))
			{
				if($newvals != '') { $force = TRUE; }
				$newvals = 'pm_sent_del = 1';
				$ret .= LAN_PM_43."<br />";
				if($row['pm_read_del'] == 1) { $force = TRUE; } // receiver has deleted as well, set force to true so the DB record can be deleted
			}

			if($force == TRUE)
			{
				// Delete any attachments and remove PM from db
				$attachments = explode(chr(0), $row['pm_attachments']);
				$aCount = array(0,0);
				foreach($attachments as $a)
				{
					$a = trim($a);
					if ($a)
					{
						$filename = e_PLUGIN.'pm/attachments/'.$a;
						if (unlink($filename)) $aCount[0]++; else $aCount[1]++;
					}
				}
				if ($aCount[0] || $aCount[1])
				{

				//	$ret .= str_replace(array('--GOOD--', '--FAIL--'), $aCount, LAN_PM_71).'<br />';
					$ret .= e107::getParser()->lanVars(LAN_PM_71, $aCount);
				}
				$sql->delete('private_msg', 'pm_id = '.$pmid);
			}
			else
			{
				$sql->update('private_msg', $newvals.' WHERE pm_id = '.$pmid);
			}
			return $ret;
		}
		return FALSE;
	}

	/**
	 *	Send an email to notify of a PM
	 *
	 *	@param int $uid - not used
	 *	@param array $pmInfo - PM details
	 *	@param int $pmid - ID of PM in database
	 *	@param int $attach_count - number of attachments
	 *
	 *	@return none
	 */
	function pm_send_notify($uid, $pmInfo, $pmid, $attach_count = 0)
	{
		require_once(e_HANDLER.'mail.php');
		$subject = LAN_PM_100.SITENAME;
	//	$pmlink = $this->url('show', 'id='.$pmid, 'full=1&encode=0'); //TODO broken - replace with e_url.php configuration.
		$pmlink = SITEURLBASE.e_PLUGIN_ABS."pm/pm.php?show.".$pmid;
		$txt = LAN_PM_101.SITENAME."\n\n";
		$txt .= LAN_PM_102.USERNAME."\n";
		$txt .= LAN_PM_103.$pmInfo['pm_subject']."\n";
		if($attach_count > 0)
		{
			$txt .= LAN_PM_104.$attach_count."\n";
		}
		$txt .= LAN_PM_105."\n".$pmlink."\n";
		sendemail($pmInfo['to_info']['user_email'], $subject, $txt, $pmInfo['to_info']['user_name']);
	}

	/**
	 *	Send PM read receipt
	 *
	 *	@param array $pmInfo - PM details
	 *
	 * 	@return none
	 */
	function pm_send_receipt($pmInfo)
	{
		require_once(e_HANDLER.'mail.php');
		$subject = LAN_PM_106.$pmInfo['sent_name'];
	//	$pmlink = $this->url('show', 'id='.$pmInfo['pm_id'], 'full=1&encode=0');
		$pmlink = SITEURLBASE.e_PLUGIN_ABS."pm/pm.php?show.".$pmInfo['pm_id'];
		$txt = str_replace("{UNAME}", $pmInfo['sent_name'], LAN_PM_107).date('l F dS Y h:i:s A')."\n\n";
		$txt .= LAN_PM_108.date('l F dS Y h:i:s A', $pmInfo['pm_sent'])."\n";
		$txt .= LAN_PM_103.$pmInfo['pm_subject']."\n";
		$txt .= LAN_PM_105."\n".$pmlink."\n";
		sendemail($pmInfo['from_email'], $subject, $txt, $pmInfo['from_name']);
	}

	/**
	 *	Get list of users in class
	 *
	 *	@param int $class - class ID
	 *
	 *	@return boolean|array - FALSE on error/none found, else array of user information arrays
	 */
	function get_users_inclass($class)
	{
		$sql = e107::getDb();
		if($class == e_UC_MEMBER)
		{
			$qry = "SELECT user_id, user_name, user_email, user_class FROM `#user` WHERE 1";
		}
		elseif($class == e_UC_ADMIN)
		{
			$qry = "SELECT user_id, user_name, user_email, user_class FROM `#user` WHERE user_admin = 1";
		}
		elseif($class)
		{
			$regex = "(^|,)(".e107::getParser()->toDB($class).")(,|$)";
			$qry = "SELECT user_id, user_name, user_email, user_class FROM `#user` WHERE user_class REGEXP '{$regex}'";
		}
		if($sql->gen($qry))
		{
			$ret = $sql->db_getList();
			return $ret;
		}
		return FALSE;
	}
}