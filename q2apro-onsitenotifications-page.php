<?php

/*
   Plugin Name: On-Site-Notifications
   Plugin URI: http://www.q2apro.com/plugins/on-site-notifications
   Plugin Description: Facebook-like / Stackoverflow-like notifications on your question2answer forum that can replace all email-notifications.
   Plugin Version: 1.0
   Plugin Date: 2014-03-29
   Plugin Author: q2apro.com
   Plugin Author URI: http://www.q2apro.com
   Plugin Minimum Question2Answer Version: 1.5
   Plugin Update Check URI: http://www.q2apro.com/pluginupdate?id=61

   Licence: Copyright © q2apro.com - All rights reserved

 */
//error_reporting(-1);
//ini_set('display_errors', 'On');
class q2apro_onsitenotifications_page {

	var $directory;
	var $urltoroot;

	function load_module($directory, $urltoroot)
	{
		$this->directory=$directory;
		$this->urltoroot=$urltoroot;
	}

	// for display in admin interface under admin/pages
	function suggest_requests() 
	{	
		return array(
			array(
				'title' => 'On-Site-Notifications Page', // title of page
				'request' => 'eventnotify', // request name
				'nav' => 'M', // 'M'=main, 'F'=footer, 'B'=before main, 'O'=opposite main, null=none
			),
		);
	}

	// for url query
	function match_request($request)
	{
		if ($request=='eventnotify') {
			return true;
		}

		return;
	}

