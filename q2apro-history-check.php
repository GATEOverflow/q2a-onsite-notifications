<?php
/* The following code originates from q2a plugin "History" by NoahY and has been modified by q2apro.com
 * It is licensed under GPLv3 http://www.gnu.org/licenses/gpl.html
 * Link to plugin: https://github.com/NoahY/q2a-history
 */

class q2apro_history_check {
	// main event processing function

	function process_event($event, $userid, $handle, $cookieid, $params) {

		if(!qa_opt('event_logger_to_database')) return;
		if ($event === 'q2apro_osn_plugin') {
			qa_db_query_sub(
					'INSERT INTO ^q2apro_osn_plugin_notifications(plugin_id, event_text, icon_class, user_id, created_at) ' .
					'VALUES ($, $, $, $, NOW())',
					$params['plugin_id'], $params['event_text'], $params['icon_class'], $params['user_id']
				       );

			return;
		}

		$twoway = array(
				'a_select',
				'q_vote_up',
				'a_vote_up',
				'q_vote_down',
				'q_edit',
				'a_edit',
				'c_edit',
				'a_vote_down',
				'q_merge',
				'q_close',
				'c_vote_up',
				'c_vote_down',
				//'a_unselect',
				//'q_vote_nil',
				//'a_vote_nil',
				//'q_flag',
				//'a_flag',
				//'c_flag',
				//'q_unflag',
				//'a_unflag',
				//'c_unflag',
				//'u_edit',
				//'u_level',
				//'u_block',
				//'u_unblock',
			);

		$special = array(
				'a_post',
				'c_post',
				'qas_blog_c_post'
				);
				//'u_mentioned',
		$interaction = array(
				'u_message',
				'u_wall_post',
				'u_favorite'
				);

		if(in_array($event, $twoway)) {

			if(strpos($event,'u_') === 0) {
				$uid = $params['userid'];
			}
			else {
				$uid = qa_db_read_one_value(
						qa_db_query_sub(
							'SELECT userid FROM ^posts WHERE postid=#',
							$params['postid']
							),
						true
						);
			}

			if($uid != $userid) {
				$ohandle = $this->getHandleFromId($uid);

				$oevent = 'in_'.$event;

				$paramstring='';

				foreach ($params as $key => $value)
					$paramstring.=(strlen($paramstring) ? "\t" : '').$key.'='.$this->value_to_text($value);
				if($uid == 42416)
				{
				qa_db_query_sub(
						'INSERT INTO qa_eventlog (datetime, ipaddress, userid, handle, cookieid, event, params) '.
						'VALUES (NOW(), $, #, $, #, $, $)',
						qa_remote_ip_address(), $uid, $ohandle, $cookieid, $oevent, $paramstring
					       );
				}
				else
				{
				qa_db_query_sub(
						'INSERT INTO ^eventlog (datetime, ipaddress, userid, handle, cookieid, event, params) '.
						'VALUES (NOW(), $, #, $, #, $, $)',
						qa_remote_ip_address(), $uid, $ohandle, $cookieid, $oevent, $paramstring
				);
				}
			}
		}
		// messages and wallposts
		if(in_array($event,$interaction)) {

			//receiver userid (QA)
			$uid = $params['userid'];
			$ohandle = $this->getHandleFromId($uid);
			$shandle = $this->getHandleFromId($userid);
			$params['sender'] = $userid;
			$params['sender_handle'] = $shandle;
			$oevent = 'new_'.$event;


			$paramstring='';

			foreach ($params as $key => $value)
				$paramstring.=(strlen($paramstring) ? "\t" : '').$key.'='.$this->value_to_text($value);

			qa_db_query_sub(
					'INSERT INTO ^eventlog (datetime, ipaddress, userid, handle, cookieid, event, params) '.
					'VALUES (NOW(), $, $, $, #, $, $)',
					qa_remote_ip_address(), $uid, $ohandle, $cookieid, $oevent, $paramstring
				       );
		}



		// comments and answers
		if(in_array($event,$special)) {
			// userid (recent C)

			if($event == 'qas_blog_c_post')
			{
				$uid = qa_db_read_one_value(
						qa_db_query_sub(
							'SELECT userid FROM ^blogs WHERE postid=#',
							$params['postid']
							),
						true
						);
				// userid (QA)
				$pid = qa_db_read_one_value(
						qa_db_query_sub(
							'SELECT userid FROM ^blogs WHERE postid=#',
							$params['parentid']
							),
						true
						);
			}
			else{
				$uid = qa_db_read_one_value(
						qa_db_query_sub(
							'SELECT userid FROM ^posts WHERE postid=#',
							$params['postid']
							),
						true
						);
				// userid (QA)
				$pid = qa_db_read_one_value(
						qa_db_query_sub(
							'SELECT userid FROM ^posts WHERE postid=#',
							$params['parentid']
							),
						true
						);
			}
			// if QA poster is not the same as commenter
			if($pid != $userid) {

				$ohandle = $this->getHandleFromId($pid);

				switch($event) {
					case 'a_post':
						$oevent = 'in_a_question';
						break;
					case 'c_post':
						if ($params['parenttype'] == 'Q')
							$oevent = 'in_c_question';
						else 
							$oevent = 'in_c_answer';
						break;
					case 'qas_blog_c_post':
						$oevent = 'in_blog_comment';
						break;

				}

				$paramstring='';

				foreach ($params as $key => $value)
					$paramstring.=(strlen($paramstring) ? "\t" : '').$key.'='.$this->value_to_text($value);

				qa_db_query_sub(
						'INSERT INTO ^eventlog (datetime, ipaddress, userid, handle, cookieid, event, params) '.
						'VALUES (NOW(), $, $, $, #, $, $)',
						qa_remote_ip_address(), $pid, $ohandle, $cookieid, $oevent, $paramstring
					       );				
			}

			// q2apro: added logging for comments in thread
			if($event=='c_post' || $event == 'qas_blog_c_post')

			{
				if($event == 'c_post'){
					$oevent = 'in_c_comment';

					// check if we have more comments to the parent
					// DISTINCT: if a user has more than 1 comment just select him unique to inform him only once
					$precCommentsQuery = qa_db_query_sub('SELECT DISTINCT userid FROM `^posts`
							WHERE `parentid` = #
							AND `type` = "C"
							AND `userid` IS NOT NULL
							',
							$params['parentid']);
				}
				else{
					$oevent = 'in_blog_c_comment';

					// check if we have more comments to the parent
					// DISTINCT: if a user has more than 1 comment just select him unique to inform him only once
					$precCommentsQuery = qa_db_query_sub('SELECT DISTINCT userid FROM `^blogs`
							WHERE `parentid` = #
							AND `type` = "C"
							AND `userid` IS NOT NULL
							',
							$params['parentid']);
				}

				while( ($comment = qa_db_read_one_assoc($precCommentsQuery,true)) !== null ) {
					$userid_CommThr = $comment['userid']; // unique

					// dont inform user that comments, and dont inform user that comments on his own question/answer
					if(($userid_CommThr != $uid) && ($userid_CommThr != $pid)) {
						$ohandle = $this->getHandleFromId($userid_CommThr);

						$paramstring='';
						foreach ($params as $key => $value) {
							$paramstring.=(strlen($paramstring) ? "\t" : '').$key.'='.$this->value_to_text($value);
						}

						qa_db_query_sub(
								'INSERT INTO ^eventlog (datetime, ipaddress, userid, handle, cookieid, event, params) '.
								'VALUES (NOW(), $, $, $, #, $, $)',
								qa_remote_ip_address(), $userid_CommThr, $ohandle, $cookieid, $oevent, $paramstring
							       );
					}
				}
			} // end in_c_comment

		} // end in_array
	}
public function value_to_text($value)
        {
                require_once QA_INCLUDE_DIR . 'util/string.php';

                if (is_array($value))
                        $text = 'array(' . count($value) . ')';
                elseif (qa_strlen($value) > 40)
                        $text = qa_substr($value, 0, 38) . '...';
                else
                        $text = $value;

                return strtr($text, "\t\n\r", '   ');
        }



					function getHandleFromId($userid) {
					require_once QA_INCLUDE_DIR.'qa-app-users.php';

					if (QA_FINAL_EXTERNAL_USERS) {
					$publictohandle=qa_get_public_from_userids(array($userid));
					$handle=@$publictohandle[$userid];

					} 
					else {
					$user = qa_db_single_select(qa_db_user_account_selectspec($userid, true));
					$handle = @$user['handle'];
					}
					return $handle;
					}
}


/*
   Omit PHP closing tag to help avoid accidental output
 */
