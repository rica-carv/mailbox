<?php
/*
 * Mailbox - an e107 plugin by Tijn Kuyper
 *
 * Copyright (C) 2016-2017 Tijn Kuyper (http://www.tijnkuyper.nl)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 */

if(!defined('e107_INIT'))
{
	require_once("../../class2.php");
}

if(!e107::isInstalled('mailbox'))
{
	e107::redirect();
	exit;
}

// Load the LAN files
e107::lan('mailbox');

// Load the header
require_once(HEADERF);

// Load template and shortcodes
$sc 		= e107::getScBatch('mailbox', TRUE);
$template 	= e107::getTemplate('mailbox');
$template 	= array_change_key_case($template);

// Define variables
$sql 	= e107::getDb();
$tp 	= e107::getParser();
$frm 	= e107::getForm();
$text 	= '';
$mid 	= (int) $_GET['id'];

/* Let's render some things now */
	// Open container
	$text .= $tp->parseTemplate($template['container']['start'], true, $sc);
		// Open sidemenu
		$text .= $tp->parseTemplate($template['box_navigation']['start'], true, $sc);
			// Load sidemenu content
			$text .= $tp->parseTemplate($template['box_navigation']['content'], true, $sc);
		// Close sidemenu
		$text .= $tp->parseTemplate($template['box_navigation']['end'], true, $sc);
	// Open tablelist 
	$text .= $tp->parseTemplate($template['tablelist']['start'], true, $sc);
		// Load right content
			// Construct query
			$query_getmessage = $sql->retrieve('mailbox_messages', '*', 'message_id='.$mid.'');

			// Check if the message is there
			if($query_getmessage)
			{
				// Make sure that the message belongs to user (sender for outbox or receiver for inbox)
				if($query_getmessage['message_to'] == USERID || $query_getmessage['message_from'] == USERID)
				{
					
					// Double check - if it's a draft, redirect to compose
					if($query_getmessage['message_draft'] != '0')
					{
						$url = e107::url('mailbox', 'composeid', array('id' => $query_getmessage['message_id']));
						e107::redirect($url);
					}

					// Message belongs to user (sender or receiver), it's not a draft, so we can now show the message 
						// update 'read' status
						$update_read = array(
							'message_read'  => time(),
							'WHERE'         => 'message_id = '.$query_getmessage['message_id']
						);

						if(!$sql->update("mailbox_messages", $update_read))
						{
							e107::getMessage()->addError("Could not update message_read status");
						}

						// Pass database values onto template, and render
						$sc->setVars($query_getmessage);
						$text .= $tp->parseTemplate($template['read_message'], true, $sc);
				}
				else
				{
					$text .= e107::getMessage()->addError(LAN_MAILBOX_MESSAGENOTYOURS);
				}
			}
			else
			{
				$text .= e107::getMessage()->addError(LAN_MAILBOX_MESSAGENOTFOUND); // may only happen when message has been deleted > 14 days ago
			}
	// Close tabellist
	$text .= $tp->parseTemplate($template['tablelist']['end'], true, $sc);
// Close container
$text .= $tp->parseTemplate($template['container']['end'], true, $sc);

$ns->tablerender(LAN_MAILBOX_NAME, e107::getMessage()->render().$text);
require_once(FOOTERF);
exit;