	function process_request($request)
	{



		// we received post data, it is the ajax call!
		$transferString = qa_post_text('ajax');
		if( $transferString !== null ) {

			// prevent empty userid
			$userid = qa_get_logged_in_userid();
			if(empty($userid)) {
				echo 'Userid is empty!';
				return;
			}	


			// ajax return all user events
			if(isset($userid) && $transferString=='receiveNotify'){
				$last_visit = qa_db_read_one_value(
					qa_db_query_sub(
						'SELECT UNIX_TIMESTAMP(meta_value) FROM ^usermeta WHERE user_id=# AND meta_key="visited_profile"',
						$userid
					), true
				);

				$event_query = $this->getEventsForUser($userid);


				$events = array();
				$postids = array();
				$blogids = array();
				$count = 0;
				while ( ($event=qa_db_read_one_assoc($event_query,true)) !== null ) {
					if(preg_match('/blog_/',$event['event']) === 1)//arjun

					{	
						preg_match('/postid=([0-9]+)/',$event['params'],$m);
						$event['postid'] = (int)$m[1];
						$blogids[] = (int)$m[1];
						$events[$m[1].'_'.$count++] = $event;

					}
					else if(preg_match('/postid=([0-9]+)/',$event['params'],$m) === 1)
					{
						$event['postid'] = (int)$m[1];
						$postids[] = (int)$m[1];
						$events[$m[1].'_'.$count++] = $event;
					}
					if($event['event']=='new_u_message') {
						// example of $event['params']: userid=1  handle=admin  messageid=4  message=hi admin, how are you?
						$ustring = $event['params'];

						// get messageid
						if(preg_match('/messageid=([0-9]+)/',$ustring,$m) === 1) {
							$event['messageid'] = (int)$m[1];
						}
						// get senderid
						if(preg_match('/sender=([0-9]+)/',$ustring,$m) === 1) {
							$event['userid'] = (int)$m[1];
						}

						// needed for function qa_post_userid_to_handle()
						require_once QA_INCLUDE_DIR.'qa-app-posts.php';
						// get handle from userid, memo: userid from receiver is saved in params (the acting userid is the sender)
						$event['handle'] = qa_post_userid_to_handle($event['userid']);

						// get message preview by cutting out the string
						$event['message'] = substr($ustring,strpos($ustring,'message=')+8, strlen($ustring)-strpos($ustring,'message=')+8);

						$events[$m[1].'_'.$count++] = $event;

					}
					// wall post
					else if($event['event']=='new_u_wall_post') {
						// example of $event['params']: userid=1        handle=admin    messageid=8     content=hi admin!       format= text=hi admin!
						$ustring = $event['params'];

						// get messageid
						if(preg_match('/messageid=([0-9]+)/',$ustring,$m) === 1) {
							$event['messageid'] = (int)$m[1];
						}
						// get senderid
						if(preg_match('/sender=([0-9]+)/',$ustring,$m) === 1) {
							$event['userid'] = (int)$m[1];
						}

						// needed for function qa_post_userid_to_handle()
						require_once QA_INCLUDE_DIR.'qa-app-posts.php';
						// get handle from userid, memo: userid from receiver is saved in params (the acting userid is the sender)
						$event['handle'] = qa_post_userid_to_handle($event['userid']);

						// get message preview by cutting out the string
						$event['message'] = substr($ustring,strpos($ustring,'text=')+5, strlen($ustring)-strpos($ustring,'text=')+5);

						$events[$m[1].'_'.$count++] = $event;
					}
					else if($event['event'] === 'q2apro_osn_plugin') {
						$events['_' . $count++] = $event;
					}

				}

				// get post info, also makes sure post exists
				$posts = null;
				if(!empty($postids)) {
					$post_query = qa_db_read_all_assoc(
						qa_db_query_sub(
							'SELECT postid, type, parentid, BINARY title as title FROM ^posts 
							WHERE postid IN ('.implode(',',$postids).')'
						)
					);
					foreach($post_query as $post) {
						$posts[(string)$post['postid']] = $post;
					}
				}
				$blogs = null;
				if(!empty($blogids)) {
					$blog_query = qa_db_read_all_assoc(
						qa_db_query_sub(
							'SELECT postid, type, parentid, BINARY title as title FROM ^blogs 
							WHERE postid IN ('.implode(',',$blogids).')'
						)
					);
					foreach($blog_query as $blog) {
						$blogs[(string)$blog['postid']] = $blog;
					}
				}

				// List all events
				$notifyBoxEvents = '<div id="nfyWrap" class="nfyWrap">
					<div class="nfyTop">'.qa_lang('q2apro_onsitenotifications_lang/my_notifications').' <a id="nfyReadClose" style="float:right;cursor:pointer;">'.qa_lang('q2apro_onsitenotifications_lang/close').' | × |</a> </div>
					<div class="nfyContainer">
					<div id="nfyContainerInbox">
';
						$url = qa_path_absolute("");

				// BIG FOREACH
				foreach($events as $postid_string => $event) {

					$type = $event['event'];
					$postid = preg_replace('/_.*/','',$postid_string);
					$post = null;
					if(preg_match('/blog_/',$event['event']) === 1)
					{

						$post = @$blogs[$postid];
					}
					else 
					{
						$post = @$posts[$postid];
					}
					$sender = null;

					$params = array();
					$paramsa = explode("\t",$event['params']);
					foreach($paramsa as $param) {
						$parama = explode('=',$param);
						if(isset($parama[1])) {
							$params[$parama[0]]=$parama[1];
						}
						else {
							$params[$param]=$param;
						}
					}

					$link = '';
					$linkTitle = '';
					$activity_url = '';


					//blogs

					if(preg_match('/blog_/',$event['event']) === 1) {
						$post = @$blogs[$postid];
						if(!isset($params['parentid'])) {

							$params['parentid'] = $post['parentid'];
						}

						$qTitle = qa_db_read_one_value( qa_db_query_sub("SELECT title FROM `^blogs` WHERE  `postid` = ".$params['parentid']." LIMIT 1"), true );
						if(!isset($qTitle)) $qTitle = ''; // (isset($getQtitle[0])) ? $getQtitle[0] : "";
						$activity_url = qa_path_absolute("blog")."/".qa_q_request($params['parentid'], $qTitle);
						$linkTitle = $qTitle;
						$link = '<a target="_blank" href="'.$activity_url.'">'.$qTitle.'</a>';

					}

					// comment or answer
					else if($post != null && strpos($event['event'],'q_') !== 0 && strpos($event['event'],'in_q_') !== 0) {
						if(!isset($params['parentid'])) {
							$params['parentid'] = $post['parentid'];
						}

						$parent = qa_db_select_with_pending(
							qa_db_full_post_selectspec(
								$userid,
								$params['parentid']
							)
						);
						if($parent['type'] == 'A') {
							$parent = qa_db_select_with_pending(
								qa_db_full_post_selectspec(
									$userid,
									$parent['parentid']
								)
							);				
						}

						$anchor = qa_anchor((strpos($event['event'],'a_') === 0 || strpos($event['event'],'in_a_') === 0?'A':'C'), $params['postid']);
						$activity_url = qa_path_absolute(qa_q_request($parent['postid'], $parent['title']), null, $anchor);
						$linkTitle = $parent['title'];
						$link = '<a target="_blank" href="'.$activity_url.'">'.$parent['title'].'</a>';
						//if($event == 'new_u_mentioned')
						//$qTitle = qa_db_read_one_value( qa_db_query_sub("SELECT title FROM `^posts` WHERE `postid` = ".$params['parentid']." LIMIT 1"), true );
					}

					else if($post != null) { // question
						if(!isset($params['title'])) {
							$params['title'] = $posts[$params['postid']]['title'];
						}
						if($params['title'] !== null) {
							$qTitle = qa_db_read_one_value( qa_db_query_sub("SELECT title FROM `^posts` WHERE `postid` = ".$params['postid']." LIMIT 1"), true );
							if(!isset($qTitle)) $qTitle = ''; // (isset($getQtitle[0])) ? $getQtitle[0] : "";
							$activity_url = qa_path_absolute(qa_q_request($params['postid'], $qTitle),  null, null);
							$linkTitle = $qTitle;
							$link = '<a target="_blank" href="'.$activity_url.'">'.$qTitle.'</a>';
						}
					}

					$eventtime = $event['datetime'];

					$whenhtml = qa_html(qa_time_to_string(qa_opt('db_time')-$eventtime));
					$when = qa_lang_html_sub('main/x_ago', $whenhtml);

					//print_r($type);
					// event name
					$eventName = '';
					$itemIcon = '';
					if($type=='in_c_question' || $type=='in_c_answer' || $type=='in_c_comment' || $type=='in_blog_comment'  || $type=='in_blog_c_comment'  ) { // added in_c_comment
						$eventName = qa_lang('q2apro_onsitenotifications_lang/in_comment');
						$itemIcon = '<img src="'.$url.$this->urltoroot.'comment-icon.png" />';
						//$itemIcon = '<div class="nicon ncomment"></div>';
					}
					else if($type=='in_q_vote_up' || $type=='in_a_vote_up') {
						$eventName = qa_lang('q2apro_onsitenotifications_lang/in_upvote');
						$itemIcon = '<img src="'.$url.$this->urltoroot.'vote-up-mini.png" />';
					//	$itemIcon = '<div class="nicon nvoteup"></div>';
					}
					else if($type=='in_q_vote_down' || $type=='in_a_vote_down') {
						$eventName = qa_lang('q2apro_onsitenotifications_lang/in_downvote');
						$itemIcon = '<img src="'.$url.$this->urltoroot.'vote-down-mini.png" />';
					//	$itemIcon = '<div class="nicon nvotedown"></div>';
					}
					else if($type=='in_c_vote_up') {
						$eventName = qa_lang('q2apro_onsitenotifications_lang/in_commentupvote');
						$itemIcon = '<img src="'.$url.$this->urltoroot.'vote-up-mini.png" />';
					//	$itemIcon = '<div class="nicon nvoteup"></div>';
					}
					else if($type=='in_c_vote_down') {
						$eventName = qa_lang('q2apro_onsitenotifications_lang/in_commentdownvote');
						$itemIcon = '<img src="'.$url.$this->urltoroot.'vote-down-mini.png" />';
					//	$itemIcon = '<div class="nicon nvotedown"></div>';
					}
					else if($type=='in_a_question') {
						$eventName = qa_lang('q2apro_onsitenotifications_lang/in_answer');
						$itemIcon = '<img src="'.$url.$this->urltoroot.'answer-icon.png" />';
					  //      $itemIcon = '<div class="nicon nanswer"></div>';

					}
					else if($type=='in_a_select') {
						$eventName = qa_lang('q2apro_onsitenotifications_lang/in_bestanswer');
						$itemIcon = '<img src="'.$url.$this->urltoroot.'best_answer_mini.png" />';
					//	$itemIcon = '<div class="nicon nbestanswer"></div>';
					}
					else if($type=='in_q_edit') {
						$eventName = qa_lang('q2apro_onsitenotifications_lang/in_edit');
						$itemIcon = '<img src="'.$url.$this->urltoroot.'edit.png" />';
					}
					else if($type=='in_a_edit') {
						$eventName = qa_lang('q2apro_onsitenotifications_lang/in_edit');
						$itemIcon = '<img src="'.$url.$this->urltoroot.'edit.png" />';
					}
					else if($type=='in_c_edit') {
						$eventName = qa_lang('q2apro_onsitenotifications_lang/in_edit');
						$itemIcon = '<img src="'.$url.$this->urltoroot.'edit.png" />';
					}
					else if($type=='in_q_merge') {
						$eventName = qa_lang('q2apro_onsitenotifications_lang/in_merge');
						$itemIcon = '<img src="'.$url.$this->urltoroot.'merge.png" />';
					}
					else if($type=='in_q_close') {
						$eventName = qa_lang('q2apro_onsitenotifications_lang/in_close');
						$itemIcon = '<img src="'.$url.$this->urltoroot.'close.png" />';
					}
					else if($type=='new_u_message') {
						if(!isset($params['title'])) {
							$params['title'] = 'New message';
						}

						$sender = $event['handle'];
						#$message = $params['message'];
						$qTitle = $event['handle'];
						$activity_url = qa_path_absolute('message').'/'.$event['handle'];
						$linkTitle = $qTitle;
						$link = '<a target="_blank" href="'.$activity_url.'">'.$qTitle.'</a>';

						$eventName = qa_lang('q2apro_onsitenotifications_lang/new_message');
						//$itemIcon = '<img src="'.$url.$this->urltoroot.'message.ico" />';
						$itemIcon = '<img src="'.$url.$this->urltoroot.'mail.png" />';
					}
					else if($type=='new_u_wall_post') {
						if(!isset($params['title'])) {
							$params['title'] = 'New wall post';
						}

						$sender = $params['sender'];
						//$message = $params['message'];
						$qTitle = $params['sender_handle'];
						$activity_url = qa_path_absolute('user').'/'.$params['handle'].'/wall';
						$linkTitle = $qTitle;
						$link = '<a target="_blank" href="'.$activity_url.'">'.$qTitle.'</a>';

						$eventName = qa_lang('q2apro_onsitenotifications_lang/new_wall_post');
						//$itemIcon = '<img src="'.$url.$this->urltoroot.'message.ico" />';
						$itemIcon = '<img src="'.$url.$this->urltoroot.'wallpost.png" />';
					}
					else if($type=='new_u_mentioned') {
						if(!isset($params['title'])) {
							$params['title'] = 'New mention';
						}
						$sender = $params['sender'];

						$eventName = qa_lang('q2apro_onsitenotifications_lang/new_mention');
						//$itemIcon = '<img src="'.$url.$this->urltoroot.'message.ico" />';
						$itemIcon = '<img src="'.$url.$this->urltoroot.'mention.png" />';
					}
					else if($type=='u_favorite') {
						if(!isset($params['title'])) {
							$params['title'] = 'New follower';
						}
						$sender = $params['sender'];
						$qTitle = $params['sender_handle'];
						$activity_url = qa_path_absolute('').'user/'.$params['sender_handle'];
						$linkTitle = $qTitle;
						$link = '<a target="_blank" href="'.$activity_url.'">'.$qTitle.'</a>';

						$eventName = qa_lang('q2apro_onsitenotifications_lang/new_favorite');
						//$itemIcon = '<img src="'.$url.$this->urltoroot.'message.ico" />';
						$itemIcon = '<img src="'.$url.$this->urltoroot.'favorite.png" />';
					}
					else if($type=='q2apro_osn_plugin') {
						$eventName = ''; // Just to make compiler happy
						$itemIcon = '<div class="nicon ' . $event['icon_class'] . '"></div>';
						$activity_url = ''; // Just to make compiler happy
						$linkTitle = ''; // Just to make compiler happy
					}
					else {
						// ignore other events such as in_c_flag
						//	continue;
					}

					// if post has been deleted there is no link, dont output

					// extra CSS for highlighting new events
					$cssNewEv = '';
					if($eventtime > $last_visit) {
						$cssNewEv = '-new';
					}
					// if post has been deleted there is no link, dont output
					if($activity_url == '' && $type !== 'q2apro_osn_plugin') {
						continue;
					} else {
						$eventHtml = ($type === 'q2apro_osn_plugin')
							? $event['event_text']
							: $eventName . ' <a ' . ($type == 'u_message' || $type == 'u_wall_post' ? 'title="' . $event['message'] . '" ' : '') . 'href="' . $activity_url . '"' . (qa_opt('q2apro_onsitenotifications_newwindow') ? ' target="_blank"' : '') . '>' . htmlentities($linkTitle) . '</a>';

						$notifyBoxEvents .= '<div class="itemBox'.$cssNewEv.'">
							'.$itemIcon.'
							<div class="nfyItemLine">
							<p class="nfyWhat">
							'.$eventHtml . '
							</p>
							<p class="nfyTime">'.$when.'</p>
							</div>
							</div>';
					}


				} // END FOREACH

				$notifyBoxEvents .= '</div>
					</div>
					<div class="nfyFooter">
					<a href="http://www.q2apro.com/">by q2apro.com</a>
					</div>
					</div>
';

				header('Access-Control-Allow-Origin: '.$url);
				echo $notifyBoxEvents;

				// update database entry so that all user notifications are seen as read
				qa_db_query_sub(
					'INSERT INTO ^usermeta (user_id,meta_key,meta_value) VALUES(#,$,NOW()) ON DUPLICATE KEY UPDATE meta_value=NOW()',
						$userid, 'visited_profile'
				);

				exit(); 
			} // END AJAX RETURN
			else {
				echo 'Unexpected problem detected! No userid, no transfer string.';
				return;
			}
		}


		/* start */
		$qa_content = qa_content_prepare();

		$qa_content['title'] = ''; // page title

		// return if not admin!
		if(qa_get_logged_in_level() < QA_USER_LEVEL_ADMIN) {
			$qa_content['custom0'] = '<div>Access denied</div>';
			return $qa_content;
		}

		return $qa_content;
	}

