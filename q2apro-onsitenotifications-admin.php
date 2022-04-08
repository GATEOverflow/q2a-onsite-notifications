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

	class q2apro_onsitenotifications_admin {

		// initialize db-table 'eventlog' if it does not exist yet
		function init_queries($tableslc) {
			$result = array();		
			$tablename = qa_db_add_table_prefix('eventlog');
			
			// check if event logger has been initialized already (check for one of the options and existing table)
			require_once QA_INCLUDE_DIR.'qa-app-options.php';
			if(qa_opt('event_logger_to_database') && in_array($tablename, $tableslc)) {
				// options exist, but check if really enabled
				if(qa_opt('event_logger_to_database')=='' && qa_opt('event_logger_to_files')=='') {
					// enabled database logging
					qa_opt('event_logger_to_database', 1);
				}
			}
			else {
				// not enabled, let's enable the event logger
			
				// set option values for event logger
				qa_opt('event_logger_to_database', 1);
				qa_opt('event_logger_to_files', '');
				qa_opt('event_logger_directory', '');
				qa_opt('event_logger_hide_header', '');
			
				if (!in_array($tablename, $tableslc)) {
					require_once QA_INCLUDE_DIR.'qa-app-users.php';
					require_once QA_INCLUDE_DIR.'qa-db-maxima.php';

					$result[] = 'CREATE TABLE IF NOT EXISTS ^eventlog ('.
						'datetime DATETIME NOT NULL,'.
						'ipaddress VARCHAR (15) CHARACTER SET ascii,'.
						'userid '.qa_get_mysql_user_column_type().','.
						'handle VARCHAR('.QA_DB_MAX_HANDLE_LENGTH.'),'.
						'cookieid BIGINT UNSIGNED,'.
						'event VARCHAR (20) CHARACTER SET ascii NOT NULL,'.
						'params VARCHAR (800) NOT NULL,'.
						'KEY datetime (datetime),'.
						'KEY ipaddress (ipaddress),'.
						'KEY userid (userid),'.
						'KEY event (event)'.
					') ENGINE=MyISAM DEFAULT CHARSET=utf8';
				}
			}

			$tablename2 = qa_db_add_table_prefix('usermeta');
                        if (!in_array($tablename2, $tableslc)) {
                                $result[] =
                                        'CREATE TABLE IF NOT EXISTS ^usermeta (
                                        meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                                        user_id bigint(20) unsigned NOT NULL,
                                        meta_key varchar(255) DEFAULT NULL,
                                        meta_value longtext,
                                        PRIMARY KEY (meta_id),
                                        UNIQUE (user_id,meta_key)
                                        ) ENGINE=MyISAM  DEFAULT CHARSET=utf8'
                                ;
                        }

                        // create table qa_usermeta which stores the last visit of each user
                        $tablename3 = qa_db_add_table_prefix('q2apro_osn_plugin_notifications');
                        if (!in_array($tablename3, $tableslc)) {
                                $result[] =
                                        'CREATE TABLE IF NOT EXISTS ^q2apro_osn_plugin_notifications (' .
                                        'plugin_id VARCHAR(50) NOT NULL, ' .
                                        'event_text VARCHAR(2000) NOT NULL, ' .
                                        'icon_class VARCHAR(50) NOT NULL, ' .
                                        'user_id ' . qa_get_mysql_user_column_type() . ' NOT NULL, ' .
                                        'created_at DATETIME NOT NULL, ' .
                                        'KEY ^q2apro_osn_plugin_notifications_idx1 (user_id, created_at), ' .
                                        'KEY ^q2apro_osn_plugin_notifications_idx2 (plugin_id, user_id, created_at)' .
                                        ') ENGINE=MyISAM  DEFAULT CHARSET=utf8'
                                ;
                        }
			return $result;
			// memo: would be best to check if plugin is installed in qa-plugin/ folder or using plugin_exists()
			// however this functionality is not available in q2a v1.6.3 
			
		} // end init_queries

		// option's value is requested but the option has not yet been set
		function option_default($option) {
			switch($option) {
				case 'q2apro_onsitenotifications_enabled':
					return 1; // true
				case 'q2apro_onsitenotifications_nill':
					return 'N'; // days
				case 'q2apro_onsitenotifications_maxage':
					return 180; // days
				case 'q2apro_onsitenotifications_maxevshow':
					return 100; // max events to show in notify box
				case 'q2apro_onsitenotifications_newwindow':
                                        return 1; // true
				case 'q2apro_osn_refreshinterval':
                                        return 20; // true
				default:
					return null;
			}
		}
			
		function allow_template($template) {
			return ($template!='admin');
		}       
			
		function admin_form(&$qa_content){                       

			// process the admin form if admin hit Save-Changes-button
			$ok = null;
			if (qa_clicked('q2apro_onsitenotifications_save')) {
				qa_opt('q2apro_onsitenotifications_enabled', (bool)qa_post_text('q2apro_onsitenotifications_enabled')); // empty or 1
				qa_opt('q2apro_onsitenotifications_nill', qa_post_text('q2apro_onsitenotifications_nill')); // string
				qa_opt('q2apro_onsitenotifications_maxevshow', (int)qa_post_text('q2apro_onsitenotifications_maxevshow')); // int
				qa_opt('q2apro_onsitenotifications_maxage', (int)qa_post_text('q2apro_onsitenotifications_maxage')); // int
				qa_opt('q2apro_osn_refreshinterval', (int)qa_post_text('q2apro_osn_refreshinterval')); // int
				qa_opt('q2apro_onsitenotifications_newwindow', (bool)qa_post_text('q2apro_onsitenotifications_newwindow')); // int

				$ok = qa_lang('admin/options_saved');
			}
			
			// form fields to display frontend for admin
			$fields = array();
			
			$fields[] = array(
				'type' => 'checkbox',
				'label' => qa_lang('q2apro_onsitenotifications_lang/enable_plugin'),
				'tags' => 'name="q2apro_onsitenotifications_enabled"',
				'value' => qa_opt('q2apro_onsitenotifications_enabled'),
			);
			
			
			$fields[] = array(
				'type' => 'input',
				'label' => qa_lang('q2apro_onsitenotifications_lang/no_notifications_label'),
				'tags' => 'name="q2apro_onsitenotifications_nill" style="width:100px;"',
				'value' => qa_opt('q2apro_onsitenotifications_nill'),
			);

			$fields[] = array(
				'type' => 'input',
				'label' => qa_lang('q2apro_onsitenotifications_lang/admin_maxeventsshow'),
				'tags' => 'name="q2apro_onsitenotifications_maxevshow" style="width:100px;"',
				'value' => qa_opt('q2apro_onsitenotifications_maxevshow'),
			);
			$fields[] = array(
				'type' => 'input',
				'label' => qa_lang('q2apro_onsitenotifications_lang/admin_maxage'),
				'tags' => 'name="q2apro_onsitenotifications_maxage" style="width:100px;"',
				'value' => qa_opt('q2apro_onsitenotifications_maxage'),
			);
			$fields[] = array(
				'type' => 'input',
				'label' => qa_lang('q2apro_onsitenotifications_lang/admin_refreshinterval'),
				'tags' => 'name="q2apro_osn_refreshinterval" style="width:100px;"',
				'value' => qa_opt('q2apro_osn_refreshinterval'),
			);
			 $fields[] = array(
                                'type' => 'checkbox',
                                'label' => qa_lang('q2apro_onsitenotifications_lang/admin_newwindow'),
                                'tags' => 'name="q2apro_onsitenotifications_newwindow"',
                                'value' => qa_opt('q2apro_onsitenotifications_newwindow'),
                        );

			return array(           
				'ok' => ($ok && !isset($error)) ? $ok : null,
				'fields' => $fields,
				'buttons' => array(
					array(
						'label' => qa_lang_html('main/save_button'),
						'tags' => 'name="q2apro_onsitenotifications_save"',
					),
				),
			);
		}
	}


/*
	Omit PHP closing tag to help avoid accidental output
*/