	private function getEventsForUser($userid)
	{
		$maxEvents = qa_opt('q2apro_onsitenotifications_maxevshow'); // maximal events to show

		$currentTime = (int)qa_opt('db_time');
		$maxageTime = $currentTime - (int)qa_opt('q2apro_onsitenotifications_maxage') * 86400;

		$event_query = qa_db_query_sub(
			'(
				SELECT
				e.event,
				e.userid,
				BINARY e.params as params,
				UNIX_TIMESTAMP(e.datetime) AS datetime,
				"" `icon_class`,
				"" event_text
				FROM ^eventlog e
				WHERE
				FROM_UNIXTIME(#) <= datetime AND
					(e.userid = # AND (e.event LIKE "in_%" OR e.event like "new_%"))
		) UNION (
			SELECT
			"q2apro_osn_plugin" `event`,
			`user_id` `userid`,
			"" `params`,
			UNIX_TIMESTAMP(`created_at`) `datetime`,
			`icon_class`,
			`event_text`
			FROM ^q2apro_osn_plugin_notifications
			WHERE FROM_UNIXTIME(#) <= `created_at` AND `user_id` = #
		)
		ORDER BY datetime DESC
		LIMIT #', // Limit
		$maxageTime, // events of last x days
			$userid,
		//	$userid,
			$maxageTime, // events of last x days
			$userid,
			$maxEvents
		);
		return $event_query;
	}


};


/*
   Omit PHP closing tag to help avoid accidental output
 */
